<?php

namespace App\Services\HarmonicContext;

/**
 * Contextual chord re-ranking service (Phase 2).
 *
 * Wires together the sub-passes that work with Phase 1 results:
 * - 2a: PedalDetector
 * - 2c′: DiminishedAsDominantResolver
 * - 2c: DiminishedResolver
 * - 2d: Diatonicity-based re-ranking
 * - 2e: Bigram transition rescore
 * - 2f: Viterbi sequence search (second-order)
 * - 2g: Root-pinned candidate injection, then 2f again
 *
 * Note: HarmonicPatternMatcher (2b) works with Roman numerals and is
 * integrated at a higher level via HarmonicContext/ProgressionDetector.
 *
 * 2a–2f all CHOOSE among the names Phase 1 emitted. 2g is the one pass that can
 * CONSTRUCT a name Phase 1 never produced, for voicings that do not carry their
 * own root (a bass alternation, where the root is absent for one beat and its
 * identity belongs to the bar rather than the shape). It does so only when the
 * functional trigram decisively demands it.
 *
 * Pure function: no DB access at runtime, no I/O, no state. (2g's injected
 * identifier is a callable into VoicingCrossref::identifyWithPinnedRoot, which
 * reads only class constants — the purity invariant holds.)
 */
class ContextualReranker implements IContextualReranker
{
    // Diatonicity bonus for chords in the song key
    private const DIATONICITY_BONUS = 2.0;

    /**
     * Sub-pass commitments that assert a SEQUENCE claim (this chord makes sense
     * given its neighbours). Viterbi (2f) makes the same claim with strictly more
     * context — a whole-path search over a second-order model — so these must stay
     * challengeable: their winner keeps pole position in the pool, but Viterbi may
     * route past it.
     *
     * Every other reason is STRUCTURAL: it asserts a voicing-level fact Viterbi
     * cannot re-derive from transition statistics (a pedal point; a dim7 that is
     * really a rootless V7b9). Those collapse the pool to a single candidate.
     *
     * Classified by reason rather than by a flag each sub-pass must remember to
     * set — a new structural pass is then locked by default, which is the safe
     * direction to fail.
     */
    private const SEQUENTIAL_REINTERPRET_REASONS = ['cadence', 'bigram'];

    /**
     * How decisively a challenger must beat the incumbent on the SAME functional
     * trigram context before sequence evidence may overrule a stronger Pass-1
     * reading. Relative, not absolute: with a ~115-token functional vocabulary a
     * "k × uniform" bar is cleared even by plainly wrong readings
     * (P(Imaj7 | IVmaj7, V7) = 0.19 ≈ 21 × uniform), so only the margin over the
     * incumbent separates truth from plausibility. Ipanema: II7 beats VIm6 by 13.9×
     * and IIm7 beats IVmaj7 by 2.95×.
     */
    private const FORWARD_WARRANT_MIN_EDGE = 2.0;

    // Root motion bonuses (from VoicingCrossref context pass)
    private const ROOT_MOTION_BONUS = [
        5  => 3,   // ascending P4 / descending P5 (V→I, ii→V)
        7  => 2,   // ascending P5 / descending P4 (I→V)
        1  => 2,   // ascending semitone (vii→I, tritone sub resolution)
        2  => 1,   // ascending whole step (IV→V, I→II)
        10 => 1,   // descending whole step (= ascending m7, V→IV)
        3  => 1,   // ascending m3 (I→bIII, relative key motion)
        9  => 1,   // ascending M6 / descending m3 (vi→IV)
    ];

    // Major scale intervals for diatonicity checks
    private const CTX_MAJOR_SCALE = [0, 2, 4, 5, 7, 9, 11];

    public function __construct(
        private DiminishedResolver $dimResolver,
        private PedalDetector $pedalDetector,
        private HarmonicPatternMatcher $patternMatcher,
        private DiminishedAsDominantResolver $dimAsDomResolver,
        private ?\App\Services\Identifier\TransitionScorer $transitionScorer = null,
        // Optional: enables root-pinned candidate injection (2f). A callable, not
        // the service — VoicingCrossref resolves ContextualReranker, so a direct
        // dependency would be circular. Mirrors how PedalDetector receives
        // identifyPhase1Only. Left null in DI-light constructions and unit tests,
        // which then simply skip injection.
        private $pinnedRootIdentifier = null,
    ) {
        // Lazy-default so DI-light constructions still work.
        $this->transitionScorer ??= new \App\Services\Identifier\TransitionScorer();
    }

    /**
     * Re-rank Phase 1 results using harmonic context.
     *
     * @param array<int, array> $phase1Results   Array of Phase 1 results with candidates
     * @param string|null       $songKey        e.g. 'F', 'Am', null
     * @param array<int, string>|null $expectedChords  Optional per-slot prior
     * @return array<int, array>  Re-ranked results with reinterpreted flags
     */
    public function rerank(
        array $phase1Results,
        ?string $songKey = null,
        ?array $expectedChords = null,
    ): array {
        // Ensure results have required fields
        $results = $this->normalizeResults($phase1Results);

        // Sub-pass 2a: PedalDetector
        $results = $this->pedalDetector->detect($results);

        // Sub-pass 2c′: DiminishedAsDominantResolver
        $results = $this->dimAsDomResolver->resolve($results);

        // Sub-pass 2c: DiminishedResolver
        $results = $this->dimResolver->resolve($results);

        // Sub-pass 2b: HarmonicPatternMatcher (fragment matching)
        // Order: 2a → 2c → 2b → 2d per spec §1.6.6
        $patternResult = $this->patternMatcher->match($results, $songKey);
        $expectedChordsFromPattern = $patternResult['expected_chords'];
        $results = $patternResult['results'];

        // Sub-pass 2d: Diatonicity-based re-ranking
        if ($songKey !== null) {
            $results = $this->applyDiatonicityRerank($results, $songKey, $expectedChordsFromPattern);
        }

        // Sub-pass 2e (Phase 3.3a): Bigram transition rescore.
        // Reweights each slot's top-K candidates by P(next | previous-winner) from
        // the corpus bigram. Promotes candidates that form idiomatic transitions
        // from the previous chord's chosen name; lightly demotes those that don't.
        $results = $this->applyTransitionRescore($results);

        // Sub-pass 2f (Phase 3.4): Viterbi sequence search.
        // Per-slot multipliers (2e) compound LOCALLY. Viterbi compounds across
        // the WHOLE sequence — a chain of strong transitions can overcome a
        // locally-stronger candidate when the alternative produces idiomatic
        // continuations through multiple slots.
        $results = $this->applyViterbiRescore($results, $songKey);

        // Sub-pass 2g: root-pinned injection, then re-run the search.
        //
        // Injection reads the SETTLED downstream reading to decide what a slot's
        // root must be, so it cannot run before 2f — ahead of the search the
        // downstream slots still carry the greedy passes' mistakes, which usually
        // do not place as numerals at all (every probability comes back null and
        // nothing is injected). Running it after 2f gives it real context; the
        // second 2f then routes the path through whatever was injected.
        //
        // Monotone: each round only appends candidates, never removes or renames,
        // so the loop terminates. Capped anyway — a corpus pathology must not turn
        // into an unbounded loop on a user's chord chart.
        for ($round = 0; $round < 2; $round++) {
            $results = $this->injectRootPinnedCandidates($results, $songKey, $injected);
            if (!$injected) break;
            $results = $this->applyViterbiRescore($results, $songKey);
        }

        return $results;
    }

    /**
     * Normalize Phase 1 results to ensure required fields exist.
     */
    private function normalizeResults(array $results): array
    {
        foreach ($results as $i => $result) {
            if (!isset($result['pcs'])) {
                $results[$i]['pcs'] = [];
            }
            if (!isset($result['bass_note'])) {
                $results[$i]['bass_note'] = null;
            }
            if (!isset($result['reinterpreted'])) {
                $results[$i]['reinterpreted'] = false;
            }
            if (!isset($result['reinterpret_reason'])) {
                $results[$i]['reinterpret_reason'] = null;
            }
            if (!isset($result['candidates'])) {
                $results[$i]['candidates'] = [];
            }
        }

        return $results;
    }

    /**
     * Sub-pass 2d: Apply diatonicity-based re-ranking.
     *
     * 1. If expectedChord[slot] is set AND it appears in the slot's top-K:
     *    promote it to winner. Record reinterpreted = true.
     * 2. Else, compute diatonicity score for each top-K candidate against
     *    the song key and re-rank.
     */
    private function applyDiatonicityRerank(
        array $results,
        string $songKey,
        ?array $expectedChords = null,
    ): array {
        $keyPc = $this->noteNameToPc($songKey);
        $isMinor = str_ends_with($songKey, 'm');

        foreach ($results as $i => $result) {
            // Skip if already reinterpreted by 2a or 2c
            if ($result['reinterpreted'] ?? false) {
                continue;
            }

            $candidates = $result['candidates'] ?? [];

            if (empty($candidates)) {
                continue;
            }

            // Check if expected chord is in top-K (from 2b fragment matching)
            if ($expectedChords !== null && isset($expectedChords[$i])) {
                $expectedName = $expectedChords[$i];
                $expectedIndex = $this->findCandidateIndex($candidates, $expectedName);

                if ($expectedIndex !== null) {
                    // Promote expected chord to winner
                    $expectedCandidate = $candidates[$expectedIndex];
                    $currentWinner = $candidates[0]; // Original winner is first in array
                    $currentScore = $currentWinner['score'] ?? 0;
                    $expectedScore = $expectedCandidate['score'] ?? 0;

                    // Only promote if expected chord has comparable or better score
                    // Allow 20% tolerance to handle minor score differences
                    $scoreRatio = $currentScore > 0 ? $expectedScore / $currentScore : 0;
                    if ($scoreRatio < 0.8) {
                        // Expected chord score is too low - don't promote
                        continue;
                    }

                    // Prefer simpler chords: avoid promoting slash chords over non-slash when scores are close
                    $currentHasSlash = str_contains($currentWinner['name'], '/');
                    $expectedHasSlash = str_contains($expectedCandidate['name'], '/');
                    if ($expectedHasSlash && !$currentHasSlash && $scoreRatio < 1.0) {
                        // Expected is a slash chord, current is not, and expected doesn't have higher score
                        // Don't promote - prefer the simpler non-slash chord
                        continue;
                    }

                    // Avoid promoting to more complex slash chord names when both are slash chords
                    if ($expectedHasSlash && $currentHasSlash && $scoreRatio <= 1.0) {
                        // Both are slash chords and expected doesn't have higher score
                        // Prefer the original - it's likely more common
                        continue;
                    }

                    // Avoid slash chords where bass note equals root (e.g., "Ddim/D", "C7/C")
                    // These are redundant and not musically useful
                    if ($expectedHasSlash) {
                        [$expectedRoot, $expectedBass] = explode('/', $expectedCandidate['name'], 2);
                        if (strcasecmp($expectedRoot, $expectedBass) === 0) {
                            // Bass note equals root - don't promote
                            continue;
                        }
                    }

                    // Only set reinterpreted flag if the expected chord is different from current winner
                    $isReinterpreted = ($expectedCandidate['name'] !== $result['name']);
                    $results[$i] = array_merge($results[$i], [
                        'name' => $expectedCandidate['name'],
                        'root' => $expectedCandidate['root'],
                        'quality' => $expectedCandidate['quality'],
                        'extensions' => $expectedCandidate['extensions'],
                        'bass_note' => $expectedCandidate['bass_note'],
                        'confidence' => $expectedCandidate['score'],
                        'reinterpreted' => $isReinterpreted,
                        'reinterpret_reason' => $isReinterpreted ? 'cadence' : null, // or 'reference_anchor' per spec §1.7.2
                    ]);
                    continue;
                }
            }

            // Compute diatonicity scores and re-rank
            $rerankedCandidates = $this->rerankByDiatonicity($candidates, $keyPc, $isMinor);

            if ($rerankedCandidates[0]['name'] !== $result['name']) {
                $newWinner = $rerankedCandidates[0];
                $currentWinner = $candidates[0];
                $newScore = $newWinner['score'] ?? 0;
                $currentScore = $currentWinner['score'] ?? 0;
                $scoreRatio = $currentScore > 0 ? $newScore / $currentScore : 0;

                // Prefer simpler chords: avoid promoting slash chords over non-slash when scores are close
                $currentHasSlash = str_contains($currentWinner['name'], '/');
                $newHasSlash = str_contains($newWinner['name'], '/');
                if ($newHasSlash && !$currentHasSlash && $scoreRatio < 1.0) {
                    // New winner is a slash chord, current is not, and new doesn't have higher score
                    // Don't promote - prefer the simpler non-slash chord
                    continue;
                }

                // Avoid promoting to more complex slash chord names when both are slash chords
                if ($newHasSlash && $currentHasSlash && $scoreRatio <= 1.0) {
                    // Both are slash chords and new doesn't have higher score
                    // Prefer the original - it's likely more common
                    continue;
                }

                // Avoid slash chords where bass note equals root (e.g., "Ddim/D", "C7/C")
                // These are redundant and not musically useful
                if ($newHasSlash) {
                    [$newRoot, $newBass] = explode('/', $newWinner['name'], 2);
                    if (strcasecmp($newRoot, $newBass) === 0) {
                        // Bass note equals root - don't promote
                        continue;
                    }
                }

                $results[$i] = array_merge($results[$i], [
                    'name' => $newWinner['name'],
                    'root' => $newWinner['root'],
                    'quality' => $newWinner['quality'],
                    'extensions' => $newWinner['extensions'],
                    'bass_note' => $newWinner['bass_note'],
                    'confidence' => $newWinner['score'],
                    'reinterpreted' => true,
                    'reinterpret_reason' => 'diatonicity',
                ]);
            }
        }

        return $results;
    }

    /**
     * Sub-pass 2e (Phase 3.3a): Bigram transition rescore.
     *
     * For each slot N (starting at slot 1), look at slot N-1's chosen chord name
     * and apply P(slot_N_candidate | slot_{N-1}) as a score multiplier to each
     * candidate at slot N. Then re-pick the winner if a different candidate now
     * leads the rescored ranking.
     *
     * Spec: docs/SBN-Identifier-Reference.md §5.3.
     *
     * Safety rails identical to 2d to avoid silly slash-chord promotions:
     *   - never promote slash over non-slash unless the rescored gap is large
     *   - never promote slash/slash unless the new winner clearly outscores
     *   - never promote X/X (bass = root)
     *
     * The transition signal compounds across the sequence; one transition can't
     * dominate alone (multiplier bounded [0.7, 1.5]), but a chain of strong
     * transitions can shift a slot's reading when other layers were borderline.
     */
    private function applyTransitionRescore(array $results): array
    {
        if ($this->transitionScorer === null) return $results;
        if (count($results) < 2) return $results;

        for ($i = 1; $i < count($results); $i++) {
            $prevName = $results[$i - 1]['name'] ?? null;
            if ($prevName === null || $prevName === '') continue;

            $candidates = $results[$i]['candidates'] ?? [];
            if (empty($candidates)) continue;
            if ($results[$i]['reinterpreted'] ?? false) continue; // upstream sub-pass already decided

            // Rescore each candidate
            $rescored = [];
            foreach ($candidates as $c) {
                $name = $c['name'] ?? '';
                if ($name === '') { $rescored[] = $c; continue; }
                $mult = $this->transitionScorer->scoreMultiplier($prevName, $name);
                $c['transition_score'] = ($c['score'] ?? 0) * $mult;
                $rescored[] = $c;
            }
            usort($rescored, fn($a, $b) => ($b['transition_score'] ?? 0) <=> ($a['transition_score'] ?? 0));

            $newWinner = $rescored[0];
            $currentWinner = $candidates[0];
            if ($newWinner['name'] === $currentWinner['name']) continue;

            // Same safety rails as 2d
            $newScore = $newWinner['transition_score'] ?? 0;
            $currentScore = $currentWinner['transition_score'] ?? ($currentWinner['score'] ?? 0);
            $ratio = $currentScore > 0 ? $newScore / $currentScore : 1.0;

            $currentHasSlash = str_contains($currentWinner['name'], '/');
            $newHasSlash = str_contains($newWinner['name'], '/');
            if ($newHasSlash && !$currentHasSlash && $ratio < 1.20) continue;
            if ($newHasSlash && $currentHasSlash && $ratio <= 1.05) continue;
            if ($newHasSlash) {
                $parts = explode('/', $newWinner['name'], 2);
                if (count($parts) === 2 && strcasecmp($parts[0], $parts[1]) === 0) continue;
            }

            $results[$i] = array_merge($results[$i], [
                'name'               => $newWinner['name'],
                'root'               => $newWinner['root'] ?? $results[$i]['root'] ?? null,
                'quality'            => $newWinner['quality'] ?? $results[$i]['quality'] ?? null,
                'extensions'         => $newWinner['extensions'] ?? $results[$i]['extensions'] ?? '',
                'bass_note'          => $newWinner['bass_note'] ?? $results[$i]['bass_note'] ?? null,
                'confidence'         => $newWinner['score'] ?? null,
                'reinterpreted'      => true,
                'reinterpret_reason' => 'bigram',
            ]);
        }
        return $results;
    }

    /**
     * Sub-pass 2f (Phase 3.4 / 3.4c): second-order Viterbi sequence search.
     *
     * Per-slot multipliers compound locally; Viterbi compounds across the entire
     * sequence. The DP state is a PAIR (prev-slot cand, curr-slot cand) so each
     * edge scores the TRIGRAM P(curr | prev2, prev1) — widening the viewport from
     * one predecessor to two. This is what makes functional patterns like
     * II7→V7→I win as a unit (a bigram can't see the three-chord shape). The
     * trigram backs off functional → surface-tri → surface-bigram, so short or
     * out-of-corpus sequences degrade to the original first-order behaviour.
     * Slot 1's edge is a plain bigram (no third predecessor exists yet).
     *
     * Structurally mirrors ProgressionBuilder::viterbiSearch (Phase D builder).
     * Spec: docs/SBN-Identifier-Reference.md §5.3.
     *
     * Safety rails:
     *   - Only candidates already in each slot's top-K are considered (cannot
     *     introduce a name no prior layer produced).
     *   - Per-slot promotion only if the Viterbi winner ALSO scores within ~30%
     *     of the locally-best score after 2e — prevents catastrophic flips when
     *     a single dominant Pass 1 reading is forced down by a chain of weak
     *     bigram transitions.
     *   - Same slash-chord guard as 2d/2e (no `X/X` bass-equals-root names).
     */
    /**
     * Inject root-pinned candidates the sequence demands but Pass 1 never emitted.
     *
     * Every other Phase-2 mechanism CHOOSES among names Pass 1 produced. That is
     * fatal for a voicing that does not carry its own root: Ipanema's 6x466x
     * {Bb,Gb,Db,F} is an exact, complete Gbmaj7/Bb in isolation, and the Ebm7(9)
     * reading it needs never enters the pool (it scores 735 and is cut by the
     * top-5 slice). So here we CONSTRUCT the missing name once the progression
     * says what the root must be.
     *
     * Evidence rule. For each of the 12 possible roots, ask VoicingCrossref for the
     * forced spelling of these exact pitch classes under that root; most roots
     * yield nothing. Score each survivor against the slot's two SUCCESSORS on the
     * functional trigram — the slot itself sits in the prev2 position, so a single
     * settled pair downstream is enough, and we need no settled predecessor.
     *
     * The gate is RELATIVE, deliberately. An absolute "beats k×uniform" bar is
     * vacuous here: the functional vocabulary is ~115 tokens, so 5×uniform ≈ 0.043
     * and even a plainly wrong reading clears it (P(Imaj7 | IVmaj7, V7) = 0.188,
     * 21×uniform). What separates truth from plausibility is that the challenger
     * beats THE INCUMBENT on the SAME context by a wide margin. For Ipanema slot1:
     * IIm7 scores 0.556 vs IVmaj7's 0.188 — a 2.95× edge.
     *
     * Functional tier only, with no bigram backoff. trigramProbability() would fall
     * back to P(curr|prev1) and hide that it had done so, making the guard
     * meaningless; functionalTrigramProbability() returns null instead, and null
     * means "no evidence", never "weak evidence".
     *
     * Chicken-and-egg. The context this needs is the SETTLED downstream reading, and
     * before Viterbi runs the downstream slots may still hold the greedy passes'
     * mistakes — which typically do not even place as numerals, so every probability
     * comes back null and nothing is injected. Hence the caller alternates
     * Viterbi → inject → Viterbi (see rerank()). Each round only ever APPENDS
     * candidates, so the iteration is monotone and terminates.
     *
     * Sets $injected true when it appended anything.
     */
    private function injectRootPinnedCandidates(array $results, ?string $songKey, ?bool &$injected = null): array
    {
        $injected = false;
        if ($this->pinnedRootIdentifier === null || $this->transitionScorer === null) return $results;
        if ($songKey === null || $songKey === '') return $results;

        $minEdge = self::FORWARD_WARRANT_MIN_EDGE;
        $n = count($results);

        for ($i = 0; $i + 2 < $n; $i++) {
            // Structural commitments are not re-openable (see pool-collapse note).
            $reason = ($results[$i]['reinterpreted'] ?? false)
                ? ($results[$i]['reinterpret_reason'] ?? '')
                : null;
            if ($reason !== null && !in_array($reason, self::SEQUENTIAL_REINTERPRET_REASONS, true)) {
                continue;
            }

            $pcs = $results[$i]['pcs'] ?? [];
            if (count($pcs) < 3) continue;   // too little evidence to re-spell

            $incumbent = $results[$i]['name'] ?? '';
            $p1 = $results[$i + 1]['name'] ?? '';
            $c  = $results[$i + 2]['name'] ?? '';
            if ($incumbent === '' || $p1 === '' || $c === '') continue;

            $pIncumbent = $this->transitionScorer
                ->functionalTrigramProbability($incumbent, $p1, $c, $songKey);
            if ($pIncumbent === null || $pIncumbent <= 0.0) continue;

            $bassName = $results[$i]['bass_note'] ?? null;
            $bassPc   = $bassName !== null ? $this->noteNameToPc($bassName) : null;

            $bestName = null;
            $bestP    = $pIncumbent * $minEdge;   // must clear the bar to win

            for ($root = 0; $root < 12; $root++) {
                $pinned = ($this->pinnedRootIdentifier)($pcs, $root, $bassPc, $songKey);
                if ($pinned === null) continue;
                if ($pinned['name'] === $incumbent) continue;

                $p = $this->transitionScorer
                    ->functionalTrigramProbability($pinned['name'], $p1, $c, $songKey);
                if ($p === null) continue;       // unplaceable ⇒ no evidence

                if ($p > $bestP) {
                    $bestP    = $p;
                    $bestName = $pinned;
                }
            }

            if ($bestName === null) continue;

            // Append as a candidate. Its score sits just under the incumbent's so
            // it cannot win on local score alone — only the path may promote it.
            $cands = $results[$i]['candidates'] ?? [];
            $incumbentScore = $this->findCandidateScore($cands, $incumbent);
            if ($incumbentScore <= 0) continue;

            foreach ($cands as $existing) {
                if (($existing['name'] ?? '') === $bestName['name']) continue 2;
            }

            $cands[] = [
                'name'       => $bestName['name'],
                'root'       => $bestName['root'],
                'quality'    => $bestName['quality'],
                'extensions' => $bestName['extensions'],
                'bass_note'  => $bestName['bass_note'],
                'score'      => $incumbentScore * 0.99,
                'injected'   => true,
            ];
            $results[$i]['candidates'] = $cands;
            $injected = true;
        }

        return $results;
    }

    private function applyViterbiRescore(array $results, ?string $songKey = null): array
    {
        if ($this->transitionScorer === null) return $results;
        $n = count($results);
        if ($n < 2) return $results;

        // Weight balancing: seed cost vs edge cost.
        // Seed cost = -log(local_score). Scores ~10²–10⁴ → costs ~5–10.
        // Edge cost = -log(P(next|prev)). Probabilities ~10⁻⁴–10⁻¹ → costs ~2–10.
        // To stop a chain of weak transitions from hijacking a slot whose local
        // reading is strong, we scale edge costs DOWN so seed costs dominate.
        $edgeWeight = 0.3;
        // Promotion threshold: don't replace upstream pick unless the Viterbi
        // winner's local score is within this fraction of the upstream winner.
        // Set high (0.85) because Viterbi's job here is to break ties between
        // ROUGHLY-EQUAL candidates using sequence evidence — NOT to override a
        // clearly-stronger local reading. Cases where a much weaker local reading
        // is "correct" require external evidence (expected-chord priors), not
        // bigram inference, which can't outweigh dominant Pass 1 scores.
        $minScoreRatio = 0.85;

        // Build per-slot candidate pools, with seed cost = -log(local_score).
        $pools = [];
        for ($i = 0; $i < $n; $i++) {
            $cands = $results[$i]['candidates'] ?? [];
            if (empty($cands)) {
                // No candidates anywhere → cannot run Viterbi for this slot;
                // fall back to whatever the upstream picked.
                $pools[$i] = [['name' => $results[$i]['name'] ?? '', '_score' => 1.0, '_fallback' => true]];
                continue;
            }
            // A slot reinterpreted by a STRUCTURAL sub-pass (2a pedal, 2c/2c′ dim)
            // is a COMMITTED decision. Its pool collapses to that single winner —
            // otherwise Viterbi can route a neighbour's transition through a
            // candidate name this slot already rejected, producing a path
            // inconsistent with the slot's own displayed chord (e.g. slot shows
            // Dm6 but the path into the next slot goes via its discarded G7/D
            // candidate, pulling the next slot to the wrong reading).
            //
            // A SEQUENTIAL commitment (2d cadence, 2e bigram) gets no such
            // veto. Those passes are greedy and first-order: 2e reweights by
            // P(curr | previous WINNER) alone, so it chains off whatever the
            // slot before it happened to pick. Viterbi answers the same question
            // over the whole path with a second-order model, so letting the
            // weaker pass lock the pool inverts the information ordering. Its
            // winner stays first (pole position for the `$pools[$i][0]`
            // fallbacks) but the alternatives remain reachable; the promotion
            // loop below re-syncs `name` to whatever the path chooses, so the
            // display/path consistency the collapse protects is preserved anyway.
            $reinterpretedBy = ($results[$i]['reinterpreted'] ?? false)
                ? ($results[$i]['reinterpret_reason'] ?? '')
                : null;
            $isSequential = $reinterpretedBy !== null
                && in_array($reinterpretedBy, self::SEQUENTIAL_REINTERPRET_REASONS, true);

            if ($reinterpretedBy !== null && !$isSequential) {
                $winnerName = $results[$i]['name'] ?? '';
                $winnerCand = null;
                foreach ($cands as $c) {
                    if (($c['name'] ?? '') === $winnerName) { $winnerCand = $c; break; }
                }
                $winnerCand ??= ['name' => $winnerName, 'score' => 1.0];
                $s = max(0.001, (float)($winnerCand['score'] ?? 1.0));
                $pools[$i] = [$winnerCand + ['_score' => $s]];
                continue;
            }

            $pool = array_map(function ($c) {
                $s = max(0.001, (float)($c['score'] ?? 1.0));
                return $c + ['_score' => $s];
            }, $cands);

            // Float a sequential winner to the head of its pool without dropping
            // the rest, so index 0 remains the upstream pick everywhere it is
            // used as a fallback.
            if ($isSequential) {
                $winnerName = $results[$i]['name'] ?? '';
                foreach ($pool as $idx => $c) {
                    if (($c['name'] ?? '') === $winnerName && $idx !== 0) {
                        array_splice($pool, $idx, 1);
                        array_unshift($pool, $c);
                        break;
                    }
                }
            }

            $pools[$i] = $pool;
        }

        $INF = 1e18;

        // ── Second-order Viterbi (Phase 3.4c) ──
        // The DP state is a PAIR (prev-slot candidate, curr-slot candidate) so
        // each transition sees TWO predecessors and can score the trigram
        // P(curr | prev2, prev1). This is what lets II7→V7→I win as a unit; a
        // first-order chain only ever sees one predecessor per edge.
        //
        // State encoding: "j,k" where j = candidate index at slot i-1 and
        // k = candidate index at slot i. Pools are ~5 candidates, so ~25
        // states/slot — negligible cost.
        $seed = fn($i, $k) => -log((float)$pools[$i][$k]['_score']);
        $edge = function ($p) use ($edgeWeight) {
            return -log(max($p, 1e-6)) * $edgeWeight;
        };
        $key  = fn($j, $k) => $j . ',' . $k;

        // Slot 1: initialize pair-states (j@0, k@1) with a BIGRAM edge — there is
        // no third predecessor yet.
        $costs = []; // costs[i]["j,k"] = best cost of a path ending at that pair
        $back  = []; // back[i]["j,k"]  = predecessor j' (candidate index at slot i-2)
        $costs[1] = [];
        $back[1]  = [];
        foreach ($pools[1] as $k => $cCurr) {
            foreach ($pools[0] as $j => $cPrev) {
                $pn = $cPrev['name'] ?? ''; $cn = $cCurr['name'] ?? '';
                if ($pn === '' || $cn === '') continue;
                $p = $this->transitionScorer->probability($pn, $cn);
                $c = $seed(0, $j) + $edge($p) + $seed(1, $k);
                $costs[1][$key($j, $k)] = $c;
                $back[1][$key($j, $k)]  = null;
            }
        }

        // Slots ≥2: extend each pair (j,k) from a predecessor pair (m,j), scoring
        // the trigram over pool tokens at (i-2, i-1, i).
        for ($i = 2; $i < $n; $i++) {
            $costs[$i] = [];
            $back[$i]  = [];
            foreach ($pools[$i] as $k => $cCurr) {
                $cn = $cCurr['name'] ?? '';
                $seedK = $seed($i, $k);
                foreach ($pools[$i - 1] as $j => $cMid) {
                    $mn = $cMid['name'] ?? '';
                    $best = $INF; $bestM = null;
                    foreach ($pools[$i - 2] as $m => $cPrev2) {
                        $pk = $key($m, $j);
                        if (!isset($costs[$i - 1][$pk])) continue;
                        $p2n = $cPrev2['name'] ?? '';
                        if ($p2n === '' || $mn === '' || $cn === '') continue;
                        $p = $this->transitionScorer->trigramProbability($p2n, $mn, $cn, $songKey);
                        $total = $costs[$i - 1][$pk] + $edge($p) + $seedK;
                        if ($total < $best) { $best = $total; $bestM = $m; }
                    }
                    if ($bestM === null) continue; // no admissible predecessor pair
                    $costs[$i][$key($j, $k)] = $best;
                    $back[$i][$key($j, $k)]  = $bestM;
                }
            }
            // Disconnected slot (no pair survived): bail to upstream picks. Rare;
            // happens only if a slot's whole pool has empty names.
            if (empty($costs[$i])) return $results;
        }

        // Reconstruct: find the best terminal pair at slot n-1, then walk back.
        $lastPair = null; $bestFinal = $INF;
        foreach ($costs[$n - 1] as $pk => $c) {
            if ($c < $bestFinal) { $bestFinal = $c; $lastPair = $pk; }
        }
        if ($lastPair === null) return $results;

        $path = [];
        // Decode the terminal pair "j,k": k is slot n-1's pick, j is slot n-2's.
        [$j, $k] = array_map('intval', explode(',', $lastPair));
        $path[$n - 1] = $pools[$n - 1][$k];
        for ($i = $n - 1; $i >= 2; $i--) {
            // At slot i the pair is (j,k); its predecessor candidate at i-2 is back.
            $pk = $key($j, $k);
            $m  = $back[$i][$pk] ?? null;
            $path[$i - 1] = $pools[$i - 1][$j];
            if ($m === null) {
                // Disconnected before slot i-2: preserve upstream for the rest.
                for ($t = $i - 2; $t >= 0; $t--) $path[$t] = $pools[$t][0];
                $j = null;
                break;
            }
            $k = $j; $j = $m; // shift the window down one slot
        }
        if ($j !== null) {
            // Reached slot 1's pair (j@0, k@1); j is slot 0's pick.
            $path[0] = $pools[0][$j];
        }
        ksort($path);

        // ── Forward re-pick for the head of the sequence ──
        // The seed cost is -log(local_score), which treats a Pass-1 score as
        // evidence about the notes. Between rival readings of the SAME pitch
        // classes it is not: Bbm6 outscores Eb7(9)/Bb 7200:1062 purely because its
        // root sits in the bass (bassBoost ×4 · exactBonus ×3). That 1.91 of seed
        // advantage swamps the 0.79 the trigram can return through $edgeWeight, so
        // the search never puts the II7 reading on the path — even though the
        // functional trigram prefers it seven-fold (0.754 vs 0.054).
        //
        // Slots 0 and 1 cannot be rescued afterwards either, because the
        // strong-trigram override needs two predecessors. They are also precisely
        // where a bass-alternation voicing hides. So for those slots, consult the
        // pool directly against FORWARD context and let a decisive challenger take
        // the path slot; the promotion rails below then apply as usual.
        //
        // Deliberately narrow: only the head, only the strict functional tier, only
        // on a wide relative margin over the path's own pick.
        if ($songKey !== null) {
            for ($i = 0; $i < min(2, $n); $i++) {
                if ($i + 2 >= $n) break;
                if (!isset($path[$i]) || !empty($path[$i]['_fallback'])) continue;
                if (count($pools[$i]) < 2) continue;

                $s1 = $path[$i + 1]['name'] ?? '';
                $s2 = $path[$i + 2]['name'] ?? '';
                if ($s1 === '' || $s2 === '') continue;

                $incumbentName = $path[$i]['name'] ?? '';
                $pIncumbent = $this->transitionScorer
                    ->functionalTrigramProbability($incumbentName, $s1, $s2, $songKey);
                if ($pIncumbent === null || $pIncumbent <= 0.0) continue;

                $bestCand = null;
                $bestP    = $pIncumbent * self::FORWARD_WARRANT_MIN_EDGE;
                foreach ($pools[$i] as $cand) {
                    $cn = $cand['name'] ?? '';
                    if ($cn === '' || $cn === $incumbentName) continue;
                    $p = $this->transitionScorer
                        ->functionalTrigramProbability($cn, $s1, $s2, $songKey);
                    if ($p === null) continue;
                    if ($p > $bestP) { $bestP = $p; $bestCand = $cand; }
                }
                if ($bestCand !== null) $path[$i] = $bestCand;
            }
        }

        // Promote winners that differ from upstream, with safety rails.
        foreach ($path as $i => $pick) {
            if (!empty($pick['_fallback'])) continue;
            $currentName = $results[$i]['name'] ?? '';
            if ($pick['name'] === $currentName) continue;

            $currentScore = $this->findCandidateScore($results[$i]['candidates'] ?? [], $currentName);
            $newScore = (float)$pick['_score'];

            // ── Strong-trigram override (Phase 3.4c) ──
            // The local-score guards below exist to stop a genuinely weaker
            // reading from hijacking a strong one via a chain of weak bigrams.
            // But every candidate for a slot describes the SAME pitch classes —
            // so a large Pass-1 gap between two candidates is a voicing-position
            // artifact (root-in-bass bonus), NOT evidence about the notes. When
            // the promoted reading is backed by a DECISIVE trigram in its chosen
            // context, that sequence evidence should win over the local artifact.
            // This is the Ipanema case: Bbm6 (root in bass, score 7200) vs
            // Eb7(9)/Bb (rootless slash, 1062) — identical PCs; II7→V7→I resolves it.
            $strongTrigram = false;
            if ($i >= 2 && $songKey !== null) {
                $p2 = $path[$i - 2]['name'] ?? '';
                $p1 = $path[$i - 1]['name'] ?? '';
                if ($p2 !== '' && $p1 !== '') {
                    $tp = $this->transitionScorer->trigramProbability($p2, $p1, $pick['name'], $songKey);
                    $uniform = 1.0 / max($this->transitionScorer->vocabSize(), 1);
                    // Decisive = well above chance. 5×uniform mirrors the
                    // scoreMultiplier calibration's "strongly promoting" band.
                    $strongTrigram = ($tp >= 5.0 * $uniform);
                }
            }

            // ── Forward-looking warrant, for slots with no two predecessors ──
            // The rule above needs (i-2, i-1), so slots 0 and 1 can never earn it —
            // yet slot 0 is exactly where a bass-alternation voicing hides. Ipanema's
            // 6x566x is Bbm6 (root in bass, 7200) and Eb7(9)/Bb (1062) over identical
            // pitch classes; the II7 reading is legible only from what FOLLOWS.
            //
            // Score the pick as prev2 of its own two successors. The bar is RELATIVE
            // (challenger must beat the incumbent on the SAME context by $minEdge),
            // never absolute: the functional vocabulary is ~115 tokens, so a k×uniform
            // bar is cleared by plainly wrong readings too. Functional tier only —
            // trigramProbability() silently backs off to a plain bigram, which would
            // make this vacuous; null means "no evidence", never "weak evidence".
            if (!$strongTrigram && $i + 2 < $n && $songKey !== null) {
                $s1 = $path[$i + 1]['name'] ?? '';
                $s2 = $path[$i + 2]['name'] ?? '';
                if ($s1 !== '' && $s2 !== '' && $currentName !== '') {
                    $pNew = $this->transitionScorer
                        ->functionalTrigramProbability($pick['name'], $s1, $s2, $songKey);
                    $pOld = $this->transitionScorer
                        ->functionalTrigramProbability($currentName, $s1, $s2, $songKey);
                    if ($pNew !== null && $pOld !== null && $pOld > 0.0) {
                        $strongTrigram = ($pNew >= self::FORWARD_WARRANT_MIN_EDGE * $pOld);
                    }
                }
            }

            // Safety: only promote when Viterbi winner is within reasonable distance
            // of the current local winner's score — UNLESS a decisive trigram
            // vouches for it (equal-PC alternative, artifactual local gap).
            if (!$strongTrigram
                && $currentScore > 0 && $newScore / $currentScore < $minScoreRatio) continue;

            // Slash-chord guards (same as 2d/2e).
            $newName = $pick['name'];
            if (str_contains($newName, '/')) {
                $parts = explode('/', $newName, 2);
                if (count($parts) === 2 && strcasecmp($parts[0], $parts[1]) === 0) continue;
            }
            $currentHasSlash = str_contains($currentName, '/');
            $newHasSlash = str_contains($newName, '/');
            if (!$strongTrigram
                && $newHasSlash && !$currentHasSlash && ($newScore / max($currentScore, 0.001)) < 0.50) continue;

            $results[$i] = array_merge($results[$i], [
                'name'               => $pick['name'],
                'root'               => $pick['root'] ?? $results[$i]['root'] ?? null,
                'quality'            => $pick['quality'] ?? $results[$i]['quality'] ?? null,
                'extensions'         => $pick['extensions'] ?? $results[$i]['extensions'] ?? '',
                'bass_note'          => $pick['bass_note'] ?? $results[$i]['bass_note'] ?? null,
                'confidence'         => $pick['_score'] ?? null,
                'reinterpreted'      => true,
                'reinterpret_reason' => 'viterbi',
            ]);
        }
        return $results;
    }

    /**
     * Find the score of a candidate by name. Returns 0 if not found.
     */
    private function findCandidateScore(array $candidates, string $name): float
    {
        foreach ($candidates as $c) {
            if (($c['name'] ?? '') === $name) {
                return (float)($c['score'] ?? 0);
            }
        }
        return 0.0;
    }

    /**
     * Re-rank candidates by diatonicity score.
     */
    private function rerankByDiatonicity(array $candidates, int $keyPc, bool $isMinor): array
    {
        foreach ($candidates as &$candidate) {
            $rootPc = $this->noteNameToPc($candidate['root']);
            $rootInterval = ($rootPc - $keyPc + 12) % 12;

            // Check if root is diatonic to the key
            $isDiatonic = in_array($rootInterval, self::CTX_MAJOR_SCALE);

            $diatonicityBonus = $isDiatonic ? self::DIATONICITY_BONUS : 0;

            $candidate['rerank_score'] = $candidate['score'] + $diatonicityBonus;
        }

        // Sort by rerank_score descending
        usort($candidates, fn($a, $b) => $b['rerank_score'] <=> $a['rerank_score']);

        return $candidates;
    }

    /**
     * Find a candidate by name in the candidates list.
     */
    private function findCandidateIndex(array $candidates, string $name): ?int
    {
        foreach ($candidates as $i => $candidate) {
            if ($candidate['name'] === $name) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Convert note name to pitch class.
     */
    private function noteNameToPc(string $name): int
    {
        $map = [
            'C' => 0, 'C#' => 1, 'Db' => 1,
            'D' => 2, 'D#' => 3, 'Eb' => 3,
            'E' => 4, 'F' => 5, 'F#' => 6, 'Gb' => 6,
            'G' => 7, 'G#' => 8, 'Ab' => 8,
            'A' => 9, 'A#' => 10, 'Bb' => 10, 'B' => 11,
        ];

        // Handle multi-character roots (C#, F#, Bb, etc.)
        if (strlen($name) >= 2 && isset($map[$name])) {
            return $map[$name];
        }

        // Single character roots
        $char = $name[0];
        return $map[$char] ?? 0;
    }
}

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
 *
 * Note: HarmonicPatternMatcher (2b) works with Roman numerals and is
 * integrated at a higher level via HarmonicContext/ProgressionDetector.
 *
 * Pure function: no DB access at runtime, no I/O, no state.
 */
class ContextualReranker implements IContextualReranker
{
    // Diatonicity bonus for chords in the song key
    private const DIATONICITY_BONUS = 2.0;

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
     * Spec: docs/SBN-Identifier-Phase3-Plan.md §5.3.
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

<?php

namespace App\Services\Identifier;

use App\Helpers\ChordName;

/**
 * Phase 3.3a: Surface bigram transition scorer.
 *
 * Loads the seeded bigram table from storage/app/harmonic-transitions.generated.php
 * and answers: "given chord X, how likely is chord Y to follow?"
 *
 * Returns smoothed probabilities (Laplace add-K, K configurable) so a never-seen
 * transition gets nonzero probability — rare ≠ impossible.
 *
 * Consumers convert probability to a score multiplier (e.g. clamped to [1.00, 1.50])
 * when combining with the other identifier scoring signals.
 *
 * Spec: docs/SBN-Identifier-Reference.md §5.3.
 */
class TransitionScorer
{
    /** Default Laplace smoothing additive K. */
    private const DEFAULT_SMOOTHING_K = 0.5;

    /** Composite-context separator; mirrors ReseedTransitions ($SEP). */
    private const SEP = "\x1f";

    private ?array $data = null;
    private string $dataPath;
    private float $smoothingK;
    private int $vocabSize = 0;
    private int $surfaceTriVocab = 0;
    private int $functionalTriVocab = 0;

    /** Lazily-resolved numeral tokenizer (functional tier). Null until first use. */
    private ?\App\Services\ProgressionDetector $detector = null;

    public function __construct(?string $dataPath = null, float $smoothingK = self::DEFAULT_SMOOTHING_K)
    {
        $this->dataPath = $dataPath ?? base_path('storage/app/harmonic-transitions.generated.php');
        $this->smoothingK = $smoothingK;
    }

    /**
     * P(next | prev) with Laplace add-K smoothing.
     *
     * Returns 0.0 if the bigram table failed to load. Otherwise:
     *   - If prev seen in corpus: smoothed conditional probability.
     *   - If prev never seen: uniform 1/V (V = vocab size of next chords).
     *
     * Chord names are normalized via ChordName::normalize before lookup
     * so equivalent spellings (Gmaj vs G, Gmaj7 vs G△7) collapse.
     */
    public function probability(string $prevChord, string $nextChord): float
    {
        $data = $this->loadData();
        if ($data === null) return 0.0;

        $V = $this->vocabSize;
        if ($V === 0) return 0.0;

        // Backoff: try exact, then progressively-simpler forms of BOTH chords.
        // Lets richly-specified chord names like "Db6(9)/Ab" hit the corpus's
        // simpler "Db/Ab" or "Db" entries when the exact form doesn't appear.
        $prevForms = $this->backoffForms($prevChord);
        $nextForms = $this->backoffForms($nextChord);

        // Find best (highest-prob) (prev, next) match across all backoff combinations.
        // Continue searching even if early prev forms are seen — a less-specific prev
        // form may yield a much higher conditional probability when paired with a
        // less-specific next form that the corpus actually contains.
        $bestProb = null;
        foreach ($prevForms as $prev) {
            $prevTotal = $data['totals'][$prev] ?? 0;
            if ($prevTotal === 0) continue;
            foreach ($nextForms as $next) {
                $bigramCount = $data['bigrams'][$prev][$next] ?? 0;
                if ($bigramCount > 0) {
                    $p = ($bigramCount + $this->smoothingK) / ($prevTotal + $this->smoothingK * $V);
                    if ($bestProb === null || $p > $bestProb) $bestProb = $p;
                }
            }
        }
        if ($bestProb !== null) return $bestProb;

        // No co-occurrence found anywhere in the backoff lattice. Use the smoothed
        // probability from the most-specific prev that was seen at all.
        foreach ($prevForms as $prev) {
            $prevTotal = $data['totals'][$prev] ?? 0;
            if ($prevTotal > 0) {
                return $this->smoothingK / ($prevTotal + $this->smoothingK * $V);
            }
        }

        // Neither prev nor any of its backoff forms exists → uniform fallback
        return 1.0 / $V;
    }

    /**
     * P(curr | prev2, prev1) — the trigram probability, widening the viewport so
     * functional patterns like II7→V7→I are visible as a unit (a bigram can't see
     * them). Backoff lattice, most-specific first:
     *
     *   1. functional trigram  — key-relative numeral triple (needs $songKey).
     *      Generalizes across all 12 keys, so every II-V-I in the corpus
     *      reinforces the same entry. This is where the Ipanema II7 disambiguation
     *      lands.
     *   2. surface trigram     — exact chord-name triple.
     *   3. surface bigram      — falls through to probability($prev1, $curr).
     *
     * Each tier uses Laplace add-K against its own vocab size, so a tier that
     * saw the context returns a smoothed conditional; an unseen context defers to
     * the next tier rather than collapsing to uniform prematurely.
     *
     * Returns a probability in (0, 1]; never 0 (the bigram floor guarantees it).
     */
    public function trigramProbability(string $prev2, string $prev1, string $curr, ?string $songKey = null): float
    {
        $data = $this->loadData();
        if ($data === null) return 0.0;

        // ── Tier 1: functional trigram ──
        if ($songKey !== null && $songKey !== ''
            && !empty($data['functional_trigrams'])) {
            $d2 = $this->numeralToken($prev2, $songKey);
            $d1 = $this->numeralToken($prev1, $songKey);
            $dc = $this->numeralToken($curr,  $songKey);
            if ($d2 !== null && $d1 !== null && $dc !== null) {
                $p = $this->triLookup(
                    $data['functional_trigrams'],
                    $data['functional_tri_totals'] ?? [],
                    $d2 . self::SEP . $d1, $dc, $this->functionalTriVocab
                );
                if ($p !== null) return $p;
            }
        }

        // ── Tier 2: surface trigram ──
        if (!empty($data['surface_trigrams'])) {
            $p = $this->triLookup(
                $data['surface_trigrams'],
                $data['surface_tri_totals'] ?? [],
                $prev2 . self::SEP . $prev1, $curr, $this->surfaceTriVocab
            );
            if ($p !== null) return $p;
        }

        // ── Tier 3: surface bigram (existing) ──
        return $this->probability($prev1, $curr);
    }

    /**
     * Bounded score multiplier for the trigram, analogous to scoreMultiplier but
     * consuming two predecessors. Same log10 calibration so trigram and bigram
     * multipliers are comparable when the Viterbi combines them.
     */
    public function trigramMultiplier(string $prev2, string $prev1, string $curr, ?string $songKey = null): float
    {
        $data = $this->loadData();
        if ($data === null || $this->vocabSize === 0) return 1.0;

        $p = $this->trigramProbability($prev2, $prev1, $curr, $songKey);
        $uniform = 1.0 / $this->vocabSize;
        if ($p <= 0) return 0.7;
        $ratio = $p / $uniform;
        $mult = 1.0 + 0.7 * log10(max($ratio, 0.01));
        return max(0.4, min(3.0, $mult));
    }

    /**
     * Smoothed conditional lookup for one trigram tier. Returns null when the
     * two-chord context was never seen (caller backs off), otherwise a Laplace
     * add-K probability. $vocab is the tier's "next-token" vocabulary size.
     */
    /**
     * P(curr | prev2, prev1) from the FUNCTIONAL trigram tier ONLY — no backoff.
     *
     * trigramProbability() returns a bare float and hides which tier answered; its
     * tier-3 fallback is a plain bigram. A caller that wants to overrule a strong,
     * complete Pass-1 reading on sequence evidence alone cannot accept that: a
     * high bigram probability would be indistinguishable from decisive functional
     * evidence, and the guard would be vacuous.
     *
     * Returns null when the key is unknown, any chord fails to place as a numeral,
     * or the 2-numeral context was never observed. Callers must treat null as
     * "no evidence", never as "weak evidence".
     */
    public function functionalTrigramProbability(
        string $prev2,
        string $prev1,
        string $curr,
        ?string $songKey,
    ): ?float {
        if ($songKey === null || $songKey === '') return null;
        $data = $this->loadData();
        if ($data === null || empty($data['functional_trigrams'])) return null;

        $d2 = $this->numeralToken($prev2, $songKey);
        $d1 = $this->numeralToken($prev1, $songKey);
        $dc = $this->numeralToken($curr,  $songKey);
        if ($d2 === null || $d1 === null || $dc === null) return null;

        return $this->triLookup(
            $data['functional_trigrams'],
            $data['functional_tri_totals'] ?? [],
            $d2 . self::SEP . $d1, $dc, $this->functionalTriVocab
        );
    }

    /** Vocab size of the functional trigram tier (for uniform-probability bars). */
    public function functionalTriVocabSize(): int
    {
        $this->loadData();
        return max($this->functionalTriVocab, 1);
    }

    private function triLookup(array $table, array $totals, string $ctx, string $next, int $vocab): ?float
    {
        $ctxTotal = $totals[$ctx] ?? 0;
        if ($ctxTotal === 0) return null; // unseen context → back off
        $V = max($vocab, 1);
        $count = $table[$ctx][$next] ?? 0;
        return ($count + $this->smoothingK) / ($ctxTotal + $this->smoothingK * $V);
    }

    /**
     * Key-relative numeral token for a chord (functional tier). Mirrors the
     * seeder's tokenization by delegating to ProgressionDetector::chordToNumeral,
     * so scorer and corpus agree. Returns null when the chord can't be placed.
     */
    private function numeralToken(string $chord, string $songKey): ?string
    {
        $this->detector ??= app(\App\Services\ProgressionDetector::class);
        $n = $this->detector->chordToNumeral($chord, $songKey);
        if ($n === null || $n === '') return null;
        // Strip the slash-bass degree so a pedal/inversion voicing matches the
        // slash-less corpus token (must mirror ReseedTransitions::functionalToken).
        if (str_contains($n, '/')) {
            $n = explode('/', $n, 2)[0];
        }
        if ($n === '' || str_starts_with($n, 'chr')) return null;
        return $n;
    }

    /**
     * Generate progressively-simpler forms of a chord name for backoff lookup.
     * Ordered most-specific first. Each form is normalized via ChordName::normalize.
     *
     * Example: "Db6(9)/Ab" → ["Db6(9)/Ab", "Db6/Ab", "Db/Ab", "Db6(9)", "Db6", "Db"]
     */
    private function backoffForms(string $chord): array
    {
        $forms = [];
        $base = ChordName::normalize($chord);
        if ($base === '') return [];

        // Separate slash bass
        $slashBass = null;
        if (str_contains($base, '/')) {
            [$base, $slashBass] = explode('/', $base, 2);
        }

        // Form variants of the base: full, then strip parentheses, then strip everything after the root
        $variants = [$base];
        $noParens = preg_replace('/\(.+?\)/', '', $base);
        if ($noParens !== $base) $variants[] = $noParens;
        // Strip to root + accidental only (single quality-less form)
        if (preg_match('/^([A-G][#b♯♭]?)/u', $base, $m)) {
            $rootOnly = $m[1];
            if (!in_array($rootOnly, $variants, true)) $variants[] = $rootOnly;
        }

        // Combine variants with / without slash
        foreach ($variants as $v) {
            $v = ChordName::normalize($v);
            if ($v === '') continue;
            if ($slashBass !== null) {
                $forms[] = $v . '/' . $slashBass;
            }
            $forms[] = $v;
        }
        return array_values(array_unique($forms));
    }

    /**
     * Score multiplier for sequence ranking. Maps a transition probability to a
     * bounded multiplier so very strong transitions promote candidates and very
     * weak ones lightly demote them.
     *
     * Calibration approach: scale relative to the baseline uniform probability 1/V.
     *   - Transitions much more likely than uniform → multiplier > 1
     *   - Transitions much less likely than uniform → multiplier < 1
     * Bounded to [0.7, 1.5] so a single bad transition can't catastrophically
     * downrank a chord; the signal compounds over the sequence.
     */
    public function scoreMultiplier(string $prevChord, string $nextChord): float
    {
        $data = $this->loadData();
        if ($data === null || $this->vocabSize === 0) return 1.0;

        $p = $this->probability($prevChord, $nextChord);
        $uniform = 1.0 / $this->vocabSize;
        if ($p <= 0) return 0.7;

        // Map ratio (p/uniform) to multiplier via log-scaled clamp.
        // Calibration prioritizes flipping plausible Pass 1 winners when the
        // bigram strongly disagrees. A 5× ratio (e.g. Db→Eb7 vs Db→Bbm6 in
        // jazz/bossa) yields ~2.0× multiplier, which is enough to overcome
        // ~5× Pass 1 score gaps that are common when both candidates pass
        // the local PC-set test.
        //
        //   p == uniform        → 1.0 (neutral)
        //   p == 5×uniform      → ~2.0
        //   p == 10×uniform     → ~2.5
        //   p == 100×uniform    → ~3.0 (cap)
        //   p == uniform/10     → ~0.5
        $ratio = $p / $uniform;
        $mult = 1.0 + 0.7 * log10(max($ratio, 0.01));
        return max(0.4, min(3.0, $mult));
    }

    /**
     * Get the top-N most likely next chords after `prev`. Useful for debugging
     * and for surfacing suggestions in the editor.
     */
    public function topNext(string $prevChord, int $n = 10): array
    {
        $data = $this->loadData();
        if ($data === null) return [];
        $prev = ChordName::normalize($prevChord);
        $row = $data['bigrams'][$prev] ?? [];
        $total = $data['totals'][$prev] ?? 0;
        if ($total === 0) return [];

        arsort($row);
        $out = [];
        foreach (array_slice($row, 0, $n, true) as $next => $count) {
            $out[] = [
                'chord' => $next,
                'count' => $count,
                'probability' => $count / $total,
            ];
        }
        return $out;
    }

    /** Surface-bigram vocabulary size V (for callers computing 1/V uniform baselines). */
    public function vocabSize(): int
    {
        $this->loadData();
        return $this->vocabSize;
    }

    /** Meta info from the generator. */
    public function meta(): array
    {
        $data = $this->loadData();
        return $data['meta'] ?? [];
    }

    private function loadData(): ?array
    {
        if ($this->data !== null) return $this->data;
        if (!is_file($this->dataPath)) return null;
        $payload = require $this->dataPath;
        if (!is_array($payload) || !isset($payload['bigrams'], $payload['totals'])) return null;
        $this->data = $payload;
        // Vocab size = union of unique chord names appearing as prev OR next.
        $vocab = $payload['totals']; // prev side
        foreach ($payload['bigrams'] as $nexts) {
            foreach ($nexts as $next => $_) $vocab[$next] = true;
        }
        $this->vocabSize = count($vocab);

        // Trigram "next-token" vocab sizes for per-tier Laplace smoothing. Union
        // of all distinct curr tokens appearing in each trigram table.
        $this->surfaceTriVocab    = $this->countNextVocab($payload['surface_trigrams'] ?? []);
        $this->functionalTriVocab = $this->countNextVocab($payload['functional_trigrams'] ?? []);

        return $this->data;
    }

    /** Distinct "next" tokens across a trigram table (for Laplace V). */
    private function countNextVocab(array $table): int
    {
        $seen = [];
        foreach ($table as $nexts) {
            foreach ($nexts as $next => $_) $seen[$next] = true;
        }
        return count($seen);
    }
}

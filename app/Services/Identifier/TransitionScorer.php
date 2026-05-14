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
 * Spec: docs/SBN-Identifier-Phase3-Plan.md §5.3.
 */
class TransitionScorer
{
    /** Default Laplace smoothing additive K. */
    private const DEFAULT_SMOOTHING_K = 0.5;

    private ?array $data = null;
    private string $dataPath;
    private float $smoothingK;
    private int $vocabSize = 0;

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
        return $this->data;
    }
}

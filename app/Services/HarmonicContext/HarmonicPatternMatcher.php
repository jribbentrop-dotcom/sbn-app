<?php

namespace App\Services\HarmonicContext;

use App\Services\ProgressionDetector;

/**
 * HarmonicPatternMatcher — Phase 2 sub-pass 2b.
 *
 * Loads the pre-generated fragment table and provides sliding-window
 * matching of Roman-numeral sequences against known progression fragments.
 *
 * Enhanced to support:
 * - Translation of Phase 1 labels to Roman numerals against song key
 * - Soft match with top-K candidates and Jaccard overlap (τ = 0.75)
 * - Setting expectedChord for matched fragments
 * - Recording matched_progression_id and matched_variant_index
 *
 * Pure function at runtime: no DB access, no I/O, no state beyond the
 * fragment table loaded at construction time.
 */
class HarmonicPatternMatcher
{
    /** @var array<int, array{id:int, numerals:array, variant_index:int|null, variant_label:string|null}> */
    private array $fragments;

    private ProgressionDetector $detector;

    // Jaccard threshold for soft match (empirically tuned per spec §1.6.5)
    private const JACCARD_THRESHOLD = 0.75;

    public function __construct(ProgressionDetector $detector)
    {
        $this->detector = $detector;
        $path = storage_path('app/harmonic-fragments.generated.php');
        $this->fragments = file_exists($path)
            ? require $path
            : [];
    }

    /**
     * Match Phase 1 results against known progression fragments.
     *
     * @param  array<int, array> $phase1Results  Phase 1 results with {name, candidates, pcs}
     * @param  string|null       $songKey       e.g. 'F', 'Am', null
     * @return array{expected_chords: array<int, string|null>, matches: array<int, array>}
     */
    public function match(array $phase1Results, ?string $songKey = null): array
    {
        if (count($phase1Results) < 2 || empty($this->fragments)) {
            return [
                'expected_chords' => [],
                'matches' => [],
            ];
        }

        // Infer song key if not provided (use first chord's root)
        if ($songKey === null && !empty($phase1Results)) {
            $firstRoot = $phase1Results[0]['root'] ?? null;
            if ($firstRoot) {
                $songKey = $firstRoot;
            }
        }

        // Step 1: Translate Phase 1 labels to Roman numerals
        $numerals = [];
        foreach ($phase1Results as $i => $result) {
            $chordName = $result['name'] ?? '';
            $numerals[$i] = $this->chordToNumeral($chordName, $songKey);
        }

        // Step 2-4: Slide fragments and soft-match
        $matches = $this->matchPatternWithSoftMatch($phase1Results, $numerals, $songKey);

        // Step 5: Build expected_chords array from matches
        $expectedChords = array_fill(0, count($phase1Results), null);
        foreach ($matches as $match) {
            $start = $match['start_idx'];
            $length = $match['length'];
            $pattern = $this->fragments[$match['fragment_index']]['numerals'];
            $progressionId = $this->fragments[$match['fragment_index']]['id'];
            $variantIndex = $match['variant_index'];
            $variantLabel = $match['variant_label'];

            for ($j = 0; $j < $length; $j++) {
                $slotIdx = $start + $j;

                // Skip anchors (first and last slots)
                if ($j === 0 || $j === $length - 1) {
                    // Record match metadata but don't set expected chord
                    if (!isset($expectedChords[$slotIdx])) {
                        $expectedChords[$slotIdx] = null;
                    }
                    continue;
                }

                // Find the candidate that matches the expected numeral
                $patternNumeral = $pattern[$j];
                $result = $phase1Results[$slotIdx];
                $candidates = $result['candidates'] ?? [];
                $expectedChordName = null;

                foreach ($candidates as $candidate) {
                    $candidateName = $candidate['name'] ?? '';
                    $candidateNumeral = $this->chordToNumeral($candidateName, $songKey);
                    if ($this->numeralMatch($candidateNumeral, $patternNumeral)) {
                        $expectedChordName = $candidateName;
                        break;
                    }
                }

                // If no exact numeral match, use Jaccard to find best candidate
                if ($expectedChordName === null) {
                    $expectedPcs = $this->numeralToPcs($patternNumeral);
                    if ($expectedPcs !== null) {
                        $bestJaccard = 0;
                        foreach ($candidates as $candidate) {
                            // Use the actual PC set from the result for Jaccard comparison
                            $candidatePcs = $result['pcs'] ?? [];
                            if (!empty($candidatePcs)) {
                                $jaccard = $this->jaccardSimilarity($candidatePcs, $expectedPcs);
                                if ($jaccard >= self::JACCARD_THRESHOLD && $jaccard > $bestJaccard) {
                                    $bestJaccard = $jaccard;
                                    $expectedChordName = $candidate['name'];
                                }
                            }
                        }
                    }
                }

                // Set expected chord for non-anchor slots
                if ($expectedChordName !== null) {
                    $expectedChords[$slotIdx] = $expectedChordName;
                }

                // Add match metadata to result
                $phase1Results[$slotIdx]['matched_progression_id'] = $progressionId;
                $phase1Results[$slotIdx]['matched_variant_index'] = $variantIndex;
                $phase1Results[$slotIdx]['matched_variant_label'] = $variantLabel;
            }
        }

        // Step 5b: Global Jaccard fallback for slots not in any matched fragment
        // This catches cases like Wine slots 6-8 where the fragment doesn't match but
        // the PC set Jaccard-overlaps a fragment numeral's PC set by ≥ 0.75
        for ($i = 0; $i < count($phase1Results); $i++) {
            if ($expectedChords[$i] !== null) {
                continue; // Already set by fragment matching
            }

            $result = $phase1Results[$i];
            $candidates = $result['candidates'] ?? [];
            $resultPcs = $result['pcs'] ?? [];

            if (empty($candidates) || empty($resultPcs)) {
                continue;
            }

            // Check against all fragment numerals to find best Jaccard match
            $bestJaccard = 0;
            $bestChordName = null;
            $bestFragmentId = null;

            foreach ($this->fragments as $fragment) {
                foreach ($fragment['numerals'] as $patternNumeral) {
                    $expectedPcs = $this->numeralToPcs($patternNumeral);
                    if ($expectedPcs === null) continue;

                    $jaccard = $this->jaccardSimilarity($resultPcs, $expectedPcs);
                    if ($jaccard >= self::JACCARD_THRESHOLD && $jaccard > $bestJaccard) {
                        $bestJaccard = $jaccard;
                        // Use the highest-scoring candidate
                        $bestChordName = $this->findBestCandidateByScore($candidates);
                        $bestFragmentId = $fragment['id'];
                    }
                }
            }

            if ($bestChordName !== null) {
                $expectedChords[$i] = $bestChordName;
                $phase1Results[$i]['matched_progression_id'] = $bestFragmentId;
                $phase1Results[$i]['matched_variant_index'] = null;
                $phase1Results[$i]['matched_variant_label'] = null;
            }
        }

        return [
            'expected_chords' => $expectedChords,
            'matches' => $matches,
            'results' => $phase1Results,
        ];
    }

    /**
     * Match pattern with soft match logic (top-K candidates + Jaccard overlap).
     */
    private function matchPatternWithSoftMatch(array $results, array $numerals, ?string $songKey): array
    {
        $matches = [];

        foreach ($this->fragments as $fragIndex => $fragment) {
            $pattern = $fragment['numerals'];
            $plen = count($pattern);

            if ($plen < 2 || $plen > count($numerals)) {
                continue;
            }

            $minHits = (int) ceil($plen * 0.66);

            for ($start = 0; $start <= count($numerals) - $plen; $start++) {
                $hits = 0;
                $firstExact = false;
                $lastExact = false;
                $softHits = []; // Track which slots are soft hits

                for ($j = 0; $j < $plen; $j++) {
                    $sectionNumeral = $numerals[$start + $j];
                    $patternNumeral = $pattern[$j];
                    $result = $results[$start + $j];

                    // Check for exact numeral match
                    if ($this->numeralMatch($sectionNumeral, $patternNumeral)) {
                        $hits++;
                        $softHits[$j] = true;
                        if ($j === 0) $firstExact = true;
                        if ($j === $plen - 1) $lastExact = true;
                    } else {
                        // Soft match: check top-K candidates or Jaccard overlap
                        $softHit = $this->softMatch($result, $patternNumeral, $songKey);
                        if ($softHit) {
                            $softHits[$j] = true;
                            // Don't count soft hits for the ≥ 0.66 threshold, only exact
                            // But track them for expected chord setting
                        }
                    }
                }

                // Anchors must be exact matches
                if (!$firstExact || !$lastExact) {
                    continue;
                }

                if ($hits >= $minHits) {
                    $confidence = $hits / $plen;

                    $matches[] = [
                        'fragment_index' => $fragIndex,
                        'progression_id' => $fragment['id'],
                        'variant_index' => $fragment['variant_index'],
                        'variant_label' => $fragment['variant_label'],
                        'start_idx' => $start,
                        'length' => $plen,
                        'confidence' => round($confidence, 3),
                        'hits' => $hits,
                        'total' => $plen,
                        'soft_hits' => $softHits,
                    ];
                }
            }
        }

        // Conflict resolution: longest first, highest confidence first
        usort($matches, function ($a, $b) {
            if ($b['length'] !== $a['length']) return $b['length'] - $a['length'];
            return $b['confidence'] <=> $a['confidence'];
        });

        // Greedy: accept longest non-overlapping matches
        $accepted = [];
        $occupied = array_fill(0, count($numerals), false);

        foreach ($matches as $m) {
            $overlap = false;
            for ($i = $m['start_idx']; $i < $m['start_idx'] + $m['length']; $i++) {
                if ($occupied[$i]) {
                    $overlap = true;
                    break;
                }
            }

            if ($overlap) continue;

            // Tie-breaking: prefer canonical (null variant_index)
            foreach ($accepted as $a) {
                if ($a['start_idx'] === $m['start_idx'] && $a['length'] === $m['length']) {
                    $aVar = $a['variant_index'] ?? PHP_INT_MAX;
                    $mVar = $m['variant_index'] ?? PHP_INT_MAX;
                    if ($mVar >= $aVar) continue 2;
                    // Remove previous, accept this one
                    for ($i = $a['start_idx']; $i < $a['start_idx'] + $a['length']; $i++) {
                        $occupied[$i] = false;
                    }
                    $accepted = array_filter($accepted, fn($x) => $x !== $a);
                    break;
                }
            }

            for ($i = $m['start_idx']; $i < $m['start_idx'] + $m['length']; $i++) {
                $occupied[$i] = true;
            }
            $accepted[] = $m;
        }

        return array_values($accepted);
    }

    /**
     * Soft match: check if any top-K candidate produces expected numeral OR
     * PC set Jaccard-overlaps expected chord's PC set by ≥ τ.
     */
    private function softMatch(array $result, string $patternNumeral, ?string $songKey): bool
    {
        $candidates = $result['candidates'] ?? [];
        $resultPcs = $result['pcs'] ?? [];

        if (empty($candidates) || empty($resultPcs)) {
            return false;
        }

        // Check top-K candidates for numeral match (only if song key is available)
        if ($songKey !== null) {
            foreach ($candidates as $candidate) {
                $candidateName = $candidate['name'] ?? '';
                $candidateNumeral = $this->chordToNumeral($candidateName, $songKey);
                if ($this->numeralMatch($candidateNumeral, $patternNumeral)) {
                    return true;
                }
            }
        }

        // Jaccard overlap check
        $expectedPcs = $this->numeralToPcs($patternNumeral);
        if ($expectedPcs === null) {
            return false;
        }

        $jaccard = $this->jaccardSimilarity($resultPcs, $expectedPcs);
        return $jaccard >= self::JACCARD_THRESHOLD;
    }

    /**
     * Convert Roman numeral to pitch class set.
     * Simplified implementation - uses major scale intervals.
     */
    private function numeralToPcs(string $numeral): ?array
    {
        // Extract root degree (e.g. 'II' from 'IIm7')
        $root = $this->extractRoot($numeral);
        if ($root === null) return null;

        // Map degree to PC (relative to C)
        $degreeMap = [
            'I' => 0, 'II' => 2, 'III' => 4, 'IV' => 5, 'V' => 7, 'VI' => 9, 'VII' => 11,
            'bII' => 1, 'bIII' => 3, 'bV' => 6, 'bVI' => 8, 'bVII' => 10,
            '#I' => 1, '#II' => 3, '#IV' => 6, '#V' => 8, '#VI' => 10,
        ];

        $rootPc = $degreeMap[$root] ?? null;
        if ($rootPc === null) return null;

        // Major scale intervals for triad (0, 4, 7) or 7th (0, 4, 7, 10)
        $intervals = [0, 4, 7]; // Triad
        if (str_contains($numeral, '7') || str_contains($numeral, 'maj7') || str_contains($numeral, 'm7')) {
            $intervals = [0, 4, 7, 10]; // 7th chord
        }

        return array_map(fn($iv) => ($rootPc + $iv) % 12, $intervals);
    }

    /**
     * Find the best candidate by score.
     */
    private function findBestCandidateByScore(array $candidates): ?string
    {
        $bestScore = -1;
        $bestChordName = null;

        foreach ($candidates as $candidate) {
            if (($candidate['score'] ?? 0) > $bestScore) {
                $bestScore = $candidate['score'];
                $bestChordName = $candidate['name'];
            }
        }

        return $bestChordName;
    }

    /**
     * Find the best candidate by PC set similarity.
     * Returns the candidate name with the highest score among those that match.
     */
    private function findBestCandidateByPcs(array $candidates, array $expectedPcs): ?string
    {
        // Since we don't have candidate PC sets, just return the highest-scoring candidate
        // The Jaccard check was already done on the result's PC set
        $bestScore = -1;
        $bestChordName = null;

        foreach ($candidates as $candidate) {
            if (($candidate['score'] ?? 0) > $bestScore) {
                $bestScore = $candidate['score'];
                $bestChordName = $candidate['name'];
            }
        }

        return $bestChordName;
    }

    /**
     * Find a candidate by PC set similarity.
     */
    private function findCandidateByPcs(array $candidates, array $expectedPcs): ?string
    {
        $bestJaccard = 0;
        $bestChordName = null;

        foreach ($candidates as $candidate) {
            $candidatePcs = $this->numeralToPcsFromName($candidate['name']);
            if ($candidatePcs !== null) {
                $jaccard = $this->jaccardSimilarity($candidatePcs, $expectedPcs);
                if ($jaccard > $bestJaccard) {
                    $bestJaccard = $jaccard;
                    $bestChordName = $candidate['name'];
                }
            }
        }

        return $bestChordName;
    }

    /**
     * Convert chord name to pitch class set.
     * Simplified implementation - uses VoicingCrossref logic.
     */
    private function numeralToPcsFromName(string $chordName): ?array
    {
        // This is a simplified implementation - in production, we'd use VoicingCrossref
        // For now, return null to fall back to exact numeral matching
        return null;
    }

    /**
     * Calculate Jaccard similarity between two PC sets.
     */
    private function jaccardSimilarity(array $pcs1, array $pcs2): float
    {
        $set1 = array_values(array_unique($pcs1));
        $set2 = array_values(array_unique($pcs2));

        if (empty($set1) || empty($set2)) {
            return 0.0;
        }

        $intersection = array_intersect($set1, $set2);
        $union = array_unique(array_merge($set1, $set2));

        return count($intersection) / count($union);
    }

    /**
     * Translate chord name to Roman numeral against song key.
     */
    private function chordToNumeral(string $chordName, ?string $songKey): string
    {
        if (empty($chordName)) return '';
        if ($songKey === null) return ''; // Can't translate without a key

        return $this->detector->chordToNumeral($chordName, $songKey);
    }

    /**
     * Match a Roman-numeral sequence against known progression fragments.
     *
     * @deprecated Use match() instead for full workflow
     */
    public function matchPattern(array $numerals): array
    {
        if (count($numerals) < 2 || empty($this->fragments)) {
            return [];
        }

        $matches = [];

        foreach ($this->fragments as $fragment) {
            $pattern = $fragment['numerals'];
            $plen = count($pattern);

            if ($plen < 2 || $plen > count($numerals)) {
                continue;
            }

            $minHits = (int) ceil($plen * 0.66);

            for ($start = 0; $start <= count($numerals) - $plen; $start++) {
                $hits = 0;
                $firstExact = false;
                $lastExact = false;

                for ($j = 0; $j < $plen; $j++) {
                    $sectionNumeral = $numerals[$start + $j];
                    $patternNumeral = $pattern[$j];

                    if ($this->numeralMatch($sectionNumeral, $patternNumeral)) {
                        $hits++;
                        if ($j === 0) $firstExact = true;
                        if ($j === $plen - 1) $lastExact = true;
                    }
                }

                if (!$firstExact || !$lastExact) {
                    continue;
                }

                if ($hits >= $minHits) {
                    $confidence = $hits / $plen;

                    $matches[] = [
                        'progression_id' => $fragment['id'],
                        'variant_index' => $fragment['variant_index'],
                        'variant_label' => $fragment['variant_label'],
                        'start_idx' => $start,
                        'length' => $plen,
                        'confidence' => round($confidence, 3),
                        'hits' => $hits,
                        'total' => $plen,
                    ];
                }
            }
        }

        usort($matches, function ($a, $b) {
            if ($b['length'] !== $a['length']) return $b['length'] - $a['length'];
            return $b['confidence'] <=> $a['confidence'];
        });

        $accepted = [];
        $occupied = array_fill(0, count($numerals), false);

        foreach ($matches as $m) {
            $overlap = false;
            for ($i = $m['start_idx']; $i < $m['start_idx'] + $m['length']; $i++) {
                if ($occupied[$i]) {
                    $overlap = true;
                    break;
                }
            }

            if ($overlap) continue;

            foreach ($accepted as $a) {
                if ($a['start_idx'] === $m['start_idx'] && $a['length'] === $m['length']) {
                    $aVar = $a['variant_index'] ?? PHP_INT_MAX;
                    $mVar = $m['variant_index'] ?? PHP_INT_MAX;
                    if ($mVar >= $aVar) continue 2;
                    for ($i = $a['start_idx']; $i < $a['start_idx'] + $a['length']; $i++) {
                        $occupied[$i] = false;
                    }
                    $accepted = array_filter($accepted, fn($x) => $x !== $a);
                    break;
                }
            }

            for ($i = $m['start_idx']; $i < $m['start_idx'] + $m['length']; $i++) {
                $occupied[$i] = true;
            }
            $accepted[] = $m;
        }

        return array_values($accepted);
    }

    /**
     * Compare a section numeral against a pattern numeral.
     */
    private function numeralMatch(string $sectionNumeral, string $patternNumeral): bool
    {
        if ($sectionNumeral === $patternNumeral) {
            return true;
        }

        $secRoot = $this->extractRoot($sectionNumeral);
        $patRoot = $this->extractRoot($patternNumeral);

        if ($secRoot === null || $patRoot === null) {
            return false;
        }

        if ($secRoot !== $patRoot) {
            return false;
        }

        return true;
    }

    /**
     * Extract the root degree from a numeral token.
     */
    private function extractRoot(string $numeral): ?string
    {
        if (preg_match('/^([b#]*[IV]+)/i', $numeral, $m)) {
            return strtoupper($m[1]);
        }
        return null;
    }

    /**
     * Get the raw fragment table (for testing/debugging).
     */
    public function getFragments(): array
    {
        return $this->fragments;
    }
}

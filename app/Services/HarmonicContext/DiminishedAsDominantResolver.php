<?php

namespace App\Services\HarmonicContext;

/**
 * DiminishedAsDominantResolver — Phase 2 sub-pass 2c′.
 *
 * Detects when a diminished 7th chord is actually a rootless dominant 7(b9).
 * dim7 chords are inversionally symmetric; they can be interpreted as rootless
 * voicings of dominant 7(b9) chords where the dim7's root is the b9 of the dominant.
 *
 * This pass runs before DiminishedResolver. If it identifies a dominant interpretation,
 * it marks the slot to skip DiminishedResolver.
 *
 * Pure function: no DB access, no I/O.
 */
class DiminishedAsDominantResolver
{
    /** Diminished qualities that trigger detection. */
    private const DIM_QUALITIES = ['dim7', 'dim', 'o7'];

    /**
     * Detect diminished-as-dominant interpretations.
     *
     * @param  array<int, array> $results  Phase 1 results with at least {root, quality, pcs}
     * @return array<int, array>          Results with dominant reinterpretations + skip flags
     */
    public function resolve(array $results): array
    {
        $count = count($results);
        if ($count === 0) return $results;

        for ($i = 0; $i < $count; $i++) {
            $quality = $results[$i]['quality'] ?? '';

            if (!in_array($quality, self::DIM_QUALITIES, true)) {
                continue;
            }

            // Need a next slot to check resolution
            if ($i >= $count - 1) {
                continue;
            }

            $nextRoot = $results[$i + 1]['root'] ?? null;
            if ($nextRoot === null) {
                continue;
            }

            $nextPc = $this->noteNameToPc($nextRoot);
            $currentRoot = $results[$i]['root'] ?? '';
            $currentPc = $this->noteNameToPc($currentRoot);
            $currentPcs = $results[$i]['pcs'] ?? [];

            // Get the four symmetric roots of the dim7
            $symmetricRoots = $this->getSymmetricRoots($currentPc);

            $validDominants = [];

            foreach ($symmetricRoots as $r) {
                // Compute dominant root: major third below r
                $domRootPc = ($r - 4 + 12) % 12;

                // Check if dom_root resolves down a perfect 5th to next slot's root
                $expectedNextPc = ($domRootPc - 7 + 12) % 12;

                if ($expectedNextPc === $nextPc) {
                    $domRootName = $this->pcToNoteName($domRootPc);
                    $validDominants[] = [
                        'dom_root_pc' => $domRootPc,
                        'dom_root_name' => $domRootName,
                        'dim_root_pc' => $r,
                        'dim_root_name' => $this->pcToNoteName($r),
                    ];
                }
            }

            if (empty($validDominants)) {
                continue; // No valid dominant interpretation
            }

            // If multiple valid, pick the one whose dom_root is not in the slot's PC set
            // (real rootless voicing has the root absent)
            $chosen = null;
            foreach ($validDominants as $candidate) {
                if (!in_array($candidate['dom_root_pc'], $currentPcs, true)) {
                    $chosen = $candidate;
                    break; // First match wins
                }
            }

            // Fallback: if all dominants have their root present, pick the first
            if ($chosen === null) {
                $chosen = $validDominants[0];
            }

            // Apply the reinterpretation
            $bassNote = $results[$i]['bass_note'] ?? $chosen['dim_root_name'];
            $domName = $chosen['dom_root_name'] . '7(b9)';

            $results[$i]['name'] = $domName;
            $results[$i]['root'] = $chosen['dom_root_name'];
            $results[$i]['quality'] = 'dom7';
            $results[$i]['extensions'] = 'b9';
            $results[$i]['bass_note'] = $bassNote;
            $results[$i]['reinterpreted'] = true;
            $results[$i]['reinterpret_reason'] = 'dim_as_rootless_v7b9';
            $results[$i]['skip_diminished_resolver'] = true; // Flag for DiminishedResolver to skip
        }

        return $results;
    }

    /**
     * Get the four symmetric roots of a dim7 chord.
     *
     * @return array<int, int>  Four pitch classes (0–11)
     */
    private function getSymmetricRoots(int $pc): array
    {
        return [
            $pc,
            ($pc + 3) % 12,
            ($pc + 6) % 12,
            ($pc + 9) % 12,
        ];
    }

    /**
     * Convert a note name to pitch class (0–11).
     */
    private function noteNameToPc(string $name): int
    {
        $map = [
            'C' => 0, 'C#' => 1, 'Db' => 1,
            'D' => 2, 'D#' => 3, 'Eb' => 3,
            'E' => 4, 'F' => 5, 'F#' => 6, 'Gb' => 6,
            'G' => 7, 'G#' => 8, 'Ab' => 8,
            'A' => 9, 'A#' => 10, 'Bb' => 10,
            'B' => 11,
        ];

        return $map[$name] ?? 0;
    }

    /**
     * Convert a pitch class to a note name (preferring sharps).
     */
    private function pcToNoteName(int $pc): string
    {
        $names = ['C', 'C#', 'D', 'D#', 'E', 'F', 'F#', 'G', 'G#', 'A', 'A#', 'B'];
        return $names[$pc % 12];
    }
}

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
    private const DIM_QUALITIES = DiminishedSymmetry::DIM_QUALITIES;

    public function __construct(private DiminishedSymmetry $symmetry = new DiminishedSymmetry()) {}

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

            // Branch B: a major triad carrying a b9 but NO b7 — e.g. `A(b9)`
            // from {A,C#,E,Bb}. A b9 over a major 3rd is a dominant-only
            // colour; the chord is a 7(b9) shell with the b7 merely omitted.
            // When it resolves down a perfect 5th (V→I), name it `X7(b9)`.
            if (!in_array($quality, self::DIM_QUALITIES, true)) {
                $this->maybeResolveMajorB9($results, $i, $count);
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
            $symmetricRoots = $this->symmetry->symmetricRoots($currentPc);

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
     * Branch B: reinterpret a major-triad-with-b9 as a rootless 7(b9).
     *
     * A chord identified as `X(b9)` — major 3rd + perfect 5th + flat 9, but
     * NO flat 7 — is, functionally, an `X7(b9)` with the 7th omitted: the b9
     * is a dominant-exclusive tension. When the chord resolves DOWN A PERFECT
     * FIFTH to the next slot (V→I), that dominant function is confirmed and we
     * rename it `X7(b9)`. Resolution-gated: a non-resolving X(b9) is left as-is.
     *
     * @param array<int,array> $results  passed by reference — mutated in place
     */
    private function maybeResolveMajorB9(array &$results, int $i, int $count): void
    {
        $quality = $results[$i]['quality'] ?? '';
        // Only plain major triads (the identifier emits 'maj' for these).
        if ($quality !== 'maj') return;

        // Must carry a b9 and nothing that implies a 7th is already named.
        $ext = (string)($results[$i]['extensions'] ?? '');
        if (!in_array($ext, ['b9'], true)) return;

        // Need a next slot to confirm the V→I resolution.
        if ($i >= $count - 1) return;
        $nextRoot = $results[$i + 1]['root'] ?? null;
        $currentRoot = $results[$i]['root'] ?? null;
        if ($nextRoot === null || $currentRoot === null) return;

        // Resolution gate: next root a perfect 5th below current (down P5).
        $currentPc = $this->noteNameToPc($currentRoot);
        $nextPc    = $this->noteNameToPc($nextRoot);
        if (($currentPc - 7 + 12) % 12 !== $nextPc) return;

        // Reinterpret to a dominant 7(b9). Bass is preserved.
        $results[$i]['name'] = $currentRoot . '7(b9)'
            . (($results[$i]['bass_note'] ?? $currentRoot) !== $currentRoot
                ? '/' . $results[$i]['bass_note'] : '');
        $results[$i]['quality'] = 'dom7';
        $results[$i]['extensions'] = 'b9';
        $results[$i]['reinterpreted'] = true;
        $results[$i]['reinterpret_reason'] = 'maj_b9_as_v7b9';
    }

    /** Note name → pitch class (0–11). Delegates to the shared primitive. */
    private function noteNameToPc(string $name): int
    {
        return $this->symmetry->toPc($name);
    }

    /** Pitch class → note name, sharp-biased (preserves historical behavior). */
    private function pcToNoteName(int $pc): string
    {
        return $this->symmetry->spellSharp($pc);
    }
}

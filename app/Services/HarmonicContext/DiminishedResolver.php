<?php

namespace App\Services\HarmonicContext;

/**
 * DiminishedResolver — Phase 2 sub-pass 2c.
 *
 * Resolves the structurally ambiguous root of diminished 7th chords.
 * dim7 chords are inversionally symmetric under transposition by minor
 * third — Cdim7, Ebdim7, Gbdim7, and Adim7 share the same PC set.
 * Phase 1 picks one root by enumeration order; this resolver picks
 * the musically correct one from neighbor context.
 *
 * Pure function: no DB access, no I/O.
 */
class DiminishedResolver
{
    /** Diminished qualities that trigger resolution. */
    private const DIM_QUALITIES = DiminishedSymmetry::DIM_QUALITIES;

    public function __construct(private DiminishedSymmetry $symmetry = new DiminishedSymmetry()) {}

    /**
     * Resolve diminished roots across a sequence of chord results.
     *
     * @param  array<int, array> $results  Phase 1 results with at least {root, quality}
     * @return array<int, array>          Results with resolved dim roots + reinterpret metadata
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

            // Skip if already handled by DiminishedAsDominantResolver
            if ($results[$i]['skip_diminished_resolver'] ?? false) {
                continue;
            }

            $currentRoot = $results[$i]['root'] ?? '';
            $currentPc   = $this->noteNameToPc($currentRoot);

            $prevRoot = ($i > 0) ? ($results[$i - 1]['root'] ?? null) : null;
            $nextRoot = ($i < $count - 1) ? ($results[$i + 1]['root'] ?? null) : null;

            $prevPc = $prevRoot !== null ? $this->noteNameToPc($prevRoot) : null;
            $nextPc = $nextRoot !== null ? $this->noteNameToPc($nextRoot) : null;

            // No neighbor context → D5 fallback
            if ($prevPc === null && $nextPc === null) {
                $results[$i]['confidence'] = 'low';
                $results[$i]['reinterpret_reason'] = null;
                continue;
            }

            $symmetricRoots = $this->symmetry->symmetricRoots($currentPc);

            // D1: Ascending passing diminished
            if ($prevPc !== null && $nextPc !== null) {
                $ascendingStep = ($nextPc - $prevPc + 12) % 12 === 2;
                if ($ascendingStep) {
                    $expectedRoot = ($prevPc + 1) % 12;
                    if (in_array($expectedRoot, $symmetricRoots, true)) {
                        $results[$i] = $this->applyResolution(
                            $results[$i], $expectedRoot, 'dim_passing_ascending'
                        );
                        continue;
                    }
                }

                // D2: Descending passing diminished
                $descendingStep = ($prevPc - $nextPc + 12) % 12 === 2;
                if ($descendingStep) {
                    $expectedRoot = ($prevPc - 1 + 12) % 12;
                    if (in_array($expectedRoot, $symmetricRoots, true)) {
                        $results[$i] = $this->applyResolution(
                            $results[$i], $expectedRoot, 'dim_passing_descending'
                        );
                        continue;
                    }
                }
            }

            // D3: Common-tone diminished (followed by tonic)
            if ($nextPc !== null) {
                // Check if next chord is a tonic (root matches key tonic — but we
                // don't have key here; we check if next quality suggests tonic function)
                $nextQuality = $results[$i + 1]['quality'] ?? '';
                $isTonicResolution = in_array($nextQuality, ['maj', 'maj7', 'min', 'm'], true);

                if ($isTonicResolution && in_array($nextPc, $symmetricRoots, true)) {
                    // Common-tone: dim7 shares root with the following tonic
                    $results[$i]['common_tone_dim'] = true;
                    // Don't rewrite — leave Phase 1's root
                    continue;
                }
            }

            // D4: Voice-leading default
            $bestRoot = $this->pickVoiceLeadingRoot($symmetricRoots, $prevPc, $nextPc);
            if ($bestRoot !== null && $bestRoot !== $currentPc) {
                $results[$i] = $this->applyResolution(
                    $results[$i], $bestRoot, 'dim_voice_leading'
                );
            }
        }

        return $results;
    }

    /**
     * Pick the symmetric root with the smoothest voice-leading to neighbors.
     *
     * Measures sum of semitone distances from candidate root to neighbor roots.
     * Tie-break: prefer ascending (D1 over D2 logic).
     *
     * @param  array<int, int> $symmetricRoots
     * @return int|null
     */
    private function pickVoiceLeadingRoot(array $symmetricRoots, ?int $prevPc, ?int $nextPc): ?int
    {
        $bestRoot  = null;
        $bestScore = PHP_INT_MAX;

        foreach ($symmetricRoots as $root) {
            $score = 0;
            $count = 0;

            if ($prevPc !== null) {
                $dist = min(
                    ($root - $prevPc + 12) % 12,
                    ($prevPc - $root + 12) % 12
                );
                $score += $dist;
                $count++;
            }

            if ($nextPc !== null) {
                $dist = min(
                    ($root - $nextPc + 12) % 12,
                    ($nextPc - $root + 12) % 12
                );
                $score += $dist;
                $count++;
            }

            if ($count === 0) continue;

            if ($score < $bestScore) {
                $bestScore = $score;
                $bestRoot  = $root;
            } elseif ($score === $bestScore && $bestRoot !== null) {
                // Tie-break: prefer ascending (lower semitone value)
                if ($root < $bestRoot) {
                    $bestRoot = $root;
                }
            }
        }

        return $bestRoot;
    }

    /**
     * Apply a resolution to a result entry.
     *
     * @param  array  $result
     * @param  int    $newRootPc
     * @param  string $reason
     * @return array
     */
    private function applyResolution(array $result, int $newRootPc, string $reason): array
    {
        $newRootName = $this->pcToNoteName($newRootPc);

        // Don't create slash chords where root equals bass (e.g., "Ddim/D")
        // These are redundant and not musically useful
        $bassNote = $result['bass_note'] ?? null;
        if ($bassNote !== null) {
            $bassPc = $this->noteNameToPc($bassNote);
            if ($newRootPc === $bassPc) {
                // New root equals bass note - don't apply resolution
                return $result;
            }
        }

        // Save original root before overwriting
        if (!isset($result['root_original'])) {
            $result['root_original'] = $result['root'] ?? null;
        }

        $oldRoot = $result['root'] ?? '';

        $result['root']             = $newRootName;
        $result['reinterpreted']    = true;
        $result['reinterpret_reason'] = $reason;

        // Update name: replace old root with new root.
        // Handle multi-char roots (F#, Bb, etc.) by matching at start of name.
        $name = $result['name'] ?? '';
        if ($name && $oldRoot && str_starts_with($name, $oldRoot)) {
            $result['name'] = $newRootName . substr($name, strlen($oldRoot));
        }

        return $result;
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

<?php

namespace App\Services\HarmonicContext;

/**
 * PedalDetector — Phase 2 sub-pass 2a.
 *
 * Detects pedal-point passages and reinterprets chords by re-running Phase 1
 * on upper-voice PCs only (without the sustained bass).
 *
 * Pure function: no DB access, no I/O.
 */
class PedalDetector
{
    private const MIN_PEDAL_LENGTH = 3;
    private const MAX_PEDAL_LENGTH = 8;

    /** @var callable */
    private $phase1Identifier;

    /**
     * @param callable $phase1Identifier Function that takes (array $pcs, ?int $bassPc, int $noteCount) and returns Phase 1 result
     */
    public function __construct(callable $phase1Identifier)
    {
        $this->phase1Identifier = $phase1Identifier;
    }

    /**
     * Detect pedal-point passages in a sequence of chord results.
     *
     * @param  array<int, array> $results  Phase 1 results with at least {bass_note, pcs}
     * @return array<int, array>          Results with pedal reinterpretations
     */
    public function detect(array $results): array
    {
        $count = count($results);
        if ($count < self::MIN_PEDAL_LENGTH) {
            return $results;
        }

        $pedalRuns = $this->findPedalRuns($results);

        if (empty($pedalRuns)) {
            return $results;
        }

        // Resolve each pedal run
        foreach ($pedalRuns as $run) {
            $results = $this->resolvePedalRun($results, $run);
        }

        return $results;
    }

    /**
     * Find all pedal-point runs in the results.
     */
    private function findPedalRuns(array $results): array
    {
        $runs = [];
        $n = count($results);

        if ($n < 3) {
            return $runs;
        }

        $runStart = null;
        $currentBass = null;
        $prevUpperPcs = null;

        for ($i = 0; $i < $n; $i++) {
            $bassName = $results[$i]['bass_note'] ?? null;
            $pcs = $results[$i]['pcs'] ?? [];

            if ($bassName === null || empty($pcs)) {
                // End current run if any
                if ($runStart !== null) {
                    if ($i - $runStart >= 3) {
                        $runs[] = [
                            'start' => $runStart,
                            'end' => $i - 1,
                            'bass_name' => $currentBass,
                            'bass_pc' => $this->noteNameToPc($currentBass),
                        ];
                    }
                    $runStart = null;
                    $currentBass = null;
                    $prevUpperPcs = null;
                }
                continue;
            }

            $bassPc = $this->noteNameToPc($bassName);

            // Trust bass field as authoritative - no verification needed
            // Bass is determined upstream from actual MIDI pitch / lowest fretted string

            $upperPcs = array_values(array_filter($pcs, fn($pc) => $pc !== $bassPc));

            if ($runStart === null) {
                // Start a new potential run
                $runStart = $i;
                $currentBass = $bassName;
                $prevUpperPcs = $upperPcs;
            } elseif ($bassName !== $currentBass) {
                // Bass changed - end current run and start new
                if ($i - $runStart >= 3) {
                    $runs[] = [
                        'start' => $runStart,
                        'end' => $i - 1,
                        'bass_name' => $currentBass,
                        'bass_pc' => $this->noteNameToPc($currentBass),
                    ];
                }
                $runStart = $i;
                $currentBass = $bassName;
                $prevUpperPcs = $upperPcs;
            } else {
                // Continue current run
                $prevUpperPcs = $upperPcs;
            }
        }

        // Close any open run at the end
        if ($runStart !== null && $n - $runStart >= 3) {
            $runs[] = [
                'start' => $runStart,
                'end' => $n - 1,
                'bass_name' => $currentBass,
                'bass_pc' => $this->noteNameToPc($currentBass),
            ];
        }

        return $runs;
    }

    /**
     * Resolve a single pedal run by re-running Phase 1 on upper-voice PCs.
     */
    private function resolvePedalRun(array $results, array $run): array
    {
        $start = $run['start'];
        $end = $run['end'];
        $bassName = $run['bass_name'];
        $bassPc = $run['bass_pc'];

        for ($i = $start; $i <= $end; $i++) {
            $pcs = $results[$i]['pcs'] ?? [];
            $upperPcs = array_values(array_filter($pcs, fn($pc) => $pc !== $bassPc));

            if (count($upperPcs) < 2) {
                continue; // Not enough upper voices to identify meaningfully
            }

            // Re-run Phase 1 on upper PCs alone
            // Pass bassPc = null to disable bass-boost
            $noteCount = count($upperPcs);
            $upperResult = ($this->phase1Identifier)(
                $upperPcs,
                null, // no bass-boost
                $noteCount
            );

            if (!($upperResult['name'] ?? null)) {
                continue; // Upper identification failed
            }

            // Check score threshold: only promote if upper result has comparable score
            $currentScore = $results[$i]['confidence'] ?? 0;
            $upperScore = $upperResult['score'] ?? 0;

            // Handle non-numeric confidence values (e.g., "exact", "partial")
            if (!is_numeric($currentScore)) {
                // If confidence is not numeric, skip score check and allow promotion
                // This happens for pedal-detected chords that were already reinterpreted
                $currentScore = $upperScore; // Treat as equal
            }

            $scoreRatio = $currentScore > 0 ? $upperScore / $currentScore : 0;

            if ($scoreRatio < 0.8) {
                // Upper structure score is too low - don't promote
                continue;
            }

            // Append pedal bass to the winner's name
            $upperName = $upperResult['name'];
            $newName = $upperName . '/' . $bassName;

            // Update the result entry
            $results[$i] = array_merge($results[$i], [
                'name' => $newName,
                'root_original' => $upperResult['root'] ?? null,
                'root' => $upperResult['root'] ?? null,
                'quality' => $upperResult['quality'] ?? null,
                'extensions' => $upperResult['extensions'] ?? null,
                'bass_note' => $bassName,
                'reinterpreted' => true,
                'reinterpret_reason' => 'pedal',
                'pedal_bass' => $bassName,
                'pedal_upper_name' => $upperName,
            ]);
        }

        return $results;
    }

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
}

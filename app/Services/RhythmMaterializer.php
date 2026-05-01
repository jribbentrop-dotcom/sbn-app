<?php

namespace App\Services;

use App\Models\RhythmPattern;

class RhythmMaterializer
{
    /**
     * Expand a single bar of one chord into stroke events.
     * Always produces exactly one bar's worth of strokes regardless of
     * how many bars the chord spans — the caller loops per bar.
     *
     * @param array $voicing       ['frets' => 'x35453', 'position' => 5]
     * @param RhythmPattern $pattern
     * @param int $divisions       MusicXML divisions per quarter note (typically 480)
     * @param int $beatsPerMeasure From sheet's time signature numerator (e.g. 4 for 4/4)
     *
     * @return array<int, array{
     *   tickOffset: int,
     *   durTicks:   int,
     *   durName:    string,
     *   strings:    int[],
     *   accent:     bool,
     *   velocity:   float,
     * }>
     */
    public function expand(
        array $voicing,
        RhythmPattern $pattern,
        int $divisions,
        int $beatsPerMeasure
    ): array {
        $gridType = $pattern->grid_type ?? 'sixteenth';
        
        $stepBeats = 0.25;
        if ($gridType === 'eighth') $stepBeats = 0.5;
        if ($gridType === 'sixteenth') $stepBeats = 0.25;
        if ($gridType === 'triplet') $stepBeats = 1.0 / 3.0;

        $stepTicks = (int) round($stepBeats * $divisions);
        $totalBarTicks = $beatsPerMeasure * $divisions;

        $fingersStr = $pattern->rhythm_pattern ?? '';
        $thumbStr = $pattern->thumb_pattern ?? '';
        $isStrum = str_contains(strtolower($pattern->category ?? ''), 'strum');

        $frets = $voicing['frets'] ?? 'xxxxxx';
        
        $availableTreble = []; 
        $availableBass = [];   
        $availableAll = [];

        for ($i = 0; $i < 6; $i++) {
            $char = strtolower($frets[$i] ?? 'x');
            if ($char !== 'x') {
                $stringNum = 6 - $i; 
                if ($i >= 3) {
                    $availableTreble[] = $stringNum;
                } else {
                    $availableBass[] = $stringNum;
                }
                $availableAll[] = $stringNum;
            }
        }

        $strokes = [];

        // 1. Process Fingers
        $fingersLength = strlen($fingersStr);
        for ($i = 0; $i < $fingersLength; $i++) {
            $char = $fingersStr[$i];
            if ($char === '.') continue;

            $tickOffset = $i * $stepTicks;
            if ($tickOffset >= $totalBarTicks) break;

            $accent = ($char === 'X');
            $velocity = $accent ? 1.0 : 0.85;

            $stringsToHit = $isStrum ? $availableAll : $availableTreble;

            if (!empty($stringsToHit)) {
                $strokes[$tickOffset . '-fingers'] = [
                    'tickOffset' => $tickOffset,
                    'accent'     => $accent,
                    'velocity'   => $velocity,
                    'strings'    => $stringsToHit,
                    'is_thumb'   => false,
                ];
            }
        }

        // 2. Process Thumb
        if (!$isStrum) {
            $thumbLength = strlen($thumbStr);
            for ($i = 0; $i < $thumbLength; $i++) {
                $char = $thumbStr[$i];
                if ($char === '.') continue;

                $tickOffset = $i * $stepTicks;
                if ($tickOffset >= $totalBarTicks) break;

                $accent = ($char === 'X');
                $velocity = $accent ? 1.0 : 0.85;

                if (!empty($availableBass)) {
                    $strokes[$tickOffset . '-thumb'] = [
                        'tickOffset' => $tickOffset,
                        'accent'     => $accent,
                        'velocity'   => $velocity,
                        'strings'    => empty($availableBass) ? [] : [max($availableBass)],

                        'is_thumb'   => true,
                    ];
                }
            }
        }

        // Sort by tickOffset, thumb before fingers
        uasort($strokes, function ($a, $b) {
            if ($a['tickOffset'] === $b['tickOffset']) {
                if ($a['is_thumb'] && !$b['is_thumb']) return -1;
                if (!$a['is_thumb'] && $b['is_thumb']) return 1;
                return 0;
            }
            return $a['tickOffset'] <=> $b['tickOffset'];
        });

        $sortedStrokes = array_values($strokes);

        $strokeCount = count($sortedStrokes);
        for ($i = 0; $i < $strokeCount; $i++) {
            $currentTick = $sortedStrokes[$i]['tickOffset'];
            
            if ($i + 1 < $strokeCount && $sortedStrokes[$i + 1]['tickOffset'] === $currentTick) {
                $nextDistinctTick = $totalBarTicks;
                for ($j = $i + 2; $j < $strokeCount; $j++) {
                    if ($sortedStrokes[$j]['tickOffset'] > $currentTick) {
                        $nextDistinctTick = $sortedStrokes[$j]['tickOffset'];
                        break;
                    }
                }
                $durTicks = $nextDistinctTick - $currentTick;
            } else {
                $nextTick = ($i + 1 < $strokeCount) ? $sortedStrokes[$i + 1]['tickOffset'] : $totalBarTicks;
                $durTicks = $nextTick - $currentTick;
            }

            $durTicks = max(1, $durTicks);

            $durRatio = $durTicks / $divisions;
            if ($durRatio >= 3.0) $durName = 'w';
            elseif ($durRatio >= 1.5) $durName = 'h';
            elseif ($durRatio >= 0.75) $durName = 'q';
            elseif ($durRatio >= 0.375) $durName = 'e';
            else $durName = 's';

            $sortedStrokes[$i]['durTicks'] = $durTicks;
            $sortedStrokes[$i]['durName'] = $durName;
            
            unset($sortedStrokes[$i]['is_thumb']);
        }

        return $sortedStrokes;
    }
}

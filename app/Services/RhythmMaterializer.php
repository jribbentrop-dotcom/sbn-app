<?php

namespace App\Services;

use App\Models\RhythmPattern;

class RhythmMaterializer
{
    /**
     * Expand a rhythm pattern across one or more bars into stroke events.
     *
     * For single-bar patterns pass a single-element array: [0 => $voicing].
     * For multi-bar patterns pass one voicing per bar keyed by bar offset (0-based).
     * Strokes automatically pick up the correct voicing as they cross bar boundaries.
     *
     * @param array<int,array> $voicingsByBar  bar-offset => ['frets' => '...', 'position' => n]
     * @param array            $prevFingerStrings  finger strings from previous chord for voice leading
     */
    public function expand(
        array $voicingsByBar,
        RhythmPattern $pattern,
        int $divisions,
        int $beatsPerMeasure,
        array $prevFingerStrings = []
    ): array {
        $gridType = $pattern->grid_type ?? 'sixteenth';

        $stepBeats = 0.25;
        if ($gridType === 'eighth') $stepBeats = 0.5;
        if ($gridType === 'sixteenth') $stepBeats = 0.25;
        if ($gridType === 'triplet') $stepBeats = 1.0 / 3.0;

        $tpm = $beatsPerMeasure * $divisions; // ticks per measure

        $patternTimeSig = $pattern->time_signature ?? '4/4';
        [$patBeats, $patBeatType] = array_map('intval', explode('/', $patternTimeSig) + [4, 4]);
        $patternBarTicks = (int) round($patBeats * $divisions * (4 / $patBeatType));
        $timeScale = ($patternBarTicks > 0) ? ($tpm / $patternBarTicks) : 1.0;
        $stepTicks = (int) round($stepBeats * $divisions * $timeScale);

        $fingersStr = $pattern->rhythm_pattern ?? '';
        $thumbStr   = $pattern->thumb_pattern ?? '';
        $isStrum    = str_contains(strtolower($pattern->category ?? ''), 'strum');

        // Total tick span covered by this expand call
        $patternBars    = count($voicingsByBar);
        $totalSpanTicks = $tpm * $patternBars;

        // Pre-resolve per-bar string assignments once (avoid re-deriving inside the loop)
        $barKeys      = array_keys($voicingsByBar);
        $barVoicings  = []; // barOffset => resolved voicing info
        $prev         = $prevFingerStrings;
        foreach ($barKeys as $barOffset) {
            $v    = $voicingsByBar[$barOffset];
            $frets = $v['frets'] ?? 'xxxxxx';

            $availableBass = [];
            $availableAll  = [];
            for ($i = 0; $i < 6; $i++) {
                $char = strtolower($frets[$i] ?? 'x');
                if ($char !== 'x') {
                    $stringNum = 6 - $i;
                    if ($i < 3) $availableBass[] = $stringNum;
                    $availableAll[] = $stringNum;
                }
            }
            $availableSorted = $availableAll;
            rsort($availableSorted);

            $fingerStrings = $this->_resolveFingerStrings(
                $availableAll, $availableBass, $availableSorted, $prev
            );
            $prev = $fingerStrings;

            $barVoicings[$barOffset] = [
                'frets'         => $frets,
                'position'      => (int) ($v['position'] ?? 1),
                'availableBass' => $availableBass,
                'availableAll'  => $availableAll,
                'fingerStrings' => $fingerStrings,
            ];
        }

        // Helper: resolve voicing info for a given tick offset within the span
        $voicingAtTick = function (int $tick) use ($tpm, $barKeys, $barVoicings): array {
            $barIdx = (int) floor($tick / $tpm);
            // Clamp to the last bar if tick lands exactly on the boundary
            $barIdx = min($barIdx, count($barKeys) - 1);
            $barOffset = $barKeys[$barIdx];
            return $barVoicings[$barOffset];
        };

        $strokes = [];

        // 1. Process Fingers
        $fingersLength = strlen($fingersStr);
        for ($i = 0; $i < $fingersLength; $i++) {
            $char = $fingersStr[$i];
            if ($char === '.') continue;

            $tickOffset = $i * $stepTicks;
            if ($tickOffset >= $totalSpanTicks) break;

            $bv           = $voicingAtTick($tickOffset);
            $accent       = ($char === 'X');
            $velocity     = $accent ? 1.0 : 0.85;
            $stringsToHit = $isStrum ? $bv['availableAll'] : $bv['fingerStrings'];

            if (!empty($stringsToHit)) {
                $strokes[$tickOffset . '-fingers'] = [
                    'tickOffset'     => $tickOffset,
                    'accent'         => $accent,
                    'velocity'       => $velocity,
                    'strings'        => $stringsToHit,
                    'frets'          => $bv['frets'],
                    'is_thumb'       => false,
                    'finger_strings' => $bv['fingerStrings'],
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
                if ($tickOffset >= $totalSpanTicks) break;

                $bv     = $voicingAtTick($tickOffset);
                $accent = ($char === 'X');
                $velocity = $accent ? 1.0 : 0.85;

                if (!empty($bv['availableBass'])) {
                    $strokes[$tickOffset . '-thumb'] = [
                        'tickOffset' => $tickOffset,
                        'accent'     => $accent,
                        'velocity'   => $velocity,
                        'strings'    => [max($bv['availableBass'])],
                        'frets'      => $bv['frets'],
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
                $nextDistinctTick = $totalSpanTicks;
                for ($j = $i + 2; $j < $strokeCount; $j++) {
                    if ($sortedStrokes[$j]['tickOffset'] > $currentTick) {
                        $nextDistinctTick = $sortedStrokes[$j]['tickOffset'];
                        break;
                    }
                }
                $durTicks = $nextDistinctTick - $currentTick;
            } else {
                $nextTick = ($i + 1 < $strokeCount) ? $sortedStrokes[$i + 1]['tickOffset'] : $totalSpanTicks;
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

        // Expose which finger strings were used so the caller can pass them to the next window.
        // Each stroke already carries finger_strings from its bar; fill in thumb strokes from their bar's data.
        foreach ($sortedStrokes as &$s) {
            if (!isset($s['finger_strings'])) {
                $bv = $voicingAtTick($s['tickOffset']);
                $s['finger_strings'] = $bv['fingerStrings'];
            }
        }
        unset($s);

        return $sortedStrokes;
    }

    /**
     * Decide which strings the fingers play for this chord.
     *
     * ≤ 4 available notes: thumb takes the lowest-pitched string (max string#);
     *   fingers take the remaining strings sorted low→high pitch.
     *
     * > 4 available notes: thumb takes max($availableBass) as before;
     *   fingers take strings 4, 3, 2 (D, G, B) that are non-x.
     *
     * Soft voice leading: if $prevFingerStrings is non-empty and every string
     *   in it is present in the current voicing, reuse it unchanged.
     */
    private function _resolveFingerStrings(
        array $availableAll,
        array $availableBass,
        array $availableSorted, // descending string# = ascending pitch
        array $prevFingerStrings
    ): array {
        // Soft voice leading check
        if (!empty($prevFingerStrings)) {
            $availableSet = array_flip($availableAll);
            $allPresent   = true;
            foreach ($prevFingerStrings as $s) {
                if (!isset($availableSet[$s])) { $allPresent = false; break; }
            }
            if ($allPresent) return $prevFingerStrings;
        }

        $noteCount = count($availableAll);

        if ($noteCount <= 4) {
            // Thumb = lowest pitch (highest string#) = first in $availableSorted
            // Fingers = the rest, ascending pitch order (descending string#)
            $fingers = array_slice($availableSorted, 1); // drop the thumb string
            rsort($fingers); // ensure ascending pitch (descending string#)
            return $fingers;
        }

        // > 4 notes: fingers on D(4), G(3), B(2) if available
        $preferred = [4, 3, 2];
        $availableSet = array_flip($availableAll);
        $fingers = [];
        foreach ($preferred as $s) {
            if (isset($availableSet[$s])) $fingers[] = $s;
        }
        return $fingers;
    }
}

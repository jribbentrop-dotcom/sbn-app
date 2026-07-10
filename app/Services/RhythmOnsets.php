<?php

namespace App\Services;

use App\Models\RhythmPattern;

/**
 * Ground-truth onset math for a rhythm pattern — the PHP twin of the JS
 * adapter's step loop in resources/js/audio/adapters/rhythmPatternToEvents.js.
 *
 * The JS adapter emits audio events; this emits only the onset TIMES, because
 * grading a tapped rhythm doesn't care which voice made the sound. Keeping the
 * conversion in one class means the "what counts as a beat you should tap"
 * question has exactly one answer server-side.
 *
 * NOTE on naming: the DB columns and the JS model fields differ. The serialized
 * payload the browser sees calls them `thumb` / `fingers` / `gridType`; the
 * `sbn_rhythm_patterns` table calls them `thumb_pattern` / `rhythm_pattern` /
 * `grid_type`. This class reads the DB columns.
 */
class RhythmOnsets
{
    /** Step size in beats, by grid type. Mirrors GRID_STEP_BEATS in the JS adapter. */
    public const GRID_STEP_BEATS = [
        'eighth'    => 0.5,
        'sixteenth' => 0.25,
        'triplet'   => 1 / 3,
    ];

    private const DEFAULT_STEP_BEATS = 0.25;

    /** A step character that is not a rest. '.' = rest, 'x' = soft, 'X' = accent. */
    private const REST = '.';

    /** Beats per step for this pattern's grid. */
    public static function stepBeats(RhythmPattern $pattern): float
    {
        return self::GRID_STEP_BEATS[$pattern->grid_type] ?? self::DEFAULT_STEP_BEATS;
    }

    /** Total length of one pass through the pattern, in beats. */
    public static function lengthInBeats(RhythmPattern $pattern): float
    {
        return (int) $pattern->beats * self::stepBeats($pattern);
    }

    /**
     * Every beat at which the student is expected to tap, sorted ascending,
     * de-duplicated.
     *
     * This is the UNION of every sounding voice in the pattern — a "tap anything
     * that sounds" model. Thumb and fingers frequently coincide on a step; the
     * student taps once, so the expected set must collapse them. In picking mode
     * the three finger rows replace the single `rhythm_pattern` row.
     *
     * @return list<float>
     */
    public static function forPattern(RhythmPattern $pattern): array
    {
        $stepBeats = self::stepBeats($pattern);
        $steps     = (int) $pattern->beats;

        $rows = [$pattern->thumb_pattern];

        if ($pattern->picking_mode) {
            $rows[] = $pattern->finger_index;
            $rows[] = $pattern->finger_middle;
            $rows[] = $pattern->finger_ring;
        } else {
            $rows[] = $pattern->rhythm_pattern;
        }

        $onsetSteps = [];

        foreach ($rows as $row) {
            if (! is_string($row) || $row === '') {
                continue; // finger_* rows are nullable
            }

            // Never read past the declared step count, even if a row is longer.
            $len = min(strlen($row), $steps);

            for ($i = 0; $i < $len; $i++) {
                if ($row[$i] !== self::REST) {
                    $onsetSteps[$i] = true; // keyed = de-duplicated across voices
                }
            }
        }

        $onsets = array_map(
            fn (int $step) => round($step * $stepBeats, 6),
            array_keys($onsetSteps),
        );

        sort($onsets);

        return $onsets;
    }
}

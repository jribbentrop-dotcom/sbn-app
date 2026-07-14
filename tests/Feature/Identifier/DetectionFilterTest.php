<?php

namespace Tests\Feature\Identifier;

use App\Services\TranscriptionAssembler;
use App\Services\VoicingCrossref;
use Tests\TestCase;

/**
 * §14 / T9 P1 — the detection-filter re-bucket. Verifies that chord-region
 * buckets can be re-derived from the cached full note set with a different
 * min-note-length / MIDI-range filter, WITHOUT re-running Python, and that the
 * no-filter path is unchanged (regression guard).
 */
class DetectionFilterTest extends TestCase
{
    private function assembler(): TranscriptionAssembler
    {
        return app(TranscriptionAssembler::class);
    }

    private function callRebucket(array $notes, array $beatTimes, ?array $filter): array
    {
        $ref = new \ReflectionMethod($this->assembler(), 'rebucketBeats');
        $ref->setAccessible(true);
        return $ref->invoke($this->assembler(), $notes, $beatTimes, $filter);
    }

    private function assemble(array $rawResult, array $opts): array
    {
        return $this->assembler()->assembleTranscription($rawResult, $opts, 0, app(VoicingCrossref::class));
    }

    public function test_rebucket_no_filter_matches_default_floor(): void
    {
        $beatTimes = [0.0, 0.5];
        $notes = [
            ['pitch' => 60, 'start' => 0.0, 'end' => 0.40], // 400 ms — kept
            ['pitch' => 64, 'start' => 0.0, 'end' => 0.02], // 20 ms — below 50 ms floor, dropped
        ];

        $beats = $this->callRebucket($notes, $beatTimes, null);
        $this->assertEqualsCanonicalizing([60], $beats[0]['notes']);
    }

    public function test_min_note_length_filter_drops_short_notes(): void
    {
        $beatTimes = [0.0, 0.5];
        $notes = [
            ['pitch' => 60, 'start' => 0.0, 'end' => 0.40], // 400 ms
            ['pitch' => 64, 'start' => 0.0, 'end' => 0.12], // 120 ms
            ['pitch' => 67, 'start' => 0.0, 'end' => 0.08], // 80 ms
        ];

        // 100 ms floor drops the 80 ms note but keeps the 120 ms one.
        $beats = $this->callRebucket($notes, $beatTimes, ['min_note_length_ms' => 100]);
        $this->assertEqualsCanonicalizing([60, 64], $beats[0]['notes']);

        // 200 ms floor drops both short notes.
        $beats = $this->callRebucket($notes, $beatTimes, ['min_note_length_ms' => 200]);
        $this->assertEqualsCanonicalizing([60], $beats[0]['notes']);
    }

    public function test_midi_range_filter_clamps_pitches(): void
    {
        $beatTimes = [0.0, 0.5];
        $notes = [
            ['pitch' => 28, 'start' => 0.0, 'end' => 0.40], // sub-bass rumble
            ['pitch' => 60, 'start' => 0.0, 'end' => 0.40], // in range
            ['pitch' => 96, 'start' => 0.0, 'end' => 0.40], // very high
        ];

        $beats = $this->callRebucket($notes, $beatTimes, ['midi_min' => 40, 'midi_max' => 88]);
        $this->assertEqualsCanonicalizing([60], $beats[0]['notes']);
    }

    public function test_assembly_with_filter_removes_clutter_notes(): void
    {
        // A bar whose beats each carry a solid Cmaj7 plus a spurious 30 ms ghost.
        $mkBeat = function (float $t) {
            return [
                'start' => $t,
                'notes' => [60, 64, 67, 71, 50],
                'note_durations' => ['60' => 0.4, '64' => 0.4, '67' => 0.4, '71' => 0.4, '50' => 0.03],
            ];
        };
        $notes = [];
        $bt = [];
        $t = 0.0;
        for ($b = 0; $b < 4; $b++) {
            $bt[] = $t;
            // full note set for melody + re-bucket source
            $notes[] = ['pitch' => 72, 'start' => $t, 'end' => $t + 0.4];
            $notes[] = ['pitch' => 60, 'start' => $t, 'end' => $t + 0.4];
            $notes[] = ['pitch' => 50, 'start' => $t, 'end' => $t + 0.03]; // 30 ms ghost
            $t += 0.5;
        }
        $rawBeats = array_map($mkBeat, $bt);

        $raw = [
            'beats'      => $rawBeats,
            'beat_times' => $bt,
            'notes'      => $notes,
            'tempo'      => 120,
            'duration'   => $t,
        ];

        // With a 100 ms filter the 30 ms ghost (pitch 50) is gone from the
        // re-bucketed chord regions.
        $analysis = $this->assemble($raw, [
            'title' => 'Filter', 'key' => 'C', 'youtube_id' => 't', 'bass_snap' => false,
            'detection_filter' => ['min_note_length_ms' => 100],
        ]);

        // The filter is cached for reproducibility.
        $this->assertSame(
            ['min_note_length_ms' => 100],
            $analysis['transcriptionRaw']['detectionFilter']
        );

        // The re-bucketed chord regions must not contain the 30 ms ghost (50).
        $rebucketed = $this->callRebucket($notes, $bt, ['min_note_length_ms' => 100]);
        foreach ($rebucketed as $beat) {
            $this->assertNotContains(50, $beat['notes'], 'ghost note survived the filter');
        }
    }
}

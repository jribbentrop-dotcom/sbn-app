<?php

namespace Tests\Feature;

use App\Models\Leadsheet;
use App\Models\User;
use Tests\TestCase;

/**
 * §13 — "Fix transcription" latch. Verifies the commit boundary:
 *   fix → re-derive (reshift) is refused with 409 → reopen → forced reshift works.
 *
 * NOTE: like the other leadsheet feature tests, this runs against the real
 * sbn.db (see setUp). The created leadsheet is deleted in tearDown.
 */
class TranscriptionFixLatchTest extends TestCase
{
    private ?Leadsheet $sheet = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.sqlite.database' => 'C:/Users/info/sbn-app/database/sbn.db']);
        \Illuminate\Support\Facades\DB::reconnect('sqlite');
    }

    protected function tearDown(): void
    {
        if ($this->sheet) {
            Leadsheet::where('id', $this->sheet->id)->delete();
        }
        parent::tearDown();
    }

    /** A minimal audio-transcribed leadsheet: json_data carries transcriptionRaw. */
    private function makeAudioSheet(): Leadsheet
    {
        $json = [
            'sections' => [
                ['label' => 'A', 'bars' => [['chords' => [['label' => 'Cmaj7', 'beat' => 1]]]]],
            ],
            'transcriptionRaw' => [
                'beats' => [
                    ['start' => 0.0, 'notes' => [60, 64, 67, 71], 'note_durations' => ['60' => 0.4, '64' => 0.4, '67' => 0.4, '71' => 0.4]],
                    ['start' => 0.5, 'notes' => [60, 64, 67, 71], 'note_durations' => ['60' => 0.4, '64' => 0.4, '67' => 0.4, '71' => 0.4]],
                    ['start' => 1.0, 'notes' => [60, 64, 67, 71], 'note_durations' => ['60' => 0.4, '64' => 0.4, '67' => 0.4, '71' => 0.4]],
                    ['start' => 1.5, 'notes' => [60, 64, 67, 71], 'note_durations' => ['60' => 0.4, '64' => 0.4, '67' => 0.4, '71' => 0.4]],
                ],
                'beat_times' => [0.0, 0.5, 1.0, 1.5],
                'notes' => [['pitch' => 72, 'start' => 0.0, 'end' => 1.9]],
                'tempo' => 120,
                'downbeatOffset' => 0,
                'bassSnap' => false,
                'tabPositionStyle' => 'fretted',
                'separateStem' => false,
            ],
        ];

        return Leadsheet::create([
            'title'          => '__test_fix_latch__',
            'slug'           => Leadsheet::generateUniqueSlug('__test_fix_latch__'),
            'composer'       => '',
            'song_key'       => 'C',
            'tempo'          => 120,
            'time_signature' => '4/4',
            'rhythm'         => '',
            'json_data'      => json_encode($json),
            'measure_count'  => 1,
            'shortcode_content' => '',
            'description'    => '',
            'harmony_notes'  => '',
            'form_notes'     => '',
            'voicing_notes'  => '',
            'popularity'     => 0,
        ]);
    }

    public function test_fix_latch_gates_reshift_until_reopened(): void
    {
        $user = User::first() ?? User::factory()->create();
        $this->sheet = $this->makeAudioSheet();
        $id = $this->sheet->id;

        // Before fixing: reshift is allowed.
        $this->actingAs($user)
            ->postJson("/api/admin/leadsheets/{$id}/reshift-downbeat", ['offset' => 0])
            ->assertOk()
            ->assertJson(['success' => true]);

        // Fix it.
        $this->actingAs($user)
            ->postJson("/api/admin/leadsheets/{$id}/fix-transcription")
            ->assertOk()
            ->assertJson(['success' => true, 'transcriptionFixed' => true]);

        $this->assertTrue((bool)($this->sheet->fresh()->parsed_data['transcriptionFixed'] ?? false));

        // Now reshift is refused with 409 and a fixed flag.
        $this->actingAs($user)
            ->postJson("/api/admin/leadsheets/{$id}/reshift-downbeat", ['offset' => 0])
            ->assertStatus(409)
            ->assertJson(['success' => false, 'fixed' => true]);

        // ...unless forced.
        $this->actingAs($user)
            ->postJson("/api/admin/leadsheets/{$id}/reshift-downbeat", ['offset' => 0, 'force' => true])
            ->assertOk()
            ->assertJson(['success' => true]);

        // Reopen clears the latch.
        $this->actingAs($user)
            ->postJson("/api/admin/leadsheets/{$id}/reopen-tuning")
            ->assertOk()
            ->assertJson(['success' => true, 'transcriptionFixed' => false]);

        // Reshift allowed again without force.
        $this->actingAs($user)
            ->postJson("/api/admin/leadsheets/{$id}/reshift-downbeat", ['offset' => 0])
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_fix_preserves_transcription_raw(): void
    {
        $user = User::first() ?? User::factory()->create();
        $this->sheet = $this->makeAudioSheet();
        $id = $this->sheet->id;

        $this->actingAs($user)
            ->postJson("/api/admin/leadsheets/{$id}/fix-transcription")
            ->assertOk();

        // The raw cache must survive the flag write (merge, not overwrite).
        $parsed = $this->sheet->fresh()->parsed_data;
        $this->assertNotEmpty($parsed['transcriptionRaw']['beats'] ?? []);
        $this->assertTrue($parsed['transcriptionFixed']);
    }

    public function test_retune_detection_rebuckets_and_respects_latch(): void
    {
        $user = User::first() ?? User::factory()->create();
        $this->sheet = $this->makeAudioSheet();
        $id = $this->sheet->id;

        // Retune with a long min-note-length: the sheet's notes are a single
        // 1.9 s note, so it survives; a huge floor would empty it. Assert 200 OK
        // and the filter echoed + cached.
        $this->actingAs($user)
            ->postJson("/api/admin/leadsheets/{$id}/retune-detection", ['min_note_length_ms' => 100])
            ->assertOk()
            ->assertJson(['success' => true, 'filter' => ['min_note_length_ms' => 100]]);

        $this->assertSame(
            ['min_note_length_ms' => 100],
            $this->sheet->fresh()->parsed_data['transcriptionRaw']['detectionFilter'] ?? null
        );

        // Fixed latch also guards retune.
        $this->actingAs($user)->postJson("/api/admin/leadsheets/{$id}/fix-transcription")->assertOk();
        $this->actingAs($user)
            ->postJson("/api/admin/leadsheets/{$id}/retune-detection", ['min_note_length_ms' => 50])
            ->assertStatus(409)
            ->assertJson(['success' => false, 'fixed' => true]);

        // Forced retune works past the latch.
        $this->actingAs($user)
            ->postJson("/api/admin/leadsheets/{$id}/retune-detection", ['min_note_length_ms' => 50, 'force' => true])
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_fix_rejects_non_audio_sheet(): void
    {
        $user = User::first() ?? User::factory()->create();
        // A sheet with no transcriptionRaw.
        $this->sheet = Leadsheet::create([
            'title'          => '__test_fix_latch_nonaudio__',
            'slug'           => Leadsheet::generateUniqueSlug('__test_fix_latch_nonaudio__'),
            'composer'       => '',
            'song_key'       => 'C',
            'tempo'          => 120,
            'time_signature' => '4/4',
            'rhythm'         => '',
            'json_data'      => json_encode(['sections' => []]),
            'measure_count'  => 0,
            'shortcode_content' => '',
            'description'    => '',
            'harmony_notes'  => '',
            'form_notes'     => '',
            'voicing_notes'  => '',
            'popularity'     => 0,
        ]);

        $this->actingAs($user)
            ->postJson("/api/admin/leadsheets/{$this->sheet->id}/fix-transcription")
            ->assertStatus(422)
            ->assertJson(['success' => false]);
    }
}

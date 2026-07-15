<?php

namespace Tests\Feature\Identifier;

use App\Models\Leadsheet;
use App\Models\User;
use Tests\TestCase;

/**
 * T9 Tier-2 re-inference endpoints: redetect (on the persisted original) and
 * transcribe-stem (on an audition session stem). Both re-run basic-pitch, so the
 * HAPPY path needs Python/Demucs + a GPU and is verified manually on the dev box.
 *
 * These tests cover everything that fires BEFORE any Python call — the guards
 * that must hold regardless of the inference environment:
 *   - the §13 fixed-transcription latch (409 unless force),
 *   - the preconditions (no sourceAudio ⇒ 422; missing stem session ⇒ 500),
 *   - validation of the detection knobs.
 *
 * Runs against the real sbn.db; the created leadsheet is deleted in tearDown.
 */
class RedetectStemTest extends TestCase
{
    private ?Leadsheet $sheet = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.sqlite.database' => database_path('sbn.db')]);
        \Illuminate\Support\Facades\DB::reconnect('sqlite');
    }

    protected function tearDown(): void
    {
        if ($this->sheet) {
            Leadsheet::where('id', $this->sheet->id)->delete();
        }
        parent::tearDown();
    }

    /** Minimal audio-transcribed sheet; $withSource adds a (non-existent) sourceAudio url. */
    private function makeAudioSheet(bool $withSource = false, bool $fixed = false): Leadsheet
    {
        $json = [
            'sections' => [
                ['label' => 'A', 'bars' => [['chords' => [['label' => 'Cmaj7', 'beat' => 1]]]]],
            ],
            'transcriptionRaw' => [
                'beats' => [
                    ['start' => 0.0, 'notes' => [60, 64, 67], 'note_durations' => ['60' => 0.4]],
                ],
                'beat_times' => [0.0, 0.5, 1.0, 1.5],
                'notes' => [['pitch' => 72, 'start' => 0.0, 'end' => 1.9]],
                'tempo' => 120,
                'downbeatOffset' => 0,
                'bassSnap' => false,
                'tabPositionStyle' => 'fretted',
                'separateStem' => false,
                'detectionParams' => null,
            ],
        ];
        if ($withSource) {
            // A url whose public_path never exists — exercises the "resolved but
            // file missing" branch without needing a real recording on disk.
            $json['sourceAudio'] = ['url' => '/audio/source/999999/original.wav', 'kind' => 'wav'];
        }
        if ($fixed) {
            $json['transcriptionFixed'] = true;
        }

        return Leadsheet::create([
            'title'          => '__test_redetect__',
            'slug'           => Leadsheet::generateUniqueSlug('__test_redetect__'),
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

    public function test_redetect_422_without_source_audio(): void
    {
        $user = User::first() ?? User::factory()->create();
        $this->sheet = $this->makeAudioSheet(withSource: false);

        $this->actingAs($user)
            ->postJson("/api/admin/leadsheets/{$this->sheet->id}/redetect", ['onset_threshold' => 0.3])
            ->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    public function test_redetect_latch_gates_before_inference(): void
    {
        $user = User::first() ?? User::factory()->create();
        // Fixed sheet WITH a source url: the 409 latch must fire before we ever
        // try to resolve the file / shell Python.
        $this->sheet = $this->makeAudioSheet(withSource: true, fixed: true);

        $this->actingAs($user)
            ->postJson("/api/admin/leadsheets/{$this->sheet->id}/redetect", ['onset_threshold' => 0.3])
            ->assertStatus(409)
            ->assertJson(['success' => false, 'fixed' => true]);
    }

    public function test_redetect_rejects_out_of_range_onset(): void
    {
        $user = User::first() ?? User::factory()->create();
        $this->sheet = $this->makeAudioSheet(withSource: true);

        $this->actingAs($user)
            ->postJson("/api/admin/leadsheets/{$this->sheet->id}/redetect", ['onset_threshold' => 1.5])
            ->assertStatus(422); // validation
    }

    public function test_redetect_422_on_non_audio_sheet(): void
    {
        $user = User::first() ?? User::factory()->create();
        $this->sheet = Leadsheet::create([
            'title'          => '__test_redetect_nonaudio__',
            'slug'           => Leadsheet::generateUniqueSlug('__test_redetect_nonaudio__'),
            'composer'       => '', 'song_key' => 'C', 'tempo' => 120, 'time_signature' => '4/4',
            'rhythm'         => '', 'json_data' => json_encode(['sections' => []]),
            'measure_count'  => 0, 'shortcode_content' => '',
            'description'    => '', 'harmony_notes' => '', 'form_notes' => '', 'voicing_notes' => '',
            'popularity'     => 0,
        ]);

        $this->actingAs($user)
            ->postJson("/api/admin/leadsheets/{$this->sheet->id}/redetect", ['onset_threshold' => 0.3])
            ->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    public function test_transcribe_stem_latch_gates_before_inference(): void
    {
        $user = User::first() ?? User::factory()->create();
        $this->sheet = $this->makeAudioSheet(fixed: true);

        $this->actingAs($user)
            ->postJson("/api/admin/leadsheets/{$this->sheet->id}/transcribe-stem", [
                'session' => 'deadbeef', 'stems' => ['guitar'],
            ])
            ->assertStatus(409)
            ->assertJson(['success' => false, 'fixed' => true]);
    }

    public function test_transcribe_stem_requires_session(): void
    {
        $user = User::first() ?? User::factory()->create();
        $this->sheet = $this->makeAudioSheet();

        $this->actingAs($user)
            ->postJson("/api/admin/leadsheets/{$this->sheet->id}/transcribe-stem", ['stems' => ['guitar']])
            ->assertStatus(422); // 'session' required
    }

    public function test_transcribe_stem_missing_session_fails_gracefully(): void
    {
        $user = User::first() ?? User::factory()->create();
        $this->sheet = $this->makeAudioSheet();

        // A well-formed request whose session dir doesn't exist: the service
        // returns success:false, which the controller surfaces as a 500 error
        // JSON (no Python is invoked — stemSessionDir() check fails first).
        $this->actingAs($user)
            ->postJson("/api/admin/leadsheets/{$this->sheet->id}/transcribe-stem", [
                'session' => 'nonexistent-session-xyz', 'stems' => ['guitar'],
            ])
            ->assertStatus(500)
            ->assertJson(['success' => false]);
    }
}

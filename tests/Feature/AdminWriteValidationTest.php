<?php

namespace Tests\Feature;

use App\Models\ChordDiagram;
use App\Models\ChordProgression;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Audit #7 follow-up — FormRequest coverage for ChordController,
 * ProgressionController, RhythmPatternController, and
 * ProgressionBuilderController (the admin controllers not touched by the
 * earlier Leadsheet* pass). Verifies the new FormRequests actually reject
 * malformed/missing input on the real routes.
 */
class AdminWriteValidationTest extends TestCase
{
    use RefreshDatabase;

    private function instructor(): User
    {
        $user = User::create([
            'name'     => 'Test User',
            'email'    => 'test-' . uniqid() . '@example.com',
            'password' => bcrypt('secret'),
        ]);
        $user->forceFill(['is_instructor' => true])->save();

        return $user;
    }

    // ── ChordController ─────────────────────────────────────────────

    public function test_chord_store_rejects_missing_required_fields(): void
    {
        $response = $this->actingAs($this->instructor())
            ->post('/admin/chords', []);

        $response->assertStatus(302)->assertSessionHasErrors([
            'root_note', 'quality', 'voicing_category', 'root_string', 'start_fret', 'diagram_data',
        ]);
    }

    public function test_chord_update_description_rejects_overlong_description(): void
    {
        $chord = ChordDiagram::create([
            'slug' => 'test-chord', 'name' => 'Test', 'root_note' => 'C', 'quality' => 'maj',
            'start_fret' => 1, 'diagram_data' => '{}',
        ]);

        $response = $this->actingAs($this->instructor())
            ->postJson("/api/admin/chords/{$chord->id}/description", [
                'description' => str_repeat('x', 10001),
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['description']);
    }

    public function test_chord_store_alias_rejects_missing_fields(): void
    {
        $chord = ChordDiagram::create([
            'slug' => 'test-chord-2', 'name' => 'Test 2', 'root_note' => 'C', 'quality' => 'maj',
            'start_fret' => 1, 'diagram_data' => '{}',
        ]);

        $response = $this->actingAs($this->instructor())
            ->postJson("/api/admin/chords/{$chord->id}/aliases", []);

        $response->assertStatus(422)->assertJsonValidationErrors(['alt_root_note', 'alt_quality']);
    }

    // ── ProgressionController ───────────────────────────────────────

    public function test_progression_store_rejects_missing_required_fields(): void
    {
        $response = $this->actingAs($this->instructor())
            ->post('/admin/progressions', []);

        $response->assertStatus(302)->assertSessionHasErrors([
            'name', 'category', 'numerals', 'tonality', 'match_mode',
        ]);
    }

    public function test_progression_store_rejects_invalid_category(): void
    {
        $response = $this->actingAs($this->instructor())
            ->post('/admin/progressions', [
                'name' => 'Test Progression',
                'category' => 'not-a-real-category',
                'numerals' => 'IIm7,V7,Imaj7',
                'tonality' => 'both',
                'match_mode' => 'strict',
            ]);

        $response->assertStatus(302)->assertSessionHasErrors(['category']);
    }

    public function test_progression_update_description_rejects_overlong_intro(): void
    {
        $progression = ChordProgression::create([
            'name' => 'Test', 'slug' => 'test-progression', 'category' => 'jazz',
            'numerals' => 'IIm7,V7,Imaj7', 'tonality' => 'both', 'match_mode' => 'strict',
        ]);

        $response = $this->actingAs($this->instructor())
            ->postJson("/admin/progressions/{$progression->id}/description", [
                'intro' => str_repeat('x', 10001),
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['intro']);
    }

    // ── RhythmPatternController ─────────────────────────────────────

    public function test_rhythm_store_rejects_missing_required_fields(): void
    {
        $response = $this->actingAs($this->instructor())
            ->postJson('/admin/rhythms', []);

        $response->assertStatus(422)->assertJsonValidationErrors(['name', 'rhythm_pattern']);
    }

    public function test_rhythm_store_rejects_invalid_pattern_characters(): void
    {
        $response = $this->actingAs($this->instructor())
            ->postJson('/admin/rhythms', [
                'name' => 'Test Rhythm',
                'rhythm_pattern' => 'not-a-valid-pattern!!',
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['rhythm_pattern']);
    }

    // ── ProgressionBuilderController ────────────────────────────────

    public function test_update_setting_rejects_unknown_key(): void
    {
        $response = $this->actingAs($this->instructor())
            ->postJson('/api/admin/progressions/builder/settings', [
                'key' => 'not_a_real_setting',
                'value' => true,
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['key']);
    }

    public function test_update_setting_rejects_wrong_value_type(): void
    {
        $response = $this->actingAs($this->instructor())
            ->postJson('/api/admin/progressions/builder/settings', [
                'key' => 'repeated_chord_reuse',
                'value' => ['not', 'a', 'boolean'],
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['value']);
    }

    public function test_update_setting_accepts_valid_boolean_setting(): void
    {
        $response = $this->actingAs($this->instructor())
            ->postJson('/api/admin/progressions/builder/settings', [
                'key' => 'repeated_chord_reuse',
                'value' => true,
            ]);

        $response->assertStatus(200)->assertJson(['success' => true]);
    }

    public function test_save_archetype_rejects_missing_name(): void
    {
        $response = $this->actingAs($this->instructor())
            ->postJson('/api/admin/progressions/builder/archetypes', []);

        $response->assertStatus(422)->assertJsonValidationErrors(['name']);
    }

    public function test_load_archetype_rejects_missing_slug(): void
    {
        $response = $this->actingAs($this->instructor())
            ->postJson('/api/admin/progressions/builder/archetypes/load', []);

        $response->assertStatus(422)->assertJsonValidationErrors(['slug']);
    }

    public function test_build_voicings_rejects_invalid_leadsheet_id(): void
    {
        $response = $this->actingAs($this->instructor())
            ->postJson('/api/admin/progressions/build-voicings', [
                'leadsheet_id' => 999999,
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['leadsheet_id']);
    }

    public function test_build_voicings_accepts_numerals_source(): void
    {
        $response = $this->actingAs($this->instructor())
            ->postJson('/api/admin/progressions/build-voicings', [
                'numerals' => 'IIm7,V7,Imaj7',
                'key' => 'C',
            ]);

        $response->assertStatus(200)->assertJson(['success' => true]);
    }
}

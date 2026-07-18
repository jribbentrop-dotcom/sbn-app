<?php

namespace Tests\Feature;

use App\Models\Leadsheet;
use App\Models\RhythmPattern;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Audit #7 — the apply-progression / fill-voicings / apply-rhythm endpoints
 * used to accept a raw Request with no validation at all. Verifies the new
 * FormRequests (ApplyProgressionRequest, FillVoicingsRequest,
 * ApplyRhythmRequest) actually reject malformed input and accept valid input.
 */
class LeadsheetWriteValidationTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(bool $isInstructor): User
    {
        $user = User::create([
            'name'     => 'Test User',
            'email'    => 'test-' . uniqid() . '@example.com',
            'password' => bcrypt('secret'),
        ]);
        $user->forceFill(['is_instructor' => $isInstructor])->save();

        return $user;
    }

    private function instructor(): User
    {
        return $this->makeUser(true);
    }

    private function leadsheet(): Leadsheet
    {
        return Leadsheet::create([
            'title'    => 'Validation Test Song',
            'slug'     => 'validation-test-song',
            'popularity' => '',
            'song_key' => 'C',
        ]);
    }

    public function test_apply_progression_rejects_missing_selections(): void
    {
        $leadsheet = $this->leadsheet();

        $response = $this->actingAs($this->instructor())
            ->postJson("/api/admin/leadsheets/{$leadsheet->id}/apply-progression", []);

        $response->assertStatus(422)->assertJsonValidationErrors(['selections']);
    }

    public function test_apply_progression_rejects_malformed_selection_entries(): void
    {
        $leadsheet = $this->leadsheet();

        $response = $this->actingAs($this->instructor())
            ->postJson("/api/admin/leadsheets/{$leadsheet->id}/apply-progression", [
                'selections' => [
                    ['chord_name' => '', 'frets' => 'x57565'],
                ],
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['selections.0.chord_name']);
    }

    public function test_apply_progression_accepts_valid_selections(): void
    {
        $leadsheet = $this->leadsheet();

        $response = $this->actingAs($this->instructor())
            ->postJson("/api/admin/leadsheets/{$leadsheet->id}/apply-progression", [
                'selections' => [
                    ['chord_name' => 'Dm7', 'frets' => 'x57565', 'position' => 5],
                ],
                'time_signature' => '4/4',
            ]);

        $response->assertStatus(200)->assertJson(['success' => true]);
    }

    public function test_fill_voicings_rejects_invalid_extension_mode(): void
    {
        $leadsheet = $this->leadsheet();

        $response = $this->actingAs($this->instructor())
            ->postJson("/api/admin/leadsheets/{$leadsheet->id}/fill-voicings", [
                'extension_mode' => 'not-a-real-mode',
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['extension_mode']);
    }

    public function test_apply_rhythm_rejects_missing_rhythm_pattern_slug(): void
    {
        $leadsheet = $this->leadsheet();

        $response = $this->actingAs($this->instructor())
            ->postJson("/api/admin/leadsheets/{$leadsheet->id}/apply-rhythm", []);

        $response->assertStatus(422)->assertJsonValidationErrors(['rhythm_pattern_slug']);
    }

    public function test_apply_rhythm_rejects_unknown_rhythm_pattern_slug(): void
    {
        $leadsheet = $this->leadsheet();

        $response = $this->actingAs($this->instructor())
            ->postJson("/api/admin/leadsheets/{$leadsheet->id}/apply-rhythm", [
                'rhythm_pattern_slug' => 'does-not-exist',
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['rhythm_pattern_slug']);
    }

    public function test_apply_rhythm_accepts_known_rhythm_pattern_slug(): void
    {
        $leadsheet = $this->leadsheet();
        RhythmPattern::create([
            'slug' => 'gilberto-rhythm',
            'name' => 'Gilberto Rhythm',
            'rhythm_pattern' => '[]',
        ]);
        $leadsheet->update(['shortcode_content' => '[sbn_leadsheet key="C"]Cmaj7 | Dm7[/sbn_leadsheet]']);

        $response = $this->actingAs($this->instructor())
            ->postJson("/api/admin/leadsheets/{$leadsheet->id}/apply-rhythm", [
                'rhythm_pattern_slug' => 'gilberto-rhythm',
            ]);

        // Validation passed the FormRequest layer either way — a 422 past this
        // point would come from _applyRhythmCore's own "no chords found" guard,
        // not from malformed input, so accept either a real success or that guard.
        $this->assertContains($response->status(), [200, 422]);
        if ($response->status() === 422) {
            $response->assertJsonMissingValidationErrors(['rhythm_pattern_slug']);
        }
    }

    public function test_non_instructor_is_forbidden(): void
    {
        $leadsheet = $this->leadsheet();
        $user = $this->makeUser(false);

        $response = $this->actingAs($user)
            ->postJson("/api/admin/leadsheets/{$leadsheet->id}/apply-progression", [
                'selections' => [['chord_name' => 'Dm7', 'frets' => 'x57565']],
            ]);

        $response->assertStatus(403);
    }

    // ── Endpoints converted from inline validate() to FormRequest classes ──

    public function test_update_is_pro_rejects_non_boolean(): void
    {
        $leadsheet = $this->leadsheet();

        $response = $this->actingAs($this->instructor())
            ->postJson("/api/admin/leadsheets/{$leadsheet->id}/is-pro", [
                'is_pro' => 'not-a-boolean',
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['is_pro']);
    }

    public function test_update_is_pro_accepts_boolean(): void
    {
        // is_pro=true is only allowed on public_domain rows (see UpdateIsProRequest).
        $leadsheet = $this->leadsheet();
        $leadsheet->update(['license_status' => Leadsheet::LICENSE_PUBLIC_DOMAIN]);

        $response = $this->actingAs($this->instructor())
            ->postJson("/api/admin/leadsheets/{$leadsheet->id}/is-pro", [
                'is_pro' => true,
            ]);

        $response->assertStatus(200)->assertJson(['success' => true, 'is_pro' => true]);
    }

    public function test_update_is_pro_true_rejected_on_non_public_domain(): void
    {
        // The is_pro editorial switch must never be enabled on a copyrighted row —
        // the full Viewer/Cinema arrangement is only licensable for public domain.
        $leadsheet = $this->leadsheet();
        $leadsheet->update(['license_status' => Leadsheet::LICENSE_COPYRIGHTED]);

        $response = $this->actingAs($this->instructor())
            ->postJson("/api/admin/leadsheets/{$leadsheet->id}/is-pro", [
                'is_pro' => true,
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['is_pro']);
        $this->assertFalse((bool) $leadsheet->fresh()->is_pro);
    }

    public function test_update_is_pro_false_allowed_regardless_of_license(): void
    {
        // Turning is_pro OFF is always safe, even on a copyrighted leadsheet.
        $leadsheet = $this->leadsheet();
        $leadsheet->update(['license_status' => Leadsheet::LICENSE_COPYRIGHTED]);

        $response = $this->actingAs($this->instructor())
            ->postJson("/api/admin/leadsheets/{$leadsheet->id}/is-pro", [
                'is_pro' => false,
            ]);

        $response->assertStatus(200)->assertJson(['success' => true, 'is_pro' => false]);
    }

    public function test_update_status_rejects_unknown_status(): void
    {
        $leadsheet = $this->leadsheet();

        $response = $this->actingAs($this->instructor())
            ->postJson("/api/admin/leadsheets/{$leadsheet->id}/status", [
                'status' => 'not-a-real-status',
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['status']);
    }

    public function test_update_status_accepts_publish(): void
    {
        $leadsheet = $this->leadsheet();

        $response = $this->actingAs($this->instructor())
            ->postJson("/api/admin/leadsheets/{$leadsheet->id}/status", [
                'status' => 'publish',
            ]);

        $response->assertStatus(200)->assertJson(['success' => true, 'status' => 'publish']);
    }

    public function test_transpose_rejects_out_of_range_semitones(): void
    {
        $leadsheet = $this->leadsheet();

        $response = $this->actingAs($this->instructor())
            ->postJson("/admin/leadsheets/{$leadsheet->id}/transpose", [
                'semitones' => 24,
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['semitones']);
    }

    public function test_merge_song_rejects_nonexistent_source(): void
    {
        $leadsheet = $this->leadsheet();

        $response = $this->actingAs($this->instructor())
            ->postJson("/admin/leadsheets/{$leadsheet->id}/merge-song", [
                'source_leadsheet_id' => 999999,
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['source_leadsheet_id']);
    }

    public function test_remove_voicing_requires_chord_name_and_fret_string(): void
    {
        $leadsheet = $this->leadsheet();

        $response = $this->actingAs($this->instructor())
            ->postJson("/api/admin/leadsheets/{$leadsheet->id}/remove-voicing", []);

        $response->assertStatus(422)->assertJsonValidationErrors(['chord_name', 'fret_string']);
    }

    public function test_create_blank_rejects_missing_required_fields(): void
    {
        $response = $this->actingAs($this->instructor())
            ->postJson('/admin/leadsheets/create-blank', []);

        $response->assertStatus(422)->assertJsonValidationErrors(['title', 'song_key', 'tempo', 'time_signature', 'structure_mode']);
    }
}

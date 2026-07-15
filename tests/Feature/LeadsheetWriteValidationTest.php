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
}

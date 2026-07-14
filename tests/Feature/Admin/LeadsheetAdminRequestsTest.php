<?php

namespace Tests\Feature\Admin;

use App\Models\Leadsheet;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Covers the three admin write endpoints converted to FormRequests for
 * audit finding #7 (SBN-Security-Audit-2026-07-09.md): updateIsPro,
 * updateStatus, uploadBackingTrack on Admin/LeadsheetController.
 *
 * Runs against the real dev DB inside a transaction that's always rolled
 * back, so nothing here is persisted. Relies on leadsheets already present
 * per CLAUDE.md's leadsheet table (slugs below are stable references).
 */
class LeadsheetAdminRequestsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.sqlite.database' => database_path('sbn.db')]);
        DB::reconnect('sqlite');
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    private function instructor(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['is_instructor' => true])->save();

        return $user;
    }

    private function nonInstructor(): User
    {
        return User::factory()->create();
    }

    public function test_non_instructor_is_forbidden_on_all_three_endpoints(): void
    {
        $user = $this->nonInstructor();
        $leadsheet = Leadsheet::where('slug', 'the-girl-from-ipanema-1')->firstOrFail();

        $this->actingAs($user)
            ->postJson(route('api.admin.leadsheets.updateIsPro', $leadsheet), ['is_pro' => true])
            ->assertForbidden();

        $this->actingAs($user)
            ->postJson(route('api.admin.leadsheets.updateStatus', $leadsheet), ['status' => 'draft'])
            ->assertForbidden();

        $this->actingAs($user)
            ->postJson(route('api.admin.leadsheets.uploadBackingTrack', $leadsheet), [
                'kind' => 'backing',
                'track' => UploadedFile::fake()->create('track.mp3', 100),
            ])
            ->assertForbidden();
    }

    public function test_is_pro_true_is_rejected_on_a_copyrighted_leadsheet(): void
    {
        $leadsheet = Leadsheet::where('slug', 'the-girl-from-ipanema-1')->firstOrFail();
        $this->assertSame('copyrighted', $leadsheet->license_status);
        $this->assertFalse((bool) $leadsheet->is_pro);

        $this->actingAs($this->instructor())
            ->postJson(route('api.admin.leadsheets.updateIsPro', $leadsheet), ['is_pro' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_pro');

        $this->assertFalse((bool) $leadsheet->fresh()->is_pro);
    }

    public function test_is_pro_true_is_allowed_on_a_public_domain_leadsheet(): void
    {
        $leadsheet = Leadsheet::where('slug', 'canon-in-d')->firstOrFail();
        $this->assertSame('public_domain', $leadsheet->license_status);

        $this->actingAs($this->instructor())
            ->postJson(route('api.admin.leadsheets.updateIsPro', $leadsheet), ['is_pro' => true])
            ->assertOk()
            ->assertJson(['success' => true, 'is_pro' => true]);

        $this->assertTrue((bool) $leadsheet->fresh()->is_pro);
    }

    public function test_is_pro_false_is_always_allowed_regardless_of_license(): void
    {
        $leadsheet = Leadsheet::where('slug', 'the-girl-from-ipanema-1')->firstOrFail();

        $this->actingAs($this->instructor())
            ->postJson(route('api.admin.leadsheets.updateIsPro', $leadsheet), ['is_pro' => false])
            ->assertOk()
            ->assertJson(['success' => true, 'is_pro' => false]);
    }

    public function test_update_status_accepts_only_draft_or_publish(): void
    {
        $leadsheet = Leadsheet::where('slug', 'wave')->firstOrFail();
        $instructor = $this->instructor();

        $this->actingAs($instructor)
            ->postJson(route('api.admin.leadsheets.updateStatus', $leadsheet), ['status' => 'draft'])
            ->assertOk()
            ->assertJson(['success' => true, 'status' => 'draft']);

        $this->actingAs($instructor)
            ->postJson(route('api.admin.leadsheets.updateStatus', $leadsheet), ['status' => 'deleted'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_backing_track_rejects_an_invalid_kind(): void
    {
        $leadsheet = Leadsheet::where('slug', 'wave')->firstOrFail();

        $this->actingAs($this->instructor())
            ->postJson(route('api.admin.leadsheets.uploadBackingTrack', $leadsheet), [
                'kind' => 'vocals',
                'track' => UploadedFile::fake()->create('track.mp3', 100),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('kind');
    }

    public function test_backing_track_rejects_a_disallowed_file_type(): void
    {
        $leadsheet = Leadsheet::where('slug', 'wave')->firstOrFail();

        $this->actingAs($this->instructor())
            ->postJson(route('api.admin.leadsheets.uploadBackingTrack', $leadsheet), [
                'kind' => 'backing',
                'track' => UploadedFile::fake()->create('track.exe', 100),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('track');
    }
}

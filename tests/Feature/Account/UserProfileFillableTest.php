<?php

namespace Tests\Feature\Account;

use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Covers audit finding #8 (SBN-Security-Audit-2026-07-09.md): UserProfile
 * moved from `$guarded = []` to an explicit `$fillable` allowlist.
 *
 * The first two tests are a regression guard for a bug that fix initially
 * introduced: firstOrCreate(['user_id' => ...], [...]) mass-assigns via
 * create(), and Eloquent silently strips any key not in $fillable *before*
 * it reaches the model — so if `user_id` isn't fillable, every new profile
 * row fails to get its primary key. `user_id` was added back to $fillable
 * to fix this; it's safe because no controller ever mass-assigns it from
 * raw request input (always `$request->user()->id`).
 *
 * Runs against the real dev DB inside a transaction that's always rolled
 * back, so nothing here is persisted.
 */
class UserProfileFillableTest extends TestCase
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

    public function test_visiting_profile_page_creates_a_profile_row_with_its_user_id(): void
    {
        $user = User::factory()->create(['name' => 'Fresh User']);
        $this->assertNull(UserProfile::find($user->id));

        $this->actingAs($user)->get(route('account.profile'))->assertOk();

        $profile = UserProfile::find($user->id);
        $this->assertNotNull($profile, 'firstOrCreate must still persist user_id as the primary key');
        $this->assertSame('Fresh User', $profile->display_name);
    }

    public function test_update_profile_persists_the_allowed_fields(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patch(route('account.profile.update'), [
            'display_name' => 'New Name',
            'bio'          => 'A short bio.',
            'public'       => true,
        ])->assertRedirect();

        $profile = UserProfile::find($user->id);
        $this->assertSame('New Name', $profile->display_name);
        $this->assertSame('A short bio.', $profile->bio);
        $this->assertTrue((bool) $profile->public);
    }

    public function test_mass_assignment_is_restricted_to_the_allowlist(): void
    {
        $user = User::factory()->create();

        $profile = UserProfile::create([
            'user_id'      => $user->id,
            'display_name' => 'Allowed',
            'avatar_path'  => 'attacker/injected.jpg',
        ]);

        $this->assertSame($user->id, $profile->user_id);
        $this->assertSame('Allowed', $profile->display_name);
        $this->assertNull($profile->avatar_path, 'avatar_path is not fillable and must not be mass-assignable');
    }
}

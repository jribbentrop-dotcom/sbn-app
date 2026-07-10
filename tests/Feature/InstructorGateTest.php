<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The instructor gate content-negotiates its rejection: API callers get a
 * clean 403, web callers a redirect. Guards EnsureIsInstructor.
 */
class InstructorGateTest extends TestCase
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

    private function nonInstructor(): User
    {
        return User::create([
            'name' => 'Plain', 'email' => 'plain-'.uniqid().'@example.test',
            'password' => bcrypt('x'), 'is_instructor' => false,
        ]);
    }

    public function test_api_admin_returns_403_json_for_non_instructor(): void
    {
        $this->actingAs($this->nonInstructor())
            ->getJson('/api/admin/rhythms')         // a real api/admin route
            ->assertForbidden();                     // 403, not a 302 redirect
    }

    public function test_web_admin_still_redirects_non_instructor(): void
    {
        $this->actingAs($this->nonInstructor())
            ->get('/admin')
            ->assertRedirect(route('account.dashboard'));
    }
}

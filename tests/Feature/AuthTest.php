<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Auth tests run against the real sbn.db connection (the schema is not fully
 * migration-defined, so RefreshDatabase against :memory: is not usable here —
 * same approach as LeadsheetLookupTest). Created users + reset tokens are
 * cleaned up in tearDown.
 */
class AuthTest extends TestCase
{
    private const TEST_EMAILS = [
        'auth-test-new@example.com',
        'auth-test-customer@example.com',
        'auth-test-instructor@example.com',
        'auth-test-reset@example.com',
        'auth-test-misc@example.com',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.sqlite.database' => database_path('sbn.db')]);
        DB::reconnect('sqlite');
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        User::whereIn('email', self::TEST_EMAILS)->delete();
        DB::table('password_reset_tokens')->whereIn('email', self::TEST_EMAILS)->delete();
    }

    private function makeUser(string $email, bool $instructor = false, string $password = 'secret-pass-1'): User
    {
        $user = User::create([
            'name' => 'Auth Test',
            'email' => $email,
            'password' => Hash::make($password),
        ]);

        // is_instructor is intentionally not mass-assignable on the model.
        $user->forceFill(['is_instructor' => $instructor])->save();

        return $user;
    }

    public function test_guest_can_view_login_page(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Auth/Login'));
    }

    public function test_guest_can_view_register_page(): void
    {
        $this->get('/register')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Auth/Register'));
    }

    public function test_authenticated_user_is_redirected_away_from_login(): void
    {
        $user = $this->makeUser('auth-test-misc@example.com');

        // The `guest` middleware redirects authenticated users away from the
        // login page before the controller runs (to the framework HOME path).
        $this->actingAs($user)->get('/login')->assertRedirect('/');
    }

    public function test_registration_creates_user_and_logs_in(): void
    {
        $response = $this->post('/register', [
            'name' => 'New Student',
            'email' => 'auth-test-new@example.com',
            'password' => 'password1234',
            'password_confirmation' => 'password1234',
        ]);

        $response->assertRedirect(route('account.dashboard'));
        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'auth-test-new@example.com']);
    }

    public function test_registration_requires_matching_password_confirmation(): void
    {
        $this->post('/register', [
            'name' => 'New Student',
            'email' => 'auth-test-new@example.com',
            'password' => 'password1234',
            'password_confirmation' => 'mismatch12345',
        ])->assertSessionHasErrors('password');

        $this->assertGuest();
    }

    public function test_customer_login_redirects_to_account(): void
    {
        $user = $this->makeUser('auth-test-customer@example.com', instructor: false);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'secret-pass-1',
        ])->assertRedirect(route('account.dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_instructor_login_redirects_to_admin(): void
    {
        $user = $this->makeUser('auth-test-instructor@example.com', instructor: true);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'secret-pass-1',
        ])->assertRedirect(route('admin.dashboard'));
    }

    public function test_login_fails_with_bad_credentials(): void
    {
        $user = $this->makeUser('auth-test-misc@example.com');

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_forgot_password_sends_reset_notification(): void
    {
        Notification::fake();
        $user = $this->makeUser('auth-test-reset@example.com');

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHas('status');

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_password_can_be_reset_with_valid_token(): void
    {
        $user = $this->makeUser('auth-test-reset@example.com');

        // Generate a real token via the broker, then drive the reset endpoint.
        $token = Password::broker()->createToken($user);

        $response = $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'new-password-12',
            'password_confirmation' => 'new-password-12',
        ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('status');

        $this->assertTrue(Auth::validate([
            'email' => $user->email,
            'password' => 'new-password-12',
        ]));
    }

    public function test_logout_ends_session(): void
    {
        $user = $this->makeUser('auth-test-misc@example.com');

        $this->actingAs($user)->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();
    }
}

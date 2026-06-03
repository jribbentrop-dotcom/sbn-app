<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\Payments\FakeProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Exercises the provider-agnostic payment pipeline end-to-end via the
 * FakeProvider — no real account. Runs against sbn.db with per-test cleanup
 * (same approach as AuthTest; RefreshDatabase/:memory: is unusable here).
 */
class PaymentWebhookTest extends TestCase
{
    private const EMAIL = 'pay-test@example.com';

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.sqlite.database' => database_path('sbn.db')]);
        DB::reconnect('sqlite');
        $this->secret = config('payments.fake.signing_secret', 'fake-secret');
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $userIds = User::where('email', self::EMAIL)->pluck('id');
        $orderIds = Order::where('guest_email', self::EMAIL)->pluck('id');

        DB::table('course_user')->whereIn('order_id', $orderIds)->delete();
        DB::table('course_user')->whereIn('user_id', $userIds)->delete();
        DB::table('sbn_order_items')->whereIn('order_id', $orderIds)->delete();
        DB::table('sbn_download_grants')->whereIn('order_id', $orderIds)->delete();
        Order::whereIn('id', $orderIds)->delete();
        Course::where('slug', 'pay-test-course')->delete();
        Product::where('slug', 'pay-test-product')->delete();
        User::whereIn('id', $userIds)->delete();
    }

    /** @return array{0:User,1:Course,2:Product} */
    private function scaffold(): array
    {
        $product = Product::create([
            'slug' => 'pay-test-product',
            'title' => 'Pay Test Product',
            'price_cents' => 1500,
            'status' => 'published',
            'published_at' => now(),
        ]);

        $course = Course::create([
            'slug' => 'pay-test-course',
            'title' => 'Pay Test Course',
            'is_free' => false,
            'product_id' => $product->id,
        ]);

        $user = User::create([
            'name' => 'Pay Test',
            'email' => self::EMAIL,
            'password' => Hash::make('secret-pass-1'),
        ]);

        return [$user, $course, $product];
    }

    private function makeOrder(Product $product, ?User $user = null, string $status = Order::STATUS_PENDING_PAYMENT): Order
    {
        $order = Order::create([
            'user_id' => $user?->id,
            'guest_email' => self::EMAIL,
            'total_cents' => $product->price_cents,
            'display_currency' => 'EUR',
            'status' => $status,
            'token' => Str::random(32),
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'title_snapshot' => $product->title,
            'price_cents_snapshot' => $product->price_cents,
            'quantity' => 1,
        ]);
        return $order;
    }

    private function fireWebhook(Order $order, string $event, ?string $secret = null): \Illuminate\Testing\TestResponse
    {
        $payload = [
            'event' => $event,
            'provider_order_id' => 'fake_' . $order->id,
            'email' => $order->guest_email,
            'custom_data' => ['order_id' => $order->id],
        ];
        $signed = FakeProvider::signedPayload($payload, $secret ?? $this->secret);

        return $this->call(
            'POST',
            '/webhooks/payments',
            [],
            [],
            [],
            ['HTTP_X-Fake-Signature' => $signed['signature'], 'CONTENT_TYPE' => 'application/json'],
            $signed['body'],
        );
    }

    public function test_paid_webhook_marks_order_paid_and_grants_course(): void
    {
        [$user, $course, $product] = $this->scaffold();
        $order = $this->makeOrder($product, $user);

        $this->fireWebhook($order, 'order_paid')->assertOk();

        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        $this->assertTrue($user->fresh()->owns($course));
        $this->assertDatabaseHas('sbn_download_grants', ['order_id' => $order->id, 'product_id' => $product->id]);
    }

    public function test_paid_webhook_is_idempotent(): void
    {
        [$user, $course, $product] = $this->scaffold();
        $order = $this->makeOrder($product, $user);

        $this->fireWebhook($order, 'order_paid')->assertOk();
        $this->fireWebhook($order, 'order_paid')->assertOk();

        $this->assertSame(1, DB::table('sbn_download_grants')->where('order_id', $order->id)->count());
    }

    public function test_refund_webhook_revokes_course(): void
    {
        [$user, $course, $product] = $this->scaffold();
        $order = $this->makeOrder($product, $user);

        $this->fireWebhook($order, 'order_paid')->assertOk();
        $this->assertTrue($user->fresh()->owns($course));

        $this->fireWebhook($order, 'order_refunded')->assertOk();

        $this->assertSame(Order::STATUS_REFUNDED, $order->fresh()->status);
        $this->assertFalse($user->fresh()->owns($course));
    }

    public function test_bad_signature_is_rejected(): void
    {
        [$user, $course, $product] = $this->scaffold();
        $order = $this->makeOrder($product, $user);

        $this->fireWebhook($order, 'order_paid', secret: 'wrong-secret')->assertStatus(403);
        $this->assertSame(Order::STATUS_PENDING_PAYMENT, $order->fresh()->status);
    }

    public function test_guest_order_claimed_on_registration(): void
    {
        [$existingUser, $course, $product] = $this->scaffold();
        // Remove the pre-made user so registration creates a fresh one with the same email.
        $existingUser->delete();

        // Guest pays (no user_id).
        $order = $this->makeOrder($product, user: null);
        $this->fireWebhook($order, 'order_paid')->assertOk();
        $this->assertNull($order->fresh()->user_id);

        // Registering with the same email claims the paid order + grants access.
        $this->post('/register', [
            'name' => 'Pay Test',
            'email' => self::EMAIL,
            'password' => 'password1234',
            'password_confirmation' => 'password1234',
        ])->assertRedirect(route('account.dashboard'));

        $user = User::where('email', self::EMAIL)->first();
        $this->assertSame($user->id, $order->fresh()->user_id);
        $this->assertTrue($user->owns($course));
    }
}

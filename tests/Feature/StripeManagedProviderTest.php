<?php

namespace Tests\Feature;

use App\Services\Payments\PaymentEvent;
use App\Services\Payments\StripeManagedProvider;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Unit-level tests for StripeManagedProvider's event-mapping and signature
 * verification. The full paid→grant pipeline is covered provider-agnostically
 * by PaymentWebhookTest (against FakeProvider) — we don't duplicate that here.
 *
 * These tests construct the provider directly with a dummy API key + a known
 * signing secret. No DB, no HTTP, no real Stripe network calls.
 */
class StripeManagedProviderTest extends TestCase
{
    private const SIGNING_SECRET = 'whsec_test_signing_secret_for_unit_tests';

    private StripeManagedProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        // Dummy API key — no network calls are made in these tests.
        $this->provider = new StripeManagedProvider('sk_test_dummy', self::SIGNING_SECRET);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build an Illuminate Request from a raw JSON array, optionally with a
     * pre-computed Stripe-Signature header.
     */
    private function makeRequest(array $payload, string $signature = ''): Request
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $request = Request::create('/webhooks/payments', 'POST', [], [], [], [], $body);
        $request->headers->set('Content-Type', 'application/json');

        if ($signature !== '') {
            $request->headers->set('Stripe-Signature', $signature);
        }

        return $request;
    }

    /**
     * Compute a valid Stripe webhook signature header for the given body + secret.
     *
     * Stripe's scheme: t=<unix_timestamp>,v1=<HMAC-SHA256(secret, "<t>.<body>")>
     */
    private function stripeSignatureHeader(string $body, string $secret, int $timestamp): string
    {
        $signed  = "{$timestamp}.{$body}";
        $hmac    = hash_hmac('sha256', $signed, $secret);
        return "t={$timestamp},v1={$hmac}";
    }

    // -------------------------------------------------------------------------
    // parseWebhook — event type mapping
    // -------------------------------------------------------------------------

    public function test_checkout_session_completed_maps_to_purchase_completed(): void
    {
        $payload = [
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id'               => 'cs_test_abc123',
                    'payment_intent'   => 'pi_test_xyz',
                    'metadata'         => ['order_id' => '123'],
                    'customer_details' => ['email' => 'buyer@example.com'],
                ],
            ],
        ];

        $event = $this->provider->parseWebhook($this->makeRequest($payload));

        $this->assertSame(PaymentEvent::PURCHASE_COMPLETED, $event->type);
        $this->assertSame(123, $event->ourOrderId);
        $this->assertSame('cs_test_abc123', $event->providerOrderId);
        $this->assertSame('buyer@example.com', $event->email);
    }

    public function test_charge_refunded_maps_to_purchase_refunded(): void
    {
        $payload = [
            'type' => 'charge.refunded',
            'data' => [
                'object' => [
                    'id'       => 'ch_test_refund456',
                    'metadata' => ['order_id' => '123'],
                    'receipt_email' => 'buyer@example.com',
                ],
            ],
        ];

        $event = $this->provider->parseWebhook($this->makeRequest($payload));

        $this->assertSame(PaymentEvent::PURCHASE_REFUNDED, $event->type);
        $this->assertSame(123, $event->ourOrderId);
        $this->assertSame('ch_test_refund456', $event->providerOrderId);
    }

    public function test_refund_created_maps_to_purchase_refunded(): void
    {
        $payload = [
            'type' => 'refund.created',
            'data' => [
                'object' => [
                    'id'             => 're_test_789',
                    'payment_intent' => 'pi_test_xyz',
                    'metadata'       => ['order_id' => '456'],
                ],
            ],
        ];

        $event = $this->provider->parseWebhook($this->makeRequest($payload));

        $this->assertSame(PaymentEvent::PURCHASE_REFUNDED, $event->type);
        $this->assertSame(456, $event->ourOrderId);
    }

    public function test_unrelated_event_type_maps_to_unhandled(): void
    {
        $payload = [
            'type' => 'customer.subscription.updated',
            'data' => ['object' => ['id' => 'sub_123']],
        ];

        $event = $this->provider->parseWebhook($this->makeRequest($payload));

        $this->assertSame(PaymentEvent::UNHANDLED, $event->type);
        $this->assertFalse($event->isHandled());
        $this->assertNull($event->ourOrderId);
    }

    public function test_parse_webhook_without_order_id_in_metadata_returns_null_our_order_id(): void
    {
        $payload = [
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id'       => 'cs_test_no_meta',
                    'metadata' => [],
                ],
            ],
        ];

        $event = $this->provider->parseWebhook($this->makeRequest($payload));

        $this->assertSame(PaymentEvent::PURCHASE_COMPLETED, $event->type);
        $this->assertNull($event->ourOrderId);
    }

    public function test_email_falls_back_to_customer_email_field(): void
    {
        $payload = [
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id'             => 'cs_test_fallback',
                    'metadata'       => ['order_id' => '99'],
                    'customer_email' => 'fallback@example.com',
                    // no customer_details key
                ],
            ],
        ];

        $event = $this->provider->parseWebhook($this->makeRequest($payload));

        $this->assertSame('fallback@example.com', $event->email);
    }

    public function test_raw_payload_is_preserved_on_event(): void
    {
        $payload = [
            'type' => 'checkout.session.completed',
            'data' => ['object' => ['id' => 'cs_raw', 'metadata' => ['order_id' => '1']]],
        ];

        $event = $this->provider->parseWebhook($this->makeRequest($payload));

        $this->assertSame($payload, $event->raw);
    }

    // -------------------------------------------------------------------------
    // verifySignature
    // -------------------------------------------------------------------------

    public function test_valid_stripe_signature_returns_true(): void
    {
        $payload   = json_encode(['type' => 'checkout.session.completed', 'data' => ['object' => []]]);
        $timestamp = time();
        $sig       = $this->stripeSignatureHeader($payload, self::SIGNING_SECRET, $timestamp);

        $request = Request::create('/webhooks/payments', 'POST', [], [], [], [], $payload);
        $request->headers->set('Content-Type', 'application/json');
        $request->headers->set('Stripe-Signature', $sig);

        $this->assertTrue($this->provider->verifySignature($request));
    }

    public function test_tampered_signature_returns_false(): void
    {
        $payload = json_encode(['type' => 'checkout.session.completed']);

        $request = Request::create('/webhooks/payments', 'POST', [], [], [], [], $payload);
        $request->headers->set('Content-Type', 'application/json');
        $request->headers->set('Stripe-Signature', 't=12345,v1=deaddead');

        $this->assertFalse($this->provider->verifySignature($request));
    }

    public function test_missing_signature_header_returns_false(): void
    {
        $request = Request::create('/webhooks/payments', 'POST', [], [], [], [],
            json_encode(['type' => 'ping'])
        );
        $request->headers->set('Content-Type', 'application/json');
        // No Stripe-Signature header.

        $this->assertFalse($this->provider->verifySignature($request));
    }

    public function test_wrong_signing_secret_returns_false(): void
    {
        $payload   = json_encode(['type' => 'checkout.session.completed']);
        $timestamp = time();
        // Sign with a DIFFERENT secret than the provider was constructed with.
        $sig = $this->stripeSignatureHeader($payload, 'whsec_wrong_secret', $timestamp);

        $request = Request::create('/webhooks/payments', 'POST', [], [], [], [], $payload);
        $request->headers->set('Content-Type', 'application/json');
        $request->headers->set('Stripe-Signature', $sig);

        $this->assertFalse($this->provider->verifySignature($request));
    }
}

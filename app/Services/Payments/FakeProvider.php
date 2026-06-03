<?php

namespace App\Services\Payments;

use App\Models\Order;
use Illuminate\Http\Request;

/**
 * A no-account payment provider for local dev + tests.
 *
 * createCheckout() returns a local route that simulates the hosted checkout and
 * fires a signed webhook back at /webhooks/payments — exercising the entire
 * paid → grant pipeline without any real provider. Webhooks are HMAC-signed
 * with the configured fake secret via the `X-Fake-Signature` header.
 */
class FakeProvider implements PaymentProvider
{
    public function __construct(
        private readonly string $signingSecret,
    ) {}

    public function createCheckout(Order $order): string
    {
        // Local simulation page; carries our order token so the dev flow can
        // post a fake webhook for this exact order.
        return route('payments.fake.checkout', ['token' => $order->token]);
    }

    public function verifySignature(Request $request): bool
    {
        $given = (string) $request->header('X-Fake-Signature', '');
        $expected = hash_hmac('sha256', $request->getContent(), $this->signingSecret);

        return $given !== '' && hash_equals($expected, $given);
    }

    public function parseWebhook(Request $request): PaymentEvent
    {
        $payload = $request->json()->all();

        $type = match ($payload['event'] ?? '') {
            'order_paid'     => PaymentEvent::PURCHASE_COMPLETED,
            'order_refunded' => PaymentEvent::PURCHASE_REFUNDED,
            default          => PaymentEvent::UNHANDLED,
        };

        return new PaymentEvent(
            type: $type,
            ourOrderId: isset($payload['custom_data']['order_id'])
                ? (int) $payload['custom_data']['order_id']
                : null,
            providerOrderId: $payload['provider_order_id'] ?? null,
            email: $payload['email'] ?? null,
            raw: $payload,
        );
    }

    /**
     * Helper for the dev simulation route + tests: build a correctly-signed
     * webhook payload + signature header for an order.
     */
    public static function signedPayload(array $payload, string $secret): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return [
            'body'      => $body,
            'signature' => hash_hmac('sha256', $body, $secret),
        ];
    }
}

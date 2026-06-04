<?php

namespace App\Services\Payments;

use App\Models\Order;
use Illuminate\Http\Request;
use Stripe\StripeClient;
use Stripe\Webhook;

/**
 * Concrete PaymentProvider backed by Stripe Checkout (Managed Payments / MoR).
 *
 * Managed Payments (MoR / tax-handling) requires `managed_payments[enabled]=true`
 * on every Checkout Session — see docs.stripe.com/payments/managed-payments/set-up.
 * Tax-inclusive pricing is enforced at the Price object level (tax_behavior=inclusive)
 * by the `sbn:sync-stripe-products` artisan command, NOT by a session-level param.
 *
 * We use the StripeClient instance API (not the global Stripe::setApiKey static)
 * so the key is scoped to this object and tests can inject a dummy key without
 * touching global state.
 */
class StripeManagedProvider implements PaymentProvider
{
    private StripeClient $client;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $signingSecret,
    ) {
        $this->client = new StripeClient($this->apiKey);
    }

    /**
     * Create a Stripe Checkout Session for the order and return its hosted URL.
     *
     * We embed our order id in both the session metadata AND payment_intent_data.metadata
     * so that it survives on the PaymentIntent/Charge objects too — meaning
     * charge.refunded events (which surface on the Charge, not the Session)
     * can still resolve the order without a DB lookup by provider_order_id.
     *
     * @throws \RuntimeException if any line-item product has no Stripe Price ID configured.
     */
    public function createCheckout(Order $order): string
    {
        $lineItems = [];

        foreach ($order->items as $item) {
            $paymentRef = $item->product->payment_ref ?? null;

            if (empty($paymentRef)) {
                throw new \RuntimeException(
                    "Product \"{$item->product->title}\" (id={$item->product_id}) has no Stripe Price ID (payment_ref). " .
                    'Configure the price_... ID in the product admin before accepting live payments.'
                );
            }

            $lineItems[] = [
                'price'    => $paymentRef,
                'quantity' => $item->quantity,
            ];
        }

        $session = $this->client->checkout->sessions->create([
            'mode'                => 'payment',
            'line_items'          => $lineItems,
            'success_url'         => route('order.success', ['token' => $order->token]),
            'cancel_url'          => route('cart.show'),
            'customer_email'      => $order->guest_email,
            // client_reference_id lets us look up the session in the dashboard by our order id.
            'client_reference_id' => (string) $order->id,
            // metadata on the Session itself (available in checkout.session.completed).
            'metadata'            => [
                'order_id' => $order->id,
            ],
            // Propagate our order_id to the PaymentIntent (and therefore to any
            // Charge created from it) so refund webhooks carry the same metadata.
            'payment_intent_data' => [
                'metadata' => [
                    'order_id' => $order->id,
                ],
            ],
            // Enable Stripe as Merchant of Record for tax collection.
            // Tax-inclusive pricing (so Stripe does NOT add tax on top) is enforced
            // at the Price object level (tax_behavior=inclusive) by sbn:sync-stripe-products.
            'managed_payments'    => ['enabled' => true],
        ]);

        return $session->url;
    }

    /**
     * Verify the Stripe webhook signature using the signing secret.
     */
    public function verifySignature(Request $request): bool
    {
        try {
            Webhook::constructEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature', ''),
                $this->signingSecret,
            );
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Parse a Stripe webhook payload into a normalized PaymentEvent.
     *
     * Event mapping:
     *  - checkout.session.completed  → PURCHASE_COMPLETED
     *  - charge.refunded             → PURCHASE_REFUNDED
     *  - refund.created              → PURCHASE_REFUNDED  (newer refund API)
     *  - everything else             → UNHANDLED
     *
     * ourOrderId comes from metadata.order_id on the data object (set at
     * checkout creation time on both the Session and the PaymentIntent).
     */
    public function parseWebhook(Request $request): PaymentEvent
    {
        $payload = json_decode($request->getContent(), true) ?? [];

        $eventType  = $payload['type']              ?? '';
        $dataObject = $payload['data']['object']    ?? [];

        $type = match ($eventType) {
            'checkout.session.completed'        => PaymentEvent::PURCHASE_COMPLETED,
            'charge.refunded', 'refund.created' => PaymentEvent::PURCHASE_REFUNDED,
            default                             => PaymentEvent::UNHANDLED,
        };

        // order_id is stored in metadata on both the Session and Charge objects.
        $rawOrderId = $dataObject['metadata']['order_id'] ?? null;
        $ourOrderId = ($rawOrderId !== null && $rawOrderId !== '') ? (int) $rawOrderId : null;

        // Use the Stripe object id as the provider reference; fall back to
        // payment_intent if the object is a refund that doesn't have its own id.
        $providerOrderId = $dataObject['id']
            ?? $dataObject['payment_intent']
            ?? null;

        // Email lives in different places depending on the event type.
        $email = $dataObject['customer_details']['email']
            ?? $dataObject['customer_email']
            ?? null;

        return new PaymentEvent(
            type:            $type,
            ourOrderId:      $ourOrderId,
            providerOrderId: $providerOrderId ? (string) $providerOrderId : null,
            email:           $email,
            raw:             $payload,
        );
    }
}

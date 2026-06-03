<?php

namespace App\Services\Payments;

use App\Models\Order;
use Illuminate\Http\Request;

/**
 * The single seam between the app and the Merchant-of-Record payment provider.
 *
 * Concrete impls: FakeProvider (dev/test), and later PaddleProvider /
 * StripeManagedProvider. Nothing else in the app knows the provider — the
 * grant pipeline (CourseAccessService) is entirely provider-neutral.
 */
interface PaymentProvider
{
    /**
     * Create a hosted/overlay checkout for the given order and return a URL the
     * frontend opens. The provider must carry our order id back in its webhook
     * (via custom_data / metadata) so parseWebhook() can reconcile.
     */
    public function createCheckout(Order $order): string;

    /**
     * Verify the webhook request authenticity (signature / signing secret).
     */
    public function verifySignature(Request $request): bool;

    /**
     * Parse a verified webhook request into a normalized PaymentEvent.
     */
    public function parseWebhook(Request $request): PaymentEvent;
}

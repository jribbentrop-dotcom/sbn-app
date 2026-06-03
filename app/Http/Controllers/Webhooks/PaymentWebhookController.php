<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\DownloadGrant;
use App\Models\Order;
use App\Services\CourseAccessService;
use App\Services\Payments\PaymentEvent;
use App\Services\Payments\PaymentProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Single provider-agnostic webhook entry point. The bound PaymentProvider
 * verifies + normalizes the request; this controller maps the normalized event
 * onto our domain (order status, download grants, course access).
 */
class PaymentWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        PaymentProvider $payments,
        CourseAccessService $access,
    ) {
        if (!$payments->verifySignature($request)) {
            return response()->json(['error' => 'invalid signature'], 403);
        }

        $event = $payments->parseWebhook($request);

        if (!$event->isHandled()) {
            return response()->json(['ignored' => true], 200);
        }

        $order = $this->resolveOrder($event);
        if (!$order) {
            Log::warning('Payment webhook could not resolve order', ['event' => $event->type]);
            return response()->json(['error' => 'order not found'], 404);
        }

        match ($event->type) {
            PaymentEvent::PURCHASE_COMPLETED => $this->handlePaid($order, $event, $access),
            PaymentEvent::PURCHASE_REFUNDED  => $this->handleRefunded($order, $access),
            default => null,
        };

        return response()->json(['ok' => true], 200);
    }

    private function resolveOrder(PaymentEvent $event): ?Order
    {
        if ($event->ourOrderId) {
            return Order::find($event->ourOrderId);
        }
        if ($event->providerOrderId) {
            return Order::where('provider_order_id', $event->providerOrderId)->first();
        }
        return null;
    }

    private function handlePaid(Order $order, PaymentEvent $event, CourseAccessService $access): void
    {
        if ($order->status === Order::STATUS_PAID) {
            return; // idempotent — provider may retry
        }

        DB::transaction(function () use ($order, $event, $access) {
            $order->update([
                'status'            => Order::STATUS_PAID,
                'provider_order_id' => $event->providerOrderId ?? $order->provider_order_id,
            ]);

            // Download grants are created here (post-payment), not at checkout.
            foreach ($order->items as $item) {
                DownloadGrant::firstOrCreate(
                    ['order_id' => $order->id, 'product_id' => $item->product_id],
                    [
                        'token'       => Str::random(32),
                        'guest_email' => $order->guest_email,
                        'expires_at'  => now()->addDays(config('shop.download.expires_days', 7)),
                    ]
                );
            }

            // Course access only when the order is owned by an account. Guest
            // purchases are claimed later via User::claimGuestOrders() on login.
            if ($order->user) {
                $access->grantPurchase($order->user, $order);
            }
        });
    }

    private function handleRefunded(Order $order, CourseAccessService $access): void
    {
        $order->update(['status' => Order::STATUS_REFUNDED]);
        $access->revokePurchase($order);
    }
}

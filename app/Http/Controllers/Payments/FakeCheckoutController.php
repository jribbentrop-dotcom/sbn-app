<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Payments\FakeProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;

/**
 * Dev-only simulation of a hosted checkout for the FakeProvider. Renders a
 * minimal "pay" page; on pay, POSTs a correctly-signed webhook to the real
 * /webhooks/payments endpoint so the entire paid → grant pipeline runs locally
 * with no real provider account. Not registered in production (see routes).
 */
class FakeCheckoutController extends Controller
{
    public function show(string $token)
    {
        $order = Order::with('items')->where('token', $token)->firstOrFail();

        return Inertia::render('Payments/FakeCheckout', [
            'order' => [
                'token'           => $order->token,
                'total_cents'     => $order->total_cents,
                'total_formatted' => $order->total_formatted,
                'guest_email'     => $order->guest_email,
                'items'           => $order->items->map(fn ($i) => [
                    'title'    => $i->title_snapshot,
                    'quantity' => $i->quantity,
                ]),
            ],
        ]);
    }

    public function pay(Request $request, string $token)
    {
        $order = Order::where('token', $token)->firstOrFail();
        $refund = $request->boolean('refund');

        $payload = [
            'event'             => $refund ? 'order_refunded' : 'order_paid',
            'provider_order_id' => 'fake_' . $order->id,
            'email'             => $order->guest_email,
            'custom_data'       => ['order_id' => $order->id],
        ];

        $signed = FakeProvider::signedPayload($payload, config('payments.fake.signing_secret', 'fake-secret'));

        Http::withHeaders(['X-Fake-Signature' => $signed['signature']])
            ->withBody($signed['body'], 'application/json')
            ->post(route('payments.webhook'));

        return redirect()->route('order.success', ['token' => $order->token]);
    }
}

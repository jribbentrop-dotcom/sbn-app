<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\Payments\PaymentProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;

class CheckoutController extends Controller
{
    /**
     * Show checkout page with order summary.
     */
    public function show()
    {
        return Inertia::render('Shop/Checkout', [
            'meta' => [
                'title' => 'Checkout - Soul Bossa Nova',
                'description' => 'Complete your purchase.',
            ],
        ]);
    }

    /**
     * Process checkout: create a pending order and hand off to the payment
     * provider. Download grants + course access are created by the webhook once
     * the order is actually paid — never here.
     */
    public function store(Request $request, PaymentProvider $payments): \Symfony\Component\HttpFoundation\Response
    {
        $validated = $request->validate([
            'guest_email' => 'required|email',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:sbn_products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'display_currency' => 'nullable|in:EUR,USD',
        ]);

        try {
            $order = DB::transaction(function () use ($validated, $request) {
                $totalCents = 0;
                $items = [];

                foreach ($validated['items'] as $item) {
                    $product = Product::findOrFail($item['product_id']);
                    $totalCents += $product->price_cents * $item['quantity'];
                    $items[] = ['product' => $product, 'quantity' => $item['quantity']];
                }

                $order = Order::create([
                    'user_id' => $request->user()?->id,
                    'guest_email' => $validated['guest_email'],
                    'total_cents' => $totalCents,
                    'display_currency' => $validated['display_currency'] ?? 'EUR',
                    'status' => Order::STATUS_PENDING_PAYMENT,
                    'token' => Str::random(32),
                ]);

                foreach ($items as $item) {
                    OrderItem::create([
                        'order_id' => $order->id,
                        'product_id' => $item['product']->id,
                        'title_snapshot' => $item['product']->title,
                        'price_cents_snapshot' => $item['product']->price_cents,
                        'quantity' => $item['quantity'],
                    ]);
                }

                return $order;
            });

            // Hand off to the provider's hosted/overlay checkout (Apple/Google
            // Pay surface there automatically). Inertia::location triggers a
            // full-page visit to the returned URL.
            $checkoutUrl = $payments->createCheckout($order);

            return Inertia::location($checkoutUrl);
        } catch (\Exception $e) {
            return back()->withErrors(['error' => 'Checkout failed: ' . $e->getMessage()]);
        }
    }
}

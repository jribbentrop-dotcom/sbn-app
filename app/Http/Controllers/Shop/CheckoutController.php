<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\DownloadGrant;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
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
     * Process checkout (stub payment).
     */
    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'guest_email' => 'required|email',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:sbn_products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'display_currency' => 'nullable|in:EUR,USD',
        ]);

        try {
            return DB::transaction(function () use ($validated) {
                // Calculate totals
                $totalCents = 0;
                $items = [];

                foreach ($validated['items'] as $item) {
                    $product = Product::findOrFail($item['product_id']);
                    $itemTotal = $product->price_cents * $item['quantity'];
                    $totalCents += $itemTotal;

                    $items[] = [
                        'product' => $product,
                        'quantity' => $item['quantity'],
                    ];
                }

                // Create order
                $order = Order::create([
                    'guest_email' => $validated['guest_email'],
                    'total_cents' => $totalCents,
                    'display_currency' => $validated['display_currency'] ?? 'EUR',
                    'status' => 'pending_stub',
                    'token' => Str::random(32),
                ]);

                // Create order items
                foreach ($items as $item) {
                    OrderItem::create([
                        'order_id' => $order->id,
                        'product_id' => $item['product']->id,
                        'title_snapshot' => $item['product']->title,
                        'price_cents_snapshot' => $item['product']->price_cents,
                        'quantity' => $item['quantity'],
                    ]);

                    // Create download grant for each item
                    DownloadGrant::create([
                        'order_id' => $order->id,
                        'product_id' => $item['product']->id,
                        'token' => Str::random(32),
                        'guest_email' => $validated['guest_email'],
                        'expires_at' => now()->addDays(config('shop.download.expires_days', 7)),
                    ]);
                }

                return redirect()->route('order.success', ['token' => $order->token]);
            });
        } catch (\Exception $e) {
            return back()->withErrors(['error' => 'Checkout failed: ' . $e->getMessage()]);
        }
    }
}

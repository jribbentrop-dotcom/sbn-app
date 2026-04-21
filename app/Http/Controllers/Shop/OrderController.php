<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Inertia\Inertia;

class OrderController extends Controller
{
    /**
     * Show order success page with download links.
     */
    public function success(string $token)
    {
        $order = Order::with(['items.product', 'downloadGrants.product'])
            ->where('token', $token)
            ->firstOrFail();

        $downloadLinks = $order->downloadGrants->map(function ($grant) {
            return [
                'token' => $grant->token,
                'product_id' => $grant->product_id,
                'product_title' => $grant->product->title,
                'is_valid' => $grant->is_valid,
                'downloads_remaining' => $grant->downloads_remaining,
                'expires_at' => $grant->expires_at,
                'download_url' => route('download.file', [
                    'token' => $grant->token,
                    'productId' => $grant->product_id,
                ]),
            ];
        });

        return Inertia::render('Shop/OrderSuccess', [
            'order' => [
                'id' => $order->id,
                'token' => $order->token,
                'guest_email' => $order->guest_email,
                'total_cents' => $order->total_cents,
                'display_currency' => $order->display_currency,
                'total_formatted' => $order->total_formatted,
                'created_at' => $order->created_at,
                'items' => $order->items->map(function ($item) {
                    return [
                        'title' => $item->title_snapshot,
                        'price_cents' => $item->price_cents_snapshot,
                        'quantity' => $item->quantity,
                    ];
                }),
            ],
            'downloadLinks' => $downloadLinks,
            'meta' => [
                'title' => 'Order Confirmation - Soul Bossa Nova',
                'description' => 'Thank you for your purchase!',
            ],
        ]);
    }
}

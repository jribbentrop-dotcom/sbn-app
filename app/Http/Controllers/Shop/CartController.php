<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use Inertia\Inertia;

class CartController extends Controller
{
    /**
     * Cart page - client-side state only.
     * The actual cart data comes from the client (localStorage).
     */
    public function show()
    {
        return Inertia::render('Shop/Cart', [
            'meta' => [
                'title' => 'Shopping Cart - Soul Bossa Nova',
                'description' => 'Review your cart and proceed to checkout.',
            ],
        ]);
    }
}

<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Payment Provider
    |--------------------------------------------------------------------------
    |
    | The active Merchant-of-Record provider. Everything provider-specific lives
    | behind the App\Services\Payments\PaymentProvider interface, so this is a
    | late, reversible decision. `fake` drives local dev + tests end-to-end with
    | no real account. Concrete impls (`paddle`, `stripe_managed`) are added once
    | the provider is finalized.
    |
    | Supported: "fake", "paddle", "stripe_managed"
    |
    */
    'provider' => env('PAYMENTS_PROVIDER', 'fake'),

    /*
    |--------------------------------------------------------------------------
    | Redirect URLs
    |--------------------------------------------------------------------------
    */
    'success_url' => env('PAYMENTS_SUCCESS_URL'), // null => derive route('order.success')

    /*
    |--------------------------------------------------------------------------
    | Per-provider credentials
    |--------------------------------------------------------------------------
    */
    'paddle' => [
        'api_key'        => env('PADDLE_API_KEY'),
        'signing_secret' => env('PADDLE_WEBHOOK_SECRET'),
        'sandbox'        => env('PADDLE_SANDBOX', true),
    ],

    'stripe_managed' => [
        'api_key'        => env('STRIPE_SECRET'),
        'signing_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    'fake' => [
        // Shared secret the FakeProvider signs/verifies dev webhooks with.
        'signing_secret' => env('PAYMENTS_FAKE_SECRET', 'fake-secret'),
    ],
];

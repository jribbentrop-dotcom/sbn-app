<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Shop Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the SBN shop including currencies, pricing,
    | and download settings.
    |
    */

    'base_currency' => 'EUR',

    'display_currencies' => ['EUR', 'USD'],

    'usd_rate' => env('SHOP_USD_RATE', 1.08), // EUR -> USD

    'download' => [
        'max_downloads' => 5, // Max downloads per grant
        'expires_days' => 7, // Days until download link expires
    ],
];

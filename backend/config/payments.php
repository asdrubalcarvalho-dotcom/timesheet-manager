<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Payment Gateway Driver
    |--------------------------------------------------------------------------
    |
    | Supported drivers: "fake", "stripe"
    |
    | - fake: FakeCreditCardGateway (development/testing)
    | - stripe: StripeCardGateway (production)
    |
    */
    'driver' => env('PAYMENTS_DRIVER', 'fake'),

    /*
    |--------------------------------------------------------------------------
    | Stripe Configuration
    |--------------------------------------------------------------------------
    |
    | Keys loaded dynamically based on STRIPE_MODE (test or live).
    | Uses env() directly to avoid circular config dependencies.
    |
    */
    'stripe' => [
        'secret_key' => env('STRIPE_MODE', 'test') === 'live' 
            ? env('STRIPE_LIVE_SECRET_KEY') 
            : env('STRIPE_TEST_SECRET_KEY'),
        'publishable_key' => env('STRIPE_MODE', 'test') === 'live' 
            ? env('STRIPE_LIVE_PUBLISHABLE_KEY') 
            : env('STRIPE_TEST_PUBLISHABLE_KEY'),
        'webhook_secret' => env('STRIPE_MODE', 'test') === 'live' 
            ? env('STRIPE_WEBHOOK_SECRET_LIVE') 
            : env('STRIPE_WEBHOOK_SECRET_TEST'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    */
    'currency' => [
        'code' => env('BILLING_CURRENCY', 'EUR'),
        'symbol' => env('BILLING_CURRENCY_SYMBOL', '€'),
    ],
];

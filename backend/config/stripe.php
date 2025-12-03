<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Stripe Mode
    |--------------------------------------------------------------------------
    |
    | This determines whether to use Stripe Test Mode or Live Mode.
    | Allowed values: 'test' or 'live'
    |
    | When set to 'test', all API calls use test keys (REMOVED*, REMOVED*)
    | When set to 'live', all API calls use live keys (REMOVED*, REMOVED*)
    |
    */

    'mode' => env('STRIPE_MODE', 'test'),

    /*
    |--------------------------------------------------------------------------
    | Stripe Test Mode Configuration
    |--------------------------------------------------------------------------
    |
    | These keys are used when STRIPE_MODE=test.
    | Get your test keys from: https://dashboard.stripe.com/test/apikeys
    |
    */

    'test' => [
        'pk' => env('STRIPE_TEST_PUBLISHABLE_KEY'),
        'sk' => env('STRIPE_TEST_SECRET_KEY'),
        'webhook' => env('STRIPE_WEBHOOK_SECRET_TEST'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Stripe Live Mode Configuration
    |--------------------------------------------------------------------------
    |
    | These keys are used when STRIPE_MODE=live.
    | Get your live keys from: https://dashboard.stripe.com/apikeys
    |
    | ⚠️ WARNING: Never commit live keys to version control!
    | ⚠️ Keep STRIPE_LIVE_* values in .env file only!
    |
    */

    'live' => [
        'pk' => env('STRIPE_LIVE_PUBLISHABLE_KEY'),
        'sk' => env('STRIPE_LIVE_SECRET_KEY'),
        'webhook' => env('STRIPE_WEBHOOK_SECRET_LIVE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    |
    | These helper methods return the active keys based on current mode.
    | Uses env() directly to avoid circular config() references.
    |
    */

    'current' => [
        'pk' => env('STRIPE_MODE', 'test') === 'live' 
            ? env('STRIPE_LIVE_PUBLISHABLE_KEY') 
            : env('STRIPE_TEST_PUBLISHABLE_KEY'),
        'sk' => env('STRIPE_MODE', 'test') === 'live' 
            ? env('STRIPE_LIVE_SECRET_KEY') 
            : env('STRIPE_TEST_SECRET_KEY'),
        'webhook' => env('STRIPE_MODE', 'test') === 'live' 
            ? env('STRIPE_WEBHOOK_SECRET_LIVE') 
            : env('STRIPE_WEBHOOK_SECRET_TEST'),
    ],

];

<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', rtrim(env('APP_URL', 'http://localhost'), '/') . '/auth/google/callback'),
    ],

    'microsoft' => [
        'client_id' => env('MICROSOFT_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
        'redirect' => env('MICROSOFT_REDIRECT_URI', rtrim(env('APP_URL', 'http://localhost'), '/') . '/auth/microsoft/callback'),
        'tenant' => env('MICROSOFT_TENANT', 'common'),
    ],

    'azure-ad' => [
        'client_id' => env('AZURE_CLIENT_ID'),
        'client_secret' => env('AZURE_CLIENT_SECRET'),
        'redirect' => env('AZURE_REDIRECT_URI', 'http://localhost:8080/api/auth/sso/azure/callback'),
        'tenant' => env('AZURE_TENANT', 'common'),
    ],

    'stripe' => [
        // Dynamically load keys based on STRIPE_MODE (test or live)
        // Uses env() directly to avoid circular config dependencies
        'secret' => env('STRIPE_MODE', 'test') === 'live' 
            ? env('STRIPE_LIVE_SECRET_KEY') 
            : env('STRIPE_TEST_SECRET_KEY'),
        'public' => env('STRIPE_MODE', 'test') === 'live' 
            ? env('STRIPE_LIVE_PUBLISHABLE_KEY') 
            : env('STRIPE_TEST_PUBLISHABLE_KEY'),
        'webhook_secret' => env('STRIPE_MODE', 'test') === 'live' 
            ? env('STRIPE_WEBHOOK_SECRET_LIVE') 
            : env('STRIPE_WEBHOOK_SECRET_TEST'),
    ],

];

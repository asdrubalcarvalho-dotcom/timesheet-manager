<?php

return [
    'enabled' => env('CAPTCHA_ENABLED', false),

    // adaptive | always | off
    'mode' => env('CAPTCHA_MODE', 'adaptive'),

    // turnstile | recaptcha
    'provider' => env('CAPTCHA_PROVIDER', 'turnstile'),

    'secret' => env('CAPTCHA_SECRET'),
    'site_key' => env('CAPTCHA_SITE_KEY'),

    // Only used for score-based providers.
    'min_score' => (float) env('CAPTCHA_MIN_SCORE', 0.5),

    // Domains that should NOT be blocked by EmailPolicy but should trigger adaptive CAPTCHA.
    // Keep small and conservative to avoid false positives.
    'risk_domains' => [
        'gmail.com',
        'outlook.com',
        'hotmail.com',
        'yahoo.com',
        'icloud.com',
        'live.com',
    ],

    'register' => [
        // Multiple attempts from the same IP in short window.
        'ip_window_seconds' => 600,
        'ip_threshold' => 3,
    ],

    'login' => [
        // After N failed attempts per (ip + tenant_slug + email) within window.
        'failure_window_seconds' => 600,
        'failure_threshold' => 3,
    ],
];

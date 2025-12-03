<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'billing/*'],

    'allowed_methods' => ['*'],

    // Static origins for non-tenant pages (registration, central app)
    'allowed_origins' => [
        'http://localhost:8082',
        'http://localhost:3000',
        'http://app.localhost:8082',
        env('FRONTEND_URL', 'http://localhost:8082'),
    ],

    // Dynamic tenant subdomains - accepts ANY subdomain matching pattern
    // Examples: http://acme.app.localhost:8082, http://demo.timeperk.localhost:8082
    'allowed_origins_patterns' => [
        '#^http://[a-z0-9-]+\.localhost:8082$#i',  // ANY subdomain on localhost:8082
        '#^http://[a-z0-9-]+\.app\.localhost:8082$#i',
        '#^http://[a-z0-9-]+\.timeperk\.localhost:8082$#i',
        '#^http://[a-z0-9-]+\.vendaslive\.localhost:8082$#i',  // VendasLive tenants
        '#^https://[a-z0-9-]+\.app\.timeperk\.com$#i',  // Production pattern
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['X-Tenant'],

    'max_age' => 3600,

    'supports_credentials' => true,
];
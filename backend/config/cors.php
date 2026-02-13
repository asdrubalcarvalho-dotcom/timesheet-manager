<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Laravel CORS Paths
    |--------------------------------------------------------------------------
    |
    | IMPORTANT:
    |   Cada endpoint que é chamado pelo frontend deve estar listado aqui,
    |   caso contrário o browser bloqueia a request por CORS.
    |
    */

    'paths' => [
        'api/*',                  // Todas as APIs
        'sanctum/csrf-cookie',    // Para autenticação
        'contact',                // 👈 Necessário para formulário da landing
        'contact/*',
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed Methods
    |--------------------------------------------------------------------------
    */

    'allowed_methods' => ['*'],

    /*
    |--------------------------------------------------------------------------
    | Allowed Origins
    |--------------------------------------------------------------------------
    |
    | Domínios autorizados a fazer pedidos à API.
    |
    */

    'allowed_origins' => array_filter(array_merge([

        // Desenvolvimento
        'http://localhost:3000',
        'http://localhost:8082',
        'http://demo.localhost:8082',        // Demo tenant
        env('FRONTEND_URL', 'http://localhost:3000'),

        // Produção
        'https://vendaslive.com',
        'https://www.vendaslive.com',
        'https://app.vendaslive.com',
        'https://management.vendaslive.com',  // SuperAdmin production
    ], array_filter(array_map('trim',
        explode(',', env('SUPERADMIN_ALLOWED_ORIGINS', 'https://management.vendaslive.com')))
    ))),

    /*
    |--------------------------------------------------------------------------
    | Allowed Origins Patterns
    |--------------------------------------------------------------------------
    |
    | Aceitar automaticamente qualquer subdomínio *.vendaslive.com
    | Necessário para os tenants: demo.vendaslive.com, empresa.vendaslive.com, etc.
    |
    | Desenvolvimento: *.localhost:8082 para tenant subdomains
    |
    */

    'allowed_origins_patterns' => [
        '/^https?:\/\/([a-z0-9-]+\.)?vendaslive\.com$/',
        '/^http:\/\/.+\.localhost:8082$/',  // Dev tenant subdomains (any subdomain)
    ],

    /*
    |--------------------------------------------------------------------------
    | Headers
    |--------------------------------------------------------------------------
    */

    'allowed_headers' => ['*'],
    'exposed_headers' => [],

    /*
    |--------------------------------------------------------------------------
    | Cache & Credentials
    |--------------------------------------------------------------------------
    */

    'max_age' => 3600,
    'supports_credentials' => true,
];

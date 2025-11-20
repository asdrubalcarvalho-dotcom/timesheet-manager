<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\AuthServiceProvider::class,
    App\Providers\RouteServiceProvider::class,
    
    // Billing Module
    Modules\Billing\Providers\BillingServiceProvider::class,
];

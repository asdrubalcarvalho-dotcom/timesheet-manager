<?php

use Illuminate\Support\Facades\Route;
use Modules\Billing\Controllers\BillingController;

/*
|--------------------------------------------------------------------------
| Billing Module Routes
|--------------------------------------------------------------------------
|
| REST API endpoints for subscription management and billing.
| All routes require authentication and tenant context.
|
*/

Route::middleware(['auth:sanctum', 'tenant.initialize', 'tenant.set-context'])->prefix('billing')->group(function () {
    
    // GET /api/billing/summary - Current subscription info
    Route::get('summary', [BillingController::class, 'summary'])
        ->middleware('throttle:read');

    // POST /api/billing/upgrade-plan - Change subscription plan
    Route::post('upgrade-plan', [BillingController::class, 'upgradePlan'])
        ->middleware('throttle:create');

    // POST /api/billing/toggle-addon - Enable/disable addons
    Route::post('toggle-addon', [BillingController::class, 'toggleAddon'])
        ->middleware('throttle:edit');

    // POST /api/billing/checkout/start - Initialize payment
    Route::post('checkout/start', [BillingController::class, 'checkoutStart'])
        ->middleware('throttle:create');

    // POST /api/billing/checkout/confirm - Complete payment
    Route::post('checkout/confirm', [BillingController::class, 'checkoutConfirm'])
        ->middleware('throttle:critical');
});

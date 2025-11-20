<?php

use Illuminate\Support\Facades\Route;
use Modules\Billing\Http\Controllers\BillingController;
use Modules\Billing\Http\Controllers\SubscriptionController;
use Modules\Billing\Http\Controllers\FeatureController;
// use Modules\Billing\Http\Controllers\WebhookController;

/*
|--------------------------------------------------------------------------
| Billing API Routes
|--------------------------------------------------------------------------
*/

// Stripe Webhooks (no auth required) - TODO: Install Laravel Cashier
// Route::post('/webhooks/stripe', [WebhookController::class, 'handleWebhook'])
//     ->name('billing.webhooks.stripe');

// Authenticated routes
Route::middleware(['auth:sanctum'])->group(function () {
    
    // Billing overview
    Route::get('/billing', [BillingController::class, 'index'])
        ->name('billing.index');

    Route::get('/billing/subscription', [BillingController::class, 'subscription'])
        ->name('billing.subscription');

    Route::get('/billing/invoices', [BillingController::class, 'invoices'])
        ->name('billing.invoices');

    Route::get('/billing/invoices/{invoice}', [BillingController::class, 'downloadInvoice'])
        ->name('billing.invoice.download');

    Route::get('/billing/payment-method', [BillingController::class, 'paymentMethod'])
        ->name('billing.payment-method');

    Route::put('/billing/payment-method', [BillingController::class, 'updatePaymentMethod'])
        ->name('billing.payment-method.update')
        ->middleware('permission:manage-billing');

    // Subscription management (requires manage-billing permission)
    Route::middleware(['permission:manage-billing'])->group(function () {
        Route::post('/subscription/add-licenses', [SubscriptionController::class, 'addLicenses'])
            ->name('subscription.add-licenses');

        Route::post('/subscription/remove-licenses', [SubscriptionController::class, 'removeLicenses'])
            ->name('subscription.remove-licenses');

        Route::post('/subscription/preview-cost', [SubscriptionController::class, 'previewCost'])
            ->name('subscription.preview-cost');

        Route::post('/subscription/switch-billing-cycle', [SubscriptionController::class, 'switchBillingCycle'])
            ->name('subscription.switch-billing-cycle');

        Route::post('/subscription/resume', [SubscriptionController::class, 'resume'])
            ->name('subscription.resume');

        Route::post('/subscription/cancel', [SubscriptionController::class, 'cancel'])
            ->name('subscription.cancel');
    });

    // Feature management
    Route::get('/features', [FeatureController::class, 'index'])
        ->name('features.index');

    Route::get('/features/enabled', [FeatureController::class, 'enabled'])
        ->name('features.enabled');

    // Feature toggle (Admin/Owner only - policy check in controller)
    Route::post('/features/{module}/enable', [FeatureController::class, 'enable'])
        ->name('features.enable');

    Route::post('/features/{module}/disable', [FeatureController::class, 'disable'])
        ->name('features.disable');

    Route::post('/features/{module}/trial', [FeatureController::class, 'setTrial'])
        ->name('features.trial');
});

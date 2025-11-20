<?php

namespace Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Modules\Billing\Services\LicenseManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Http\Controllers\WebhookController as CashierWebhookController;

/**
 * WebhookController
 * 
 * Handles Stripe webhook events for billing operations.
 * Extends Laravel Cashier's WebhookController for base functionality.
 */
class WebhookController extends CashierWebhookController
{
    /**
     * Handle subscription updated event
     */
    protected function handleCustomerSubscriptionUpdated(array $payload): void
    {
        parent::handleCustomerSubscriptionUpdated($payload);

        $subscription = $payload['data']['object'];
        $quantity = $subscription['quantity'] ?? 1;

        // Find tenant by Stripe customer ID
        $tenant = Tenant::where('stripe_id', $subscription['customer'])->first();

        if (!$tenant) {
            Log::warning('Subscription updated for unknown customer', [
                'customer_id' => $subscription['customer']
            ]);
            return;
        }

        // Update license quantity in tenant context
        $tenant->run(function () use ($quantity) {
            $licenseManager = app(LicenseManager::class);
            $license = $licenseManager->getLicense();

            if ($license && $license->purchased_licenses !== $quantity) {
                $license->purchased_licenses = $quantity;
                $license->save();

                Log::info('License quantity updated via webhook', [
                    'tenant_id' => tenancy()->tenant->id,
                    'new_quantity' => $quantity,
                ]);
            }
        });
    }

    /**
     * Handle subscription deleted event
     */
    protected function handleCustomerSubscriptionDeleted(array $payload): void
    {
        parent::handleCustomerSubscriptionDeleted($payload);

        $subscription = $payload['data']['object'];

        $tenant = Tenant::where('stripe_id', $subscription['customer'])->first();

        if (!$tenant) {
            return;
        }

        Log::warning('Subscription canceled', [
            'tenant_id' => $tenant->id,
            'subscription_id' => $subscription['id'],
        ]);

        // Optionally: Send notification to tenant admins
    }

    /**
     * Handle invoice payment succeeded event
     */
    protected function handleInvoicePaymentSucceeded(array $payload): void
    {
        parent::handleInvoicePaymentSucceeded($payload);

        $invoice = $payload['data']['object'];

        $tenant = Tenant::where('stripe_id', $invoice['customer'])->first();

        if (!$tenant) {
            return;
        }

        Log::info('Invoice payment succeeded', [
            'tenant_id' => $tenant->id,
            'invoice_id' => $invoice['id'],
            'amount' => $invoice['amount_paid'] / 100,
        ]);

        // Optionally: Send receipt email or notification
    }

    /**
     * Handle invoice payment failed event
     */
    protected function handleInvoicePaymentFailed(array $payload): void
    {
        parent::handleInvoicePaymentFailed($payload);

        $invoice = $payload['data']['object'];

        $tenant = Tenant::where('stripe_id', $invoice['customer'])->first();

        if (!$tenant) {
            return;
        }

        Log::error('Invoice payment failed', [
            'tenant_id' => $tenant->id,
            'invoice_id' => $invoice['id'],
            'amount' => $invoice['amount_due'] / 100,
        ]);

        // TODO: Send payment failure notification to tenant admins
        // TODO: Consider suspending account after multiple failures
    }
}

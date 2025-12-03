<?php

namespace App\Services\Billing;

use App\Models\Tenant;
use App\Models\PaymentFailure;
use Illuminate\Support\Facades\Log;

/**
 * SubscriptionStatusService
 * 
 * Phase 4: Centralized subscription status management
 * 
 * Purpose:
 * - Provide single source of truth for subscription status updates
 * - Handle status transitions from Stripe webhooks
 * - Maintain consistency between Stripe and local database
 * - Track status change history
 * 
 * Status Flow:
 * - trialing → active (first payment succeeds)
 * - active → past_due (payment fails)
 * - past_due → active (payment retries succeed)
 * - past_due → unpaid (all retries fail)
 * - active → paused (manual pause)
 * - paused → active (manual resume)
 * - any → canceled (subscription ended)
 * 
 * Usage:
 * - Called by all Stripe webhook handlers
 * - Used by pause/resume endpoints
 * - Queried by subscription status API
 */
class SubscriptionStatusService
{
    /**
     * Update tenant subscription status
     * 
     * Single method to handle ALL subscription status changes.
     * Ensures consistency and proper logging.
     * 
     * @param Tenant $tenant
     * @param string $newStatus - Stripe subscription status
     * @param string $event - Webhook event type for audit trail
     * @param array $metadata - Additional context
     * @return bool
     */
    public function updateStatus(Tenant $tenant, string $newStatus, string $event, array $metadata = []): bool
    {
        $oldStatus = $tenant->subscription_status;

        // Skip if status unchanged
        if ($oldStatus === $newStatus && $event !== 'force_update') {
            Log::debug('SubscriptionStatusService: Status unchanged', [
                'tenant_id' => $tenant->id,
                'status' => $newStatus,
                'event' => $event,
            ]);
            return true;
        }

        try {
            // Update tenant record
            $tenant->update([
                'subscription_status' => $newStatus,
                'subscription_last_event' => $event,
                'subscription_last_status_change_at' => now(),
                'is_paused' => $newStatus === 'paused',
            ]);

            Log::info('SubscriptionStatusService: Status updated', [
                'tenant_id' => $tenant->id,
                'tenant_slug' => $tenant->slug,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'event' => $event,
                'metadata' => $metadata,
            ]);

            // Handle status-specific side effects
            $this->handleStatusChange($tenant, $oldStatus, $newStatus, $metadata);

            return true;

        } catch (\Exception $e) {
            Log::error('SubscriptionStatusService: Failed to update status', [
                'tenant_id' => $tenant->id,
                'new_status' => $newStatus,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Handle status change side effects
     * 
     * @param Tenant $tenant
     * @param string $oldStatus
     * @param string $newStatus
     * @param array $metadata
     */
    protected function handleStatusChange(Tenant $tenant, string $oldStatus, string $newStatus, array $metadata): void
    {
        // Payment succeeded after failure → resolve payment failures
        if (in_array($oldStatus, ['past_due', 'unpaid']) && $newStatus === 'active') {
            $this->resolvePaymentFailures($tenant, 'auto_retry');
        }

        // Subscription paused → log
        if ($newStatus === 'paused') {
            Log::info('Subscription paused', [
                'tenant_id' => $tenant->id,
                'method' => $metadata['pause_method'] ?? 'unknown',
            ]);
        }

        // Subscription canceled → cleanup
        if ($newStatus === 'canceled') {
            Log::warning('Subscription canceled', [
                'tenant_id' => $tenant->id,
                'reason' => $metadata['cancel_reason'] ?? 'unknown',
            ]);
        }
    }

    /**
     * Mark all pending payment failures as resolved
     * 
     * @param Tenant $tenant
     * @param string $method - Resolution method
     */
    protected function resolvePaymentFailures(Tenant $tenant, string $method): void
    {
        $failures = PaymentFailure::where('tenant_id', $tenant->id)
            ->pending()
            ->get();

        foreach ($failures as $failure) {
            $failure->markAsResolved($method, 'Resolved via status change to active');
        }

        if ($failures->isNotEmpty()) {
            Log::info('PaymentFailures resolved', [
                'tenant_id' => $tenant->id,
                'count' => $failures->count(),
                'method' => $method,
            ]);
        }
    }

    /**
     * Get comprehensive subscription status for a tenant
     * 
     * Returns DTO with all relevant subscription information
     * 
     * @param Tenant $tenant
     * @return array
     */
    public function getStatus(Tenant $tenant): array
    {
        // Get payment failures
        $failures = PaymentFailure::where('tenant_id', $tenant->id)
            ->pending()
            ->orderBy('failed_at', 'desc')
            ->get()
            ->map(function ($failure) {
                return [
                    'id' => $failure->id,
                    'stripe_invoice_id' => $failure->stripe_invoice_id,
                    'amount' => $failure->amount,
                    'reason' => $failure->readable_reason,
                    'failed_at' => $failure->failed_at->toIso8601String(),
                    'days_since_failure' => $failure->days_since_failure,
                    'dunning_stage' => $failure->dunning_stage,
                    'reminder_count' => $failure->reminder_count,
                ];
            });

        // Get recent invoices (via BillingInvoice model)
        $invoices = \App\Models\BillingInvoice::where('tenant_id', $tenant->id)
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($invoice) {
                return [
                    'stripe_invoice_id' => $invoice->stripe_invoice_id,
                    'status' => $invoice->status,
                    'amount_due' => $invoice->amount_due,
                    'amount_paid' => $invoice->amount_paid,
                    'pdf_url' => $invoice->pdf_url,
                    'created_at' => $invoice->created_at->toIso8601String(),
                ];
            });

        return [
            'subscription_id' => $tenant->stripe_subscription_id,
            'status' => $tenant->subscription_status,
            'is_paused' => $tenant->is_paused,
            'last_event' => $tenant->subscription_last_event,
            'last_status_change_at' => $tenant->subscription_last_status_change_at?->toIso8601String(),
            'renews_at' => $tenant->subscription_renews_at?->toIso8601String(),
            'plan' => $tenant->plan,
            'active_addons' => $tenant->active_addons ?? [],
            'has_payment_failures' => $failures->isNotEmpty(),
            'payment_failures' => $failures,
            'recent_invoices' => $invoices,
            'health' => $this->calculateHealth($tenant, $failures),
        ];
    }

    /**
     * Calculate subscription health score
     * 
     * @param Tenant $tenant
     * @param \Illuminate\Support\Collection $failures
     * @return array
     */
    protected function calculateHealth(Tenant $tenant, $failures): array
    {
        $status = $tenant->subscription_status;
        $isPaused = $tenant->is_paused;
        $hasFailures = $failures->isNotEmpty();

        // Determine health level
        if ($status === 'canceled') {
            $level = 'critical';
            $message = 'Subscription canceled';
        } elseif ($status === 'unpaid') {
            $level = 'critical';
            $message = 'Payment failed - subscription unpaid';
        } elseif ($isPaused) {
            $level = 'warning';
            $message = 'Subscription paused';
        } elseif ($status === 'past_due') {
            $level = 'warning';
            $message = 'Payment past due - retrying';
        } elseif ($hasFailures) {
            $level = 'warning';
            $message = 'Recent payment failures detected';
        } elseif ($status === 'active') {
            $level = 'healthy';
            $message = 'Subscription active';
        } elseif ($status === 'trialing') {
            $level = 'healthy';
            $message = 'Trial period active';
        } else {
            $level = 'unknown';
            $message = 'Status: ' . $status;
        }

        return [
            'level' => $level, // healthy, warning, critical, unknown
            'message' => $message,
            'requires_action' => in_array($level, ['warning', 'critical']),
        ];
    }

    /**
     * Check if tenant has restricted access due to subscription issues
     * 
     * @param Tenant $tenant
     * @return array ['restricted' => bool, 'reason' => ?string]
     */
    public function checkAccessRestrictions(Tenant $tenant): array
    {
        $status = $tenant->subscription_status;
        $isPaused = $tenant->is_paused;

        // Canceled subscription
        if ($status === 'canceled') {
            return [
                'restricted' => true,
                'reason' => 'Subscription canceled. Please reactivate to continue.',
            ];
        }

        // Unpaid status (after all retries failed)
        if ($status === 'unpaid') {
            return [
                'restricted' => true,
                'reason' => 'Payment failed. Please update payment method.',
            ];
        }

        // Paused subscription (if configured to restrict)
        if ($isPaused && config('billing.pause_resume.restrict_access_when_paused')) {
            return [
                'restricted' => true,
                'reason' => 'Subscription paused. Resume to restore full access.',
            ];
        }

        // Past due (grace period - show warning but allow access)
        if ($status === 'past_due') {
            return [
                'restricted' => false,
                'reason' => 'Payment issue detected. Service may be interrupted soon.',
                'warning' => true,
            ];
        }

        return ['restricted' => false, 'reason' => null];
    }
}

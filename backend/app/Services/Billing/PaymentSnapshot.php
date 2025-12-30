<?php

namespace App\Services\Billing;

use Modules\Billing\Models\Payment;
use Modules\Billing\Models\Subscription;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * PaymentSnapshot Service
 * 
 * Manages payment snapshots for Billing Model A (No Proration).
 * Creates immutable records of billing state at payment time.
 */
class PaymentSnapshot
{
    /**
     * Create a payment snapshot from current subscription state.
     * 
     * CRITICAL: For plan upgrades, pass $targetPlan and $targetUserLimit to ensure
     * snapshot reflects what customer is PAYING FOR, not current subscription state.
     * 
     * @param Tenant $tenant
     * @param Subscription $subscription Current subscription (for fallback values)
     * @param float $amount Amount to charge
     * @param string $stripePaymentIntentId Stripe PaymentIntent ID
     * @param string|null $targetPlan Target plan for upgrade (team/enterprise), null for current
     * @param int|null $targetUserLimit Target license count, null to use current user_limit
     * @return Payment
     * @throws InvalidArgumentException
     */
    public function createSnapshot(
        Tenant $tenant,
        Subscription $subscription,
        float $amount,
        string $stripePaymentIntentId,
        ?string $targetPlan = null,
        ?int $targetUserLimit = null
    ): Payment {
        // Validate inputs
        if ($amount <= 0) {
            throw new InvalidArgumentException('Payment amount must be greater than zero');
        }

        if (empty($stripePaymentIntentId)) {
            throw new InvalidArgumentException('Stripe PaymentIntent ID is required');
        }

        // Calculate billing cycle dates
        $cycleStart = $subscription->billing_cycle_start 
            ? Carbon::parse($subscription->billing_cycle_start)
            : Carbon::now();
        
        $cycleEnd = (clone $cycleStart)->addMonth()->subDay();

        // Use TARGET values from checkout if provided, otherwise fall back to current subscription
        // CRITICAL: This ensures snapshot captures what customer is BUYING, not what they currently have
        $plan = $targetPlan
            ?? $subscription->plan
            ?? 'starter';
        
        $userLimit = $targetUserLimit
            ?? $subscription->user_limit
            ?? 1;

        // Build addons array from subscription JSON column (canonical source)
        $addons = $subscription->addons ?? [];

        \Log::info('[PaymentSnapshot] Creating snapshot', [
            'tenant_id' => $tenant->id,
            'current_plan' => $subscription->plan,
            'current_user_limit' => $subscription->user_limit,
            'target_plan' => $targetPlan,
            'target_user_limit' => $targetUserLimit,
            'snapshot_plan' => $plan,
            'snapshot_user_limit' => $userLimit,
            'amount' => $amount,
        ]);

        // Create payment snapshot
        $paymentData = [
            'tenant_id' => $tenant->id,
            'plan' => $plan,
            'user_limit' => $userLimit,
            'addons' => $addons,
            'amount' => $amount,
            'currency' => 'EUR',
            'cycle_start' => $cycleStart,
            'cycle_end' => $cycleEnd,
            'stripe_payment_intent_id' => $stripePaymentIntentId,
            'status' => 'pending',
            'metadata' => [
                'created_via' => 'checkout',
                'subscription_id' => $subscription->id,
            ],
        ];
        
        \Log::info('[PaymentSnapshot] Data being saved', $paymentData);
        
        $payment = Payment::create($paymentData);
        
        \Log::info('[PaymentSnapshot] Payment created', [
            'id' => $payment->id,
            'plan' => $payment->plan,
            'user_limit' => $payment->user_limit,
            'cycle_start' => $payment->cycle_start,
        ]);
        
        return $payment;
    }

    /**
     * Apply a payment snapshot to a subscription.
     * This is called after successful payment confirmation.
     * 
     * @param Payment $payment
     * @param Subscription $subscription
     * @return void
     * @throws InvalidArgumentException
     */
    public function applySnapshot(Payment $payment, Subscription $subscription): void
    {
        if (!$payment->isPaid()) {
            throw new InvalidArgumentException('Cannot apply unpaid payment snapshot');
        }

        $updates = [
            'plan' => $payment->plan,
            'user_limit' => $payment->user_limit, // Purchased licenses are stored as user_limit on payments
            'addons' => $payment->addons ?? [],
            'next_renewal_at' => $payment->cycle_end,
            // Keep billing period fields in sync with snapshot to avoid stale-period read-only regressions.
            'billing_period_started_at' => $payment->cycle_start,
            'billing_period_ends_at' => $payment->cycle_end,
            'status' => 'active',
        ];

        // Successful paid checkout must end an active Enterprise trial.
        // Otherwise PriceCalculator will keep returning trial_enterprise (total=0).
        if ($subscription->is_trial) {
            $updates['is_trial'] = false;
            $updates['trial_ends_at'] = null;
        }

        // Update subscription with snapshot values
        $subscription->update($updates);

        // Ensure subscription_start_date is set once the tenant is on a paid plan.
        if ($subscription->plan !== 'starter') {
            app(PlanManager::class)->setSubscriptionStartDate($subscription);
        }

        // Ensure tenant feature flags stay in sync with updated subscription
        $tenant = $subscription->tenant ?? Tenant::find($subscription->tenant_id);
        if ($tenant) {
            app(PlanManager::class)->resyncFeatures($tenant, $subscription);
        }
    }

    /**
     * Get all payment snapshots for a tenant within a date range.
     * Useful for invoice generation and audit trails.
     * 
     * @param Tenant $tenant
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getSnapshotsForPeriod(Tenant $tenant, Carbon $startDate, Carbon $endDate)
    {
        return Payment::forTenant($tenant->id)
            ->forPeriod($startDate, $endDate)
            ->withStatus('paid')
            ->orderBy('cycle_start')
            ->get();
    }

    /**
     * Get the most recent paid snapshot for a tenant.
     * 
     * @param Tenant $tenant
     * @return Payment|null
     */
    public function getLatestSnapshot(Tenant $tenant): ?Payment
    {
        return Payment::forTenant($tenant->id)
            ->withStatus('paid')
            ->orderBy('created_at', 'desc')
            ->first();
    }

    /**
     * Find a payment snapshot by Stripe PaymentIntent ID.
     * 
     * @param string $paymentIntentId
     * @return Payment|null
     */
    public function findByPaymentIntent(string $paymentIntentId): ?Payment
    {
        return Payment::where('stripe_payment_intent_id', $paymentIntentId)->first();
    }

    /**
     * Validate that a snapshot matches the current subscription state.
     * Used to detect if subscription was modified between checkout start and confirm.
     * 
     * @param Payment $payment
     * @param Subscription $subscription
     * @return bool
     */
    public function validateSnapshotMatchesSubscription(Payment $payment, Subscription $subscription): bool
    {
        // Check plan
        if ($payment->plan !== $subscription->plan) {
            return false;
        }

        // Check addons (JSON field mirrors billing state)
        $currentAddons = $subscription->addons ?? [];
        $planningEnabled = in_array('planning', $payment->addons ?? []);
        if ($planningEnabled !== in_array('planning', $currentAddons, true)) {
            return false;
        }

        $aiEnabled = in_array('ai', $payment->addons ?? []);
        if ($aiEnabled !== in_array('ai', $currentAddons, true)) {
            return false;
        }

        return true;
    }

    /**
     * Get active user count for a tenant.
     * This queries the tenant database to count active users/technicians.
     * 
     * @param Tenant $tenant
     * @return int
     */
    protected function getActiveUserCount(Tenant $tenant): int
    {
        // Initialize tenant context
        $originalTenantId = tenancy()->tenant?->id;
        
        try {
            tenancy()->initialize($tenant);
            
            // Count active technicians/users in tenant database
            $connection = tenancy()->initialized ? 'tenant' : config('database.default');
            $count = DB::connection($connection)
                ->table('technicians')
                ->where('is_active', 1)
                ->count();
            
            return max(1, $count); // Minimum 1 user
        } finally {
            // Restore original tenant context
            if ($originalTenantId) {
                tenancy()->initialize(Tenant::find($originalTenantId));
            } else {
                tenancy()->end();
            }
        }
    }

    /**
     * Convert a payment snapshot to Stripe metadata format.
     * Used to enrich PaymentIntent with billing context.
     * 
     * @param Payment $payment
     * @return array
     */
    public function toStripeMetadata(Payment $payment): array
    {
        return [
            'tenant_id' => $payment->tenant_id,
            'plan' => $payment->plan,
            'user_count' => (string) $payment->user_count,
            'addons' => json_encode($payment->addons ?? []),
            'cycle_start' => $payment->cycle_start ? $payment->cycle_start->format('Y-m-d') : now()->format('Y-m-d'),
            'cycle_end' => $payment->cycle_end ? $payment->cycle_end->format('Y-m-d') : now()->addMonth()->format('Y-m-d'),
            'amount' => (string) $payment->amount,
            'currency' => $payment->currency,
        ];
    }

    /**
     * Get a human-readable summary of a payment snapshot.
     * 
     * @param Payment $payment
     * @return string
     */
    public function getSnapshotSummary(Payment $payment): string
    {
        $addonsText = empty($payment->addons) 
            ? 'No add-ons' 
            : 'Add-ons: ' . implode(', ', $payment->addons);
        
        $cycleStart = $payment->cycle_start ? $payment->cycle_start->format('M d, Y') : now()->format('M d, Y');
        $cycleEnd = $payment->cycle_end ? $payment->cycle_end->format('M d, Y') : now()->addMonth()->format('M d, Y');
        
        return sprintf(
            '%s plan with %d user(s) • %s • %s for %s to %s',
            ucfirst($payment->plan),
            $payment->user_count,
            $addonsText,
            $payment->formatted_amount,
            $cycleStart,
            $cycleEnd
        );
    }
}

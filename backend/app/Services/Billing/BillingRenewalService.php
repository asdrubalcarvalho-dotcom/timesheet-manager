<?php

namespace App\Services\Billing;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Models\Payment;
use App\Services\Billing\PlanManager;
use App\Services\Billing\PriceCalculator;
use App\Models\Tenant;
use Exception;

/**
 * BillingRenewalService
 *
 * LOCAL TESTING:
 *   php artisan billing:run-renewals
 *   STRIPE_MODE=test uses test keys automatically.
 *   Use tenant factory or set billing_period_ends_at = now() for testing.
 */
class BillingRenewalService
{
    public function runForDueSubscriptions(): array
    {
        Log::info('[BillingRenewalService] Starting renewal run');
        $now = Carbon::now();
        $eligible = Subscription::where('status', 'active')
            ->where('is_trial', false)
            ->whereNotNull('billing_period_ends_at')
            ->where('billing_period_ends_at', '<=', $now)
            ->get();

        $total = $eligible->count();
        $succeeded = 0;
        $failed = 0;

        foreach ($eligible as $subscription) {
            try {
                $tenant = $subscription->tenant;
                if (!$tenant) {
                    Log::error('[BillingRenewalService] Tenant not found for subscription', [
                        'subscription_id' => $subscription->id,
                    ]);
                    $failed++;
                    continue;
                }

                // Apply pending plan changes if effective (outside tenant context)
                if ($subscription->pending_plan && $subscription->pending_plan_effective_at && $subscription->pending_plan_effective_at <= $now) {
                    $planManager = app(PlanManager::class);
                    $planManager->updatePlan($tenant, $subscription->pending_plan, $subscription->pending_user_limit ?? $subscription->user_limit);
                    $subscription->plan = $subscription->pending_plan;
                    $subscription->user_limit = $subscription->pending_user_limit ?? $subscription->user_limit;
                    $subscription->pending_plan = null;
                    $subscription->pending_user_limit = null;
                    $subscription->pending_plan_effective_at = null;
                    $subscription->save();
                    Log::info('[BillingRenewalService] Applied pending plan change', [
                        'tenant_id' => $tenant->id,
                        'subscription_id' => $subscription->id,
                        'plan' => $subscription->plan,
                        'user_limit' => $subscription->user_limit,
                    ]);
                }

                // Calculate renewal amount based on subscribed user_limit (not active users)
                // This ensures consistent billing regardless of temporary user deactivations
                $planConfig = config("billing.plans.{$subscription->plan}");
                
                // Get price per user for the plan
                $pricePerUser = $planConfig['price_per_user'] ?? 0;
                
                // Calculate total based on user_limit (not active technician count)
                $userCount = $subscription->user_limit;
                $amount = $pricePerUser * $userCount;
                
                // Add addon pricing if any
                $addons = is_array($subscription->addons) ? $subscription->addons : json_decode($subscription->addons ?? '[]', true);
                foreach (($addons ?? []) as $addon) {
                    if (isset($planConfig['addons'][$addon])) {
                        $addonPercentage = $planConfig['addons'][$addon] ?? 0;
                        $amount += ($amount * $addonPercentage); // Percentage of base price
                    }
                }

                $gatewayName = $subscription->billing_gateway ?? config('billing.driver', 'stripe');
                $gateway = $gatewayName === 'stripe'
                    ? app('App\\Services\\Payments\\StripeGateway')
                    : app('App\\Services\\Payments\\FakeCreditCardGateway');

                // Charge via gateway
                try {
                    $payment = $gateway->createPaymentIntent(
                        $tenant,
                        $amount,
                        [
                            'operation' => 'renewal',
                            'subscription_id' => $subscription->id,
                            'plan' => $subscription->plan,
                            'user_count' => $subscription->user_limit,
                            'addons' => $subscription->addons,
                            'billing_period_start' => $subscription->billing_period_started_at?->toDateString(),
                            'billing_period_end' => $subscription->billing_period_ends_at?->toDateString(),
                        ]
                    );
                    
                    // For renewals, use saved payment method (no card data needed for fake gateway)
                    if ($gatewayName === 'fake') {
                        // Fake gateway: Pass empty card data for renewal (simulate saved payment method)
                        $gateway->confirmPayment($payment, []);
                    } else {
                        // Stripe gateway: Uses saved payment method automatically
                        $gateway->confirmPayment($payment);
                    }

                    // Update subscription billing dates
                    $oldEnd = $subscription->billing_period_ends_at ?? $now;
                    $subscription->billing_period_started_at = $oldEnd;
                    $subscription->billing_period_ends_at = Carbon::parse($oldEnd)->addMonth();
                    $subscription->last_renewal_at = $now;
                    $subscription->status = 'active';
                    $subscription->save();

                    // Log success
                    Log::info('[BillingRenewalService] Subscription renewed successfully', [
                        'tenant_id' => $tenant->id,
                        'subscription_id' => $subscription->id,
                        'amount' => $amount,
                        'gateway' => $gatewayName,
                        'billing_period_start' => $subscription->billing_period_started_at,
                        'billing_period_end' => $subscription->billing_period_ends_at,
                    ]);
                    $succeeded++;
                } catch (Exception $e) {
                    $subscription->status = 'past_due';
                    $subscription->save();
                    Log::error('[BillingRenewalService] Subscription renewal failed', [
                        'tenant_id' => $tenant->id,
                        'subscription_id' => $subscription->id,
                        'amount' => $amount,
                        'gateway' => $gatewayName,
                        'error' => $e->getMessage(),
                    ]);
                    $failed++;
                }
            } catch (Exception $ex) {
                Log::error('[BillingRenewalService] Fatal error in renewal loop', [
                    'subscription_id' => $subscription->id,
                    'error' => $ex->getMessage(),
                ]);
                $failed++;
            }
        }

        Log::info('[BillingRenewalService] Renewal run complete', [
            'total_checked' => $total,
            'succeeded' => $succeeded,
            'failed' => $failed,
        ]);
        return compact('total', 'succeeded', 'failed');
    }
}

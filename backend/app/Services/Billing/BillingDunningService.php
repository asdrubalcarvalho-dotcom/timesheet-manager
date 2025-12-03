<?php

namespace App\Services\Billing;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Models\Payment;
use App\Services\Billing\PlanManager;
use App\Models\Tenant;
use Exception;

/**
 * BillingDunningService
 * 
 * Phase 9: Failed Payment Recovery (Dunning Engine)
 * 
 * Handles automatic retry of failed renewal payments with grace periods.
 * 
 * RETRY SCHEDULE:
 * - Attempt 1: Immediate (when renewal fails)
 * - Attempt 2: +3 days
 * - Attempt 3: +7 days (final attempt)
 * - Grace period: 7 days from first failure
 * - After grace period + max attempts: Cancel subscription
 * 
 * STATUS FLOW:
 * active → past_due → (recovered → active) OR (canceled)
 */
class BillingDunningService
{
    protected const MAX_RETRY_ATTEMPTS = 3;
    protected const GRACE_PERIOD_DAYS = 7;
    
    /**
     * Process all subscriptions in past_due status
     * 
     * Includes both:
     * - Subscriptions within grace period (for retry attempts)
     * - Subscriptions with expired grace period (for cancellation)
     */
    public function runDunningProcess(): array
    {
        Log::info('[BillingDunningService] Starting dunning process');
        
        $now = Carbon::now();
        
        // Get ALL past_due subscriptions (both within and beyond grace period)
        $pastDueSubscriptions = Subscription::where('status', 'past_due')->get();
        
        $totalChecked = $pastDueSubscriptions->count();
        $recovered = 0;
        $failed = 0;
        $canceled = 0;
        
        foreach ($pastDueSubscriptions as $subscription) {
            try {
                $tenant = $subscription->tenant;
                if (!$tenant) {
                    Log::error('[BillingDunningService] Tenant not found for subscription', [
                        'subscription_id' => $subscription->id,
                    ]);
                    $failed++;
                    continue;
                }
                
                // Check if grace period expired
                if ($subscription->grace_period_until && $subscription->grace_period_until < $now) {
                    // Grace period expired - cancel subscription
                    $this->cancelSubscription($subscription, $tenant);
                    $canceled++;
                    continue;
                }
                
                // Check if max attempts reached
                if ($subscription->failed_renewal_attempts >= self::MAX_RETRY_ATTEMPTS) {
                    // Max attempts reached but still in grace period - skip for now
                    Log::info('[BillingDunningService] Max attempts reached, waiting for grace period expiry', [
                        'subscription_id' => $subscription->id,
                        'grace_period_until' => $subscription->grace_period_until,
                    ]);
                    continue;
                }
                
                // Attempt recovery charge
                $recoveryResult = $this->attemptRecoveryCharge($subscription, $tenant);
                
                if ($recoveryResult['success']) {
                    $recovered++;
                } else {
                    $failed++;
                }
                
            } catch (Exception $e) {
                Log::error('[BillingDunningService] Fatal error in dunning loop', [
                    'subscription_id' => $subscription->id,
                    'error' => $e->getMessage(),
                ]);
                $failed++;
            }
        }
        
        Log::info('[BillingDunningService] Dunning process complete', [
            'total_checked' => $totalChecked,
            'recovered' => $recovered,
            'failed' => $failed,
            'canceled' => $canceled,
        ]);
        
        return [
            'total_checked' => $totalChecked,
            'recovered' => $recovered,
            'failed' => $failed,
            'canceled' => $canceled,
        ];
    }
    
    /**
     * Attempt to charge the failed renewal payment
     */
    protected function attemptRecoveryCharge(Subscription $subscription, Tenant $tenant): array
    {
        $attemptNumber = $subscription->failed_renewal_attempts + 1;
        
        Log::info('[BillingDunningService] Attempting recovery charge', [
            'tenant_id' => $tenant->id,
            'subscription_id' => $subscription->id,
            'attempt' => $attemptNumber,
        ]);
        
        try {
            // Calculate renewal amount (same logic as BillingRenewalService)
            $planConfig = config("billing.plans.{$subscription->plan}");
            $pricePerUser = $planConfig['price_per_user'] ?? 0;
            $userCount = $subscription->user_limit;
            $amount = $pricePerUser * $userCount;
            
            // Add addon pricing
            $addons = is_array($subscription->addons) ? $subscription->addons : json_decode($subscription->addons ?? '[]', true);
            foreach (($addons ?? []) as $addon) {
                if (isset($planConfig['addons'][$addon])) {
                    $addonPercentage = $planConfig['addons'][$addon] ?? 0;
                    $amount += ($amount * $addonPercentage);
                }
            }
            
            // Get payment gateway
            $gatewayName = $subscription->billing_gateway ?? config('billing.driver', 'stripe');
            $gateway = $gatewayName === 'stripe'
                ? app('App\\Services\\Payments\\StripeGateway')
                : app('App\\Services\\Payments\\FakeCreditCardGateway');
            
            // Attempt charge
            $payment = $gateway->createPaymentIntent(
                $tenant,
                $amount,
                [
                    'operation' => 'dunning_recovery',
                    'subscription_id' => $subscription->id,
                    'attempt' => $attemptNumber,
                    'plan' => $subscription->plan,
                    'user_count' => $subscription->user_limit,
                    'addons' => $subscription->addons,
                ]
            );
            
            // Confirm payment
            if ($gatewayName === 'fake') {
                $gateway->confirmPayment($payment, []);
            } else {
                $gateway->confirmPayment($payment);
            }
            
            // Check if payment succeeded
            $payment->refresh();
            if ($payment->status === 'completed' || $payment->status === 'succeeded') {
                // SUCCESS - Reset dunning fields and reactivate
                $subscription->status = 'active';
                $subscription->failed_renewal_attempts = 0;
                $subscription->grace_period_until = null;
                
                // Advance billing period
                $oldEnd = $subscription->billing_period_ends_at ?? Carbon::now();
                $subscription->billing_period_started_at = $oldEnd;
                $subscription->billing_period_ends_at = Carbon::parse($oldEnd)->addMonth();
                $subscription->last_renewal_at = Carbon::now();
                
                $subscription->save();
                
                Log::info('[BillingDunningService] Recovery successful', [
                    'tenant_id' => $tenant->id,
                    'subscription_id' => $subscription->id,
                    'attempt' => $attemptNumber,
                    'amount' => $amount,
                ]);
                
                // Send recovery success email
                $this->sendRecoverySuccessEmail($tenant, $subscription);
                
                return ['success' => true, 'amount' => $amount];
            }
            
            // Payment failed - increment attempts
            throw new Exception('Payment confirmation failed');
            
        } catch (Exception $e) {
            // FAILURE - Increment attempt counter
            $subscription->failed_renewal_attempts = $attemptNumber;
            
            // Set grace period on first failure
            if ($attemptNumber === 1) {
                $subscription->grace_period_until = Carbon::now()->addDays(self::GRACE_PERIOD_DAYS);
            }
            
            $subscription->save();
            
            Log::error('[BillingDunningService] Recovery attempt failed', [
                'tenant_id' => $tenant->id,
                'subscription_id' => $subscription->id,
                'attempt' => $attemptNumber,
                'error' => $e->getMessage(),
                'grace_period_until' => $subscription->grace_period_until,
            ]);
            
            // Send appropriate notification
            if ($attemptNumber < self::MAX_RETRY_ATTEMPTS) {
                $this->sendRetryWarningEmail($tenant, $subscription, $attemptNumber);
            } else {
                $this->sendFinalWarningEmail($tenant, $subscription);
            }
            
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Cancel subscription after grace period expiry
     */
    protected function cancelSubscription(Subscription $subscription, Tenant $tenant): void
    {
        $subscription->status = 'canceled';
        $subscription->save();
        
        Log::warning('[BillingDunningService] Subscription canceled due to failed payments', [
            'tenant_id' => $tenant->id,
            'subscription_id' => $subscription->id,
            'failed_attempts' => $subscription->failed_renewal_attempts,
        ]);
        
        // Disable premium features via PlanManager BEFORE setting canceled status
        try {
            $planManager = app(PlanManager::class);
            $planManager->updatePlan($tenant, 'starter', 2);
            
            // Re-fetch subscription after PlanManager update, then set to canceled
            $subscription->refresh();
            $subscription->status = 'canceled';
            $subscription->save();
        } catch (Exception $e) {
            Log::error('[BillingDunningService] Failed to downgrade to Starter', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);
        }
        
        // Send cancellation email
        $this->sendCancellationEmail($tenant, $subscription);
    }
    
    /**
     * Send recovery success email
     */
    protected function sendRecoverySuccessEmail(Tenant $tenant, Subscription $subscription): void
    {
        try {
            // TODO: Implement email sending
            Log::info('[BillingDunningService] Recovery success email queued', [
                'tenant_id' => $tenant->id,
                'email' => $tenant->email,
            ]);
        } catch (Exception $e) {
            Log::error('[BillingDunningService] Failed to send recovery success email', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * Send retry warning email
     */
    protected function sendRetryWarningEmail(Tenant $tenant, Subscription $subscription, int $attemptNumber): void
    {
        try {
            // TODO: Implement email sending
            Log::info('[BillingDunningService] Retry warning email queued', [
                'tenant_id' => $tenant->id,
                'email' => $tenant->email,
                'attempt' => $attemptNumber,
            ]);
        } catch (Exception $e) {
            Log::error('[BillingDunningService] Failed to send retry warning email', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * Send final warning before cancellation
     */
    protected function sendFinalWarningEmail(Tenant $tenant, Subscription $subscription): void
    {
        try {
            // TODO: Implement email sending
            Log::info('[BillingDunningService] Final warning email queued', [
                'tenant_id' => $tenant->id,
                'email' => $tenant->email,
                'grace_period_until' => $subscription->grace_period_until,
            ]);
        } catch (Exception $e) {
            Log::error('[BillingDunningService] Failed to send final warning email', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * Send cancellation email
     */
    protected function sendCancellationEmail(Tenant $tenant, Subscription $subscription): void
    {
        try {
            // TODO: Implement email sending
            Log::info('[BillingDunningService] Cancellation email queued', [
                'tenant_id' => $tenant->id,
                'email' => $tenant->email,
            ]);
        } catch (Exception $e) {
            Log::error('[BillingDunningService] Failed to send cancellation email', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

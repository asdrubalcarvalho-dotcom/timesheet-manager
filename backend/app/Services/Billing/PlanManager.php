<?php

namespace App\Services\Billing;

use App\Models\Tenant;
use Modules\Billing\Models\Subscription;
use Illuminate\Support\Carbon;
use Laravel\Pennant\Feature;
use App\Models\User;

/**
 * PlanManager
 *
 * Controls subscription lifecycle:
 * - Starting/ending trial
 * - Upgrading/downgrading plans
 * - Syncing Pennant feature flags
 * - Enforcing business rules between Starter, Team, Enterprise
 */
class PlanManager
{
    /**
     * Start a 15‑day Enterprise trial for a tenant.
     */
    public function startTrialForTenant(Tenant $tenant): Subscription
    {
        $trialConfig = config('billing.trial');
        $days        = $trialConfig['days'] ?? 15;

        $subscription = $tenant->subscription ?? new Subscription([
            'tenant_id' => $tenant->id,
        ]);

        $subscription->plan          = $trialConfig['plan'] ?? 'enterprise';
        $subscription->is_trial      = true;
        $subscription->trial_ends_at = Carbon::now()->addDays($days);
        $subscription->status        = 'active';
        $subscription->user_limit    = null; // Trial has NO user limit (unlimited users)
        $subscription->addons        = []; // All included during trial
        $subscription->save();

        $this->syncFeaturesForSubscription($tenant, $subscription);

        return $subscription;
    }

    /**
     * End trial and downgrade to Starter plan.
     * 
     * Called when trial expires (either manually or via scheduled command).
     * Idempotent: safe to call multiple times, will not error if trial already ended.
     * 
     * @param Tenant $tenant
     * @return Subscription|null Returns updated subscription, or null if no subscription exists
     */
    public function endTrialForTenant(Tenant $tenant): ?Subscription
    {
        $subscription = $tenant->subscription;

        // No subscription = nothing to do
        if (!$subscription) {
            return null;
        }

        // Already ended = idempotent, return as-is
        if (!$subscription->is_trial) {
            return $subscription;
        }

        // End trial only if trial_ends_at has passed (or is null)
        if ($subscription->trial_ends_at && $subscription->trial_ends_at->isFuture()) {
            // Trial still active, don't end it
            return $subscription;
        }

        // Store previous plan for history logging
        $previousPlan = $subscription->plan;
        
        // Downgrade to Starter plan
        $subscription->plan          = 'starter';
        $subscription->is_trial      = false;
        $subscription->trial_ends_at = null;
        $subscription->addons        = []; // Starter has no addons
        $subscription->user_limit    = 2;  // Starter allows max 2 users
        $subscription->status        = 'active'; // Keep active
        
        // Clear any pending downgrade (trial expiration takes precedence)
        $subscription->pending_plan = null;
        $subscription->pending_user_limit = null;
        
        $subscription->save();

        // Log the trial expiration as a plan change
        $this->logPlanChange(
            $tenant,
            $previousPlan,
            'starter',
            null, // trial has no user limit
            2,    // starter has 2 users
            'system',
            'Trial expired - downgraded to Starter plan'
        );

        // Sync Pennant features to Starter (only timesheets + expenses)
        $this->syncPennantFeatures($tenant, $subscription);

        return $subscription;
    }

    /**
     * Manual downgrade from trial (wrapper).
     */
    public function downgradeFromTrial(Tenant $tenant): void
    {
        $this->endTrialForTenant($tenant);
    }

    /**
     * Apply a new plan (Starter → Team → Enterprise).
     */
    public function applyPlan(Tenant $tenant, string $plan, array $addons = []): Subscription
    {
        $this->validatePlan($plan);

        $subscription = $tenant->subscription ?? new Subscription([
            'tenant_id' => $tenant->id,
        ]);

        $previousPlan = $subscription->plan;
        $previousUserLimit = $subscription->user_limit;

        $subscription->plan          = $plan;
        $subscription->is_trial      = false;
        $subscription->trial_ends_at = null;
        $subscription->addons        = $addons;
        $subscription->status        = 'active';
        
        // Handle user_limit based on plan type
        if ($plan === 'starter') {
            // Starter: Force user_limit = 2 (hard limit)
            $subscription->user_limit = 2;
        } else {
            // Team/Enterprise: Preserve existing user_limit OR set to active user count if upgrading from Starter
            if ($previousPlan === 'starter') {
                // Upgrading from Starter → preserve current user_limit (already set by checkout)
                // If not set, use active technician count as fallback
                if (!$subscription->user_limit) {
                    $activeUserCount = $tenant->run(function () {
                        return \App\Models\Technician::where('is_active', 1)->count();
                    });
                    $subscription->user_limit = max(1, $activeUserCount);
                }
            }
            // For Team → Enterprise or any other case: keep existing user_limit (don't overwrite)
        }
        
        // Set subscription_start_date on first paid plan (immutable after set)
        if ($plan !== 'starter') {
            $this->setSubscriptionStartDate($subscription);
        }
        
        $subscription->save();

        // Log plan change if this is an existing subscription
        if ($previousPlan) {
            $this->logPlanChange(
                $tenant,
                $previousPlan,
                $plan,
                $previousUserLimit,
                $subscription->user_limit,
                auth()->user()?->email ?? 'system',
                'Plan applied via applyPlan method'
            );
        }

        $this->syncFeaturesForSubscription($tenant, $subscription);

        return $subscription;
    }

    /**
     * Get feature flags for a subscription based on plan + addons + trial.
     * 
     * This is the SINGLE SOURCE OF TRUTH for which features should be active.
     * Returns: ['timesheets' => bool, 'expenses' => bool, 'travels' => bool, 'planning' => bool, 'ai' => bool]
     * 
     * BUSINESS RULES:
     * - STARTER: only timesheets + expenses, no addons allowed
     * - TEAM: timesheets + expenses + travels, planning/ai via addons
     * - ENTERPRISE: all features included
     * - TRIAL_ENTERPRISE: same as enterprise
     */
    private function getFeatureFlagsForSubscription(Subscription $subscription): array
    {
        $plan = $subscription->plan;
        
        // Trial behaves like Enterprise for features
        if ($subscription->is_trial) {
            $plan = 'enterprise';
        }
        
        // Start with base plan features from config
        $features = match($plan) {
            'starter' => [
                'timesheets' => true,
                'expenses'   => true,
                'travels'    => false,
                'planning'   => false,
                'ai'         => false,
            ],
            'team' => [
                'timesheets' => true,
                'expenses'   => true,
                'travels'    => true,
                'planning'   => false, // addon-based
                'ai'         => false, // addon-based
            ],
            'enterprise' => [
                'timesheets' => true,
                'expenses'   => true,
                'travels'    => true,
                'planning'   => true,
                'ai'         => true,
            ],
            default => [
                'timesheets' => true,
                'expenses'   => true,
                'travels'    => false,
                'planning'   => false,
                'ai'         => false,
            ],
        };
        
        // Apply addons for TEAM plan only (Starter doesn't allow, Enterprise includes all)
        if ($plan === 'team' && !$subscription->is_trial) {
            $addons = $subscription->addons ?? [];
            if (in_array('planning', $addons)) {
                $features['planning'] = true;
            }
            if (in_array('ai', $addons)) {
                $features['ai'] = true;
            }
        }
        
        return $features;
    }
    
    /**
     * Sync Pennant feature flags for a tenant based on subscription.
     * 
     * Uses getFeatureFlagsForSubscription() as single source of truth.
     */
    private function syncPennantFeatures(Tenant $tenant, Subscription $subscription): void
    {
        $features = $this->getFeatureFlagsForSubscription($subscription);
        
        foreach ($features as $feature => $enabled) {
            if ($enabled) {
                Feature::for($tenant)->activate($feature);
            } else {
                Feature::for($tenant)->deactivate($feature);
            }
        }
    }
    
    /**
     * Legacy wrapper for backwards compatibility.
     * @deprecated Use syncPennantFeatures() directly
     */
    protected function syncFeaturesForSubscription(Tenant $tenant, Subscription $subscription): void
    {
        $this->syncPennantFeatures($tenant, $subscription);
    }

    /**
     * Validate plan.
     */
    protected function validatePlan(string $plan): void
    {
        $valid = ['starter', 'team', 'enterprise'];
        if (!in_array($plan, $valid)) {
            throw new \InvalidArgumentException(
                "Invalid plan '{$plan}'. Must be one of: " . implode(', ', $valid)
            );
        }
    }

    /**
     * Get comprehensive subscription summary for tenant.
     * 
     * Returns current billing state by delegating ALL pricing logic
     * to PriceCalculator and adding subscription metadata.
     * 
     * Auto-expires trial if trial_ends_at has passed.
     * 
     * @param Tenant $tenant
     * @return array
     */
    public function getSubscriptionSummary(Tenant $tenant): array
    {
        // Auto-expire trial if past end date (ensures API never shows expired trial as active)
        $subscription = $tenant->subscription;
        if ($subscription && $subscription->is_trial && $subscription->trial_ends_at) {
            if ($subscription->trial_ends_at->isPast()) {
                $this->endTrialForTenant($tenant);
                // Reload both tenant and subscription relationship after changes
                $tenant->refresh();
                $tenant->load('subscription');
                $subscription = $tenant->subscription; // Re-assign to get fresh data
            }
        }
        
        // Get pricing calculation from PriceCalculator (never duplicate logic)
        $calculator = app(PriceCalculator::class);
        $summary = $calculator->calculateForTenant($tenant);
        
        // Add subscription metadata
        $subscription = $tenant->subscription;
        
        if ($subscription) {
            $summary['subscription'] = [
                'id' => $subscription->id,
                'status' => $subscription->status,
                'subscription_start_date' => $subscription->subscription_start_date?->toIso8601String(),
                'next_renewal_at' => $subscription->next_renewal_at?->toIso8601String(),
                'created_at' => $subscription->created_at->toIso8601String(),
            ];
            
            // Add user_limit to response (frontend may need it)
            $summary['user_limit'] = $subscription->user_limit;

            // Add pending downgrade info if scheduled
            if ($subscription->hasPendingDowngrade()) {
                $summary['pending_downgrade'] = [
                    'target_plan' => $subscription->pending_plan,
                    'target_user_limit' => $subscription->pending_user_limit,
                    'effective_at' => $subscription->next_renewal_at?->toIso8601String(),
                ];
                // Check if downgrade can be cancelled
                $summary['can_cancel_downgrade'] = $this->canCancelDowngrade($subscription);
            } else {
                $summary['can_cancel_downgrade'] = false;
            }
        } else {
            // No subscription = implicit Starter
            $summary['subscription'] = null;
            $summary['user_limit'] = 2; // Starter default
            $summary['can_cancel_downgrade'] = false;
        }
        
        return $summary;
    }

    /**
     * Update subscription plan and user limit.
     * 
     * This is for IMMEDIATE upgrades only.
     * Sets next_renewal_at = now + 30 days.
     * 
     * SANITIZATION RULES:
     * - user_limit > 500: reset to null (cleanup abnormal test values like 99999)
     * - Otherwise: preserve exactly as requested for accurate billing
     * 
     * EXAMPLE: Upgrading from STARTER (2 users) to TEAM with user_limit=2:
     * - Result: subscription.user_limit = 2 (billing: 2 × €44 = €88/month)
     * - Adding +1 license: user_limit = 3 (billing: 3 × €44 = €132/month)
     * 
     * @param Tenant $tenant
     * @param string $plan
     * @param int $userLimit
     * @return Subscription
     */
    public function updatePlan(Tenant $tenant, string $plan, int $userLimit): Subscription
    {
        $previousPlan = $tenant->subscription?->plan;
        $previousUserLimit = $tenant->subscription?->user_limit;
        
        // Get current addons (preserve them during plan upgrade)
        $currentAddons = $tenant->subscription?->addons ?? [];
        
        // Apply the plan change
        $subscription = $this->applyPlan($tenant, $plan, $currentAddons);
        
        // Force user_limit = 2 for Starter, otherwise sanitize and apply
        if ($plan === 'starter') {
            $subscription->user_limit = 2;
        } else {
            // SANITIZATION LOGIC FOR TEAM & ENTERPRISE PLANS
            $sanitizedUserLimit = $this->sanitizeUserLimit($userLimit, $previousPlan, $plan, $previousUserLimit);
            $subscription->user_limit = $sanitizedUserLimit;
            
            // Log sanitization if value changed
            if ($sanitizedUserLimit !== $userLimit) {
                \Log::info('[PlanManager] user_limit sanitized during upgrade', [
                    'tenant_id' => $tenant->id,
                    'previous_plan' => $previousPlan,
                    'new_plan' => $plan,
                    'requested_limit' => $userLimit,
                    'sanitized_limit' => $sanitizedUserLimit,
                    'reason' => $userLimit > 500 ? 'test_value_cleanup' : 'starter_to_paid_upgrade',
                ]);
            }
        }
        
        // CRITICAL: Set next_renewal_at = now + 30 days for IMMEDIATE upgrades
        $subscription->next_renewal_at = Carbon::now()->addDays(30);
        
        $subscription->save();
        
        return $subscription;
    }
    
    /**
     * Sanitize user_limit to prevent preserving abnormal test values.
     * 
     * RULES:
     * 1. Abnormally high values (test leftovers like 99999) → reset to null
     * 2. Otherwise: preserve user_limit exactly as requested (purchased licenses)
     * 
     * REMOVED: Auto-reset to unlimited for STARTER upgrades - users must explicitly
     * purchase licenses. If upgrading from STARTER with 2 users to TEAM, user_limit
     * should be 2 (not null/unlimited).
     * 
     * @param int $requestedLimit The user_limit from the upgrade request
     * @param string|null $previousPlan The plan before upgrade
     * @param string $newPlan The target plan after upgrade
     * @param int|null $previousUserLimit The previous user_limit value
     * @return int|null The sanitized user_limit
     */
    private function sanitizeUserLimit(int $requestedLimit, ?string $previousPlan, string $newPlan, ?int $previousUserLimit): ?int
    {
        // Protection: Abnormally high values (test leftovers like 99999)
        if ($requestedLimit > 500) {
            return null; // Reset to unlimited
        }
        
        // Preserve exactly what was requested - no auto-resets
        // This ensures billing accuracy: 2 users = 2 licenses = 2 × price
        return $requestedLimit;
    }

    /**
     * Toggle addon on/off for current subscription.
     * 
     * BUSINESS RULES:
     * - STARTER: Addons not allowed (caught in controller with HTTP 400)
     * - TEAM: Planning and AI as optional addons
     * - ENTERPRISE: All features included, addons are no-op
     * 
     * @param Tenant $tenant
     * @param string $addon ('planning' or 'ai')
     * @return array Returns ['addon' => string, 'action' => 'enabled'|'disabled', 'subscription' => Subscription]
     * @throws \InvalidArgumentException if addon is invalid or plan doesn't support it
     * @throws \RuntimeException if no active subscription exists
     */
    public function toggleAddon(Tenant $tenant, string $addon): array
    {
        $validAddons = ['planning', 'ai'];
        if (!in_array($addon, $validAddons)) {
            throw new \InvalidArgumentException("Invalid addon '{$addon}'. Must be one of: " . implode(', ', $validAddons));
        }
        
        $subscription = $tenant->subscription;
        
        if (!$subscription || !$subscription->isActive()) {
            throw new \RuntimeException('No active subscription found. Cannot toggle addons.');
        }
        
        // STARTER: Should be caught by controller before reaching here
        if ($subscription->plan === 'starter') {
            throw new \InvalidArgumentException('Starter plan does not support addons. Please upgrade to Team or Enterprise.');
        }
        
        // ENTERPRISE: All features already included, no-op
        if ($subscription->plan === 'enterprise') {
            return [
                'addon' => $addon,
                'action' => 'no_change',
                'message' => 'All features are already included in Enterprise plan.',
                'subscription' => $subscription,
            ];
        }
        
        // TEAM: Toggle addon
        $currentAddons = $subscription->addons ?? [];
        
        if (in_array($addon, $currentAddons)) {
            // Remove addon
            $currentAddons = array_values(array_diff($currentAddons, [$addon]));
            $action = 'disabled';
        } else {
            // Add addon
            $currentAddons[] = $addon;
            $action = 'enabled';
        }
        
        // Update subscription
        $subscription->addons = $currentAddons;
        $subscription->save();
        
        // Sync Pennant features after addon change
        $this->syncPennantFeatures($tenant, $subscription);
        
        return [
            'addon' => $addon,
            'action' => $action,
            'subscription' => $subscription,
        ];
    }

    /**
     * Schedule a downgrade for the next billing cycle.
     * 
     * SPECIAL CASE: If subscription is_trial=true, applies change IMMEDIATELY (trial→paid conversion).
     * 
     * For paid plans, downgrades are NEVER immediate:
     * - Store pending_plan and pending_user_limit
     * - Features change immediately via Pennant
     * - Billing price remains unchanged until next renewal
     * 
     * @param Tenant $tenant
     * @param string $targetPlan The plan to downgrade to
     * @param int|null $targetUserLimit Optional user limit for target plan
     * @return array Response with scheduled downgrade info or immediate trial conversion
     * @throws \InvalidArgumentException
     */
    public function scheduleDowngrade(Tenant $tenant, string $targetPlan, ?int $targetUserLimit = null): array
    {
        $this->validatePlan($targetPlan);

        $subscription = $tenant->subscription;

        if (!$subscription) {
            throw new \InvalidArgumentException('No active subscription found.');
        }

        // SPECIAL CASE: Trial → Paid Plan (IMMEDIATE conversion)
        if ($subscription->is_trial) {
            return $this->applyTrialToPaidConversion($tenant, $subscription, $targetPlan, $targetUserLimit);
        }

        // ===== PAID PLAN DOWNGRADE LOGIC (Scheduled) =====

        // Prevent scheduling new downgrade if one already pending
        if ($subscription->hasPendingDowngrade()) {
            throw new \InvalidArgumentException(
                "A downgrade to {$subscription->pending_plan} is already scheduled. Cancel it first to schedule a different downgrade."
            );
        }

        // Cannot downgrade from Starter (already lowest tier)
        if ($subscription->plan === 'starter') {
            throw new \InvalidArgumentException('Cannot downgrade from Starter plan (already lowest tier).');
        }

        // Validate downgrade direction (must be downward)
        $planHierarchy = ['starter' => 1, 'team' => 2, 'enterprise' => 3];
        $currentLevel = $planHierarchy[$subscription->plan];
        $targetLevel = $planHierarchy[$targetPlan];

        if ($targetLevel >= $currentLevel) {
            throw new \InvalidArgumentException("Cannot schedule downgrade from {$subscription->plan} to {$targetPlan}. Use upgrade instead.");
        }

        // Get current active user count
        $currentUserCount = $tenant->run(function () {
            return \App\Models\Technician::where('is_active', 1)->count();
        });
        
        // Get current purchased licenses
        $currentLicenses = $subscription->user_limit ?? 0;

        // Validate licenses for target plan (not active users, but purchased licenses)
        if ($targetPlan === 'starter') {
            if ($currentUserCount > 2) {
                throw new \InvalidArgumentException(
                    "Cannot convert to Starter plan. You have {$currentUserCount} active users, but Starter supports only 2. Please reduce users first or select another plan."
                );
            }
            $targetUserLimit = 2; // Force Starter limit
        } elseif ($targetPlan === 'team') {
            if ($currentLicenses > 50) {
                throw new \InvalidArgumentException(
                    "Cannot downgrade to Team plan. You currently have {$currentLicenses} licenses, but Team plan supports up to 50 licenses. Please reduce your licenses first or contact support."
                );
            }
            $targetUserLimit = $targetUserLimit ?? min($currentLicenses, 50);
        } elseif ($targetPlan === 'enterprise') {
            if ($currentLicenses > 150) {
                throw new \InvalidArgumentException(
                    "Cannot downgrade to Enterprise plan. You currently have {$currentLicenses} licenses, but Enterprise plan supports up to 150 licenses. Please reduce your licenses first or contact support."
                );
            }
            $targetUserLimit = $targetUserLimit ?? min($currentLicenses, 150);
        } elseif ($targetUserLimit && $currentUserCount > $targetUserLimit) {
            throw new \InvalidArgumentException(
                "Cannot convert to this plan. You have {$currentUserCount} active users, but requested limit is {$targetUserLimit}. Please reduce users first or select another plan."
            );
        }

        // Store pending downgrade
        $subscription->pending_plan = $targetPlan;
        $subscription->pending_user_limit = $targetUserLimit ?? ($targetPlan === 'starter' ? 2 : $subscription->user_limit);
        $subscription->save();

        // Log the scheduled downgrade
        $this->logPlanChange(
            $tenant,
            $subscription->plan,
            $targetPlan,
            $subscription->user_limit,
            $subscription->pending_user_limit,
            auth()->user()?->email ?? 'system',
            'Downgrade scheduled for next renewal'
        );

        // DO NOT apply feature changes yet - features remain active until renewal
        // Features will be synced when applyPendingDowngrade() runs at renewal time

        return [
            'success' => true,
            'message' => 'Downgrade scheduled for next billing cycle.',
            'effective_at' => $subscription->next_renewal_at?->toIso8601String(),
            'current_plan' => $subscription->plan,
            'next_plan' => $targetPlan,
            'pending_user_limit' => $subscription->pending_user_limit,
        ];
    }

    /**
     * Apply immediate trial-to-paid conversion.
     * 
     * Called when user selects ANY plan while in trial.
     * Does NOT schedule - applies immediately.
     * 
     * @param Tenant $tenant
     * @param Subscription $subscription Current trial subscription
     * @param string $targetPlan 'starter' | 'team' | 'enterprise'
     * @param int|null $targetUserLimit User limit for target plan
     * @return array Response with immediate change confirmation
     * @throws \InvalidArgumentException
     */
    private function applyTrialToPaidConversion(
        Tenant $tenant,
        Subscription $subscription,
        string $targetPlan,
        ?int $targetUserLimit = null
    ): array {
        // Get current active user count
        $currentUserCount = $tenant->run(function () {
            return \App\Models\Technician::where('is_active', 1)->count();
        });

        // Validate user limits for target plan
        if ($targetPlan === 'starter') {
            if ($currentUserCount > 2) {
                throw new \InvalidArgumentException(
                    "Cannot convert to Starter plan. You have {$currentUserCount} active users, but Starter supports only 2. Please reduce users first or select another plan."
                );
            }
            $targetUserLimit = 2; // Force Starter limit
        } elseif ($targetUserLimit && $currentUserCount > $targetUserLimit) {
            throw new \InvalidArgumentException(
                "Cannot convert to this plan. You have {$currentUserCount} active users, but requested limit is {$targetUserLimit}. Please reduce users first or select another plan."
            );
        }

        // Store previous values for logging
        $previousPlan = $subscription->plan;
        $previousUserLimit = $subscription->user_limit;

        // IMMEDIATE APPLICATION (no scheduling)
        $subscription->plan = $targetPlan;
        $subscription->is_trial = false;
        $subscription->trial_ends_at = null;
        $subscription->user_limit = $targetUserLimit ?? ($targetPlan === 'starter' ? 2 : 5); // Default to 5 for team/enterprise
        $subscription->addons = []; // Start fresh (user can add later)
        $subscription->status = 'active';

        // Set subscription_start_date (immutable, only set once)
        if ($subscription->subscription_start_date === null) {
            $subscription->subscription_start_date = Carbon::now();
        }

        // Calculate next_renewal_at = subscription_start_date + 1 month
        $subscription->next_renewal_at = $subscription->subscription_start_date->copy()->addMonth();

        // Clear any pending downgrade (shouldn't exist during trial, but safety)
        $subscription->pending_plan = null;
        $subscription->pending_user_limit = null;

        $subscription->save();

        // Log the immediate conversion
        $this->logPlanChange(
            $tenant,
            $previousPlan,
            $targetPlan,
            $previousUserLimit,
            $subscription->user_limit,
            auth()->user()?->email ?? 'system',
            'Trial converted to paid plan (immediate)'
        );

        // Sync Pennant features for new paid plan
        $this->syncPennantFeatures($tenant, $subscription);

        return [
            'success' => true,
            'message' => "Trial ended. You are now on the {$targetPlan} plan.",
            'is_immediate' => true, // Flag for frontend to distinguish from scheduled
            'plan' => $subscription->plan,
            'user_limit' => $subscription->user_limit,
            'subscription_start_date' => $this->toIso8601($subscription->subscription_start_date),
            'next_renewal_at' => $this->toIso8601($subscription->next_renewal_at),
            'is_trial' => false,
        ];
    }

    /**
     * Apply a pending downgrade (called at renewal time).
     * 
     * This should be called by cron job or renewal processor.
     * 
     * @param Tenant $tenant
     * @return Subscription|null
     */
    public function applyPendingDowngrade(Tenant $tenant): ?Subscription
    {
        $subscription = $tenant->subscription;

        if (!$subscription || !$subscription->hasPendingDowngrade()) {
            return null;
        }

        // Apply the downgrade
        $subscription->plan = $subscription->pending_plan;
        $subscription->user_limit = $subscription->pending_user_limit;
        $subscription->addons = []; // Clear addons on downgrade
        
        // Clear pending fields
        $subscription->clearPendingDowngrade();

        // Sync features NOW (features were kept active until this moment)
        $this->syncPennantFeatures($tenant, $subscription);

        return $subscription;
    }

    /**
     * Cancel a scheduled downgrade.
     * 
     * RULE: Can only cancel if more than 24 hours remain before next_renewal_at.
     * 
     * @param Tenant $tenant
     * @return array Response with success message
     * @throws \InvalidArgumentException
     */
    public function cancelScheduledDowngrade(Tenant $tenant): array
    {
        $subscription = $tenant->subscription;

        if (!$subscription) {
            throw new \InvalidArgumentException('No active subscription found.');
        }

        if (!$subscription->hasPendingDowngrade()) {
            throw new \InvalidArgumentException('No scheduled downgrade to cancel.');
        }

        // Validate 24h window using helper method
        if (!$this->canCancelDowngrade($subscription)) {
            $hoursUntilRenewal = Carbon::now()->diffInHours($subscription->next_renewal_at, false);
            throw new \InvalidArgumentException(
                "Cannot cancel downgrade. Only {$hoursUntilRenewal} hours until renewal (24h minimum required)."
            );
        }

        // Clear the pending downgrade
        $previousTargetPlan = $subscription->pending_plan;
        $subscription->clearPendingDowngrade();

        // NO feature sync needed - features were never changed during scheduling

        // Log cancellation
        $this->logPlanChange(
            $tenant,
            $previousTargetPlan,
            $subscription->plan,
            $subscription->pending_user_limit,
            $subscription->user_limit,
            auth()->user()?->email ?? 'system',
            'Scheduled downgrade cancelled by user'
        );

        return [
            'success' => true,
            'message' => 'Scheduled downgrade cancelled successfully.',
            'current_plan' => $subscription->plan,
        ];
    }

    /**
     * Set subscription start date when first paid plan is selected.
     * This date is PERMANENT and never changes (even with plan changes).
     * 
     * @param Subscription $subscription
     * @return void
     */
    public function setSubscriptionStartDate(Subscription $subscription): void
    {
        // Only set if not already set (immutable after first set)
        if ($subscription->subscription_start_date === null) {
            $subscription->subscription_start_date = Carbon::now();
            $subscription->save();
        }
    }

    /**
     * Helper to safely convert Carbon to ISO8601 string for JSON responses.
     * 
     * @param Carbon|null $date
     * @return string|null
     */
    private function toIso8601($date): ?string
    {
        return $date ? $date->toIso8601String() : null;
    }

    /**
     * Calculate next renewal date based on subscription_start_date + n months.
     * Always aligns to the same day of month as original subscription start.
     * 
     * @param Subscription $subscription
     * @return Carbon
     */
    public function calculateNextRenewal(Subscription $subscription): Carbon
    {
        if (!$subscription->subscription_start_date) {
            // Fallback: use current next_renewal_at or calculate from now
            return $subscription->next_renewal_at ?? Carbon::now()->addMonth();
        }

        $startDate = $subscription->subscription_start_date;
        $now = Carbon::now();

        // Find how many months since start
        $monthsSinceStart = $startDate->diffInMonths($now);
        
        // Calculate next renewal as start_date + (n+1) months
        $nextRenewal = $startDate->copy()->addMonths($monthsSinceStart + 1);

        // If calculated date is in the past, add another month
        while ($nextRenewal->isPast()) {
            $nextRenewal->addMonth();
        }

        return $nextRenewal;
    }

    /**
     * Log a plan change to subscription_plan_history table.
     * 
     * @param Tenant $tenant
     * @param string|null $previousPlan
     * @param string $newPlan
     * @param int|null $previousUserLimit
     * @param int|null $newUserLimit
     * @param string|null $changedBy User email or 'system'
     * @param string|null $notes Optional notes about the change
     * @return void
     */
    public function logPlanChange(
        Tenant $tenant,
        ?string $previousPlan,
        string $newPlan,
        ?int $previousUserLimit = null,
        ?int $newUserLimit = null,
        ?string $changedBy = null,
        ?string $notes = null
    ): void {
        \App\Models\Modules\Billing\Models\PlanChangeHistory::create([
            'tenant_id' => $tenant->id,
            'previous_plan' => $previousPlan,
            'new_plan' => $newPlan,
            'previous_user_limit' => $previousUserLimit,
            'new_user_limit' => $newUserLimit,
            'changed_at' => Carbon::now(),
            'changed_by' => $changedBy ?? 'system',
            'notes' => $notes,
        ]);
    }

    /**
     * Check if a scheduled downgrade can be cancelled (must be >24h before renewal).
     * 
     * @param Subscription $subscription
     * @return bool
     */
    public function canCancelDowngrade(Subscription $subscription): bool
    {
        if (!$subscription->hasPendingDowngrade()) {
            return false;
        }

        if (!$subscription->next_renewal_at) {
            return false;
        }

        $hoursUntilRenewal = Carbon::now()->diffInHours($subscription->next_renewal_at, false);
        
        return $hoursUntilRenewal > 24;
    }
}


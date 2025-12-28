<?php
 /* =====================================================================
 * IMPORTANT — BILLING CONTROLLER INTENT & SAFETY NOTES (COMMENTS ONLY)
 * =====================================================================
 *
 * ⚠️ DO NOT REFACTOR OR SIMPLIFY THIS FILE WITHOUT FULL BILLING CONTEXT.
 *
 * This controller intentionally contains:
 * - Multiple checkout modes (plan / licenses / addon)
 * - Snapshot-based billing (Billing Model A)
 * - Trial-specific behavior (Enterprise trial → paid transitions)
 * - Strict separation between:
 *      • user_limit  = BILLABLE / PURCHASED LICENSES
 *      • user_count  = DISPLAY-ONLY ACTIVE USERS
 *
 * ---------------------------------------------------------------------
 * CORE CONCEPTS (READ BEFORE CHANGES)
 * ---------------------------------------------------------------------
 *
 * 1) user_limit vs user_count
 * ---------------------------------------------------------------------
 * - user_limit:
 *     • Stored on Subscription
 *     • Represents PURCHASED licenses
 *     • Used for billing, enforcement, pricing
 *     • NULL during trial = unlimited users
 *     • Starter plan ALWAYS forced to 2
 *
 * - user_count:
 *     • Computed at runtime (active technicians)
 *     • DISPLAY ONLY
 *     • NEVER used for billing calculations
 *
 * Mixing these concepts WILL break billing correctness.
 *
 * ---------------------------------------------------------------------
 * 2) Trial Enterprise is a VIRTUAL PLAN
 * ---------------------------------------------------------------------
 * - Database:
 *     plan = 'enterprise'
 *     is_trial = true
 *
 * - API / Frontend:
 *     plan = 'trial_enterprise'
 *
 * This transformation is intentional and REQUIRED.
 * Do NOT attempt to "clean this up" or normalize it.
 *
 * ---------------------------------------------------------------------
 * 3) Checkout MODES are intentionally implicit
 * ---------------------------------------------------------------------
 * checkoutStart() supports multiple behaviors via `mode`:
 *
 * - mode = plan
 *     • Plan upgrade / downgrade pricing
 *     • License count is PRESERVED (not changed here)
 *
 * - mode = licenses
 *     • Explicit purchase of additional licenses
 *     • user_limit MUST increase
 *
 * - mode = addon
 *     • Activates billing add-ons (planning / ai)
 *
 * There is NO explicit operation_type field by design.
 * Behavior is inferred intentionally.
 *
 * ---------------------------------------------------------------------
 * 4) Snapshot-based billing (Billing Model A)
 * ---------------------------------------------------------------------
 * - Checkout NEVER updates subscription directly
 * - A Payment SNAPSHOT is created first
 * - Snapshot is applied ONLY after successful payment
 *
 * This guarantees:
 *   • Auditability
 *   • Correct proration
 *   • Idempotency
 *
 * DO NOT bypass snapshots.
 *
 * ---------------------------------------------------------------------
 * 5) License rules are duplicated ON PURPOSE
 * ---------------------------------------------------------------------
 * You will see license caps enforced in:
 * - upgradePlan()
 * - checkoutStart()
 * - PlanManager
 *
 * This is DEFENSIVE and INTENTIONAL.
 * Removing "redundant" checks is a common source of regressions.
 *
 * ---------------------------------------------------------------------
 * 6) Downgrades are NEVER immediate
 * ---------------------------------------------------------------------
 * - scheduleDowngrade() stores intent only
 * - Actual downgrade happens at next renewal
 * - cancelScheduledDowngrade() has time-based guards
 *
 * This is a hard business rule.
 *
 * ---------------------------------------------------------------------
 * 7) AI feature uses a 3-layer control model
 * ---------------------------------------------------------------------
 * - entitlements['ai'] : plan-level permission
 * - tenant.ai_enabled  : tenant preference
 * - features['ai']     : FINAL computed value
 *
 * Frontend MUST rely on features['ai'], not entitlements alone.
 *
 * ---------------------------------------------------------------------
 * TL;DR
 * ---------------------------------------------------------------------
 * This file looks complex because billing IS complex.
 * The complexity is INTENTIONAL.
 *
 * Any refactor must be preceded by:
 * - Full billing flow review
 * - Trial + downgrade + license increment testing
 *
 * If unsure: ADD COMMENTS, DO NOT CHANGE LOGIC.
 * =====================================================================
 */

namespace App\Services\Billing;

use App\Models\Tenant;
use App\Models\User;
use Modules\Billing\Models\Subscription;
use Illuminate\Support\Carbon;

class PriceCalculator
{
    /**
     * Backwards-compatible wrapper for legacy code calling calculate().
     */
    public function calculate(Tenant $tenant): array
    {
        return $this->calculateForTenant($tenant);
    }

    /**
     * Calculate price for a specific plan (for checkout preview).
     * 
     * @param string $plan 'starter' | 'team' | 'enterprise'
     * @param int|null $userLimit Optional user count, defaults to 1
     * @return float Monthly price for the plan
     */
    public function calculatePlanPrice(string $plan, ?int $userLimit = null): float
    {
        $userCount = $userLimit ?? 1;
        $plansConfig = config('billing.plans');

        switch ($plan) {
            case 'starter':
                return 0.0; // Starter is always free

            case 'team':
                $basePricePerUser = $plansConfig['team']['price_per_user'] ?? 44;
                return round($basePricePerUser * $userCount, 2);

            case 'enterprise':
                $basePricePerUser = $plansConfig['enterprise']['price_per_user'] ?? 59;
                return round($basePricePerUser * $userCount, 2);

            default:
                return 0.0;
        }
    }

    /**
     * Calculate prorated price for adding licenses to existing plan.
     * 
     * Charges only for the ADDITIONAL licenses for the remaining billing period.
     * 
     * @param Tenant $tenant Current tenant
     * @param int $newUserLimit New total user_limit (e.g., upgrading from 2 to 5)
     * @return float Prorated amount to charge NOW
     */
    public function calculateLicenseIncrementPrice(Tenant $tenant, int $newUserLimit): float
    {
        $subscription = $tenant->subscription;
        
        if (!$subscription) {
            return 0.0;
        }

        $currentUserLimit = $subscription->user_limit ?? 0;
        $licenseIncrement = $newUserLimit - $currentUserLimit;

        if ($licenseIncrement <= 0) {
            return 0.0; // No increment, no charge
        }

        // Get price per user for current plan
        $plansConfig = config('billing.plans');
        $pricePerUser = match ($subscription->plan) {
            'team'       => $plansConfig['team']['price_per_user'] ?? 44,
            'enterprise' => $plansConfig['enterprise']['price_per_user'] ?? 59,
            default      => 0,
        };

        // Calculate prorated amount: (price × increment × days_remaining) / 30
        $now = Carbon::now();
        $nextRenewal = $subscription->next_renewal_at ? Carbon::parse($subscription->next_renewal_at) : $now->copy()->addDays(30);
        $daysRemaining = max(1, $now->diffInDays($nextRenewal, false)); // At least 1 day
        $prorataFactor = $daysRemaining / 30;

        $incrementMonthlyPrice = $pricePerUser * $licenseIncrement;
        $proratedPrice = $incrementMonthlyPrice * $prorataFactor;

        \Log::info('[PriceCalculator] License increment calculation', [
            'tenant_id' => $tenant->id,
            'current_user_limit' => $currentUserLimit,
            'new_user_limit' => $newUserLimit,
            'license_increment' => $licenseIncrement,
            'price_per_user' => $pricePerUser,
            'days_remaining' => $daysRemaining,
            'prorata_factor' => $prorataFactor,
            'increment_monthly' => $incrementMonthlyPrice,
            'prorated_amount' => round($proratedPrice, 2),
        ]);

        return round($proratedPrice, 2);
    }

    /**
     * Calculate price for plan upgrades.
     * 
     * SIMPLE PRICING RULE:
     * - Always charge: new_plan_price × user_limit (NO proration, NO difference calculation)
     * - Example: Team → Enterprise with 9 licenses = €59 × 9 = €531
     * 
     * @param Tenant $tenant Current tenant
     * @param string $newPlan Target plan ('team' | 'enterprise')
     * @param int|null $newUserLimit New user limit (defaults to current subscription user_limit)
     * @return float Amount to charge NOW
     */
    public function calculatePlanUpgradePrice(Tenant $tenant, string $newPlan, ?int $newUserLimit = null): float
    {
        $subscription = $tenant->subscription;
        
        if (!$subscription) {
            // No subscription - treat as new purchase (full price, no proration)
            return $this->calculatePlanPrice($newPlan, $newUserLimit ?? 1);
        }

        $currentPlan = $subscription->plan;
        $userLimit = $newUserLimit ?? $subscription->user_limit ?? 1;

        // Get plan pricing from config
        $plansConfig = config('billing.plans');
        
        $newPlanPrice = match ($newPlan) {
            'starter'    => 0,
            'team'       => $plansConfig['team']['price_per_user'] ?? 44,
            'enterprise' => $plansConfig['enterprise']['price_per_user'] ?? 59,
            default      => 0,
        };

        // Simple calculation: new_plan_price × user_limit (NO proration)
        $amount = round($newPlanPrice * $userLimit, 2);

        \Log::info('[PriceCalculator] Plan upgrade simple pricing', [
            'tenant_id' => $tenant->id,
            'current_plan' => $currentPlan,
            'new_plan' => $newPlan,
            'user_limit' => $userLimit,
            'new_plan_price_per_user' => $newPlanPrice,
            'amount' => $amount,
        ]);

        return $amount;
    }

    /**
     * Main entry: calculate billing summary for a tenant.
     */
    public function calculateForTenant(Tenant $tenant): array
    {
        /** @var Subscription|null $subscription */
        $subscription = $tenant->subscription;

        $plansConfig  = config('billing.plans');
        $trialConfig  = config('billing.trial');
        $userCount    = $this->getActiveUserCount($tenant); // For display only
        $now          = Carbon::now();

        // No subscription → treat as Starter with 0 cost
        if (!$subscription) {
            return $this->buildStarterResult($userCount);
        }

        // Trial active?
        if ($subscription->is_trial && $subscription->trial_ends_at && $now->lt($subscription->trial_ends_at)) {
            return $this->buildTrialEnterpriseResult($tenant, $subscription, $userCount, $plansConfig, $trialConfig);
        }

        // Normal plan flow
        switch ($subscription->plan) {
            case 'starter':
                return $this->buildStarterResult($userCount);

            case 'team':
                return $this->buildTeamResult($subscription, $userCount, $plansConfig['team']);

            case 'enterprise':
                return $this->buildEnterpriseResult($subscription, $userCount, $plansConfig['enterprise']);

            default:
                return $this->buildStarterResult($userCount);
        }
    }

    protected function getActiveUserCount(Tenant $tenant): int
    {
        // Count only users that have an ACTIVE technician record
        // This prevents counting orphaned users or inactive technicians
        $count = $tenant->run(function () {
            return \App\Models\Technician::where('is_active', 1)->count();
        });
        
        \Log::info('[PriceCalculator] 🔢 Active user count fetched', [
            'tenant_id' => $tenant->id,
            'user_count' => $count,
            'method' => 'Technician::where(is_active=1)->count()',
            'timestamp' => now()->toIso8601String()
        ]);
        
        return $count;
    }

    /**
     *  STARTER PLAN
     *  
     *  Features MUST match PlanManager::getFeatureFlagsForSubscription() for 'starter':
     *  - timesheets: true
     *  - expenses: true
     *  - travels: false
     *  - planning: false
     *  - ai: false
     */
    protected function buildStarterResult(int $userCount): array
    {
        $requiresUpgrade = $userCount > 2;

        return [
            'plan'              => 'starter',
            'is_trial'          => false,
            'user_count'        => $userCount,
            'base_subtotal'     => 0.0,
            'addons'            => [
                'planning' => 0.0,
                'ai'       => 0.0,
            ],
            'total'             => 0.0,
            'requires_upgrade'  => $requiresUpgrade,
            'features'          => [
                'timesheets' => true,
                'expenses'   => true,
                'travels'    => false,
                'planning'   => false,
                'ai'         => false,
            ],
        ];
    }

    /**
     *  TRIAL ENTERPRISE (15 days)
     *  
     *  Features MUST match PlanManager::getFeatureFlagsForSubscription() for trial:
     *  - All features enabled (same as enterprise)
     *  - timesheets: true
     *  - expenses: true
     *  - travels: true
     *  - planning: true
     *  - ai: true
     */
    protected function buildTrialEnterpriseResult(
        Tenant $tenant,
        Subscription $subscription,
        int $userCount,
        array $plansConfig,
        array $trialConfig
    ): array {
        $enterpriseConfig = $plansConfig['enterprise'];
        $nominalSubtotal  = ($enterpriseConfig['price_per_user'] ?? 59) * $userCount;

        return [
            'plan'              => 'trial_enterprise',
            'is_trial'          => true,
            'user_count'        => $userCount,
            'base_subtotal'     => $nominalSubtotal, // used only for display
            'addons'            => [
                'planning' => 0.0,
                'ai'       => 0.0,
            ],
            'total'             => 0.0, // trial = free
            'requires_upgrade'  => false,
            'features'          => [
                'timesheets' => true,
                'expenses'   => true,
                'travels'    => true,
                'planning'   => true,
                'ai'         => true,
            ],
            'trial' => [
                'ends_at' => $subscription->trial_ends_at,
            ],
        ];
    }

    /**
     *  TEAM PLAN (44€/user + optional addons 18%)
     *  
     *  Features MUST match PlanManager::getFeatureFlagsForSubscription() for 'team':
     *  - timesheets: true
     *  - expenses: true
     *  - travels: true
     *  - planning: true IF in activeAddons, false otherwise
     *  - ai: true IF in activeAddons, false otherwise
     */
    protected function buildTeamResult(Subscription $subscription, int $userCount, array $planConfig): array
    {
        $basePricePerUser = $planConfig['price_per_user'] ?? 44;
        // CRITICAL FIX: Use user_limit (purchased licenses) instead of active user count for pricing
        $userLimit        = $subscription->user_limit ?? 1;
        $baseSubtotal     = $basePricePerUser * $userLimit;

        $addonsConfig = $planConfig['addons'] ?? [];
        $activeAddons = $subscription->addons ?? [];

        // Planning addon (18% of base price calculated from licenses)
        $planningAmount = 0.0;
        if (in_array('planning', $activeAddons)) {
            $planningPct   = $addonsConfig['planning'] ?? 0.18;
            $planningAmount = $baseSubtotal * $planningPct;
        }

        // AI addon (18% of base price, NOT compounded)
        $aiAmount = 0.0;
        if (in_array('ai', $activeAddons)) {
            $aiPct   = $addonsConfig['ai'] ?? 0.18;
            $aiAmount = $baseSubtotal * $aiPct; // Based on licensed seats, not active users
        }

        $total = $baseSubtotal + $planningAmount + $aiAmount;

        return [
            'plan'              => 'team',
            'is_trial'          => false,
            'user_count'        => $userCount, // Keep for display (active users)
            'user_limit'        => $userLimit, // Purchased licenses
            'base_subtotal'     => round($baseSubtotal, 2), // Now based on user_limit
            'addons'            => [
                'planning' => round($planningAmount, 2),
                'ai'       => round($aiAmount, 2),
            ],
            'total'             => round($total, 2),
            'requires_upgrade'  => false,
            'features'          => [
                'timesheets' => true,
                'expenses'   => true,
                'travels'    => true,
                'planning'   => in_array('planning', $activeAddons),
                'ai'         => in_array('ai', $activeAddons),
            ],
        ];
    }

    /**
     *  ENTERPRISE PLAN (59€/user, everything included)
     *  
     *  Features MUST match PlanManager::getFeatureFlagsForSubscription() for 'enterprise':
     *  - All features enabled
     *  - timesheets: true
     *  - expenses: true
     *  - travels: true
     *  - planning: true
     *  - ai: true
     */
    protected function buildEnterpriseResult(Subscription $subscription, int $userCount, array $planConfig): array
    {
        $basePricePerUser = $planConfig['price_per_user'] ?? 59;
        // CRITICAL FIX: Use user_limit (purchased licenses) instead of active user count for pricing
        $userLimit        = $subscription->user_limit ?? 1;
        $baseSubtotal     = $basePricePerUser * $userLimit;

        return [
            'plan'              => 'enterprise',
            'is_trial'          => false,
            'user_count'        => $userCount, // Keep for display (active users)
            'user_limit'        => $userLimit, // Purchased licenses
            'base_subtotal'     => round($baseSubtotal, 2), // Now based on user_limit
            'addons'            => [
                'planning' => 0.0,
                'ai'       => 0.0,
            ],
            'total'             => round($baseSubtotal, 2),
            'requires_upgrade'  => false,
            'features'          => [
                'timesheets' => true,
                'expenses'   => true,
                'travels'    => true,
                'planning'   => true,
                'ai'         => true,
            ],
        ];
    }
}

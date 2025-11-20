<?php

namespace Modules\Billing\Services;

use Modules\Billing\Models\TenantLicense;
use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;

/**
 * LicenseManager Service
 * 
 * Manages license/seat allocation and usage tracking.
 * Integrates with Stripe Cashier for billing.
 */
class LicenseManager
{
    /**
     * Cache TTL in seconds (5 minutes for license info)
     */
    private const CACHE_TTL = 300;

    /**
     * Get license information for current tenant
     */
    public function getLicense(): ?TenantLicense
    {
        $tenant = tenancy()->tenant;

        if (!$tenant) {
            return null;
        }

        return TenantLicense::where('tenant_id', $tenant->id)->first();
    }

    /**
     * Get or create license for current tenant
     */
    public function getOrCreateLicense(int $initialLicenses = 1): TenantLicense
    {
        $tenant = tenancy()->tenant;

        return TenantLicense::firstOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'purchased_licenses' => $initialLicenses,
                'used_licenses' => 0,
                'price_per_license' => 5.00, // Default: €5/user/month
                'billing_cycle' => 'monthly',
                'trial_ends_at' => now()->addDays(14), // 14-day trial
                'auto_upgrade' => false, // Require manual upgrade by default
                'created_by' => auth()->id(),
            ]
        );
    }

    /**
     * Check if tenant can add a new user
     */
    public function canAddUser(): bool
    {
        $license = $this->getLicense();

        if (!$license) {
            // No license record = unlimited (for backwards compatibility)
            return true;
        }

        return $license->canAddUser();
    }

    /**
     * Get number of available licenses
     */
    public function availableLicenses(): int
    {
        $license = $this->getLicense();

        if (!$license) {
            return PHP_INT_MAX; // Unlimited if no license tracking
        }

        return $license->availableLicenses();
    }

    /**
     * Add licenses to current tenant
     * 
     * @param int $quantity Number of licenses to add
     * @param bool $updateStripe Whether to update Stripe subscription
     */
    public function addLicenses(int $quantity, bool $updateStripe = true): TenantLicense
    {
        $license = $this->getOrCreateLicense();
        $license->incrementLicenses($quantity);

        if ($updateStripe && $license->stripe_subscription_id) {
            $this->updateStripeSubscription($license, $quantity);
        }

        $this->clearCache();

        return $license;
    }

    /**
     * Remove licenses from current tenant
     * 
     * @param int $quantity Number of licenses to remove
     * @param bool $updateStripe Whether to update Stripe subscription
     */
    public function removeLicenses(int $quantity, bool $updateStripe = true): TenantLicense
    {
        $license = $this->getLicense();

        if (!$license) {
            throw new \Exception('No license record found');
        }

        $license->decrementLicenses($quantity);

        if ($updateStripe && $license->stripe_subscription_id) {
            $this->updateStripeSubscription($license, -$quantity);
        }

        $this->clearCache();

        return $license;
    }

    /**
     * Increment usage when a user is added
     */
    public function incrementUsage(): void
    {
        $license = $this->getOrCreateLicense();

        if (!$license->canAddUser()) {
            throw new \Exception('No available licenses. Please purchase more licenses to add users.');
        }

        $license->incrementUsage();
        $this->clearCache();
    }

    /**
     * Decrement usage when a user is removed
     */
    public function decrementUsage(): void
    {
        $license = $this->getLicense();

        if ($license) {
            $license->decrementUsage();
            $this->clearCache();
        }
    }

    /**
     * Update Stripe subscription quantity
     */
    protected function updateStripeSubscription(TenantLicense $license, int $quantityChange): void
    {
        $tenant = Tenant::find($license->tenant_id);

        if (!$tenant || !$tenant->subscribed('default')) {
            return;
        }

        $subscription = $tenant->subscription('default');

        if ($quantityChange > 0) {
            $subscription->incrementQuantity($quantityChange);
        } else {
            $subscription->decrementQuantity(abs($quantityChange));
        }
    }

    /**
     * Get license summary
     */
    public function getSummary(): array
    {
        $license = $this->getLicense();

        if (!$license) {
            return [
                'purchased' => 0,
                'used' => 0,
                'available' => 0,
                'utilization' => 0,
                'is_trialing' => false,
                'trial_ends_at' => null,
                'monthly_cost' => 0,
                'annual_cost' => 0,
            ];
        }

        return [
            'purchased' => $license->purchased_licenses,
            'used' => $license->used_licenses,
            'available' => $license->availableLicenses(),
            'utilization' => $license->utilizationPercentage(),
            'is_trialing' => $license->isTrialing(),
            'trial_ends_at' => $license->trial_ends_at,
            'monthly_cost' => $license->monthlyCost(),
            'annual_cost' => $license->annualCost(),
            'billing_cycle' => $license->billing_cycle,
            'price_per_license' => $license->price_per_license,
            'auto_upgrade' => $license->auto_upgrade,
        ];
    }

    /**
     * Calculate cost for adding licenses
     */
    public function calculateCost(int $quantity): array
    {
        $license = $this->getOrCreateLicense();

        $monthlyIncrease = $quantity * $license->price_per_license;
        $annualIncrease = $monthlyIncrease * 12;

        // Calculate prorated amount for current month
        $daysInMonth = now()->daysInMonth;
        $daysRemaining = now()->endOfMonth()->diffInDays(now());
        $proratedAmount = ($monthlyIncrease / $daysInMonth) * $daysRemaining;

        return [
            'quantity' => $quantity,
            'price_per_license' => $license->price_per_license,
            'monthly_increase' => round($monthlyIncrease, 2),
            'annual_increase' => round($annualIncrease, 2),
            'prorated_amount' => round($proratedAmount, 2),
            'billing_cycle' => $license->billing_cycle,
            'new_monthly_total' => round(($license->purchased_licenses + $quantity) * $license->price_per_license, 2),
        ];
    }

    /**
     * Clear cache
     */
    protected function clearCache(): void
    {
        $tenant = tenancy()->tenant;

        if ($tenant) {
            Cache::forget("tenant:{$tenant->id}:license_summary");
        }
    }

    /**
     * Initialize license for a new tenant
     */
    public function initializeLicense(
        string $tenantId,
        int $licenses = 1,
        string $billingCycle = 'monthly',
        ?int $trialDays = 14
    ): TenantLicense {
        return TenantLicense::create([
            'tenant_id' => $tenantId,
            'purchased_licenses' => $licenses,
            'used_licenses' => 0,
            'price_per_license' => 5.00,
            'billing_cycle' => $billingCycle,
            'trial_ends_at' => $trialDays ? now()->addDays($trialDays) : null,
            'auto_upgrade' => false,
        ]);
    }
}

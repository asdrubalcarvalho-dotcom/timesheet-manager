<?php

namespace App\Services;

use App\Models\Tenant;
use Laravel\Pennant\Feature;

/**
 * TenantFeatures Service
 * 
 * Standard Laravel Pennant wrapper for tenant-scoped feature flags.
 * Feature logic is defined in AppServiceProvider::defineTenantFeatures().
 * 
 * Usage:
 * - TenantFeatures::active($tenant, 'travels') - Check if enabled
 * - Feature::for($tenant)->activate('travels') - Manual override
 * - Feature::for($tenant)->value('travels') - Get value with logic
 */
class TenantFeatures
{
    public const TIMESHEETS = 'timesheets';
    public const EXPENSES = 'expenses';
    public const TRAVELS = 'travels';
    public const PLANNING = 'planning';
    public const AI = 'ai';

    public const ALL_FEATURES = [
        self::TIMESHEETS,
        self::EXPENSES,
        self::TRAVELS,
        self::PLANNING,
        self::AI,
    ];

    /**
     * Check if feature is active (uses database + definition logic).
     */
    public static function active(Tenant $tenant, string $feature): bool
    {
        self::validateFeature($feature);
        return Feature::for($tenant)->active($feature);
    }

    /**
     * Get all features status for tenant.
     * 
     * @return array<string, bool>
     */
    public static function all(Tenant $tenant): array
    {
        return collect(self::ALL_FEATURES)
            ->mapWithKeys(fn($feature) => [$feature => self::active($tenant, $feature)])
            ->all();
    }

    /**
     * Sync feature flags from subscription.
     * Activates/deactivates features based on subscription plan and addons.
     * 
     * Note: Feature logic is defined in AppServiceProvider, but this
     * ensures flags are synced when subscription changes.
     */
    public static function syncFromSubscription(Tenant $tenant): void
    {
        $subscription = $tenant->subscription;
        
        if (!$subscription) {
            // No subscription - deactivate all premium features
            Feature::for($tenant)->deactivate(self::TRAVELS);
            Feature::for($tenant)->deactivate(self::PLANNING);
            Feature::for($tenant)->deactivate(self::AI);
            return;
        }

        // Timesheets and Expenses are always active (base features)
        Feature::for($tenant)->activate(self::TIMESHEETS);
        Feature::for($tenant)->activate(self::EXPENSES);

        // Travels: Team+ or Starter with >2 users
        if ($subscription->plan === 'team' || $subscription->plan === 'enterprise') {
            Feature::for($tenant)->activate(self::TRAVELS);
        } elseif ($subscription->plan === 'starter' && $subscription->user_limit > 2) {
            Feature::for($tenant)->activate(self::TRAVELS);
        } else {
            Feature::for($tenant)->deactivate(self::TRAVELS);
        }

        // Planning addon
        $addons = $subscription->addons ?? [];
        if (in_array('planning', $addons)) {
            Feature::for($tenant)->activate(self::PLANNING);
        } else {
            Feature::for($tenant)->deactivate(self::PLANNING);
        }

        // AI addon (Enterprise only)
        if ($subscription->plan === 'enterprise' && in_array('ai', $addons)) {
            Feature::for($tenant)->activate(self::AI);
        } else {
            Feature::for($tenant)->deactivate(self::AI);
        }
    }

    /**
     * Validate feature key.
     * 
     * @throws \InvalidArgumentException
     */
    protected static function validateFeature(string $feature): void
    {
        if (!in_array($feature, self::ALL_FEATURES)) {
            throw new \InvalidArgumentException("Invalid feature: {$feature}");
        }
    }
}

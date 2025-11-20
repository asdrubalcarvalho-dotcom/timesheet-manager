<?php

namespace Modules\Billing\Policies;

use App\Models\User;
use Modules\Billing\Models\TenantFeature;

/**
 * TenantFeaturePolicy
 * 
 * Authorization logic for feature flag management.
 */
class TenantFeaturePolicy
{
    /**
     * Determine if user can manage feature flags
     * (Only Owner and Admin can enable/disable modules)
     */
    public function manage(User $user): bool
    {
        return $user->hasAnyRole(['Owner', 'Admin']);
    }

    /**
     * Determine if user can view feature flags
     * (All authenticated users can view)
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine if user can view a specific feature flag
     */
    public function view(User $user, TenantFeature $feature): bool
    {
        return true;
    }
}

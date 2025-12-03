<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\Auth;

/**
 * TenantResolver - Resolves current tenant from various sources
 * 
 * Priority order:
 * 1. Already initialized tenant (via Stancl middleware)
 * 2. Authenticated user's tenant
 * 3. Domain/subdomain resolution
 * 4. X-Tenant header
 * 
 * Used by billing services to ensure correct tenant context.
 */
class TenantResolver
{
    /**
     * Get the current tenant from various sources.
     */
    public static function resolve(): ?Tenant
    {
        // 1. Check if tenant already initialized by Stancl
        if (tenancy()->initialized) {
            return tenant();
        }

        // 2. Try to get tenant from authenticated user
        $user = Auth::user();
        if ($user && method_exists($user, 'tenant')) {
            return $user->tenant;
        }

        // 3. For non-initialized contexts, return null
        // Tenancy should be initialized by middleware before reaching services
        return null;
    }

    /**
     * Get current tenant or throw exception.
     * 
     * @throws \RuntimeException
     */
    public static function resolveOrFail(): Tenant
    {
        $tenant = self::resolve();

        if (!$tenant) {
            throw new \RuntimeException('Tenant context is required but not initialized.');
        }

        return $tenant;
    }

    /**
     * Get tenant ID safely.
     */
    public static function getTenantId(): ?string
    {
        return self::resolve()?->id;
    }

    /**
     * Check if tenant context is available.
     */
    public static function hasTenant(): bool
    {
        return tenancy()->initialized || (Auth::check() && Auth::user()->tenant_id !== null);
    }
}

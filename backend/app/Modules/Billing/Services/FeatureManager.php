<?php

namespace Modules\Billing\Services;

use Modules\Billing\Models\TenantFeature;
use Illuminate\Support\Facades\Cache;

/**
 * FeatureManager Service
 * 
 * Centralized service for managing feature flags and module access control.
 * Implements caching for performance.
 */
class FeatureManager
{
    /**
     * Cache TTL in seconds (1 hour)
     */
    private const CACHE_TTL = 3600;

    /**
     * Check if a module is enabled for the current tenant
     */
    public function isEnabled(string $module): bool
    {
        $tenant = tenancy()->tenant;

        if (!$tenant) {
            return false;
        }

        $cacheKey = "tenant:{$tenant->id}:feature:{$module}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($tenant, $module) {
            $feature = TenantFeature::forTenant($tenant->id)
                ->where('module_name', $module)
                ->first();

            if (!$feature) {
                // Check if it's a core module (enabled by default)
                return in_array($module, TenantFeature::CORE_MODULES);
            }

            return $feature->isActive();
        });
    }

    /**
     * Check if a module is enabled for a specific tenant (without tenant context)
     */
    public function isEnabledForTenant(string $tenantId, string $module): bool
    {
        $cacheKey = "tenant:{$tenantId}:feature:{$module}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($tenantId, $module) {
            $feature = TenantFeature::forTenant($tenantId)
                ->where('module_name', $module)
                ->first();

            if (!$feature) {
                return in_array($module, TenantFeature::CORE_MODULES);
            }

            return $feature->isActive();
        });
    }

    /**
     * Require a module to be enabled (throws exception if not)
     * 
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function requireModule(string $module): void
    {
        if (!$this->isEnabled($module)) {
            abort(403, "The '{$module}' module is not enabled for your subscription.");
        }
    }

    /**
     * Get all enabled modules for current tenant
     */
    public function getEnabledModules(): array
    {
        $tenant = tenancy()->tenant;

        if (!$tenant) {
            return [];
        }

        $cacheKey = "tenant:{$tenant->id}:enabled_modules";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($tenant) {
            $features = TenantFeature::forTenant($tenant->id)
                ->active()
                ->pluck('module_name')
                ->toArray();

            // Add core modules (always enabled)
            return array_unique(array_merge($features, TenantFeature::CORE_MODULES));
        });
    }

    /**
     * Get all available modules with their status
     */
    public function getAllModulesStatus(): array
    {
        $tenant = tenancy()->tenant;

        if (!$tenant) {
            return [];
        }

        $modules = [];

        foreach (TenantFeature::MODULES as $key => $name) {
            $feature = TenantFeature::forTenant($tenant->id)
                ->where('module_name', $key)
                ->first();

            $modules[$key] = [
                'name' => $name,
                'enabled' => $feature ? $feature->isActive() : in_array($key, TenantFeature::CORE_MODULES),
                'is_core' => in_array($key, TenantFeature::CORE_MODULES),
                'is_trialing' => $feature ? $feature->isTrialing() : false,
                'expires_at' => $feature?->expires_at,
                'days_remaining' => $feature?->daysRemainingInTrial(),
            ];
        }

        return $modules;
    }

    /**
     * Enable a module for current tenant
     */
    public function enable(string $module): TenantFeature
    {
        $tenant = tenancy()->tenant;

        $feature = TenantFeature::firstOrCreate(
            [
                'tenant_id' => $tenant->id,
                'module_name' => $module,
            ],
            [
                'is_enabled' => true,
                'created_by' => auth()->id(),
            ]
        );

        if (!$feature->is_enabled) {
            $feature->enable();
            $feature->updated_by = auth()->id();
            $feature->save();
        }

        $this->clearCache($tenant->id, $module);

        return $feature;
    }

    /**
     * Disable a module for current tenant
     * 
     * @throws \Exception if trying to disable core module
     */
    public function disable(string $module): TenantFeature
    {
        if (in_array($module, TenantFeature::CORE_MODULES)) {
            throw new \Exception("Cannot disable core module: {$module}");
        }

        $tenant = tenancy()->tenant;

        $feature = TenantFeature::forTenant($tenant->id)
            ->where('module_name', $module)
            ->firstOrFail();

        $feature->disable();
        $feature->updated_by = auth()->id();
        $feature->save();

        $this->clearCache($tenant->id, $module);

        return $feature;
    }

    /**
     * Set trial period for a module
     */
    public function setTrial(string $module, int $days): TenantFeature
    {
        $tenant = tenancy()->tenant;

        $feature = TenantFeature::firstOrCreate(
            [
                'tenant_id' => $tenant->id,
                'module_name' => $module,
            ],
            [
                'is_enabled' => true,
                'created_by' => auth()->id(),
            ]
        );

        $feature->expires_at = now()->addDays($days);
        $feature->updated_by = auth()->id();
        $feature->save();

        $this->clearCache($tenant->id, $module);

        return $feature;
    }

    /**
     * Clear cache for a specific tenant and module
     */
    public function clearCache(?string $tenantId = null, ?string $module = null): void
    {
        $tenantId = $tenantId ?? tenancy()->tenant?->id;

        if (!$tenantId) {
            return;
        }

        if ($module) {
            Cache::forget("tenant:{$tenantId}:feature:{$module}");
        }

        Cache::forget("tenant:{$tenantId}:enabled_modules");
    }

    /**
     * Initialize default features for a new tenant
     */
    public function initializeDefaultFeatures(string $tenantId, array $modules = []): void
    {
        // Enable core modules by default
        foreach (TenantFeature::CORE_MODULES as $module) {
            TenantFeature::firstOrCreate([
                'tenant_id' => $tenantId,
                'module_name' => $module,
            ], [
                'is_enabled' => true,
            ]);
        }

        // Enable additional modules if specified
        foreach ($modules as $module) {
            if (in_array($module, array_keys(TenantFeature::MODULES))) {
                TenantFeature::firstOrCreate([
                    'tenant_id' => $tenantId,
                    'module_name' => $module,
                ], [
                    'is_enabled' => true,
                ]);
            }
        }
    }
}

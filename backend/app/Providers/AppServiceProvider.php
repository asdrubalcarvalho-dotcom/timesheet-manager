<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
use App\Services\TimesheetAIService;
use App\Tenancy\Resolvers\TenantHeaderOrSlugResolver;
use Illuminate\Support\ServiceProvider;
use Laravel\Pennant\Feature;
use Laravel\Sanctum\Sanctum;
use Stancl\Tenancy\Resolvers\RequestDataTenantResolver;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TimesheetAIService::class);
        $this->app->singleton(RequestDataTenantResolver::class, TenantHeaderOrSlugResolver::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Configure Sanctum to use our custom PersonalAccessToken model
        // that automatically uses the tenant connection when tenancy is initialized
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // Define Pennant feature flags for multi-tenant module access control
        $this->defineTenantFeatures();
    }

    /**
     * Define feature flags for tenant modules using Laravel Pennant.
     * 
     * Standard Pennant pattern: Feature::define() with scope resolver.
     * Database driver allows manual activation via Feature::activate().
     */
    protected function defineTenantFeatures(): void
    {
        // Core modules - always enabled
        Feature::define('timesheets', fn() => true);
        Feature::define('expenses', fn() => true);

        // Premium modules - check subscription OR database override
        Feature::define('travels', function ($tenant) {
            if (!$tenant) return false;
            
            // Allow trial/development plans by default
            $plan = $tenant->billing_plan ?? $tenant->subscription?->plan ?? 'none';
            if (in_array($plan, ['trial', 'team', 'enterprise'])) {
                return true;
            }
            
            // Starter plan: requires >2 users
            if ($plan === 'starter') {
                $userCount = $tenant->subscription?->user_limit ?? 1;
                return $userCount > 2;
            }
            
            return false;
        });

        Feature::define('planning', function ($tenant) {
            if (!$tenant) return false;
            
            $subscription = $tenant->subscription;
            if (!$subscription) return false;
            
            $addons = $subscription->addons ?? [];
            return in_array('planning', $addons);
        });

        Feature::define('ai', function ($tenant) {
            if (!$tenant) return false;
            
            $subscription = $tenant->subscription;
            if (!$subscription || $subscription->plan !== 'enterprise') {
                return false;
            }
            
            $addons = $subscription->addons ?? [];
            return in_array('ai', $addons);
        });
    }
}

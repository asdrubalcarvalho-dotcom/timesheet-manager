<?php

namespace Modules\Billing\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Gate;
use Modules\Billing\Services\FeatureManager;
use Modules\Billing\Services\LicenseManager;
use Modules\Billing\Models\TenantFeature;
use Modules\Billing\Policies\TenantFeaturePolicy;
use Modules\Billing\Http\Middleware\CheckModuleAccess;

/**
 * BillingServiceProvider
 * 
 * Registers the Billing module with the application.
 */
class BillingServiceProvider extends ServiceProvider
{
    /**
     * Register services
     */
    public function register(): void
    {
        // Register singleton services
        $this->app->singleton(FeatureManager::class, function ($app) {
            return new FeatureManager();
        });

        $this->app->singleton(LicenseManager::class, function ($app) {
            return new LicenseManager();
        });
    }

    /**
     * Bootstrap services
     */
    public function boot(): void
    {
        // Load routes
        $this->loadRoutes();

        // Load migrations
        $this->loadMigrations();

        // Register policies
        $this->registerPolicies();

        // Register middleware
        $this->registerMiddleware();

        // Publish config (optional)
        $this->publishes([
            __DIR__ . '/../config/billing.php' => config_path('billing.php'),
        ], 'billing-config');
    }

    /**
     * Load module routes
     */
    protected function loadRoutes(): void
    {
        Route::middleware('api')
            ->prefix('api')
            ->group(__DIR__ . '/../routes/billing.php');
    }

    /**
     * Load migrations
     */
    protected function loadMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    /**
     * Register policies
     */
    protected function registerPolicies(): void
    {
        Gate::policy(TenantFeature::class, TenantFeaturePolicy::class);
    }

    /**
     * Register middleware
     */
    protected function registerMiddleware(): void
    {
        $router = $this->app['router'];

        $router->aliasMiddleware('module.access', CheckModuleAccess::class);
    }
}

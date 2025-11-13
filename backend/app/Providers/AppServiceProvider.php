<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
use App\Services\TimesheetAIService;
use App\Tenancy\Resolvers\TenantHeaderOrSlugResolver;
use Illuminate\Support\ServiceProvider;
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
    }
}

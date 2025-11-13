<?php

namespace App\Tenancy\Bootstrappers;

use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

class SanctumTenancyBootstrapper implements TenancyBootstrapper
{
    public function bootstrap(Tenant $tenant): void
    {
        // When tenant is initialized, configure Sanctum to use tenant database
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
        
        // Set the connection for PersonalAccessToken to the tenant connection
        PersonalAccessToken::clearBootedModels();
    }

    public function revert(): void
    {
        // When reverting to central context, reset to default
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
        PersonalAccessToken::clearBootedModels();
    }
}

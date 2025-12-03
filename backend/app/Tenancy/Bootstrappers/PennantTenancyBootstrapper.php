<?php

namespace App\Tenancy\Bootstrappers;

use Illuminate\Support\Facades\Config;
use Laravel\Pennant\Feature;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * PennantTenancyBootstrapper
 * 
 * Switches Pennant's database connection to tenant database when tenancy is initialized.
 * This ensures feature flags are stored in the tenant's database, not the central database.
 */
class PennantTenancyBootstrapper implements TenancyBootstrapper
{
    /**
     * Bootstrap tenancy - switch Pennant to tenant connection.
     */
    public function bootstrap(Tenant $tenant): void
    {
        // Switch Pennant database connection to tenant
        Config::set('pennant.stores.database.connection', 'tenant');
        
        // Flush Pennant cache to reload with new connection
        Feature::flushCache();
    }

    /**
     * Revert tenancy - switch Pennant back to default connection.
     */
    public function revert(): void
    {
        // Switch back to default connection
        Config::set('pennant.stores.database.connection', config('database.default'));
        
        // Flush Pennant cache
        Feature::flushCache();
    }
}

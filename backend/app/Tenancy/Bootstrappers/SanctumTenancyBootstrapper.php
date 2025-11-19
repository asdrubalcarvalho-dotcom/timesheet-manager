<?php

namespace App\Tenancy\Bootstrappers;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

class SanctumTenancyBootstrapper implements TenancyBootstrapper
{
    public function bootstrap(Tenant $tenant): void
    {
        // Force manual DB connection for tenant
        $databaseName = $tenant->getInternal('db_name');
        
        config(['database.connections.tenant' => [
            'driver' => 'mysql',
            'host' => config('database.connections.mysql.host'),
            'port' => config('database.connections.mysql.port'),
            'database' => $databaseName,
            'username' => config('database.connections.mysql.username'),
            'password' => config('database.connections.mysql.password'),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
        ]]);
        
        DB::purge('tenant');
        DB::reconnect('tenant');
        
        // Configure Sanctum to use tenant connection
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
        PersonalAccessToken::clearBootedModels();
        
        // Force PersonalAccessToken to use tenant connection
        Config::set('sanctum.connection', 'tenant');
    }

    public function revert(): void
    {
        // Reset to central connection
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
        PersonalAccessToken::clearBootedModels();
        Config::set('sanctum.connection', null);
    }
}

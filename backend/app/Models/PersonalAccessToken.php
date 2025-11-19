<?php

namespace App\Models;

use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

class PersonalAccessToken extends SanctumPersonalAccessToken
{
    /**
     * CRITICAL: Force connection BEFORE any query execution.
     * This method is called by Eloquent before resolving the connection.
     */
    public function getConnectionName()
    {
        // ALWAYS use tenant connection if X-Tenant header is present
        try {
            $request = request();
            $tenantSlug = $request ? $request->header('X-Tenant') : null;
            
            if ($tenantSlug) {
                // Find tenant and set up connection
                $tenant = \App\Models\Tenant::where('slug', $tenantSlug)->first();
                
                if ($tenant) {
                    $databaseName = $tenant->getInternal('db_name');
                    
                    // Configure tenant connection
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
                    
                    return 'tenant';
                }
            }
        } catch (\Exception $e) {
            \Log::warning('[PersonalAccessToken] Failed to set tenant connection: ' . $e->getMessage());
        }
        
        // Fallback to central connection
        return parent::getConnectionName();
    }
}

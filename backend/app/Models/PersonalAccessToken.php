<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

class PersonalAccessToken extends SanctumPersonalAccessToken
{
    /**
     * Find the token instance matching the given token.
     *
     * @param  string  $token
     * @return static|null
     */
    public static function findToken($token)
    {
        // Get tenant from X-Tenant header
        // This allows authentication to work before tenancy middleware runs
        $tenant = null;
        
        try {
            $request = request();
            $tenantSlug = $request ? $request->header('X-Tenant') : null;
            
            if ($tenantSlug) {
                // Find tenant by slug
                $tenant = \App\Models\Tenant::where('slug', $tenantSlug)->first();
            }
        } catch (\Exception $e) {
            \Log::warning('Failed to get tenant in PersonalAccessToken::findToken: ' . $e->getMessage());
        }
        
        // If we have a tenant, setup connection and query from tenant database
        if ($tenant) {
            try {
                // Create a temporary connection to the tenant database
                $databaseName = $tenant->getInternal('tenancy_db_name') ?: 'timesheet_' . $tenant->getTenantKey();
                
                // Configure the tenant connection dynamically
                config(['database.connections.tenant_temp' => [
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
                
                // Use the temporary connection
                if (strpos($token, '|') === false) {
                    return static::on('tenant_temp')
                        ->where('token', hash('sha256', $token))
                        ->first();
                }

                [$id, $token] = explode('|', $token, 2);

                if ($instance = static::on('tenant_temp')->find($id)) {
                    return hash_equals($instance->token, hash('sha256', $token)) ? $instance : null;
                }

                return null;
            } catch (\Exception $e) {
                \Log::warning('Failed to query tenant database in PersonalAccessToken::findToken: ' . $e->getMessage());
            }
        }

        // Fall back to parent implementation for central database
        return parent::findToken($token);
    }

    /**
     * Get the current connection name for the model.
     *
     * @return string|null
     */
    public function getConnectionName()
    {
        // If tenancy is initialized, use the tenant database name
        if (function_exists('tenancy') && tenancy()->initialized) {
            $tenant = tenancy()->tenant;
            if ($tenant) {
                return $tenant->getInternal('tenancy_db_name') ?: 'timesheet_' . $tenant->getTenantKey();
            }
        }

        return parent::getConnectionName();
    }
}

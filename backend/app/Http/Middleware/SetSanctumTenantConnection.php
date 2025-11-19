<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use App\Models\Tenant;
use Symfony\Component\HttpFoundation\Response;

class SetSanctumTenantConnection
{
    /**
     * Handle an incoming request.
     *
     * Set up tenant database connection BEFORE auth:sanctum middleware
     * tries to query for personal access tokens.
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $tenantSlug = $request->header('X-Tenant');
            
            if (!$tenantSlug) {
                \Log::warning('[SetSanctumTenantConnection] No X-Tenant header found');
                return $next($request);
            }

            // Find tenant in central DB (force central connection first)
            DB::setDefaultConnection('mysql');
            $tenant = Tenant::where('slug', $tenantSlug)->first();
            
            if (!$tenant) {
                \Log::warning("[SetSanctumTenantConnection] Tenant not found: {$tenantSlug}");
                return $next($request);
            }

            // Get tenant database name
            $databaseName = $tenant->getInternal('db_name');
            
            if (!$databaseName) {
                \Log::error("[SetSanctumTenantConnection] No database configured for tenant: {$tenantSlug}");
                return $next($request);
            }

            // Configure tenant connection
            Config::set('database.connections.tenant', [
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
                'engine' => null,
            ]);

            // Purge and reconnect to ensure clean state
            DB::purge('tenant');
            DB::reconnect('tenant');
            
            // CRITICAL: Set default connection BEFORE Sanctum Guard runs
            DB::setDefaultConnection('tenant');
            
            // Force all database configs to use tenant connection
            Config::set('database.default', 'tenant');
            Config::set('sanctum.connection', 'tenant');
            
            \Log::info("[SetSanctumTenantConnection] Tenant connection set as default: {$databaseName}");
            
        } catch (\Exception $e) {
            \Log::error('[SetSanctumTenantConnection] Failed to setup tenant connection: ' . $e->getMessage());
        }

        return $next($request);
    }
}

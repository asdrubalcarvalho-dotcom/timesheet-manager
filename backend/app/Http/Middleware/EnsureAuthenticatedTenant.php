<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureAuthenticatedTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        // If tenancy is initialized, force DB connection for Sanctum BEFORE auth check
        if (tenancy()->initialized && ($tenant = tenant())) {
            $databaseName = $tenant->getInternal('db_name');
            
            // Manual DB connection (same pattern as controllers)
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
            DB::setDefaultConnection('tenant');
            
            // Force Sanctum to use tenant connection
            Config::set('sanctum.connection', 'tenant');
        }
        
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        if (! tenancy()->initialized || ! tenant()) {
            return new JsonResponse([
                'message' => 'Tenant context missing for authenticated request.',
            ], Response::HTTP_BAD_REQUEST);
        }

        // In multi-database tenancy, user existence in tenant DB implies ownership
        // No need to check tenant_id FK since each tenant has separate database
        
        return $next($request);
    }
}

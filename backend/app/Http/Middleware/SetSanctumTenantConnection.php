<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades.DB;
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
            // 1) Skip central/public endpoints and management/superadmin stuff (NO tenancy)
            if (
                $request->is('api/tenants/request-signup') ||
                $request->is('api/tenants/verify-signup') ||
                $request->is('api/tenants/check-slug') ||
                $request->is('api/public/*') ||
                $request->is('api/contact') ||
                $request->is('api/landing/*') ||
                $request->is('api/admin/telemetry/*') ||
                $request->is('api/superadmin/telemetry/*')
            ) {
                \Log::debug('[SetSanctumTenantConnection] Skipped for central/public/superadmin endpoint: ' . $request->path());
                return $next($request);
            }

            // 2) If no X-Tenant header, stay on central DB
            $tenantSlug = $request->header('X-Tenant');
            if (!$tenantSlug) {
                \Log::debug('[SetSanctumTenantConnection] No X-Tenant header found → central mode');
                return $next($request);
            }

            // 3) Find tenant in central DB
            DB::setDefaultConnection('mysql');
            $tenant = Tenant::where('slug', $tenantSlug)->first();

            if (!$tenant) {
                \Log::warning("[SetSanctumTenantConnection] Tenant not found: {$tenantSlug}");
                return $next($request);
            }

            // 4) Get tenant database name
            $databaseName = $tenant->getInternal('db_name');

            if (!$databaseName) {
                \Log::error("[SetSanctumTenantConnection] No database configured for tenant: {$tenantSlug}");
                return $next($request);
            }

            // 5) Configure tenant connection
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

            // 6) Purge + reconnect
            DB::purge('tenant');
            DB::reconnect('tenant');

            // 7) Set tenant as default BEFORE Sanctum runs
            DB::setDefaultConnection('tenant');
            Config::set('database.default', 'tenant');
            Config::set('sanctum.connection', 'tenant');

            \Log::info("[SetSanctumTenantConnection] Tenant connection set as default: {$databaseName}");
        } catch (\Throwable $e) {
            \Log::error('[SetSanctumTenantConnection] Failed to setup tenant connection: ' . $e->getMessage(), [
                'exception' => get_class($e),
            ]);
        }

        return $next($request);
    }
}

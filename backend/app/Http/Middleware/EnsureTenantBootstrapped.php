<?php

namespace App\Http\Middleware;

use App\Support\Access\TenantRoleManager;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantBootstrapped
{
    private const REQUEST_FLAG = '_tenant_bootstrapped_ran';

    /**
     * Guard against multiple executions in the same request
     * (e.g. nested route middleware groups).
     */
    private static bool $ran = false;

    public function handle(Request $request, Closure $next): Response
    {
        if (!tenancy()->initialized) {
            return $next($request);
        }

        if (self::$ran || $request->attributes->get(self::REQUEST_FLAG) === true) {
            return $next($request);
        }

        self::$ran = true;
        $request->attributes->set(self::REQUEST_FLAG, true);

        try {
            app(TenantRoleManager::class)->ensurePermissions();

            // Compatibility: prevent PermissionDoesNotExist crashes for legacy permissions
            // referenced by policies but not present in RoleMatrix. Creating the permission
            // record does NOT grant access to any user/role.
            Permission::firstOrCreate(['name' => 'view-all-timesheets', 'guard_name' => 'web']);

            app(PermissionRegistrar::class)->forgetCachedPermissions();
        } catch (\Throwable $exception) {
            Log::warning('EnsureTenantBootstrapped failed (non-fatal).', [
                'tenant_id' => tenant()?->id,
                'tenant_slug' => tenant()?->slug,
                'error' => $exception->getMessage(),
                'exception' => get_class($exception),
            ]);
        }

        return $next($request);
    }
}

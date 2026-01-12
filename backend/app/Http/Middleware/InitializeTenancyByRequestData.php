<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Stancl\Tenancy\Middleware\InitializeTenancyByRequestData as BaseInitializeTenancyByRequestData;
use Stancl\Tenancy\Resolvers\RequestDataTenantResolver;

class InitializeTenancyByRequestData extends BaseInitializeTenancyByRequestData
{
    /**
     * The tenant resolver implementation to use.
     *
     * @var string
     */
    public static string $tenantResolver = RequestDataTenantResolver::class;

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next)
    {
        // Check for X-Tenant header first
        $tenantSlug = $request->header(config('tenancy.identification.header', 'X-Tenant'));
        
        // Fall back to query parameter
        if (!$tenantSlug) {
            $tenantSlug = $request->query(config('tenancy.identification.query_parameter', 'tenant'));
        }

        if (!$tenantSlug) {
            // Allow access to central routes without tenant context
            if ($this->shouldAllowCentralAccess($request)) {
                return $next($request);
            }

            return response()->json([
                'message' => 'Tenant identifier required. Provide X-Tenant header or ?tenant= query parameter.'
            ], 400);
        }

        // Inject into request for parent handler
        if (!$request->hasHeader(config('tenancy.identification.header', 'X-Tenant'))) {
            $request->headers->set(config('tenancy.identification.header', 'X-Tenant'), $tenantSlug);
        }

        return parent::handle($request, $next);
    }

    protected function shouldAllowCentralAccess(Request $request): bool
    {
        $centralRoutes = [
            '/api/tenants/register',
            '/api/health',
            '/healthz',
            '/readyz',
            // SSO callbacks originate from the OAuth provider and may not include tenant identifiers.
            // Tenant is enforced inside the callback using signed state.
            'api/auth/*/callback',
        ];

        foreach ($centralRoutes as $route) {
            if ($request->is($route) || $request->is(trim($route, '/') . '/*')) {
                return true;
            }
        }

        $fallbackConfig = config('tenancy.domains.central_fallback', []);
        if (!($fallbackConfig['enabled'] ?? false)) {
            return false;
        }

        $allowedEnvironments = $fallbackConfig['environments'] ?? ['local', 'development'];
        return in_array(app()->environment(), $allowedEnvironments);
    }
}

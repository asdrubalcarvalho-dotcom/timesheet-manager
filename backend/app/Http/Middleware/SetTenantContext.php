<?php

namespace App\Http\Middleware;

use App\Services\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SetTenantContext Middleware
 * 
 * Stores resolved tenant in application container for easy access
 * throughout the request lifecycle.
 * 
 * This complements Stancl's tenancy initialization by providing
 * a service container binding for billing and feature services.
 */
class SetTenantContext
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Resolve tenant (will use already-initialized tenant from Stancl if available)
        $tenant = TenantResolver::resolve();

        // Bind tenant instance to container for dependency injection
        if ($tenant) {
            app()->instance('tenant', $tenant);
            app()->instance(\App\Models\Tenant::class, $tenant);
        }

        return $next($request);
    }
}

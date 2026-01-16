<?php

namespace App\Http\Middleware;

use App\Services\TenantResolver;
use App\Tenancy\TenantContext;
use Carbon\Carbon;
use Closure;
use Illuminate\Support\Facades\App;
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

            // Build and bind tenant-aware context from tenants.settings.
            $context = TenantContext::fromTenant($tenant);
            app()->instance(TenantContext::class, $context);

            // Apply locale + timezone BEFORE controllers/policies/validation.
            App::setLocale($context->locale);
            Carbon::setLocale($context->locale);

            @date_default_timezone_set($context->timezone);
            config(['app.timezone' => $context->timezone]);
        }

        $response = $next($request);

        // Attach context headers to every tenant response.
        if ($tenant && isset($context)) {
            $response->headers->set('X-Tenant-Locale', $context->locale);
            $response->headers->set('X-Tenant-Timezone', $context->timezone);
            $response->headers->set('X-Tenant-Week-Start', $context->weekStart);
            $response->headers->set('X-Tenant-Currency', $context->currency);
        }

        return $response;
    }
}

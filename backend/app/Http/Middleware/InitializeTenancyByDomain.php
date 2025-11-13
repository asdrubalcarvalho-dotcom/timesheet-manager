<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain as BaseInitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\InitializeTenancyByRequestData as BaseInitializeTenancyByRequestData;
use Stancl\Tenancy\Resolvers\DomainTenantResolver;
use Stancl\Tenancy\Tenancy;

class InitializeTenancyByDomain extends BaseInitializeTenancyByDomain
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next)
    {
        // Allow central domain access in configured environments
        if ($this->shouldAllowCentralFallback($request)) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }

    protected function shouldAllowCentralFallback(Request $request): bool
    {
        $fallbackConfig = config('tenancy.domains.central_fallback', []);
        
        if (!($fallbackConfig['enabled'] ?? false)) {
            return false;
        }

        $allowedEnvironments = $fallbackConfig['environments'] ?? ['local', 'development'];
        
        return in_array(app()->environment(), $allowedEnvironments);
    }
}

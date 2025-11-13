<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\CentralDomainFallback;
use Closure;
use Illuminate\Http\Request;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedById;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedOnDomainException;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;

class InitializeTenancyByDomainWithFallback extends InitializeTenancyByDomain
{
    public function handle($request, Closure $next)
    {
        try {
            return parent::handle($request, $next);
        } catch (TenantCouldNotBeIdentifiedOnDomainException|TenantCouldNotBeIdentifiedById $exception) {
            if (CentralDomainFallback::shouldAllow($request)) {
                return $next($request);
            }

            if (CentralDomainFallback::isCentralDomain($request)) {
                abort(403, 'Tenant could not be identified for the requested central domain.');
            }

            throw $exception;
        }
    }
}

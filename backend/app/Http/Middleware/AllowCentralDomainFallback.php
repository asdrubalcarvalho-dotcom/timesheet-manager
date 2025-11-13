<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\CentralDomainFallback;
use Closure;
use Illuminate\Http\Request;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

class AllowCentralDomainFallback extends PreventAccessFromCentralDomains
{
    public function handle(Request $request, Closure $next)
    {
        if ($this->shouldAllowFallback($request)) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }

    protected function shouldAllowFallback(Request $request): bool
    {
        return CentralDomainFallback::shouldAllow($request);
    }
}

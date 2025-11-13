<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PreventAccessFromCentralDomains
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $centralDomains = config('tenancy.central_domains', []);
        $currentHost = $request->getHost();

        if (in_array($currentHost, $centralDomains)) {
            return response()->json([
                'message' => 'This route cannot be accessed from a central domain. Please use a tenant-specific domain or provide tenant identification.',
                'hint' => 'Try accessing via subdomain or include X-Tenant header / ?tenant= query parameter.'
            ], 403);
        }

        return $next($request);
    }
}

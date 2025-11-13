<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAuthenticatedTenant
{
    public function handle(Request $request, Closure $next): Response
    {
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

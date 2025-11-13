<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! tenancy()->initialized && ! app()->runningInConsole()) {
            return new JsonResponse([
                'message' => 'Tenant context is required. Provide a valid tenant identifier via header or query parameter.',
            ], Response::HTTP_BAD_REQUEST);
        }

        return $next($request);
    }
}

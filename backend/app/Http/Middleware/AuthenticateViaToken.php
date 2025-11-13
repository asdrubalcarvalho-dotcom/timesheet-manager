<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateViaToken
{
    /**
     * Handle an incoming request.
     * Allows authentication via ?token query parameter in addition to Authorization header.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // If token is in query string, move it to Authorization header
        if ($request->has('token') && !$request->bearerToken()) {
            $token = $request->query('token');
            $request->headers->set('Authorization', 'Bearer ' . $token);
        }

        // If X-Tenant is in query string, move it to header
        if ($request->has('tenant') && !$request->header('X-Tenant')) {
            $tenant = $request->query('tenant');
            $request->headers->set('X-Tenant', $tenant);
        }

        // Authenticate user via Sanctum token
        if ($bearerToken = $request->bearerToken()) {
            // Find token in tenant database
            $accessToken = PersonalAccessToken::findToken($bearerToken);
            
            if ($accessToken) {
                // Set the authenticated user
                $request->setUserResolver(function () use ($accessToken) {
                    return $accessToken->tokenable;
                });
                
                // Also set for Auth facade
                Auth::setUser($accessToken->tokenable);
            }
        }

        return $next($request);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Telemetry Internal Middleware
 * 
 * Protects telemetry endpoints with API key authentication.
 * Requires X-Internal-Api-Key header matching TELEMETRY_API_KEY env variable.
 */
class TelemetryInternalMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $request->header('X-Internal-Api-Key');
        $expectedKey = config('telemetry.internal_key');

        if (!$expectedKey) {
            return response()->json([
                'success' => false,
                'message' => 'Telemetry API not configured',
            ], 500);
        }

        if (!$apiKey || $apiKey !== $expectedKey) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized - Invalid API key',
            ], 401);
        }

        return $next($request);
    }
}

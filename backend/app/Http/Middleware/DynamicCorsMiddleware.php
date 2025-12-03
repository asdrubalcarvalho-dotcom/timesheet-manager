<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DynamicCorsMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->headers->get('Origin');
        
        // Allowed patterns for tenant subdomains
        $allowedPatterns = [
            '#^http://[a-z0-9-]+\.app\.localhost:8082$#i',
            '#^http://[a-z0-9-]+\.timeperk\.localhost:8082$#i',
            '#^https://[a-z0-9-]+\.app\.timeperk\.com$#i', // Production
        ];
        
        // Static allowed origins
        $allowedOrigins = [
            'http://localhost:8082',
            'http://localhost:3000',
            'http://app.localhost:8082',
            'http://timesheetperk.localhost:8082',
        ];
        
        $isAllowed = false;
        
        // Check static origins
        if (in_array($origin, $allowedOrigins)) {
            $isAllowed = true;
        }
        
        // Check dynamic patterns
        if (!$isAllowed && $origin) {
            foreach ($allowedPatterns as $pattern) {
                if (preg_match($pattern, $origin)) {
                    $isAllowed = true;
                    break;
                }
            }
        }
        
        // Handle preflight OPTIONS request
        if ($request->isMethod('OPTIONS')) {
            return response('', 200)
                ->header('Access-Control-Allow-Origin', $isAllowed ? $origin : '')
                ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS, PATCH')
                ->header('Access-Control-Allow-Headers', 'Content-Type, X-Requested-With, X-CSRF-TOKEN, Authorization, X-Tenant, Accept')
                ->header('Access-Control-Allow-Credentials', 'true')
                ->header('Access-Control-Max-Age', '3600');
        }
        
        // Process request
        $response = $next($request);
        
        // Add CORS headers to response
        if ($isAllowed && $origin) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS, PATCH');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, X-Requested-With, X-CSRF-TOKEN, Authorization, X-Tenant, Accept');
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
        }
        
        return $response;
    }
}

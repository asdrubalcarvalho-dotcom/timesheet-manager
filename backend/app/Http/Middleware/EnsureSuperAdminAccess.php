<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;

# COPILOT GLOBAL RULES — DO NOT IGNORE
# See backend/config/telemetry.php for complete rules

class EnsureSuperAdminAccess
{
    /**
     * Handle an incoming request.
     *
     * Manual Sanctum authentication + SuperAdmin checks
     * Does NOT use auth:sanctum middleware to avoid tenant DB issues
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // CRITICAL: Configure tenant DB connection for 'management' tenant
        // SuperAdmin uses 'management' tenant (01KBX7H2G0VKN43ABFEKBX6TP9)
        Config::set('database.connections.tenant', [
            'driver' => 'mysql',
            'host' => config('database.connections.mysql.host'),
            'port' => config('database.connections.mysql.port'),
            'database' => 'timesheet_01KBX7H2G0VKN43ABFEKBX6TP9', // Management tenant DB (UPPERCASE)
            'username' => config('database.connections.mysql.username'),
            'password' => config('database.connections.mysql.password'),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
        ]);
        DB::setDefaultConnection('tenant'); // Use tenant connection
        Config::set('database.default', 'tenant');
        
        // 1. Manual Sanctum authentication (uses management tenant DB)
        $token = $request->bearerToken();
        
        if (!$token) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated - No bearer token provided'
            ], 401);
        }

        // Get token from management tenant database
        $accessToken = PersonalAccessToken::findToken($token);
        
        if (!$accessToken) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated - Invalid token'
            ], 401);
        }

        // Set the authenticated user
        $request->setUserResolver(fn () => $accessToken->tokenable);
        $user = $accessToken->tokenable;

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated - Token user not found'
            ], 401);
        }

        // 2. Check origin domain (must be management.*)
        $origin = $request->header('Origin') ?: $request->header('Referer');
        $originHost = $origin ? parse_url($origin, PHP_URL_HOST) : null;
        
        $allowedDomains = config('telemetry.allowed_superadmin_domains', []);

        if (!$originHost || !in_array($originHost, $allowedDomains)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden - Must access from management subdomain',
            ], 403);
        }

        // 3. Check user email (supervisor@upg2ai.com)
        $superadminEmail = config('telemetry.superadmin_email');

        if ($user->email !== $superadminEmail) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden - Insufficient permissions'
            ], 403);
        }

        return $next($request);
    }
}


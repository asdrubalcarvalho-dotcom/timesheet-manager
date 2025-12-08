<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use App\Models\Tenant;

class EnsureSuperAdminAccess
{
    /**
     * Handle an incoming request.
     *
     * SuperAdmin telemetry:
     * - Detect tenant from domain (subdomain)
     * - Switch to that tenant DB
     * - Validate Sanctum token + email + allowed domain
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /**
         * STEP 0 — Extract Origin + tenant slug from domain
         */
        $origin = $request->header('Origin') ?: $request->header('Referer');
        $originHost = $origin ? parse_url($origin, PHP_URL_HOST) : null;

        $allowedDomains = config('telemetry.allowed_superadmin_domains', []);

        if (!$originHost || !in_array($originHost, $allowedDomains)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden – Must access from allowed SuperAdmin domain',
            ], 403);
        }

        // Example: management.vendaslive.com -> management
        //          upg2ai.vendaslive.com    -> upg2ai
        $parts = $originHost ? explode('.', $originHost) : [];
        $tenantSlug = $parts[0] ?? null;

        if (!$tenantSlug) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to detect tenant from domain',
            ], 500);
        }

        /**
         * STEP 1 — Read Sanctum token from request
         */
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated – No bearer token provided',
            ], 401);
        }

        /**
         * STEP 2 — Use CENTRAL DB to resolve tenant + DB name
         */
        DB::setDefaultConnection(config('tenancy.database.central_connection', 'mysql'));

        $tenant = Tenant::where('slug', $tenantSlug)->first();

        if (!$tenant) {
            return response()->json([
                'success' => false,
                'message' => "Tenant not found for slug: {$tenantSlug}",
            ], 500);
        }

        $databaseName = $tenant->getInternal('db_name');

        if (!$databaseName) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant database not configured',
            ], 500);
        }

        /**
         * STEP 3 — Configure TENANT connection dynamically
         * Here we point the 'tenant' connection to the correct database.
         */
        Config::set('database.connections.tenant', [
            'driver' => 'mysql',
            'host' => config('database.connections.mysql.host'),
            'port' => config('database.connections.mysql.port'),
            'database' => $databaseName,
            'username' => config('database.connections.mysql.username'),
            'password' => config('database.connections.mysql.password'),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => null,
        ]);

        DB::purge('tenant');
        DB::setDefaultConnection('tenant');
        Config::set('database.default', 'tenant');
        Config::set('sanctum.connection', 'tenant');

        /**
         * STEP 4 — Now authenticate with Sanctum on TENANT DB
         */
        $accessToken = PersonalAccessToken::findToken($token);

        if (!$accessToken) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated – Invalid token',
            ], 401);
        }

        $user = $accessToken->tokenable;

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated – Token user not found',
            ], 401);
        }

        // Make user available via $request->user()
        $request->setUserResolver(fn () => $user);

        /**
         * STEP 5 — Validate SuperAdmin email
         */
        $superadminEmail = config('telemetry.superadmin_email');

        if ($user->email !== $superadminEmail) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden – Not a SuperAdmin',
            ], 403);
        }

        return $next($request);
    }
}

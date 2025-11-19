<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Tenancy;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Initialize tenancy by slug from X-Tenant header or tenant query parameter.
 * This middleware is designed for API requests where the frontend sends tenant slug.
 */
class InitializeTenancyBySlug
{
    /** @var Tenancy */
    protected $tenancy;

    /** @var string */
    public static $header = 'X-Tenant';

    /** @var string|null */
    public static $queryParameter = 'tenant';

    public function __construct(Tenancy $tenancy)
    {
        $this->tenancy = $tenancy;
    }

    public function handle(Request $request, Closure $next)
    {
        // Skip if tenancy is already initialized
        if ($this->tenancy->initialized) {
            \Log::info('Tenancy already initialized', ['tenant_id' => tenant('id')]);
            return $next($request);
        }

        // Try to get tenant slug from header or query parameter
        $slug = $request->header(static::$header)
            ?? $request->query(static::$queryParameter);

        \Log::info('InitializeTenancyBySlug', [
            'header' => $request->header(static::$header),
            'query' => $request->query(static::$queryParameter),
            'slug' => $slug,
            'url' => $request->url(),
            'all_headers' => $request->headers->all()
        ]);

        if (! $slug) {
            // No tenant identifier provided - continue without initializing tenancy
            // (useful for central routes like /api/login, /api/register)
            \Log::warning('No tenant slug provided - continuing without tenancy');
            return $next($request);
        }

        // Find tenant by slug
        $tenant = Tenant::where('slug', $slug)->first();

        if (! $tenant) {
            \Log::error("Tenant not found", ['slug' => $slug]);
            throw new NotFoundHttpException("Tenant with slug '{$slug}' not found.");
        }

        // Initialize tenancy
        \Log::info('Initializing tenancy', ['slug' => $slug, 'tenant_id' => $tenant->id]);
        $this->tenancy->initialize($tenant);
        
        // CRITICAL: Set up tenant DB connection for Sanctum BEFORE auth:sanctum middleware runs
        $databaseName = $tenant->getInternal('db_name');
        
        config(['database.connections.tenant' => [
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
        ]]);
        
        DB::purge('tenant');
        DB::reconnect('tenant');
        DB::setDefaultConnection('tenant');
        
        // Force Sanctum to use tenant connection
        Config::set('sanctum.connection', 'tenant');

        return $next($request);
    }
}

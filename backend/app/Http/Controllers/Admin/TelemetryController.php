<?php
/* 
--------------------------------------------------------------------------------
COPILOT INSTRUCTIONS — DO NOT IGNORE
--------------------------------------------------------------------------------

NEW GOAL:
Add TWO new telemetry endpoints INSIDE THIS CONTROLLER:

1) INTERNAL ENDPOINT → /api/admin/telemetry/health
   Method name: health()
   Requirements:
     - MUST NOT use tenant DB (no tenancy()->tenant)
     - MUST NOT invent models, tables, fields, or migrations
     - MUST NOT modify the database schema
     - MUST use ONLY built-in PHP functions or Laravel helpers
     - Return server health indicators:
         cpu_load (float)
         memory_usage_percent
         disk_free_gb
         disk_total_gb
         disk_usage_percent
         queue_connection (just return config('queue.default'))
         cache_connection (config('cache.default'))
         database_connection (config('database.default'))
     - Everything wrapped in try/catch
     - Output JSON in format:
         { "success": true, "data": { ... } }

2) INTERNAL ENDPOINT → /api/admin/telemetry/errors
   Method name: errors()
   Requirements:
     - DO NOT parse entire file
     - DO NOT ingest huge logs (only last ~200 lines)
     - Use tail-like approach:
         readfile(storage_path('logs/laravel.log')) but LIMIT output
     - NEVER invent fields or structures
     - Output JSON:
         { "success": true, "data": { "lines": [...last errors...] } }
     - If file missing: return empty array
     - All inside try/catch

GLOBAL RULES:
- NO new migrations, NO database writes.
- NO invented models or tables.
- NO tenant context.
- RETURN JSON ALWAYS.
- If unsure → STOP and ask.

--------------------------------------------------------------------------------
*/
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Telemetry Controller
 * 
 * Internal APIs for monitoring system health and metrics.
 * Protected by telemetry.internal middleware (requires X-Internal-Api-Key).
 * 
 * CRITICAL: These are CENTRAL APIs - NO tenant context, NO tenant DB access.
 */
class TelemetryController extends Controller
{
    /**
     * GET /api/admin/telemetry/info
     * 
     * Returns application and environment information.
     */
    public function info(): JsonResponse
    {
        try {
            $data = [
                'app_name' => config('app.name'),
                'app_env' => config('app.env'),
                'app_debug' => config('app.debug'),
                'app_url' => config('app.url'),
                'php_version' => PHP_VERSION,
                'laravel_version' => app()->version(),
                'timezone' => config('app.timezone'),
                'database_connection' => config('database.default'),
            ];

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/admin/telemetry/tenants
     * 
     * Returns list of all tenants with basic info.
     * Uses EXISTING Tenant model (central DB).
     */
    public function tenants(): JsonResponse
    {
        try {
            $tenants = Tenant::select([
                'id',
                'slug',
                'owner_email',
                'status',
                'plan',
                'created_at',
                'trial_ends_at',
            ])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($tenant) {
                return [
                    'id' => $tenant->id,
                    'slug' => $tenant->slug,
                    'owner_email' => $tenant->owner_email,
                    'status' => $tenant->status,
                    'plan' => $tenant->plan,
                    'created_at' => $tenant->created_at?->toIso8601String(),
                    'trial_ends_at' => $tenant->trial_ends_at?->toIso8601String(),
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'total' => $tenants->count(),
                    'tenants' => $tenants,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/admin/telemetry/billing
     * 
     * Returns billing summary using EXISTING Subscription and Payment models.
     */
    public function billing(): JsonResponse
    {
        try {
            // Subscription metrics
            $totalSubscriptions = Subscription::count();
            $activeSubscriptions = Subscription::where('status', 'active')->count();
            $trialSubscriptions = Subscription::where('is_trial', true)->count();
            
            $subscriptionsByPlan = Subscription::selectRaw('plan, COUNT(*) as count')
                ->groupBy('plan')
                ->get()
                ->pluck('count', 'plan')
                ->toArray();

            // Payment metrics
            $totalPayments = Payment::count();
            $completedPayments = Payment::where('status', 'completed')->count();
            $totalRevenue = Payment::where('status', 'completed')
                ->sum('amount');

            $data = [
                'subscriptions' => [
                    'total' => $totalSubscriptions,
                    'active' => $activeSubscriptions,
                    'trial' => $trialSubscriptions,
                    'by_plan' => $subscriptionsByPlan,
                ],
                'payments' => [
                    'total' => $totalPayments,
                    'completed' => $completedPayments,
                    'total_revenue' => round((float) $totalRevenue, 2),
                    'currency' => 'EUR',
                ],
            ];

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/admin/telemetry/usage
     * 
     * Returns lightweight usage metrics.
     * Placeholder: returns 0 metrics to avoid schema changes.
     */
    public function usage(): JsonResponse
    {
        try {
            $data = [
                'timesheets' => [
                    'total' => 0,
                    'today' => 0,
                ],
                'expenses' => [
                    'total' => 0,
                    'today' => 0,
                ],
                'users' => [
                    'total' => 0,
                    'active' => 0,
                ],
            ];

            return response()->json([
                'success' => true,
                'data' => $data,
                'note' => 'Placeholder metrics - detailed usage tracking not implemented',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/admin/telemetry/errors
     * 
     * Returns recent error log entries from Laravel log file.
     * RULE 3 compliant: Simple regex filtering, no DB access.
     */
    public function errors(): JsonResponse
    {
        try {
            $logFile = storage_path('logs/laravel.log');
            
            if (!file_exists($logFile)) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'message' => 'Log file not found',
                ]);
            }

            // Read last 200 lines
            $lines = array_slice(file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), -200);
            
            $errors = [];
            foreach ($lines as $line) {
                // Simple regex to extract ERROR or CRITICAL entries
                if (preg_match('/\[([\d\-]+ [\d:]+)\].*\.(ERROR|CRITICAL):?\s*(.*)/', $line, $matches)) {
                    $errors[] = [
                        'timestamp' => $matches[1],
                        'level' => $matches[2],
                        'message' => trim($matches[3]),
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'data' => array_slice($errors, -50), // Return last 50 errors
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/admin/telemetry/health
     * 
     * Returns system health check status.
     * RULE 2 compliant: Database, Redis, and disk checks only.
     */
    public function health(): JsonResponse
    {
        try {
            $health = [
                'app' => 'ok',
                'database' => 'down',
                'redis' => 'disabled',
                'disk_free_mb' => 0,
            ];

            // Check database
            try {
                \DB::select('select 1');
                $health['database'] = 'ok';
            } catch (\Throwable $e) {
                $health['database'] = 'down';
            }

            // Check Redis if available
            if (class_exists(\Illuminate\Support\Facades\Redis::class)) {
                try {
                    \Illuminate\Support\Facades\Redis::ping();
                    $health['redis'] = 'ok';
                } catch (\Throwable $e) {
                    $health['redis'] = 'down';
                }
            }

            // Disk space
            $diskFree = disk_free_space(storage_path());
            $health['disk_free_mb'] = $diskFree ? round($diskFree / 1024 / 1024, 2) : 0;

            return response()->json([
                'success' => true,
                'data' => $health,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/admin/telemetry/ping?url={url}
     * 
     * Tests performance of internal endpoints.
     * RULE 1 compliant: No DB access, simple HTTP GET request measurement.
     */
    public function ping(Request $request): JsonResponse
    {
        try {
            $url = $request->query('url');
            
            if (!$url) {
                return response()->json([
                    'success' => false,
                    'message' => 'URL parameter required',
                ], 400);
            }

            // If URL is relative, make it absolute using nginx_api
            if (!preg_match('/^https?:\/\//', $url)) {
                $url = 'http://nginx_api' . $url;
            }

            // Measure request time
            $start = microtime(true);
            
            try {
                // Add X-Tenant header for tenant-scoped endpoints (simulates tenant context)
                $response = \Illuminate\Support\Facades\Http::timeout(5)
                    ->withHeaders(['X-Tenant' => 'management']) // Use management tenant for testing
                    ->get($url);
                    
                $duration = round((microtime(true) - $start) * 1000, 2); // ms
                $httpCode = $response->status();
                
                // Interpret status based on HTTP code
                $status = 'ok';
                if ($httpCode === 401 || $httpCode === 403) {
                    $status = 'auth_required'; // Expected for protected endpoints
                } elseif ($httpCode === 405) {
                    $status = 'method_not_allowed'; // Expected for POST-only endpoints like /login
                } elseif ($httpCode >= 400) {
                    $status = 'error';
                }
                
                return response()->json([
                    'success' => true,
                    'data' => [
                        'url' => $url,
                        'response_time_ms' => $duration,
                        'status' => $status,
                        'http_code' => $httpCode,
                    ],
                ]);
            } catch (\Throwable $e) {
                $duration = round((microtime(true) - $start) * 1000, 2);
                
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'data' => [
                        'url' => $url,
                        'response_time_ms' => $duration,
                        'status' => 'fail',
                    ],
                ], 500);
            }
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}


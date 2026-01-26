<?php
/*
--------------------------------------------------------------------------------
COPILOT INSTRUCTIONS — DO NOT IGNORE
--------------------------------------------------------------------------------

NEW GOAL:

Implement TWO NEW PROXY METHODS in THIS controller:

1) health()
2) errors()

These MUST:
  - NOT query the DB
  - NOT call tenant connections
  - NOT implement logic
  - ONLY proxy internal telemetry endpoints:
        GET {config('telemetry.internal_base_url')}/health
        GET {config('telemetry.internal_base_url')}/errors
  - Use Http::withHeaders([
        'X-Internal-Api-Key' => config('telemetry.internal_key')
    ])
  - Wrap everything in try/catch
  - On success:
        return [ "success" => true, "data" => <internal_response.data> ]
  - On internal failure:
        return [ "success" => false, "message" => <error> ]

ABSOLUTE RULES:
- DO NOT invent endpoints.
- DO NOT access internal key directly.
- DO NOT expose internal key in logs.
- DO NOT modify the database schema.
- NO new migrations.

--------------------------------------------------------------------------------
*/
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Http;

# COPILOT GLOBAL RULES — DO NOT IGNORE
# See backend/config/telemetry.php for complete rules

class SuperAdminTelemetryController extends Controller
{
    /**
     * Get system information via internal API proxy
     */
    public function info(): JsonResponse
    {
        try {
            $response = Http::withHeaders([
                'X-Internal-Api-Key' => config('telemetry.internal_key'),
                'Accept' => 'application/json',
            ])->get(rtrim(config('telemetry.internal_base_url'), '/') . '/info');

            return response()->json([
                'success' => true,
                'data' => $response->json('data', $response->json())
            ], $response->status());

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get tenants list via internal API proxy
     */
    public function tenants(): JsonResponse
    {
        try {
            $response = Http::withHeaders([
                'X-Internal-Api-Key' => config('telemetry.internal_key'),
                'Accept' => 'application/json',
            ])->get(rtrim(config('telemetry.internal_base_url'), '/') . '/tenants');

            return response()->json([
                'success' => true,
                'data' => $response->json('data', $response->json())
            ], $response->status());

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get billing summary via internal API proxy
     */
    public function billing(): JsonResponse
    {
        try {
            $response = Http::withHeaders([
                'X-Internal-Api-Key' => config('telemetry.internal_key'),
                'Accept' => 'application/json',
            ])->get(rtrim(config('telemetry.internal_base_url'), '/') . '/billing');

            return response()->json([
                'success' => true,
                'data' => $response->json('data', $response->json())
            ], $response->status());

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get usage metrics via internal API proxy
     */
    public function usage(): JsonResponse
    {
        try {
            $response = Http::withHeaders([
                'X-Internal-Api-Key' => config('telemetry.internal_key'),
                'Accept' => 'application/json',
            ])->get(rtrim(config('telemetry.internal_base_url'), '/') . '/usage');

            return response()->json([
                'success' => true,
                'data' => $response->json('data', $response->json())
            ], $response->status());

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get errors via internal API proxy
     */
    public function errors(): JsonResponse
    {
        try {
            $response = Http::withHeaders([
                'X-Internal-Api-Key' => config('telemetry.internal_key'),
                'Accept' => 'application/json',
            ])->timeout(5)->get(rtrim(config('telemetry.internal_base_url'), '/') . '/errors');

            if (!$response->successful()) {
                $message = $response->json('message');
                if (!$message) {
                    $message = trim((string) $response->body());
                }

                return response()->json([
                    'success' => false,
                    'message' => $message !== '' ? $message : 'Internal telemetry request failed',
                ], $response->status());
            }
            $data = $response->json('data');
            if ($data === null) {
                $data = $response->json();
            }

            return response()->json([
                'success' => true,
                'data' => $data,
            ], $response->status()); 

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/superadmin/telemetry/health
     * 
     * Proxy to internal /api/admin/telemetry/health
     * RULE 4 compliant: HTTP proxy only, no direct checks.
     */
    public function health(): JsonResponse
    {
        try {
            $response = Http::withHeaders([
                'X-Internal-Api-Key' => config('telemetry.internal_key'),
                'Accept' => 'application/json',
            ])->timeout(5)->get(rtrim(config('telemetry.internal_base_url'), '/') . '/health');

            if (!$response->successful()) {
                $message = $response->json('message');
                if (!$message) {
                    $message = trim((string) $response->body());
                }

                return response()->json([
                    'success' => false,
                    'message' => $message !== '' ? $message : 'Internal telemetry request failed',
                ], $response->status());
            }
            $data = $response->json('data');
            if ($data === null) {
                $data = $response->json();
            }

            return response()->json([
                'success' => true,
                'data' => $data,
            ], $response->status());
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/superadmin/telemetry/ping?url={url}
     * 
     * Proxy to internal /api/admin/telemetry/ping
     * RULE 4 compliant: HTTP proxy only, no direct operations.
     */
    public function ping(Request $request): JsonResponse
    {
        try {
            $url = rtrim(config('telemetry.internal_base_url'), '/') . '/ping?url=' . urlencode($request->query('url', ''));
            
            $response = Http::withHeaders([
                'X-Internal-Api-Key' => config('telemetry.internal_key'),
                'Accept' => 'application/json',
            ])->timeout(5)->get($url);

            return response()->json([
                'success' => true,
                'data' => $response->json('data', $response->json())
            ], $response->status());

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Helper method to call internal telemetry API
     * 
     * @param string $endpoint
     * @return \Illuminate\Http\Client\Response
     */
    private function callInternalApi(string $endpoint)
    {
        $url = rtrim(config('telemetry.internal_base_url'), '/') . '/' . ltrim($endpoint, '/');
        
        return Http::withHeaders([
            'X-Internal-Api-Key' => config('telemetry.internal_key'),
            'Accept' => 'application/json',
        ])->timeout(5)->get($url);
    }

    /**
     * GET /api/superadmin/telemetry/tenants/{slug}/usage
     *
     * Returns per-tenant usage snapshot (today) from central tenant_metrics_daily.
     */
    public function tenantUsage(string $slug): JsonResponse
    {
        try {
            $tenant = Tenant::where('slug', $slug)->first();
            if (!$tenant) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant not found',
                ], 404);
            }

            if (!Schema::connection('mysql')->hasTable('tenant_metrics_daily')) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'timesheets' => ['total' => 0, 'today' => 0],
                        'expenses' => ['total' => 0, 'today' => 0],
                        'users' => ['total' => 0, 'active' => 0],
                    ],
                    'note' => 'Metrics table not available yet',
                ]);
            }

            $today = now()->toDateString();
            $row = DB::connection('mysql')->table('tenant_metrics_daily')
                ->where('tenant_id', $tenant->id)
                ->where('date', $today)
                ->first();

            return response()->json([
                'success' => true,
                'data' => [
                    'timesheets' => [
                        'total' => (int) ($row->timesheets_total ?? 0),
                        'today' => (int) ($row->timesheets_today ?? 0),
                    ],
                    'expenses' => [
                        'total' => (int) ($row->expenses_total ?? 0),
                        'today' => (int) ($row->expenses_today ?? 0),
                    ],
                    'users' => [
                        'total' => (int) ($row->users_total ?? 0),
                        'active' => (int) ($row->users_active_today ?? 0),
                    ],
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
     * GET /api/superadmin/telemetry/tenants/{slug}/billing-details
     *
     * Returns per-tenant subscription + existing history tables (if present).
     */
    public function tenantBillingDetails(string $slug): JsonResponse
    {
        try {
            $tenant = Tenant::where('slug', $slug)->first();
            if (!$tenant) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant not found',
                ], 404);
            }

            $subscription = DB::connection('mysql')->table('subscriptions')
                ->where('tenant_id', $tenant->id)
                ->first();

            $planChangeHistory = [];
            if (Schema::connection('mysql')->hasTable('plan_change_history')) {
                $planChangeHistory = DB::connection('mysql')->table('plan_change_history')
                    ->where('tenant_id', $tenant->id)
                    ->orderByDesc('created_at')
                    ->limit(100)
                    ->get()
                    ->toArray();
            }

            $subscriptionPlanHistory = [];
            if (Schema::connection('mysql')->hasTable('subscription_plan_history')) {
                $subscriptionPlanHistory = DB::connection('mysql')->table('subscription_plan_history')
                    ->where('tenant_id', $tenant->id)
                    ->orderByDesc('changed_at')
                    ->limit(100)
                    ->get()
                    ->toArray();
            }

            $paymentFailures = [];
            if (Schema::connection('mysql')->hasTable('billing_payment_failures')) {
                $paymentFailures = DB::connection('mysql')->table('billing_payment_failures')
                    ->where('tenant_id', $tenant->id)
                    ->orderByDesc('failed_at')
                    ->limit(50)
                    ->get()
                    ->toArray();
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'tenant' => [
                        'id' => $tenant->id,
                        'slug' => $tenant->slug,
                        'name' => $tenant->name,
                        'status' => $tenant->status,
                        'plan' => $tenant->plan,
                    ],
                    'subscription' => $subscription,
                    'history' => [
                        'plan_change_history' => $planChangeHistory,
                        'subscription_plan_history' => $subscriptionPlanHistory,
                        'payment_failures' => $paymentFailures,
                    ],
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
     * POST /api/superadmin/telemetry/tenants/{slug}/delete
     *
     * Strong-confirm deletion for management UI.
     */
    public function deleteTenant(Request $request, string $slug): JsonResponse
    {
        try {
            $tenant = Tenant::where('slug', $slug)->first();
            if (!$tenant) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant not found',
                ], 404);
            }

            $confirmSlug = (string) $request->input('confirm_slug', '');
            $confirmIrreversible = (bool) $request->input('confirm_irreversible', false);
            $confirmFinal = (bool) $request->input('confirm_final', false);

            if ($confirmSlug !== $slug || !$confirmIrreversible || !$confirmFinal) {
                return response()->json([
                    'success' => false,
                    'message' => 'Deletion confirmation failed',
                ], 422);
            }

            $actor = $request->user();

            DB::connection('mysql')->table('admin_actions')->insert([
                'actor_user_id' => $actor?->id,
                'actor_email' => $actor?->email,
                'action' => 'tenant_delete_requested',
                'tenant_id' => $tenant->id,
                'payload' => json_encode([
                    'slug' => $slug,
                    'confirm_slug' => $confirmSlug,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $dbPrefix = config('tenancy.database.prefix', 'timesheet_');
            $tenantDbName = $dbPrefix . $tenant->id;

            $tenantId = $tenant->id;
            $tenant->delete();

            // Verify database dropped; fallback manual drop.
            try {
                // Best-effort hardening: sanitize identifier and always use backticks.
                // Keep DB name construction: prefix + tenant id.
                $safeDbName = str_replace('`', '', (string) $tenantDbName);
                $safeDbName = preg_replace('/[^A-Za-z0-9_]/', '', $safeDbName) ?? '';

                if ($safeDbName === '') {
                    throw new \RuntimeException('Invalid tenant database name');
                }

                $databases = DB::connection('mysql')->select('SHOW DATABASES LIKE ?', [$safeDbName]);
                if (!empty($databases)) {
                    DB::connection('mysql')->statement("DROP DATABASE IF EXISTS `{$safeDbName}`");
                }
            } catch (\Throwable $e) {
                // Best effort.
            }

            DB::connection('mysql')->table('admin_actions')->insert([
                'actor_user_id' => $actor?->id,
                'actor_email' => $actor?->email,
                'action' => 'tenant_deleted',
                'tenant_id' => $tenantId,
                'payload' => json_encode([
                    'slug' => $slug,
                    'database' => $tenantDbName,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'tenant_id' => $tenantId,
                    'slug' => $slug,
                    'database' => $tenantDbName,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}


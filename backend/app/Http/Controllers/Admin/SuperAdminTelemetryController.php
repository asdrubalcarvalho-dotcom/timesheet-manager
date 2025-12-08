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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            ])->get(rtrim(config('telemetry.internal_base_url'), '/') . '/errors');

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
     * GET /api/superadmin/telemetry/health
     * 
     * Proxy to internal /api/admin/telemetry/health
     * RULE 4 compliant: HTTP proxy only, no direct checks.
     */
    public function health(): JsonResponse
    {
        try {
            $url = rtrim(config('telemetry.internal_base_url'), '/') . '/health';
            
            $response = Http::withHeaders([
                'X-Internal-Api-Key' => config('telemetry.internal_key'),
                'Accept' => 'application/json',
            ])->get($url);

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
}


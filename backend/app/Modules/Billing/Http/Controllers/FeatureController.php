<?php

namespace Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Billing\Services\FeatureManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * FeatureController
 * 
 * Manages feature flags and module access
 */
class FeatureController extends Controller
{
    public function __construct(
        protected FeatureManager $features
    ) {
        $this->middleware('auth:sanctum');
    }

    /**
     * Get all modules and their status
     */
    public function index(): JsonResponse
    {
        $modules = $this->features->getAllModulesStatus();

        return response()->json(['modules' => $modules]);
    }

    /**
     * Get enabled modules only
     */
    public function enabled(): JsonResponse
    {
        $enabled = $this->features->getEnabledModules();

        return response()->json(['enabled_modules' => $enabled]);
    }

    /**
     * Enable a module (Admin/Owner only)
     */
    public function enable(Request $request, string $module): JsonResponse
    {
        $this->authorize('manage', \Modules\Billing\Models\TenantFeature::class);

        try {
            $feature = $this->features->enable($module);

            return response()->json([
                'message' => "Module '{$module}' enabled successfully",
                'feature' => [
                    'module_name' => $feature->module_name,
                    'is_enabled' => $feature->is_enabled,
                    'expires_at' => $feature->expires_at,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to enable module',
                'error' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Disable a module (Admin/Owner only)
     */
    public function disable(Request $request, string $module): JsonResponse
    {
        $this->authorize('manage', \Modules\Billing\Models\TenantFeature::class);

        try {
            $feature = $this->features->disable($module);

            return response()->json([
                'message' => "Module '{$module}' disabled successfully",
                'feature' => [
                    'module_name' => $feature->module_name,
                    'is_enabled' => $feature->is_enabled,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to disable module',
                'error' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Set trial for a module (Admin/Owner only)
     */
    public function setTrial(Request $request, string $module): JsonResponse
    {
        $this->authorize('manage', \Modules\Billing\Models\TenantFeature::class);

        $request->validate([
            'days' => 'required|integer|min:1|max:365',
        ]);

        try {
            $feature = $this->features->setTrial($module, $request->days);

            return response()->json([
                'message' => "Trial set for module '{$module}'",
                'feature' => [
                    'module_name' => $feature->module_name,
                    'is_enabled' => $feature->is_enabled,
                    'expires_at' => $feature->expires_at,
                    'days_remaining' => $feature->daysRemainingInTrial(),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to set trial',
                'error' => $e->getMessage()
            ], 422);
        }
    }
}

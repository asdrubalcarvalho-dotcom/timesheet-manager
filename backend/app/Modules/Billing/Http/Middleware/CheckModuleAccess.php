<?php

namespace Modules\Billing\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Billing\Services\FeatureManager;
use Symfony\Component\HttpFoundation\Response;

/**
 * CheckModuleAccess Middleware
 * 
 * Verifies that the tenant has access to the requested module.
 * Returns 403 with upgrade message if module is disabled.
 */
class CheckModuleAccess
{
    public function __construct(
        protected FeatureManager $features
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $module): Response
    {
        if (!$this->features->isEnabled($module)) {
            return response()->json([
                'message' => "The '{$module}' module is not enabled for your subscription.",
                'module' => $module,
                'upgrade_required' => true,
                'upgrade_url' => route('billing.index'),
            ], 403);
        }

        return $next($request);
    }
}

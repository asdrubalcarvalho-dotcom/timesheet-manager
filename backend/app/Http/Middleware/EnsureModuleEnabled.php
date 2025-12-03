<?php

namespace App\Http\Middleware;

use App\Services\TenantFeatures;
use App\Services\TenantResolver;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnsureModuleEnabled Middleware
 * 
 * Blocks access to module routes based on tenant's subscription plan
 * and active feature flags in Laravel Pennant.
 * 
 * Usage:
 * Route::middleware('module:travels')->group(...)
 * Route::middleware('module:planning')->group(...)
 * Route::middleware('module:ai')->group(...)
 * 
 * Core modules (timesheets, expenses) are always enabled and don't need this middleware.
 */
class EnsureModuleEnabled
{
    /**
     * Handle an incoming request.
     * 
     * @param string $module Feature key to check (travels|planning|ai)
     */
    public function handle(Request $request, Closure $next, string $module): Response
    {
        // Resolve tenant from context
        $tenant = TenantResolver::resolve();

        if (!$tenant) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Tenant context required.',
                'module' => $module,
            ], Response::HTTP_BAD_REQUEST);
        }

        // Check if module feature is active for this tenant
        $isActive = TenantFeatures::active($tenant, $module);

        if (!$isActive) {
            return $this->moduleDisabledResponse($module, $tenant);
        }

        return $next($request);
    }

    /**
     * Generate appropriate response based on module and plan.
     */
    protected function moduleDisabledResponse(string $module, $tenant): JsonResponse
    {
        $subscription = $tenant->subscription;
        $plan = $subscription?->plan ?? 'none';

        $messages = [
            'travels' => $this->getTravelsMessage($plan),
            'planning' => $this->getPlanningMessage($plan),
            'ai' => $this->getAIMessage($plan),
        ];

        $message = $messages[$module] ?? "The {$module} module is not available on your current plan.";

        return new JsonResponse([
            'success' => false,
            'message' => $message,
            'module' => $module,
            'plan' => $plan,
            'upgrade_required' => true,
            'suggestions' => $this->getUpgradeSuggestions($module, $plan),
        ], Response::HTTP_FORBIDDEN);
    }

    /**
     * Get Travels module message based on plan.
     */
    protected function getTravelsMessage(string $plan): string
    {
        if ($plan === 'starter') {
            return 'Travels module requires Team plan or more than 2 users on Starter plan.';
        }

        return 'Travels module is not available on your current plan. Upgrade to Team or Enterprise.';
    }

    /**
     * Get Planning module message based on plan.
     */
    protected function getPlanningMessage(string $plan): string
    {
        if (in_array($plan, ['team', 'enterprise'])) {
            return 'Planning module requires an addon. Enable it in your subscription settings.';
        }

        return 'Planning module is available on Team and Enterprise plans with addon.';
    }

    /**
     * Get AI module message based on plan.
     */
    protected function getAIMessage(string $plan): string
    {
        if ($plan === 'enterprise') {
            return 'AI Insights requires an addon. Enable it in your subscription settings.';
        }

        return 'AI Insights is only available on Enterprise plan with addon.';
    }

    /**
     * Get upgrade suggestions for each module.
     */
    protected function getUpgradeSuggestions(string $module, string $plan): array
    {
        $suggestions = [];

        switch ($module) {
            case 'travels':
                if ($plan === 'starter') {
                    $suggestions[] = 'Add more users to your team (unlocks at >2 users)';
                    $suggestions[] = 'Upgrade to Team plan for unlimited users';
                } else {
                    $suggestions[] = 'Upgrade to Team or Enterprise plan';
                }
                break;

            case 'planning':
                if (in_array($plan, ['team', 'enterprise'])) {
                    $suggestions[] = 'Enable Planning addon (+18% on subscription)';
                } else {
                    $suggestions[] = 'Upgrade to Team or Enterprise plan';
                    $suggestions[] = 'Then enable Planning addon';
                }
                break;

            case 'ai':
                if ($plan === 'enterprise') {
                    $suggestions[] = 'Enable AI Insights addon (+18% on subscription)';
                } else {
                    $suggestions[] = 'Upgrade to Enterprise plan';
                    $suggestions[] = 'Then enable AI Insights addon';
                }
                break;
        }

        return $suggestions;
    }
}

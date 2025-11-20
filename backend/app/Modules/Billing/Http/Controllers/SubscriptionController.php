<?php

namespace Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Billing\Services\LicenseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SubscriptionController
 * 
 * Manages subscription operations (add/remove licenses, upgrade/downgrade)
 */
class SubscriptionController extends Controller
{
    public function __construct(
        protected LicenseManager $licenses
    ) {
        $this->middleware('auth:sanctum');
        $this->middleware('permission:manage-billing');
    }

    /**
     * Add licenses to subscription
     */
    public function addLicenses(Request $request): JsonResponse
    {
        $request->validate([
            'quantity' => 'required|integer|min:1|max:100',
        ]);

        try {
            $license = $this->licenses->addLicenses($request->quantity, true);

            return response()->json([
                'message' => 'Licenses added successfully',
                'license' => [
                    'purchased' => $license->purchased_licenses,
                    'used' => $license->used_licenses,
                    'available' => $license->availableLicenses(),
                ],
                'cost' => $this->licenses->calculateCost(0), // Current cost
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to add licenses',
                'error' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Remove licenses from subscription
     */
    public function removeLicenses(Request $request): JsonResponse
    {
        $request->validate([
            'quantity' => 'required|integer|min:1',
        ]);

        try {
            $license = $this->licenses->removeLicenses($request->quantity, true);

            return response()->json([
                'message' => 'Licenses removed successfully',
                'license' => [
                    'purchased' => $license->purchased_licenses,
                    'used' => $license->used_licenses,
                    'available' => $license->availableLicenses(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to remove licenses',
                'error' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Calculate cost preview for adding licenses
     */
    public function previewCost(Request $request): JsonResponse
    {
        $request->validate([
            'quantity' => 'required|integer|min:1|max:100',
        ]);

        $cost = $this->licenses->calculateCost($request->quantity);

        return response()->json($cost);
    }

    /**
     * Switch billing cycle (monthly <-> annual)
     */
    public function switchBillingCycle(Request $request): JsonResponse
    {
        $request->validate([
            'billing_cycle' => 'required|in:monthly,annual',
        ]);

        $tenant = tenancy()->tenant;

        if (!$tenant->subscribed('default')) {
            return response()->json([
                'message' => 'No active subscription'
            ], 422);
        }

        $subscription = $tenant->subscription('default');

        // Cancel current subscription
        $subscription->cancel();

        // Create new subscription with new billing cycle
        $license = $this->licenses->getLicense();
        $license->billing_cycle = $request->billing_cycle;
        $license->save();

        // TODO: Create new Stripe subscription with correct price ID

        return response()->json([
            'message' => 'Billing cycle changed successfully',
            'billing_cycle' => $request->billing_cycle,
        ]);
    }

    /**
     * Resume canceled subscription
     */
    public function resume(): JsonResponse
    {
        $tenant = tenancy()->tenant;

        if (!$tenant->subscribed('default')) {
            return response()->json([
                'message' => 'No subscription to resume'
            ], 422);
        }

        $subscription = $tenant->subscription('default');

        if (!$subscription->onGracePeriod()) {
            return response()->json([
                'message' => 'Subscription is not in grace period'
            ], 422);
        }

        $subscription->resume();

        return response()->json([
            'message' => 'Subscription resumed successfully'
        ]);
    }

    /**
     * Cancel subscription
     */
    public function cancel(): JsonResponse
    {
        $tenant = tenancy()->tenant;

        if (!$tenant->subscribed('default')) {
            return response()->json([
                'message' => 'No active subscription'
            ], 422);
        }

        $subscription = $tenant->subscription('default');
        $subscription->cancel();

        return response()->json([
            'message' => 'Subscription canceled. You will retain access until the end of your billing period.',
            'ends_at' => $subscription->ends_at->toDateString(),
        ]);
    }
}

<?php

namespace Modules\Billing\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Billing\PlanManager;
use App\Services\Billing\PriceCalculator;
use App\Services\Payments\FakeCreditCardGateway;
use App\Services\TenantResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Billing\Models\Payment;

/**
 * BillingController
 * 
 * Handles billing and subscription management API endpoints.
 * 
 * Endpoints:
 * - GET /api/billing/summary - Current subscription and pricing
 * - POST /api/billing/upgrade-plan - Change subscription plan
 * - POST /api/billing/toggle-addon - Enable/disable addons
 * - POST /api/billing/checkout/start - Initialize payment
 * - POST /api/billing/checkout/confirm - Complete payment and apply plan
 */
class BillingController extends Controller
{
    public function __construct(
        protected PriceCalculator $priceCalculator,
        protected PlanManager $planManager,
        protected FakeCreditCardGateway $paymentGateway
    ) {}

    /**
     * GET /api/billing/summary
     * 
     * Get current subscription summary with pricing breakdown.
     */
    public function summary(): JsonResponse
    {
        $tenant = tenant();
        
        // getSubscriptionSummary already calls PriceCalculator internally
        // No need to call it again here
        $summary = $this->planManager->getSubscriptionSummary($tenant);

        return response()->json([
            'success' => true,
            'data' => $summary,
        ]);
    }

    /**
     * POST /api/billing/upgrade-plan
     * 
     * Upgrade or downgrade subscription plan.
     * Creates pending payment that must be confirmed via checkout.
     * 
     * Body: { "plan": "team", "user_limit": 5 }
     */
    public function upgradePlan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plan' => 'required|in:starter,team,enterprise',
            'user_limit' => 'required|integer|min:1',
        ]);

        $tenant = TenantResolver::resolveOrFail();

        // Calculate pricing using current tenant state (includes user_count)
        $pricing = $this->priceCalculator->calculate($tenant);

        // DOWNGRADE PROTECTION: Check if current user count exceeds target plan limit
        $currentUserCount = $pricing['user_count'] ?? User::count();

        // Determine target user limit based on plan
        $targetUserLimit = match ($validated['plan']) {
            'starter'    => 2,                          // Starter is always max 2 users
            'team'       => $validated['user_limit'],
            'enterprise' => $validated['user_limit'],
            default      => $validated['user_limit'],
        };

        if ($currentUserCount > $targetUserLimit) {
            return response()->json([
                'success'          => false,
                'code'             => 'downgrade_user_limit_exceeded',
                'message'          => "You currently have {$currentUserCount} active users. The {$validated['plan']} plan supports up to {$targetUserLimit} users. To downgrade, first reduce your users to {$targetUserLimit} or contact support.",
                'current_users'    => $currentUserCount,
                'target_plan'      => $validated['plan'],
                'target_user_limit'=> $targetUserLimit,
            ], 400);
        }

        // At this point, downgrade is allowed → continue normal flow

        // Check if upgrade is required for Starter
        if ($pricing['requires_upgrade'] && $validated['plan'] === 'starter') {
            return response()->json([
                'success' => false,
                'message' => 'Starter plan supports maximum 2 users. Please upgrade to Team or Enterprise.',
                'requires_upgrade' => true,
                'pricing' => $pricing,
            ], 400);
        }

        // Create completed payment (fake gateway - instant approval)
        $payment = Payment::create([
            'tenant_id' => $tenant->id,
            'amount'    => $pricing['total'],
            'currency'  => 'EUR',
            'status'    => 'completed',
            'gateway'   => 'fake_card',
            'completed_at' => now(),
            'metadata'  => [
                'plan'             => $validated['plan'],
                'user_limit'       => $validated['user_limit'],
                'pricing_summary'  => $pricing,
            ],
        ]);

        // Update subscription immediately (fake payment system)
        $subscription = $this->planManager->updatePlan(
            $tenant,
            $validated['plan'],
            $validated['user_limit']
        );

        // Get updated summary with new pricing
        $summary = $this->planManager->getSubscriptionSummary($tenant);

        return response()->json([
            'success' => true,
            'message' => 'Plan upgraded successfully!',
            'data'    => $summary,
        ]);
    }

    /**
     * POST /api/billing/toggle-addon
     * 
     * Enable or disable an addon (planning or ai).
     * 
     * Body: { "addon": "planning" }
     */
    public function toggleAddon(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'addon' => 'required|in:planning,ai',
        ]);

        $tenant = TenantResolver::resolveOrFail();

        // Get current subscription to check plan
        $subscription = $tenant->subscription;

        // RULE: Starter plan does NOT allow add-ons
        if ($subscription && $subscription->plan === 'starter') {
            return response()->json([
                'success' => false,
                'code'    => 'addons_not_allowed_on_starter',
                'message' => 'Add-ons are not available on the Starter plan. Please upgrade to Team or Enterprise.',
            ], 400);
        }

        // RULE: Enterprise plan includes everything, addons are no-op
        if ($subscription && $subscription->plan === 'enterprise') {
            // Get current summary (no changes)
            $summary = $this->planManager->getSubscriptionSummary($tenant);
            
            return response()->json([
                'success' => true,
                'code'    => 'addons_included_in_enterprise',
                'message' => 'All features are already included in your Enterprise plan.',
                'data' => $summary,
            ]);
        }

        try {
            $result = $this->planManager->toggleAddon($tenant, $validated['addon']);

            // Get updated summary with new pricing
            $summary = $this->planManager->getSubscriptionSummary($tenant);

            return response()->json([
                'success' => true,
                'message' => "Addon '{$result['addon']}' has been {$result['action']}.",
                'data' => $summary,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * POST /api/billing/checkout/start
     * 
     * Initialize payment gateway transaction.
     * 
     * Body: { "payment_id": 123 }
     */
    public function checkoutStart(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'payment_id' => 'required|exists:payments,id',
        ]);

        $tenant = TenantResolver::resolveOrFail();
        
        $payment = Payment::where('id', $validated['payment_id'])
            ->where('tenant_id', $tenant->id)
            ->firstOrFail();

        if (!$payment->isPending()) {
            return response()->json([
                'success' => false,
                'message' => 'Payment has already been processed.',
            ], 400);
        }

        // Initialize payment gateway
        $gatewayResult = $this->paymentGateway->initializePayment(
            $payment->amount,
            $payment->currency,
            [
                'payment_id' => $payment->id,
                'tenant_id' => $tenant->id,
            ]
        );

        if (!$gatewayResult['success']) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to initialize payment: ' . $gatewayResult['message'],
            ], 500);
        }

        // Store transaction ID in payment metadata
        $metadata = $payment->metadata ?? [];
        $metadata['transaction_id'] = $gatewayResult['transaction_id'];
        $payment->metadata = $metadata;
        $payment->save();

        return response()->json([
            'success' => true,
            'message' => 'Payment initialized.',
            'data' => [
                'payment_id' => $payment->id,
                'transaction_id' => $gatewayResult['transaction_id'],
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'gateway' => $payment->gateway,
            ],
        ]);
    }

    /**
     * POST /api/billing/checkout/confirm
     * 
     * Confirm payment and apply subscription changes.
     * On success, updates plan and syncs Pennant features.
     * 
     * Body: { "payment_id": 123, "card_number": "4111111111111111" }
     */
    public function checkoutConfirm(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'payment_id' => 'required|exists:payments,id',
            'card_number' => 'required|string',
        ]);

        $tenant = TenantResolver::resolveOrFail();
        
        $payment = Payment::where('id', $validated['payment_id'])
            ->where('tenant_id', $tenant->id)
            ->firstOrFail();

        if (!$payment->isPending()) {
            return response()->json([
                'success' => false,
                'message' => 'Payment has already been processed.',
            ], 400);
        }

        $transactionId = $payment->metadata['transaction_id'] ?? null;

        if (!$transactionId) {
            return response()->json([
                'success' => false,
                'message' => 'Payment not initialized. Call checkout/start first.',
            ], 400);
        }

        // Process payment through gateway
        $gatewayResult = $this->paymentGateway->processPayment($transactionId, [
            'card_number' => $validated['card_number'],
        ]);

        if (!$gatewayResult['success']) {
            $payment->markFailed($gatewayResult['message']);

            return response()->json([
                'success' => false,
                'message' => 'Payment failed: ' . $gatewayResult['message'],
                'data' => [
                    'payment_id' => $payment->id,
                    'status' => 'failed',
                ],
            ], 402); // 402 Payment Required
        }

        // Payment successful - mark as completed
        $payment->markCompleted();

        // Apply subscription changes from metadata
        $metadata = $payment->metadata;
        $newPlan = $metadata['plan'] ?? null;
        $userLimit = $metadata['user_limit'] ?? 1;

        if ($newPlan) {
            $subscription = $this->planManager->updatePlan($tenant, $newPlan, $userLimit);

            return response()->json([
                'success' => true,
                'message' => 'Payment successful! Your subscription has been updated.',
                'data' => [
                    'payment_id' => $payment->id,
                    'transaction_id' => $transactionId,
                    'subscription' => [
                        'plan' => $subscription->plan,
                        'user_limit' => $subscription->user_limit,
                        'status' => $subscription->status,
                    ],
                    'features' => $this->planManager->getSubscriptionSummary($tenant)['features'],
                ],
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Payment successful!',
            'data' => [
                'payment_id' => $payment->id,
                'status' => 'completed',
            ],
        ]);
    }
}

<?php
/**
 * === COPILOT GUIDANCE — DOWNGRADE SCHEDULING IMPLEMENTATION ===
 *
 * Goal:
 *   Add SAFE downgrade flow for subscription changes:
 *   - Downgrade never happens immediately
 *   - Upgrade still happens immediately (do NOT modify)
 *   - Scheduled downgrade applies at next renewal date
 *
 * Required features:
 *   1. POST /api/billing/schedule-downgrade
 *      - Validates user_limit rules
 *      - Stores pending downgrade in subscription metadata
 *      - Does NOT modify current plan or features yet
 *
 *   2. On next renewal (cron or manual simulate):
 *      - Apply downgrade using PlanManager::applyPlan()
 *      - Clear pending downgrade metadata
 *      - Sync Pennant features
 *
 *   3. BillingPage UI:
 *      - When scheduling a downgrade:
 *         • Show modal: “Downgrade will apply on next billing cycle”
 *         • No payment required
 *      - If downgrade already scheduled:
 *         • Show badge: “Downgrade scheduled for next cycle”
 *         • Disable further downgrade actions
 *
 * Strict constraints — DO NOT MODIFY:
 *   - PriceCalculator.php (pricing logic stays untouched)
 *   - billing.php (plan definitions stay untouched)
 *   - Pennant feature mapping or feature names
 *   - Trial lifecycle logic
 *   - Upgrade logic (stays immediate)
 *   - billing summary response schema
 *
 * Backend code allowed to modify:
 *   - BillingController::upgradePlan (add branch for downgrade scheduling)
 *   - PlanManager::updatePlan (only add ability to store "pending_downgrade")
 *   - Subscription model (metadata column usage)
 *
 * Frontend code allowed to modify:
 *   - BillingPage.tsx
 *   - BillingContext.tsx
 *   - Any toast messages or UI badges
 *
 * Acceptance criteria:
 *   - Downgrade never applies immediately
 *   - No payment flow triggered on downgrade
 *   - User sees clear info about scheduled downgrade
 *   - Upgrade flow continues to function exactly as before
 */
namespace App\Modules\Billing\Controllers;

use App\Http\Controllers\Controller;
use Modules\Billing\Models\Payment;
use App\Models\Payment as PaymentSnapshot;
use App\Models\Tenant;
use App\Services\Billing\PlanManager;
use App\Services\Billing\PriceCalculator;
use App\Services\Billing\PaymentSnapshot as PaymentSnapshotService;
use App\Services\Payments\PaymentGatewayFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * BillingController - Phase 1 Billing Module
 * 
 * Handles:
 * - Billing summary (plan, pricing, features)
 * - Plan upgrades
 * - Addon toggles
 * - Checkout flow (Phase 1.5 - fake payment)
 */
class BillingController extends Controller
{
    protected PlanManager $planManager;
    protected PriceCalculator $priceCalculator;
    protected PaymentGatewayFactory $gatewayFactory;
    protected PaymentSnapshotService $snapshotService;

    public function __construct(
        PlanManager $planManager,
        PriceCalculator $priceCalculator,
        PaymentGatewayFactory $gatewayFactory,
        PaymentSnapshotService $snapshotService
    ) {
        $this->planManager = $planManager;
        $this->priceCalculator = $priceCalculator;
        $this->gatewayFactory = $gatewayFactory;
        $this->snapshotService = $snapshotService;
    }

    /**
     * GET /api/billing/summary
     * Returns billing summary for current tenant
     */
    public function summary(): JsonResponse
    {
        try {
            $tenant = tenancy()->tenant;
            
            if (!$tenant) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tenant context'
                ], 400);
            }

            $summary = $this->planManager->getSubscriptionSummary($tenant);

            return response()->json([
                'success' => true,
                'data' => $summary
            ])->header('Cache-Control', 'no-cache, no-store, must-revalidate')
              ->header('Pragma', 'no-cache')
              ->header('Expires', '0');
        } catch (\Exception $e) {
            \Log::error('Billing summary failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch billing summary'
            ], 500);
        }
    }

    /**
     * POST /api/billing/upgrade-plan
     * Creates a pending payment for plan upgrade (Stripe checkout flow)
     * Does NOT apply the upgrade yet - that happens in checkoutConfirm after payment.
     * 
     * Request body:
     * {
     *   "plan": "starter" | "team" | "enterprise",
     *   "user_limit": integer
     * }
     */
    public function upgradePlan(Request $request): JsonResponse
    {
        try {
            $tenant = tenancy()->tenant;
            
            if (!$tenant) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tenant context'
                ], 400);
            }

            \Log::info('[upgradePlan] REQUEST RECEIVED', [
                'tenant_id' => $tenant->id,
                'plan' => $request->input('plan'),
                'user_limit' => $request->input('user_limit'),
                'all_input' => $request->all(),
            ]);

            $validated = $request->validate([
                'plan' => 'required|in:starter,team,enterprise',
                'user_limit' => 'required|integer|min:1'
            ]);

            $requestedPlan = $validated['plan'];
            $requestedLimit = $validated['user_limit'];

            \Log::info('[upgradePlan] VALIDATED', [
                'plan' => $requestedPlan,
                'user_limit' => $requestedLimit,
            ]);

            // Get current ACTIVE user count (only technicians with is_active=1)
            $currentUserCount = $tenant->run(function () {
                return \App\Models\Technician::where('is_active', 1)->count();
            });

            \Log::info('[upgradePlan] USER COUNT', [
                'current_user_count' => $currentUserCount,
                'requested_limit' => $requestedLimit,
            ]);

            // CRITICAL VALIDATION: Only validate for Starter plan
            // Team and Enterprise have flexible user limits
            if ($requestedPlan === 'starter') {
                // Starter has hard limit of 2 users
                if ($currentUserCount > 2) {
                    return response()->json([
                        'message' => "You currently have {$currentUserCount} active users. The Starter plan supports up to 2 users. To downgrade, first reduce your users to 2 or contact support.",
                        'statusCode' => 400
                    ], 400);
                }
            } else {
                // Team and Enterprise: user_limit is customizable, validate against current subscription limit
                $currentSubscription = $tenant->subscription;
                
                // Prevent downgrade of user_limit (can only increase or keep same)
                if ($currentSubscription && $requestedLimit < ($currentSubscription->user_limit ?? 0)) {
                    return response()->json([
                        'message' => "Cannot reduce user limit from {$currentSubscription->user_limit} to {$requestedLimit}. You can only increase licenses or keep the same amount.",
                        'statusCode' => 400,
                        'current_limit' => $currentSubscription->user_limit,
                        'requested_limit' => $requestedLimit,
                    ], 400);
                }
                
                // Also validate that requested limit can accommodate current active users
                if ($currentUserCount > $requestedLimit) {
                    return response()->json([
                        'message' => "You currently have {$currentUserCount} active users. The requested user limit ({$requestedLimit}) is less than your current count. Please increase the user limit or reduce active users before upgrading.",
                        'statusCode' => 400
                    ], 400);
                }
            }

            // Calculate pricing for upgrade
            $currentSubscription = $tenant->subscription;
            $isLicenseIncrement = $currentSubscription 
                && $currentSubscription->plan === $requestedPlan 
                && $requestedLimit > ($currentSubscription->user_limit ?? 0);

            if ($isLicenseIncrement) {
                // Same plan, adding licenses → prorated charge for increment only
                $pricing = $this->priceCalculator->calculateLicenseIncrementPrice($tenant, $requestedLimit);
                
                \Log::info('[upgradePlan] LICENSE INCREMENT detected', [
                    'current_limit' => $currentSubscription->user_limit,
                    'new_limit' => $requestedLimit,
                    'prorated_amount' => $pricing,
                ]);
            } else {
                // Different plan or first purchase → full monthly price
                $pricing = $this->priceCalculator->calculatePlanPrice($requestedPlan, $requestedLimit);
                
                \Log::info('[upgradePlan] PLAN CHANGE detected', [
                    'from_plan' => $currentSubscription?->plan,
                    'to_plan' => $requestedPlan,
                    'full_monthly_price' => $pricing,
                ]);
            }

            // Create pending payment (does NOT charge yet - waits for Stripe confirmation)
            $gateway = $this->gatewayFactory->driver();
            
            $metadata = [
                'mode'        => 'plan',
                'plan'        => $requestedPlan,
                'user_limit'  => $requestedLimit,
                'old_limit'   => $currentSubscription->user_limit ?? null,
                'tenant_id'   => $tenant->id,
            ];

            $payment = $gateway->createPaymentIntent($tenant, $pricing, $metadata);

            \Log::info('Plan upgrade payment created (pending checkout)', [
                'tenant_id' => $tenant->id,
                'plan' => $requestedPlan,
                'amount' => $pricing,
                'payment_id' => $payment->id,
                'gateway' => $gateway->getName(),
            ]);

            // Extract client_secret from payment metadata
            $clientSecret = $payment->metadata['stripe_client_secret'] ?? null;
            $sessionId = $payment->metadata['fake_session_id'] ?? null;

            return response()->json([
                'success' => true,
                'payment_id' => $payment->id,
                'client_secret' => $clientSecret,
                'session_id' => $sessionId,
                'gateway' => $this->gatewayFactory->isStripe() ? 'stripe' : 'fake',
                'amount' => $pricing,
                'currency' => 'EUR',
                'message' => 'Payment created. Please complete checkout to finalize upgrade.'
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Plan upgrade failed', [
                'plan' => $request->input('plan'),
                'user_limit' => $request->input('user_limit'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to upgrade plan: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST /api/billing/toggle-addon
     * Toggles addon (planning or ai)
     * 
     * Request body:
     * {
     *   "addon": "planning" | "ai"
     * }
     */
    public function toggleAddon(Request $request): JsonResponse
    {
        try {
            $tenant = tenancy()->tenant;
            
            if (!$tenant) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tenant context'
                ], 400);
            }

            $validated = $request->validate([
                'addon' => 'required|in:planning,ai'
            ]);

            $result = $this->planManager->toggleAddon($tenant, $validated['addon']);

            return response()->json([
                'success' => true,
                'message' => "Addon {$result['addon']} {$result['action']}",
                'data' => $result
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'code' => 'addon_not_available'
            ], 400);
        } catch (\Exception $e) {
            \Log::error('Addon toggle failed', [
                'addon' => $request->input('addon'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to toggle addon'
            ], 500);
        }
    }

    /**
     * POST /api/billing/licenses/increase
     * Increase user_limit for Team/Enterprise plans
     * 
     * Allows billing admins to purchase additional licenses
     * immediately without going through checkout flow.
     * 
     * @param Request $request { increment: number }
     * @return JsonResponse { success: bool, new_user_limit: int }
     */
    public function increaseLicenses(Request $request): JsonResponse
    {
        try {
            $tenant = tenancy()->tenant;
            
            if (!$tenant) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tenant context'
                ], 400);
            }

            $subscription = $tenant->subscription;
            
            if (!$subscription) {
                return response()->json([
                    'success' => false,
                    'message' => 'No active subscription found'
                ], 404);
            }

            // Validate plan allows license increase (Team/Enterprise only)
            if (!in_array($subscription->plan, ['team', 'enterprise'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'License increase is only available for Team and Enterprise plans',
                    'code' => 'plan_not_eligible'
                ], 400);
            }

            $validated = $request->validate([
                'increment' => 'required|integer|min:1|max:100'
            ]);

            // Get plan-specific limits
            $planLimits = config("billing.user_limits.{$subscription->plan}");
            if (!$planLimits) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid plan configuration'
                ], 500);
            }

            $newLimit = $subscription->user_limit + $validated['increment'];
            
            // Validate against plan maximum
            if ($newLimit > $planLimits['max']) {
                return response()->json([
                    'success' => false,
                    'message' => "Cannot increase beyond {$planLimits['max']} users for {$subscription->plan} plan. Current limit: {$subscription->user_limit}. To add more users, please upgrade to a higher tier plan.",
                    'code' => 'max_limit_reached',
                    'max_limit' => $planLimits['max'],
                    'current_limit' => $subscription->user_limit
                ], 400);
            }
            
            // Update subscription user_limit
            $subscription->user_limit = $newLimit;
            $subscription->save();

            // Log the operation
            \Log::info('[License Increase] User limit increased', [
                'tenant_id' => $tenant->id,
                'old_limit' => $subscription->user_limit - $validated['increment'],
                'new_limit' => $newLimit,
                'increment' => $validated['increment'],
                'plan' => $subscription->plan,
                'user_id' => $request->user()?->id
            ]);

            return response()->json([
                'success' => true,
                'new_user_limit' => $newLimit,
                'increment' => $validated['increment'],
                'message' => "Successfully increased license limit by {$validated['increment']}. New limit: {$newLimit} users."
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            \Log::error('[License Increase] Failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to increase licenses'
            ], 500);
        }
    }

    /**
     * POST /api/billing/checkout/start
     * Starts checkout session with payment snapshot (Billing Model A)
     * 
     * Flow:
     * 1. Calculate amount based on requested changes
     * 2. Create payment snapshot with billing state
     * 3. Create Stripe PaymentIntent with snapshot metadata
     * 4. Return client_secret for frontend
     * 
     * Accepts optional params to calculate NEW plan pricing:
     * - mode: 'plan' | 'addon' | 'users'
     * - plan: 'starter' | 'team' | 'enterprise'
     * - addon: 'planning' | 'ai'
     * - user_limit: number
     */
    public function checkoutStart(Request $request): JsonResponse
    {
        try {
            $tenant = tenancy()->tenant;
            
            if (!$tenant) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tenant context'
                ], 400);
            }

            $validated = $request->validate([
                'mode' => 'nullable|in:plan,addon,users,licenses',
                'plan' => 'nullable|in:starter,team,enterprise',
                'addon' => 'nullable|in:planning,ai',
                'user_limit' => 'nullable|integer|min:1'
            ]);

            // Get current subscription
            $subscription = $tenant->subscription;
            if (!$subscription) {
                return response()->json([
                    'success' => false,
                    'message' => 'No active subscription found'
                ], 400);
            }

            // Calculate amount based on what's being purchased
            $amount = 0;
            
            if (!empty($validated['mode']) && $validated['mode'] === 'licenses') {
                // MODE: licenses - Simple pricing: delta × price_per_user (NO proration)
                $currentLimit = $subscription->user_limit ?? 0;
                $newLimit = $validated['user_limit'] ?? 0;
                
                if ($newLimit <= $currentLimit) {
                    return response()->json([
                        'success' => false,
                        'message' => "New limit ({$newLimit}) must be greater than current limit ({$currentLimit})"
                    ], 422);
                }
                
                // Simple calculation: (new - current) × price_per_user
                $plan = $subscription->plan;
                $pricePerUser = config("billing.plans.{$plan}.price_per_user") ?? 0;
                $delta = $newLimit - $currentLimit;
                $amount = round($pricePerUser * $delta, 2);
                
                \Log::info('[checkoutStart] LICENSE INCREMENT simple pricing applied', [
                    'plan' => $plan,
                    'price_per_user' => $pricePerUser,
                    'current_limit' => $currentLimit,
                    'new_limit' => $newLimit,
                    'delta' => $delta,
                    'amount' => $amount,
                ]);
                
            } elseif (!empty($validated['mode']) && $validated['mode'] === 'plan' && !empty($validated['plan'])) {
                // MODE: plan - Prorated plan upgrade/downgrade
                // Uses differential pricing for Starter → Paid (full price) vs Paid → Paid (difference only)
                $amount = $this->priceCalculator->calculatePlanUpgradePrice(
                    $tenant,
                    $validated['plan'],
                    $validated['user_limit'] ?? null
                );
                
                \Log::info('[checkoutStart] PLAN UPGRADE mode', [
                    'current_plan' => $subscription->plan,
                    'new_plan' => $validated['plan'],
                    'user_limit' => $validated['user_limit'] ?? $subscription->user_limit,
                    'prorated_amount' => $amount,
                ]);
                
            } elseif (!empty($validated['mode']) && $validated['mode'] === 'addon' && !empty($validated['addon'])) {
                // MODE: addon - Activate addon (18% of base price)
                $summary = $this->planManager->getSubscriptionSummary($tenant);
                $basePrice = $summary['base_subtotal'];
                $addonPercentage = 0.18; // 18% as defined in config
                $amount = round($basePrice * $addonPercentage, 2);
            } else {
                // Fallback: current plan total
                $summary = $this->planManager->getSubscriptionSummary($tenant);
                $amount = $summary['total'];
            }

            // Use gateway abstraction to create payment intent
            $gateway = $this->gatewayFactory->driver();
            
            // Create payment intent via gateway (Stripe creates real PI, Fake generates test ID)
            $gatewayPayment = $gateway->createPaymentIntent($tenant, $amount, [
                'mode' => $validated['mode'] ?? 'plan',
                'plan' => $validated['plan'] ?? null,
                'addon' => $validated['addon'] ?? null,
                'user_limit' => $validated['user_limit'] ?? null,
            ]);
            
            // Extract PaymentIntent ID and client_secret
            $stripePaymentIntentId = $gatewayPayment->gateway_reference;
            $clientSecret = $gatewayPayment->metadata['stripe_client_secret'] ?? $gatewayPayment->metadata['fake_client_secret'] ?? null;
            
            // Determine target plan and user_limit for snapshot
            // CRITICAL: Pass what customer is BUYING, not current subscription state
            $targetPlan = null;
            $targetUserLimit = null;

            if (!empty($validated['mode']) && $validated['mode'] === 'plan' && !empty($validated['plan'])) {
                        $targetPlan = $validated['plan'];

                        /**
                         * BUSINESS RULES FOR PLAN CHANGES
                         *
                         * 1) Starter → Any paid plan (Team/Enterprise)
                         *    - Starter always carries 2 purchased licenses
                         *    - Even if only 1/2 users are active, the customer owns 2 seats
                         *
                         * 2) Paid → Paid (Team/Enterprise → Team/Enterprise)
                         *    - Always preserve ALL purchased licenses (subscription->user_limit)
                         *    - Plan change does NOT change license count
                         *
                         * 3) Trial Enterprise → Paid (Team/Enterprise)
                         *    - If subscription already has a user_limit configured, preserve it
                         *    - Otherwise, fall back to active user count (safeguard)
                         *
                         * IMPORTANT:
                         * - We completely ignore validated['user_limit'] here for mode=plan
                         *   License purchase is handled via the 'licenses' mode and/or
                         *   /licenses/increase endpoint, not via plan change.
                         */

                        // Case 1: Coming from Starter → always 2 licenses
                        if ($subscription->plan === 'starter') {
                            $targetUserLimit = 2;
                        }
                        // Case 2: Trial Enterprise → use configured limit or active users
                        elseif ($subscription->is_trial ?? false) {
                            if (!empty($subscription->user_limit)) {
                                // Trial already has an explicit license configuration
                                $targetUserLimit = $subscription->user_limit;
                            } else {
                                // Fallback: use current active user count (never less than 1)
                                $activeUserCount = $tenant->run(function () {
                                    return \App\Models\Technician::where('is_active', 1)->count();
                                });
                                $targetUserLimit = max($activeUserCount, 1);
                            }
                        }
                        // Case 3: Paid → Paid (Team/Enterprise) → preserve all purchased licenses
                        else {
                            // If for some reason user_limit is null, keep a safe minimum of 1
                            $targetUserLimit = $subscription->user_limit ?? 1;
                        }

                        \Log::info('[checkoutStart] SNAPSHOT TARGET for plan change', [
                            'tenant_id' => $tenant->id,
                            'current_plan' => $subscription->plan,
                            'target_plan' => $targetPlan,
                            'current_user_limit' => $subscription->user_limit,
                            'target_user_limit' => $targetUserLimit,
                            'is_trial' => $subscription->is_trial ?? false,
                        ]);
                    } elseif (!empty($validated['mode']) && $validated['mode'] === 'licenses') {
                        // License increment: plan stays same, user_limit increases
                        // Here we still trust validated['user_limit'] because customer
                        // is explicitly buying more seats.
                        $targetPlan = $subscription->plan;   // ensure snapshot receives correct plan
                        $targetUserLimit = $validated['user_limit']; // new purchased licenses

                        \Log::info('[checkoutStart] SNAPSHOT TARGET for license increment', [
                            'tenant_id' => $tenant->id,
                            'plan' => $subscription->plan,
                            'current_user_limit' => $subscription->user_limit,
                            'target_user_limit' => $targetUserLimit,
                        ]);
                    }
                    
                    // Update gateway payment with snapshot fields
                    $plan = $targetPlan ?? $subscription->plan ?? 'starter';
                    $userCount = $targetUserLimit ?? $subscription->user_limit ?? 1;
                    
                    // Build addons array
                    $addons = [];
                    if ($subscription->planning_addon_enabled ?? false) {
                        $addons[] = 'planning';
                    }
                    if ($subscription->ai_addon_enabled ?? false) {
                        $addons[] = 'ai';
                    }
                    
                    $cycleStart = now();
                    $cycleEnd = $cycleStart->copy()->addMonth();
                    
                    // Update payment with snapshot data
                    $gatewayPayment->plan = $plan;
                    $gatewayPayment->user_count = $userCount;
                    $gatewayPayment->addons = $addons;
                    $gatewayPayment->cycle_start = $cycleStart;
                    $gatewayPayment->cycle_end = $cycleEnd;
                    $gatewayPayment->save();
                    
                    $payment = $gatewayPayment;

                    // Update Stripe PaymentIntent with snapshot metadata
                    if ($this->gatewayFactory->isStripe()) {
                        $snapshotMetadata = $this->snapshotService->toStripeMetadata($payment);
                        $this->updateStripeMetadata($stripePaymentIntentId, $snapshotMetadata);
                    }

                    \Log::info('[BillingController] Payment with snapshot created', [
                        'payment_id' => $payment->id,
                        'plan' => $payment->plan,
                        'user_count' => $payment->user_count,
                        'stripe_payment_intent_id' => $stripePaymentIntentId,
                        'amount' => $amount,
                    ]);

            return response()->json([
                'payment_id' => $payment->id,
                'client_secret' => $clientSecret,
                'session_id' => $gatewayPayment->metadata['fake_session_id'] ?? null,
                'gateway' => $this->gatewayFactory->isStripe() ? 'stripe' : 'fake',
                'amount' => $amount,
                'currency' => 'EUR'
            ]);
        } catch (\Exception $e) {
            \Log::error('Checkout start failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to start checkout'
            ], 500);
        }
    }

    /**
     * Update Stripe PaymentIntent metadata with billing snapshot.
     */
    protected function updateStripeMetadata(string $paymentIntentId, array $metadata): void
    {
        try {
            $stripe = new \Stripe\StripeClient(config('stripe.current.sk'));
            $stripe->paymentIntents->update($paymentIntentId, [
                'metadata' => $metadata,
            ]);
        } catch (\Exception $e) {
            \Log::error('[BillingController] Failed to update Stripe metadata', [
                'payment_intent_id' => $paymentIntentId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * POST /api/billing/checkout/confirm
     * Confirms checkout with Billing Model A snapshot application
     * 
     * Flow:
     * 1. Confirm payment with Stripe/gateway
     * 2. Find payment snapshot by PaymentIntent ID
     * 3. Mark snapshot as paid
     * 4. Apply snapshot to subscription (not immediate subscription update)
     * 
     * Request body:
     * {
     *   "payment_id": number,
     *   "payment_method_id": string (Stripe),
     *   "card_number": string (fake gateway)
     * }
     */
    public function checkoutConfirm(Request $request): JsonResponse
    {
        try {
            $tenant = tenancy()->tenant;
            
            if (!$tenant) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tenant context'
                ], 400);
            }

            // Log incoming data for debugging
            \Log::info('Checkout confirm request data', [
                'all_data' => $request->all(),
                'content' => $request->getContent(),
            ]);

            $validated = $request->validate([
                'payment_id' => 'required|exists:mysql.payments,id',
                'payment_method_id' => 'nullable|string', // For Stripe
                'card_number' => 'nullable|string', // For fake gateway
            ]);

            // Retrieve the pending payment (force central database connection)
            $payment = Payment::on('mysql')->findOrFail($validated['payment_id']);
            
            if ($payment->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment already processed'
                ], 400);
            }

            // Use gateway to confirm payment
            $gateway = $this->gatewayFactory->driver();
            
            // Pass payment method data to gateway
            $paymentMethodData = [
                'payment_method_id' => $validated['payment_method_id'] ?? null,
                'card_number' => $validated['card_number'] ?? null,
            ];
            
            $confirmedPayment = $gateway->confirmPayment($payment, $paymentMethodData);

            // BILLING MODEL A: Apply payment snapshot to subscription
            if ($confirmedPayment->status === 'completed') {
                // The $payment passed to confirmPayment IS the snapshot (created in checkoutStart)
                // Use it directly instead of searching by payment_intent_id
                $snapshot = $payment;
                
                try {
                    // Mark snapshot as paid
                    $snapshot->refresh(); // Get latest status from confirmPayment
                    if ($snapshot->status === 'completed') {
                        $snapshot->markAsPaid();
                        
                        // Apply snapshot to subscription
                        $subscription = $tenant->subscription;
                        if ($subscription) {
                            $this->snapshotService->applySnapshot($snapshot, $subscription);
                            
                            // Refresh to get updated values
                            $subscription->refresh();
                            
                            \Log::info('[checkoutConfirm] Payment snapshot applied successfully', [
                                'snapshot_id' => $snapshot->id,
                                'payment_id' => $confirmedPayment->id,
                                'snapshot_plan' => $snapshot->plan,
                                'snapshot_user_count' => $snapshot->user_count,
                                'final_subscription_plan' => $subscription->plan,
                                'final_subscription_user_limit' => $subscription->user_limit,
                                'addons' => $snapshot->addons,
                            ]);
                        } else {
                            \Log::warning('[BillingController] No subscription found to apply snapshot', [
                                'tenant_id' => $tenant->id,
                                'snapshot_id' => $snapshot->id,
                            ]);
                        }
                    }
                } catch (\Exception $e) {
                    \Log::error('[BillingController] Failed to apply payment snapshot', [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                    // Continue - gateway already applied changes, snapshot is for audit
                }
            }

            // Generate success message based on payment result
            // NOTE: Subscription already updated by applySnapshot() above - no need to update again
            $metadata = $confirmedPayment->metadata ?? [];
            $mode = $metadata['mode'] ?? 'plan';
            
            $subscription = $tenant->subscription;
            
            \Log::info('[checkoutConfirm] Payment confirmed, checking final subscription state', [
                'tenant_id' => $tenant->id,
                'payment_id' => $confirmedPayment->id,
                'mode' => $mode,
                'final_plan' => $subscription->plan ?? 'unknown',
                'final_user_limit' => $subscription->user_limit ?? 'unknown',
            ]);
            
            // Build success message based on mode (subscription already updated via applySnapshot)
            if ($mode === 'licenses') {
                $message = "License limit increased successfully";
            } elseif ($mode === 'plan' && !empty($metadata['plan'])) {
                $message = "Plan upgraded to {$subscription->plan} with {$subscription->user_limit} licenses successfully";
            } elseif ($mode === 'addon' && !empty($metadata['addon'])) {
                $message = "Addon {$metadata['addon']} activated successfully";
            } else {
                $message = "Payment processed successfully";
            }

            return response()->json([
                'success' => true,
                'payment_id' => $confirmedPayment->id,
                'status' => $confirmedPayment->status,
                'message' => $message
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Checkout confirm failed', [
                'payment_id' => $request->input('payment_id'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Payment failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST /api/billing/schedule-downgrade
     * Schedules a downgrade for the next billing cycle
     * 
     * Request body:
     * {
     *   "plan": "starter" | "team",
     *   "user_limit": integer (optional)
     * }
     */
    public function scheduleDowngrade(Request $request): JsonResponse
    {
        try {
            $tenant = tenancy()->tenant;
            
            if (!$tenant) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tenant context'
                ], 400);
            }

            $validated = $request->validate([
                'plan' => 'required|in:starter,team',
                'user_limit' => 'nullable|integer|min:1'
            ]);

            $result = $this->planManager->scheduleDowngrade(
                $tenant,
                $validated['plan'],
                $validated['user_limit'] ?? null
            );

            return response()->json($result);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Schedule downgrade failed', [
                'plan' => $request->input('plan'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to schedule downgrade: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cancel a scheduled downgrade.
     * 
     * Can only cancel if >24h before next renewal date.
     */
    public function cancelScheduledDowngrade(): JsonResponse
    {
        try {
            $tenant = tenancy()->tenant;
            
            if (!$tenant) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tenant context'
                ], 400);
            }

            $result = $this->planManager->cancelScheduledDowngrade($tenant);

            return response()->json($result);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        } catch (\Exception $e) {
            \Log::error('Cancel scheduled downgrade failed', [
                'tenant_id' => $tenant->id ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel downgrade: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET /api/billing/gateway
     * Returns the active payment gateway configuration for frontend
     * 
     * Response:
     * {
     *   "gateway": "stripe" | "fake",
     *   "stripe_public_key": "pk_...", // Only for Stripe
     *   "currency": "EUR"
     * }
     */
    public function getGatewayConfig(): JsonResponse
    {
        try {
            $isStripe = $this->gatewayFactory->isStripe();
            
            $config = [
                'gateway' => $isStripe ? 'stripe' : 'fake',
                'currency' => 'EUR',
            ];
            
            if ($isStripe) {
                $config['stripe_public_key'] = config('stripe.current.pk');
            }
            
            return response()->json($config);
        } catch (\Exception $e) {
            \Log::error('Failed to get gateway config', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to get gateway configuration'
            ], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // PHASE 3: ERP INVOICE TRACKING
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Get pending invoices awaiting ERP processing
     * 
     * GET /api/billing/invoices/pending-erp
     * 
     * Returns list of Stripe invoices that need accounting system integration:
     * - Includes PDF download URLs
     * - Shows ERP processing deadlines
     * - Flags overdue invoices
     * 
     * Requires: Admin/Owner role (add authorization as needed)
     * 
     * Response:
     * {
     *   "success": true,
     *   "summary": {
     *     "pending_count": 5,
     *     "overdue_count": 1,
     *     "total_amount": 244.00
     *   },
     *   "invoices": [
     *     {
     *       "stripe_invoice_id": "in_xxx",
     *       "tenant_slug": "acme",
     *       "amount_due": 44.00,
     *       "pdf_url": "https://...",
     *       "erp_deadline_at": "2025-12-15T00:00:00+00:00",
     *       "days_until_deadline": 5,
     *       "is_overdue": false
     *     }
     *   ]
     * }
     */
    public function getPendingErpInvoices(Request $request): JsonResponse
    {
        try {
            // Guard: ERP sync must be enabled
            if (!config('billing.erp_sync.enabled')) {
                return response()->json([
                    'success' => false,
                    'message' => 'ERP sync not enabled (BILLING_ERP_SYNC_ENABLED=false)',
                ], 403);
            }

            $syncService = app(\App\Services\Billing\InvoiceSyncService::class);

            // Get query parameters
            $limit = $request->query('limit', null);
            $type = $request->query('type', 'pending'); // pending, approaching, overdue

            // Fetch invoices based on type
            switch ($type) {
                case 'approaching':
                    $days = $request->query('days', 7);
                    $invoices = $syncService->listApproachingDeadline($days);
                    break;

                case 'overdue':
                    $invoices = $syncService->listOverdue();
                    break;

                case 'pending':
                default:
                    $invoices = $syncService->listPending($limit);
                    break;
            }

            // Get summary statistics
            $summary = $syncService->getSummary();

            return response()->json([
                'success' => true,
                'summary' => $summary,
                'invoices' => $invoices,
                'type' => $type,
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to get pending ERP invoices', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve pending invoices',
            ], 500);
        }
    }

    /**
     * GET /api/billing/portal
     * Create Stripe Customer Portal session for tenant
     * 
     * Phase 4: Customer Portal Integration
     * 
     * Purpose:
     * - Allow tenants to manage subscription and payment methods
     * - Provide self-service billing management
     * - Redirect to Stripe-hosted portal
     * 
     * Security:
     * - Tenant-isolated via tenant() helper
     * - Uses tenant's own stripe_customer_id
     * - No cross-tenant access possible
     */
    public function createPortalSession(Request $request): JsonResponse
    {
        try {
            // Guard: Portal must be enabled
            if (!config('billing.portal.enabled')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Customer portal not enabled (BILLING_PORTAL_ENABLED=false)',
                ], 403);
            }

            $tenant = tenancy()->tenant;

            if (!$tenant) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tenant context',
                ], 400);
            }

            // Ensure tenant has Stripe customer ID
            if (!$tenant->stripe_customer_id) {
                // Create Stripe customer if not exists
                $gateway = $this->gatewayFactory->make();
                $tenant->stripe_customer_id = $gateway->ensureStripeCustomer($tenant);
                $tenant->save();

                \Log::info('Created Stripe customer for portal', [
                    'tenant_id' => $tenant->id,
                    'stripe_customer_id' => $tenant->stripe_customer_id,
                ]);
            }

            // Create Stripe portal session
            \Stripe\Stripe::setApiKey(config('services.stripe.secret'));

            $session = \Stripe\BillingPortal\Session::create([
                'customer' => $tenant->stripe_customer_id,
                'return_url' => config('billing.portal.return_url') ?: config('app.url') . '/billing',
            ]);

            \Log::info('Stripe portal session created', [
                'tenant_id' => $tenant->id,
                'session_url' => $session->url,
            ]);

            return response()->json([
                'success' => true,
                'url' => $session->url,
            ]);

        } catch (\Stripe\Exception\ApiErrorException $e) {
            \Log::error('Stripe portal session failed', [
                'error' => $e->getMessage(),
                'tenant_id' => tenancy()->tenant?->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create portal session: ' . $e->getMessage(),
            ], 500);

        } catch (\Exception $e) {
            \Log::error('Portal session creation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create portal session',
            ], 500);
        }
    }
}


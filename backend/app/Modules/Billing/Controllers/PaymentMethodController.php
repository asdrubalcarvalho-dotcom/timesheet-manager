<?php

namespace App\Modules\Billing\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Payments\PaymentGatewayFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PaymentMethodController
 * 
 * Manages credit/debit cards (payment methods) for tenant billing.
 * All endpoints require authentication and tenant context.
 */
class PaymentMethodController extends Controller
{
    protected PaymentGatewayFactory $gatewayFactory;

    public function __construct(PaymentGatewayFactory $gatewayFactory)
    {
        $this->gatewayFactory = $gatewayFactory;
    }

    /**
     * List all payment methods for current tenant.
     * 
     * GET /api/billing/payment-methods
     * 
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $tenant = tenancy()->tenant;
            
            if (!$tenant) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tenant context found',
                ], 400);
            }

            // Get gateway driver (Stripe or Fake)
            $gateway = $this->gatewayFactory->driver();
            
            $paymentMethods = $gateway->listPaymentMethods($tenant);

            return response()->json([
                'success' => true,
                'data' => $paymentMethods,
            ]);
        } catch (\Exception $e) {
            \Log::error('[PaymentMethodController] List failed', [
                'error' => $e->getMessage(),
                'tenant_id' => tenancy()->tenant?->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve payment methods',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Add (attach) a new payment method to tenant.
     * 
     * POST /api/billing/payment-methods/add
     * Body: { "payment_method_id": "pm_xxx" }
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function add(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'payment_method_id' => 'required|string|starts_with:pm_',
        ]);

        try {
            $tenant = tenancy()->tenant;
            
            if (!$tenant) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tenant context found',
                ], 400);
            }

            $gateway = $this->gatewayFactory->driver();
            
            $result = $gateway->storePaymentMethod($tenant, $validated['payment_method_id']);

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'message' => $result['message'],
                ], 201);
            }

            return response()->json([
                'success' => false,
                'message' => $result['message'],
            ], 400);
        } catch (\Exception $e) {
            \Log::error('[PaymentMethodController] Add failed', [
                'error' => $e->getMessage(),
                'tenant_id' => tenancy()->tenant?->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to add payment method',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Set a payment method as default.
     * 
     * POST /api/billing/payment-methods/default
     * Body: { "payment_method_id": "pm_xxx" }
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function setDefault(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'payment_method_id' => 'required|string|starts_with:pm_',
        ]);

        try {
            $tenant = tenancy()->tenant;
            
            if (!$tenant) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tenant context found',
                ], 400);
            }

            $gateway = $this->gatewayFactory->driver();
            
            $result = $gateway->setDefaultPaymentMethod($tenant, $validated['payment_method_id']);

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'message' => $result['message'],
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => $result['message'],
            ], 400);
        } catch (\Exception $e) {
            \Log::error('[PaymentMethodController] Set default failed', [
                'error' => $e->getMessage(),
                'tenant_id' => tenancy()->tenant?->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to set default payment method',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Remove (detach) a payment method from tenant.
     * 
     * DELETE /api/billing/payment-methods/{paymentMethodId}
     * 
     * @param string $paymentMethodId
     * @return JsonResponse
     */
    public function destroy(string $paymentMethodId): JsonResponse
    {
        try {
            $tenant = tenancy()->tenant;
            
            if (!$tenant) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tenant context found',
                ], 400);
            }

            $gateway = $this->gatewayFactory->driver();
            
            $result = $gateway->removePaymentMethod($tenant, $paymentMethodId);

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'message' => $result['message'],
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => $result['message'],
            ], 400);
        } catch (\Exception $e) {
            \Log::error('[PaymentMethodController] Remove failed', [
                'error' => $e->getMessage(),
                'tenant_id' => tenancy()->tenant?->id,
                'payment_method_id' => $paymentMethodId,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to remove payment method',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Create a SetupIntent for adding a new payment method.
     * Returns client_secret for Stripe Elements.
     * 
     * GET /api/billing/payment-methods/setup-intent
     * 
     * @return JsonResponse
     */
    public function setupIntent(Request $request): JsonResponse
    {
        try {
            $tenant = tenancy()->tenant;
            
            if (!$tenant) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tenant context found',
                ], 400);
            }

            // Get gateway driver (Stripe or Fake)
            $gateway = $this->gatewayFactory->driver();
            
            $setupIntent = $gateway->createSetupIntent($tenant);

            return response()->json([
                'success' => true,
                'client_secret' => $setupIntent['client_secret'],
                'setup_intent_id' => $setupIntent['setup_intent_id'] ?? null,
            ]);
        } catch (\Exception $e) {
            \Log::error('[PaymentMethodController] SetupIntent creation failed', [
                'error' => $e->getMessage(),
                'tenant_id' => tenancy()->tenant?->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create setup intent',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }
}

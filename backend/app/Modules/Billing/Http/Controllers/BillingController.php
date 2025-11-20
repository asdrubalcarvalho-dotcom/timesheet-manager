<?php

namespace Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Billing\Services\FeatureManager;
use Modules\Billing\Services\LicenseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * BillingController
 * 
 * Handles billing overview, payment methods, and invoices.
 */
class BillingController extends Controller
{
    public function __construct(
        protected FeatureManager $features,
        protected LicenseManager $licenses
    ) {
        $this->middleware('auth:sanctum');
    }

    /**
     * Get billing overview
     */
    public function index(): JsonResponse
    {
        $tenant = tenancy()->tenant;

        $data = [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'plan' => $tenant->plan,
                'status' => $tenant->status,
            ],
            'license' => $this->licenses->getSummary(),
            'modules' => $this->features->getAllModulesStatus(),
            'subscription' => $this->getSubscriptionInfo(),
        ];

        return response()->json($data);
    }

    /**
     * Get subscription information
     */
    public function subscription(): JsonResponse
    {
        return response()->json($this->getSubscriptionInfo());
    }

    /**
     * Get all invoices
     */
    public function invoices(): JsonResponse
    {
        $tenant = tenancy()->tenant;

        if (!$tenant->subscribed('default')) {
            return response()->json([
                'invoices' => [],
                'message' => 'No active subscription'
            ]);
        }

        $invoices = $tenant->invoices()->map(function ($invoice) {
            return [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'date' => $invoice->date()->toDateString(),
                'total' => $invoice->total(),
                'status' => $invoice->status,
                'download_url' => route('billing.invoice.download', $invoice->id),
            ];
        });

        return response()->json(['invoices' => $invoices]);
    }

    /**
     * Download a specific invoice
     */
    public function downloadInvoice(Request $request, string $invoiceId)
    {
        $tenant = tenancy()->tenant;

        $invoice = $tenant->findInvoice($invoiceId);

        if (!$invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        return $invoice->download($tenant->name . '-invoice-' . $invoiceId . '.pdf');
    }

    /**
     * Get payment method information
     */
    public function paymentMethod(): JsonResponse
    {
        $tenant = tenancy()->tenant;

        if (!$tenant->hasPaymentMethod()) {
            return response()->json([
                'has_payment_method' => false,
                'message' => 'No payment method on file'
            ]);
        }

        $paymentMethod = $tenant->defaultPaymentMethod();

        return response()->json([
            'has_payment_method' => true,
            'payment_method' => [
                'type' => $paymentMethod->type,
                'card' => [
                    'brand' => $paymentMethod->card->brand,
                    'last4' => $paymentMethod->card->last4,
                    'exp_month' => $paymentMethod->card->exp_month,
                    'exp_year' => $paymentMethod->card->exp_year,
                ],
            ],
        ]);
    }

    /**
     * Update payment method
     */
    public function updatePaymentMethod(Request $request): JsonResponse
    {
        $request->validate([
            'payment_method' => 'required|string',
        ]);

        $tenant = tenancy()->tenant;

        $tenant->updateDefaultPaymentMethod($request->payment_method);

        return response()->json([
            'message' => 'Payment method updated successfully'
        ]);
    }

    /**
     * Helper: Get subscription info
     */
    protected function getSubscriptionInfo(): array
    {
        $tenant = tenancy()->tenant;

        if (!$tenant->subscribed('default')) {
            return [
                'is_subscribed' => false,
                'is_trialing' => false,
            ];
        }

        $subscription = $tenant->subscription('default');

        return [
            'is_subscribed' => true,
            'is_trialing' => $subscription->onTrial(),
            'trial_ends_at' => $subscription->trial_ends_at?->toDateString(),
            'stripe_id' => $subscription->stripe_id,
            'stripe_status' => $subscription->stripe_status,
            'quantity' => $subscription->quantity,
            'ends_at' => $subscription->ends_at?->toDateString(),
            'on_grace_period' => $subscription->onGracePeriod(),
        ];
    }
}

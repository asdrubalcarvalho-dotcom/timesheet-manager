<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\Subscription;
use App\Services\Billing\TenantStripeResolver;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Stripe\Webhook;

class StripeWebhookController extends Controller
{
    protected $tenantStripeResolver;

    public function __construct(TenantStripeResolver $tenantStripeResolver)
    {
        $this->tenantStripeResolver = $tenantStripeResolver;
    }

    public function handleWebhook(Request $request)
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $secret = config('billing.stripe.webhook_secret');

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $secret);
        } catch (\Exception $e) {
            return response('Invalid signature', 400);
        }

        $paymentIntent = $event->data->object;

        try {
            $tenant = $this->tenantStripeResolver->resolveFromStripeObject($paymentIntent);
        } catch (\Exception $e) {
            return response('Tenant not found', 200);
        }

        return $tenant->run(function () use ($event, $paymentIntent) {
            switch ($event->type) {
                case 'payment_intent.succeeded':
                    return $this->handlePaymentIntentSucceeded($paymentIntent);
                case 'payment_intent.payment_failed':
                    return $this->handlePaymentIntentFailed($paymentIntent);
                default:
                    return response('Event type not handled', 200);
            }
        });
    }

    protected function handlePaymentIntentSucceeded($paymentIntent)
    {
        \Log::info('[StripeWebhook] Succeeded handler triggered', [
            'payment_intent_id' => $paymentIntent->id ?? null,
            'metadata' => $paymentIntent->metadata ?? null,
        ]);

        if (Payment::where('transaction_id', $paymentIntent->id)->exists()) {
            return response()->json(['message' => 'Idempotent skip'], 200);
        }

        $subscriptionId = $paymentIntent->metadata->subscription_id ?? null;
        $operation = $paymentIntent->metadata->operation ?? null;

        $subscription = Subscription::find($subscriptionId);

        if (!$subscription) {
            return response()->json(['message' => 'Subscription not found'], 200);
        }

        $subscription->status = 'active';
        $subscription->failed_renewal_attempts = 0;
        $subscription->grace_period_until = null;

        if ($operation === 'renewal') {
            $subscription->billing_period_ends_at = $subscription->billing_period_ends_at->addMonth();
            $subscription->last_renewal_at = now();
        }

        $subscription->save();

        Payment::create([
            'tenant_id' => $subscription->tenant_id,
            'subscription_id' => $subscription->id,
            'status' => 'completed',
            'gateway' => 'stripe',
            'transaction_id' => $paymentIntent->id,
            'amount' => $paymentIntent->amount_received / 100,
            'paid_at' => now(),
            'operation' => $operation,
            'payment_method' => $paymentIntent->payment_method_types[0] ?? null,
            'metadata' => json_encode($paymentIntent->metadata),
        ]);

        \Log::info('[StripeWebhook] Succeeded handler completed', [
            'subscription_id' => $subscription->id ?? null,
            'status' => $subscription->status ?? null,
            'billing_period_ends_at' => $subscription->billing_period_ends_at ?? null,
        ]);

        return response()->json(['message' => 'Payment processed'], 200);
    }

    protected function handlePaymentIntentFailed($paymentIntent)
    {
        \Log::warning('[StripeWebhook] Failed handler triggered', [
            'payment_intent_id' => $paymentIntent->id ?? null,
            'error_message' => $paymentIntent->last_payment_error->message ?? null,
            'metadata' => $paymentIntent->metadata ?? null,
        ]);

        $subscriptionId = $paymentIntent->metadata->subscription_id ?? null;
        $subscription = Subscription::find($subscriptionId);

        if (!$subscription) {
            return response()->json(['message' => 'Subscription not found'], 200);
        }

        $subscription->failed_renewal_attempts++;
        if ($subscription->failed_renewal_attempts === 1) {
            $subscription->grace_period_until = now()->addDays(15);
        }
        $subscription->status = 'past_due';
        $subscription->save();

        Payment::create([
            'tenant_id' => $subscription->tenant_id,
            'subscription_id' => $subscription->id,
            'status' => 'failed',
            'gateway' => 'stripe',
            'transaction_id' => $paymentIntent->id,
            'amount' => $paymentIntent->amount / 100,
            'notes' => $paymentIntent->last_payment_error->message ?? null,
            'operation' => $paymentIntent->metadata->operation ?? null,
            'payment_method' => $paymentIntent->payment_method_types[0] ?? null,
            'metadata' => json_encode($paymentIntent->metadata),
        ]);

        \Log::warning('[StripeWebhook] Failed handler completed', [
            'subscription_id' => $subscription->id ?? null,
            'failed_attempts' => $subscription->failed_renewal_attempts ?? null,
            'grace_period_until' => $subscription->grace_period_until ?? null,
        ]);

        return response()->json(['message' => 'Payment failure processed'], 200);
    }
}

<?php

namespace App\Services\Billing;

use App\Models\Tenant;
use Illuminate\Support\Facades\Log;

class TenantStripeResolver
{
    public function resolveFromStripeObject($stripeObject): Tenant
    {
        $tenantId = $stripeObject->metadata->tenant_id ?? null;

        if (!$tenantId) {
            throw new \Exception('tenant_id missing from metadata');
        }

        $tenant = Tenant::find($tenantId);

        if (!$tenant) {
            \Log::error('[StripeWebhook] Tenant resolution failed', [
                'metadata' => $stripeObject->metadata ?? null
            ]);
            throw new \Exception('Tenant not found');
        }

        \Log::info('[StripeWebhook] Tenant resolved', [
            'tenant_id' => $tenant->id ?? null,
            'slug' => $tenant->slug ?? null,
        ]);

        return $tenant;
    }
}

<?php

namespace App\Services\Payments;

use Stripe\PaymentIntent;
use Illuminate\Support\Facades\Log;

class StripeGateway
{
    public function createPaymentIntent($amount, $currency, $tenant, $subscription, $operation)
    {
        $paymentIntent = PaymentIntent::create([
            'amount' => $amount,
            'currency' => $currency,
            'metadata' => [
                'tenant_id' => $tenant->id,
                'subscription_id' => $subscription->id,
                'operation' => $operation,
            ],
        ]);

        \Log::info('[StripeGateway] PaymentIntent created', [
            'operation' => $operation ?? null,
            'subscription_id' => $subscription->id ?? null,
            'tenant_id' => $tenant->id ?? null,
            'amount' => $amount ?? null,
        ]);

        return $paymentIntent;
    }
}

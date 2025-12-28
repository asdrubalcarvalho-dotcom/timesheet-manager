<?php

namespace App\Http\Controllers;

use App\Services\Billing\TenantStripeResolver;
use App\Services\Payments\StripeGateway;
use Modules\Billing\Models\Subscription;
use Modules\Billing\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Webhook;
use Stripe\Exception\SignatureVerificationException;
use Exception;

/**
 * StripeWebhookController
 * 
 * Phase 10: Handle Stripe webhook events
 * 
 * IMPORTANT: This endpoint is NOT tenant-scoped (no tenant middleware)
 * Tenant resolution happens via metadata extraction
 * 
 * Required webhook events to configure in Stripe Dashboard:
 * - payment_intent.succeeded
 * - payment_intent.payment_failed
 * 
 * Webhook URL: https://api.localhost/stripe/webhook
 */
class StripeWebhookController extends Controller
{
    protected TenantStripeResolver $tenantResolver;
    protected StripeGateway $stripeGateway;
    
    public function __construct(
        TenantStripeResolver $tenantResolver,
        StripeGateway $stripeGateway
    ) {
        $this->tenantResolver = $tenantResolver;
        $this->stripeGateway = $stripeGateway;
    }

    
    /**
     * Handle incoming Stripe webhook
     * 
     * POST /stripe/webhook
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function handleWebhook(Request $request)
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $webhookSecret = config('billing.gateways.stripe.webhook_secret');
        
        if (!$webhookSecret) {
            Log::error('[StripeWebhook] Webhook secret not configured');
            return response()->json(['error' => 'Webhook secret not configured'], 500);
        }
        
        // Verify Stripe signature
        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
        } catch (SignatureVerificationException $e) {
            Log::error('[StripeWebhook] Signature verification failed', [
                'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => 'Invalid signature'], 400);
        } catch (Exception $e) {
            Log::error('[StripeWebhook] Webhook processing error', [
                'error' => $e->getMessage(),
            ]);
            return response()->json(['error' => 'Webhook error'], 400);
        }
        
        Log::info('[StripeWebhook] Event received', [
            'event_id' => $event->id,
            'event_type' => $event->type,
        ]);
        
        // Route to handler based on event type
        try {
            switch ($event->type) {
                case 'payment_intent.succeeded':
                    $this->handlePaymentIntentSucceeded($event);
                    break;
                    
                case 'payment_intent.payment_failed':
                    $this->handlePaymentIntentFailed($event);
                    break;
                    
                default:
                    Log::info('[StripeWebhook] Unhandled event type', [
                        'event_type' => $event->type,
                    ]);
            }
            
            return response()->json(['status' => 'success']);
            
        } catch (Exception $e) {
            // Never throw unhandled exceptions to Stripe
            Log::error('[StripeWebhook] Handler error', [
                'event_type' => $event->type,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            // Return 200 to prevent Stripe retries on application errors
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 200);
        }
    }


    /**
     * Handle payment_intent.succeeded event
     * 
     * Marks subscription active and creates successful Payment record
     * 
     * @param \Stripe\Event $event
     * @return void
     */
    protected function handlePaymentIntentSucceeded($event)
    {
        $paymentIntent = $event->data->object;
        
        // Resolve tenant from metadata
        $tenant = $this->tenantResolver->resolveFromStripeObject($paymentIntent);
        
        // Run operation in tenant context
        $tenant->run(function () use ($paymentIntent) {
            // Idempotency guard: skip if this PaymentIntent was already processed (transaction_id or stored intent refs)
            $existingPayment = Payment::where(function ($query) use ($paymentIntent) {
                $query->where('transaction_id', $paymentIntent->id)
                    ->orWhere('stripe_payment_intent_id', $paymentIntent->id)
                    ->orWhere('gateway_reference', $paymentIntent->id);
            })->first();
            
            if ($existingPayment) {
                Log::info('[StripeWebhook] Payment already processed (idempotent skip)', [
                    'payment_intent_id' => $paymentIntent->id,
                    'payment_id' => $existingPayment->id,
                ]);
                return;
            }
            
            // Extract subscription_id from metadata
            $subscriptionId = $paymentIntent->metadata->subscription_id ?? null;
            
            if (!$subscriptionId) {
                Log::warning('[StripeWebhook] No subscription_id in PaymentIntent metadata', [
                    'payment_intent_id' => $paymentIntent->id,
                ]);
                return;
            }
            
            $subscription = Subscription::find($subscriptionId);
            
            if (!$subscription) {
                Log::error('[StripeWebhook] Subscription not found', [
                    'subscription_id' => $subscriptionId,
                    'payment_intent_id' => $paymentIntent->id,
                ]);
                return;
            }
            
            // Determine operation type (renewal vs initial checkout)
            $operation = $paymentIntent->metadata->operation ?? 'checkout';
            
            // Update subscription status
            $subscription->status = 'active';
            $subscription->failed_renewal_attempts = 0;
            $subscription->grace_period_until = null;
            
            // If renewal: advance billing period
            if ($operation === 'renewal') {
                $currentPeriodEnd = $subscription->billing_period_ends_at;
                $subscription->billing_period_started_at = $currentPeriodEnd;
                $subscription->billing_period_ends_at = $currentPeriodEnd->addMonth();
                $subscription->last_renewal_at = now();
            }
            
            $subscription->save();
            
            // Create Payment record
            Payment::create([
                'subscription_id' => $subscription->id,
                'amount' => $paymentIntent->amount / 100, // Convert cents to dollars
                'currency' => strtoupper($paymentIntent->currency),
                'status' => 'completed',
                'payment_method' => 'stripe',
                'transaction_id' => $paymentIntent->id,
                'operation' => $operation === 'renewal' ? 'renewal' : 'initial',
                'paid_at' => now(),
            ]);
            
            Log::info('[StripeWebhook] Payment succeeded processed', [
                'subscription_id' => $subscription->id,
                'payment_intent_id' => $paymentIntent->id,
                'amount' => $paymentIntent->amount / 100,
                'operation' => $operation,
                'billing_period_ends_at' => $subscription->billing_period_ends_at,
            ]);
        });
    }


    /**
     * Handle payment_intent.payment_failed event
     * 
     * Marks subscription as past_due and creates failed Payment record
     * Does NOT cancel subscription (handled by dunning scheduler)
     * 
     * @param \Stripe\Event $event
     * @return void
     */
    protected function handlePaymentIntentFailed($event)
    {
        $paymentIntent = $event->data->object;
        
        // Resolve tenant from metadata
        $tenant = $this->tenantResolver->resolveFromStripeObject($paymentIntent);
        
        // Run operation in tenant context
        $tenant->run(function () use ($paymentIntent) {
            // Idempotency guard: skip if this PaymentIntent was already processed (transaction_id or stored intent refs)
            $existingPayment = Payment::where(function ($query) use ($paymentIntent) {
                $query->where('transaction_id', $paymentIntent->id)
                    ->orWhere('stripe_payment_intent_id', $paymentIntent->id)
                    ->orWhere('gateway_reference', $paymentIntent->id);
            })->first();

            if ($existingPayment) {
                Log::info('[StripeWebhook] Payment already processed (idempotent skip)', [
                    'payment_intent_id' => $paymentIntent->id,
                    'payment_id' => $existingPayment->id,
                ]);
                return;
            }

            // Extract subscription_id from metadata
            $subscriptionId = $paymentIntent->metadata->subscription_id ?? null;
            
            if (!$subscriptionId) {
                Log::warning('[StripeWebhook] No subscription_id in PaymentIntent metadata', [
                    'payment_intent_id' => $paymentIntent->id,
                ]);
                return;
            }
            
            $subscription = Subscription::find($subscriptionId);
            
            if (!$subscription) {
                Log::error('[StripeWebhook] Subscription not found', [
                    'subscription_id' => $subscriptionId,
                    'payment_intent_id' => $paymentIntent->id,
                ]);
                return;
            }
            
            // Increment failure counter
            $isFirstFailure = $subscription->failed_renewal_attempts === 0;
            $subscription->failed_renewal_attempts++;
            
            // Set grace period on first failure (15 days)
            if ($isFirstFailure) {
                $subscription->grace_period_until = now()->addDays(15);
            }
            
            // Mark as past_due
            $subscription->status = 'past_due';
            $subscription->save();
            
            // Create failed Payment record
            $failureReason = $paymentIntent->last_payment_error->message ?? 'Payment failed';
            
            Payment::create([
                'subscription_id' => $subscription->id,
                'amount' => $paymentIntent->amount / 100,
                'currency' => strtoupper($paymentIntent->currency),
                'status' => 'failed',
                'payment_method' => 'stripe',
                'transaction_id' => $paymentIntent->id,
                'operation' => $paymentIntent->metadata->operation ?? 'renewal',
                'notes' => $failureReason,
            ]);
            
            Log::warning('[StripeWebhook] Payment failed processed', [
                'subscription_id' => $subscription->id,
                'payment_intent_id' => $paymentIntent->id,
                'failed_attempts' => $subscription->failed_renewal_attempts,
                'grace_period_until' => $subscription->grace_period_until,
                'reason' => $failureReason,
            ]);
        });
    }
}

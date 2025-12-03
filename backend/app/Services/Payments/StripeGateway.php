<?php

namespace App\Services\Payments;

use App\Models\Tenant;
use App\Services\Billing\PlanManager;
use Illuminate\Support\Str;
use Modules\Billing\Models\Payment;
use Stripe\StripeClient;
use Stripe\Exception\ApiErrorException;

/**
 * StripeGateway
 *
 * Real payment gateway using Stripe API.
 * Supports both test and live modes via config/stripe.php configuration.
 *
 * Mode Selection:
 * - Set STRIPE_MODE=test in .env to use test keys (REMOVED*, REMOVED*)
 * - Set STRIPE_MODE=live in .env to use live keys (REMOVED*, REMOVED*)
 *
 * Features:
 * - Payment Intents for immediate charges
 * - Setup Intents for saving payment methods
 * - Payment method management (list, add, remove, set default)
 * - Webhook handling for async payment confirmations
 * - Subscription support (Phase 2) with feature flag control
 */
class StripeGateway implements PaymentGatewayInterface
{
    protected StripeClient $stripe;
    protected PlanManager $planManager;
    protected bool $isConfigured;
    protected string $mode;

    public function __construct(PlanManager $planManager)
    {
        $this->planManager = $planManager;
        $this->mode = config('stripe.mode', 'test');
        $this->isConfigured = $this->initializeStripe();
    }

    /**
     * Check if Stripe Subscriptions are enabled
     * 
     * Guards:
     * - Feature flag: BILLING_SUBSCRIPTIONS_ENABLED must be true
     * - Test mode constraint: If test_mode_only=true, STRIPE_MODE must be "test"
     * 
     * Usage:
     * - BillingController: After PaymentIntent confirmation, conditionally create subscription
     * - StripeSubscriptionManager: Enforced in all public methods
     * 
     * @return bool
     */
    public function subscriptionsEnabled(): bool
    {
        // Check feature flag
        if (!config('billing.subscriptions.enabled')) {
            return false;
        }

        // Check test mode constraint
        if (config('billing.subscriptions.test_mode_only') && config('stripe.mode') !== 'test') {
            \Log::debug('StripeGateway: Subscriptions disabled due to test_mode_only constraint', [
                'current_mode' => config('stripe.mode'),
            ]);
            return false;
        }

        return true;
    }

    /**
     * Check if Stripe Automatic Tax is enabled (Phase 3)
     * 
     * Guards:
     * - Feature flag: BILLING_TAX_ENABLED must be true
     * - Test mode constraint: If test_mode_only=true, STRIPE_MODE must be "test"
     * 
     * @return bool
     */
    public function taxEnabled(): bool
    {
        if (!config('billing.tax.enabled')) {
            return false;
        }

        if (config('billing.tax.test_mode_only') && config('stripe.mode') !== 'test') {
            return false;
        }

        return true;
    }

    /**
     * Check if Stripe Invoice generation is enabled (Phase 3)
     * 
     * Guards:
     * - Feature flag: BILLING_INVOICES_ENABLED must be true
     * - Test mode constraint: If test_mode_only=true, STRIPE_MODE must be "test"
     * 
     * @return bool
     */
    public function invoicesEnabled(): bool
    {
        if (!config('billing.invoices.enabled')) {
            return false;
        }

        if (config('billing.invoices.test_mode_only') && config('stripe.mode') !== 'test') {
            return false;
        }

        return true;
    }

    /**
     * Initialize Stripe client with API key from config.
     * Dynamically selects test or live keys based on STRIPE_MODE.
     */
    protected function initializeStripe(): bool
    {
        // Get secret key based on current mode
        $apiKey = config("stripe.{$this->mode}.sk");

        if (empty($apiKey)) {
            \Log::warning("[StripeGateway] Stripe secret key not configured for mode '{$this->mode}'. Gateway disabled.", [
                'mode' => $this->mode,
                'expected_env_key' => strtoupper("STRIPE_{$this->mode}_SECRET_KEY"),
            ]);
            return false;
        }

        try {
            $this->stripe = new StripeClient($apiKey);
            
            \Log::info("[StripeGateway] Initialized successfully in {$this->mode} mode", [
                'mode' => $this->mode,
                'key_prefix' => substr($apiKey, 0, 8) . '...',
            ]);
            
            return true;
        } catch (\Exception $e) {
            \Log::error('[StripeGateway] Failed to initialize Stripe client', [
                'mode' => $this->mode,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Create a payment intent for a tenant.
     *
     * This creates a Stripe PaymentIntent and persists a Payment row with status = 'pending'.
     */
    public function createPaymentIntent($tenant, float $amount, array $metadata = []): Payment
    {
        if (!$this->isConfigured) {
            throw new \RuntimeException('Stripe is not configured. Please set STRIPE_SECRET_KEY in .env');
        }

        if (!$tenant instanceof Tenant) {
            throw new \InvalidArgumentException('Tenant instance is required.');
        }

        // Create Stripe customer if not exists
        $stripeCustomerId = $this->ensureStripeCustomer($tenant);

        // Create payment in database first
        $payment = new Payment();
        $payment->tenant_id = $tenant->id;
        $payment->amount = $amount;
        $payment->currency = config('billing.currency.code', 'EUR');
        $payment->status = 'pending';
        $payment->gateway = $this->getName();
        $payment->metadata = $metadata;

        try {
            // Build PaymentIntent parameters
            $intentParams = [
                'amount' => (int) ($amount * 100), // Convert to cents
                'currency' => strtolower($payment->currency),
                'customer' => $stripeCustomerId,
                'metadata' => [
                    'tenant_id' => $tenant->id,
                    'subscription_id' => $metadata['subscription_id'] ?? null,
                    'operation' => $metadata['operation'] ?? 'checkout',
                    'payment_id' => 'pending', // Will update after save
                    'plan' => $metadata['plan'] ?? null,
                    'mode' => $metadata['mode'] ?? 'plan',
                    'user_limit' => $metadata['user_limit'] ?? null,
                ],
                'description' => $this->buildDescription($metadata),
                'automatic_payment_methods' => [
                    'enabled' => true,
                ],
                // 'automatic_tax' => ['enabled' => true], // Disabled: not supported in test mode
            ];

            // Create Stripe PaymentIntent
            $paymentIntent = $this->stripe->paymentIntents->create($intentParams);

            $payment->gateway_reference = $paymentIntent->id;
            $payment->metadata = array_merge($payment->metadata ?? [], [
                'stripe_payment_intent_id' => $paymentIntent->id,
                'stripe_client_secret' => $paymentIntent->client_secret,
            ]);
            $payment->save();

            // Update PaymentIntent with actual payment_id
            $this->stripe->paymentIntents->update($paymentIntent->id, [
                'metadata' => ['payment_id' => $payment->id],
            ]);

            \Log::info('[StripeGateway] PaymentIntent created', [
                'payment_id' => $payment->id,
                'stripe_payment_intent_id' => $paymentIntent->id,
                'amount' => $amount,
            ]);

            return $payment;

        } catch (ApiErrorException $e) {
            \Log::error('[StripeGateway] Failed to create PaymentIntent', [
                'error' => $e->getMessage(),
                'tenant_id' => $tenant->id,
            ]);
            throw new \RuntimeException('Failed to create Stripe payment: ' . $e->getMessage());
        }
    }

    /**
     * Confirm a payment (called after frontend confirms with Stripe.js or from webhook).
     * Applies plan upgrade if payment is successful.
     */
    public function confirmPayment(Payment $payment, array $cardData = []): Payment
    {
        if (!$this->isConfigured) {
            throw new \RuntimeException('Stripe is not configured.');
        }

        // If already processed, just return
        if ($payment->status !== 'pending') {
            return $payment;
        }

        try {
            $paymentIntentId = $payment->gateway_reference;
            $paymentIntent = $this->stripe->paymentIntents->retrieve($paymentIntentId);

            // Persist tax amount if available
            if (isset($paymentIntent->automatic_tax) && isset($paymentIntent->automatic_tax->amount)) {
                $payment->metadata = array_merge($payment->metadata ?? [], [
                    'stripe_tax_amount' => $paymentIntent->automatic_tax->amount,
                ]);
            }

            // Map Stripe status to our payment status
            $newStatus = match ($paymentIntent->status) {
                'succeeded' => 'completed',
                'processing' => 'processing',
                'requires_payment_method' => 'failed',
                'requires_confirmation' => 'pending',
                'requires_action' => 'requires_action',
                'canceled' => 'canceled',
                default => 'failed',
            };

            $payment->status = $newStatus;
            $payment->metadata = array_merge($payment->metadata ?? [], [
                'stripe_status' => $paymentIntent->status,
                'stripe_updated_at' => now()->toIso8601String(),
            ]);

            // If payment succeeded, apply the plan upgrade/addon
            if ($payment->status === 'completed') {
                $payment->completed_at = now();
                
                $metadata = $payment->metadata ?? [];
                $mode = $metadata['mode'] ?? null;
                $plan = $metadata['plan'] ?? null;
                $addon = $metadata['addon'] ?? null;

                // Get user_limit from metadata (will be sanitized by PlanManager)
                $userLimit = $metadata['user_limit'] ?? 1;

                // Apply plan upgrade (PlanManager handles sanitization)
                if ($mode === 'plan' && $plan) {
                    $tenant = $payment->tenant;
                    $this->planManager->updatePlan($tenant, $plan, $userLimit);
                    
                    \Log::info('[StripeGateway] Plan upgraded after payment confirmation', [
                        'payment_id' => $payment->id,
                        'tenant_id' => $tenant->id,
                        'plan' => $plan,
                        'requested_user_limit' => $userLimit,
                    ]);
                }

                // Apply addon toggle
                if ($mode === 'addon' && $addon) {
                    $tenant = $payment->tenant;
                    $this->planManager->toggleAddon($tenant, $addon);
                    
                    \Log::info('[StripeGateway] Addon toggled after payment confirmation', [
                        'payment_id' => $payment->id,
                        'tenant_id' => $tenant->id,
                        'addon' => $addon,
                    ]);
                }
            }

            $payment->save();

            \Log::info('[StripeGateway] Payment confirmed', [
                'payment_id' => $payment->id,
                'status' => $payment->status,
                'stripe_status' => $paymentIntent->status,
            ]);

            return $payment;

        } catch (ApiErrorException $e) {
            \Log::error('[StripeGateway] Failed to confirm payment', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);

            $payment->status = 'failed';
            $payment->metadata = array_merge($payment->metadata ?? [], [
                'error_message' => $e->getMessage(),
            ]);
            $payment->save();

            return $payment;
        }
    }

    /**
     * Create a SetupIntent for collecting payment method without immediate charge.
     */
    public function createSetupIntent(Tenant $tenant): array
    {
        if (!$this->isConfigured) {
            throw new \RuntimeException('Stripe is not configured.');
        }

        $stripeCustomerId = $this->ensureStripeCustomer($tenant);

        try {
            $setupIntent = $this->stripe->setupIntents->create([
                'customer' => $stripeCustomerId,
                'metadata' => [
                    'tenant_id' => $tenant->id,
                ],
            ]);

            \Log::info('[StripeGateway] SetupIntent created', [
                'tenant_id' => $tenant->id,
                'setup_intent_id' => $setupIntent->id,
            ]);

            return [
                'client_secret' => $setupIntent->client_secret,
                'setup_intent_id' => $setupIntent->id,
            ];

        } catch (ApiErrorException $e) {
            \Log::error('[StripeGateway] Failed to create SetupIntent', [
                'error' => $e->getMessage(),
                'tenant_id' => $tenant->id,
            ]);
            throw new \RuntimeException('Failed to create Stripe setup intent: ' . $e->getMessage());
        }
    }

    /**
     * List all payment methods for a tenant.
     */
    public function listPaymentMethods(Tenant $tenant): array
    {
        if (!$this->isConfigured) {
            return [];
        }

        $stripeCustomerId = $tenant->stripe_customer_id;
        if (!$stripeCustomerId) {
            return [];
        }

        try {
            $paymentMethods = $this->stripe->paymentMethods->all([
                'customer' => $stripeCustomerId,
                'type' => 'card',
            ]);

            // Get customer to check default payment method
            $customer = $this->stripe->customers->retrieve($stripeCustomerId);
            $defaultPaymentMethodId = $customer->invoice_settings->default_payment_method;

            return array_map(function ($pm) use ($defaultPaymentMethodId) {
                return [
                    'id' => $pm->id,
                    'type' => $pm->type,
                    'card' => [
                        'brand' => $pm->card->brand,
                        'last4' => $pm->card->last4,
                        'exp_month' => $pm->card->exp_month,
                        'exp_year' => $pm->card->exp_year,
                    ],
                    'is_default' => $pm->id === $defaultPaymentMethodId,
                ];
            }, $paymentMethods->data);

        } catch (ApiErrorException $e) {
            \Log::error('[StripeGateway] Failed to list payment methods', [
                'error' => $e->getMessage(),
                'tenant_id' => $tenant->id,
            ]);
            return [];
        }
    }

    /**
     * Store (attach) a payment method to tenant.
     */
    public function storePaymentMethod(Tenant $tenant, string $paymentMethodId): array
    {
        if (!$this->isConfigured) {
            throw new \RuntimeException('Stripe is not configured.');
        }

        $stripeCustomerId = $this->ensureStripeCustomer($tenant);

        try {
            // Attach payment method to customer
            $paymentMethod = $this->stripe->paymentMethods->attach(
                $paymentMethodId,
                ['customer' => $stripeCustomerId]
            );

            \Log::info('[StripeGateway] Payment method attached', [
                'tenant_id' => $tenant->id,
                'payment_method_id' => $paymentMethodId,
            ]);

            return [
                'success' => true,
                'message' => 'Payment method added successfully',
                'payment_method' => [
                    'id' => $paymentMethod->id,
                    'type' => $paymentMethod->type,
                    'card' => [
                        'brand' => $paymentMethod->card->brand,
                        'last4' => $paymentMethod->card->last4,
                        'exp_month' => $paymentMethod->card->exp_month,
                        'exp_year' => $paymentMethod->card->exp_year,
                    ],
                    'is_default' => false,
                ],
            ];

        } catch (ApiErrorException $e) {
            \Log::error('[StripeGateway] Failed to attach payment method', [
                'error' => $e->getMessage(),
                'tenant_id' => $tenant->id,
            ]);
            throw new \RuntimeException('Failed to add payment method: ' . $e->getMessage());
        }
    }

    /**
     * Set default payment method for tenant.
     */
    public function setDefaultPaymentMethod(Tenant $tenant, string $paymentMethodId): array
    {
        if (!$this->isConfigured) {
            throw new \RuntimeException('Stripe is not configured.');
        }

        $stripeCustomerId = $tenant->stripe_customer_id;
        if (!$stripeCustomerId) {
            throw new \RuntimeException('Tenant has no Stripe customer ID.');
        }

        try {
            $this->stripe->customers->update($stripeCustomerId, [
                'invoice_settings' => [
                    'default_payment_method' => $paymentMethodId,
                ],
            ]);

            \Log::info('[StripeGateway] Default payment method updated', [
                'tenant_id' => $tenant->id,
                'payment_method_id' => $paymentMethodId,
            ]);

            return [
                'success' => true,
                'message' => 'Default payment method updated successfully',
            ];

        } catch (ApiErrorException $e) {
            \Log::error('[StripeGateway] Failed to set default payment method', [
                'error' => $e->getMessage(),
                'tenant_id' => $tenant->id,
            ]);
            throw new \RuntimeException('Failed to update default payment method: ' . $e->getMessage());
        }
    }

    /**
     * Remove (detach) a payment method from tenant.
     */
    public function removePaymentMethod(Tenant $tenant, string $paymentMethodId): array
    {
        if (!$this->isConfigured) {
            throw new \RuntimeException('Stripe is not configured.');
        }

        try {
            $this->stripe->paymentMethods->detach($paymentMethodId);

            \Log::info('[StripeGateway] Payment method detached', [
                'tenant_id' => $tenant->id,
                'payment_method_id' => $paymentMethodId,
            ]);

            return [
                'success' => true,
                'message' => 'Payment method removed successfully',
            ];

        } catch (ApiErrorException $e) {
            \Log::error('[StripeGateway] Failed to detach payment method', [
                'error' => $e->getMessage(),
                'tenant_id' => $tenant->id,
            ]);
            throw new \RuntimeException('Failed to remove payment method: ' . $e->getMessage());
        }
    }

    /**
     * Ensure tenant has a Stripe customer ID.
     */
    protected function ensureStripeCustomer(Tenant $tenant): string
    {
        if ($tenant->stripe_customer_id) {
            return $tenant->stripe_customer_id;
        }

        try {
            $company = $tenant->company;
            $customerData = [
                'email' => $company->owner_email ?? null,
                'name' => $tenant->name,
                'metadata' => [
                    'tenant_id' => $tenant->id,
                    'tenant_slug' => $tenant->slug,
                ],
            ];

            // Add billing address if available
            if ($tenant->billing_country || $tenant->billing_address || $tenant->billing_postal_code) {
                $customerData['address'] = [
                    'country' => $tenant->billing_country,
                    'line1' => $tenant->billing_address,
                    'postal_code' => $tenant->billing_postal_code,
                ];
            }

            // Add VAT number if available
            if ($tenant->billing_vat_number) {
                $customerData['tax'] = [
                    'tax_id' => $tenant->billing_vat_number,
                ];
                $customerData['tax_exempt'] = 'reverse';
            }

            $customer = $this->stripe->customers->create($customerData);

            $tenant->stripe_customer_id = $customer->id;
            $tenant->save();

            \Log::info('[StripeGateway] Stripe customer created', [
                'tenant_id' => $tenant->id,
                'stripe_customer_id' => $customer->id,
            ]);

            return $customer->id;

        } catch (ApiErrorException $e) {
            \Log::error('[StripeGateway] Failed to create Stripe customer', [
                'error' => $e->getMessage(),
                'tenant_id' => $tenant->id,
            ]);
            throw new \RuntimeException('Failed to create Stripe customer: ' . $e->getMessage());
        }
    }

    /**
     * Build payment description from metadata.
     */
    protected function buildDescription(array $metadata): string
    {
        $plan = $metadata['plan'] ?? null;
        $addons = $metadata['addons'] ?? [];

        $parts = [];
        if ($plan) {
            $parts[] = "Plan: {$plan}";
        }
        
        // Decode $addons if it's a JSON string
        if (is_string($addons)) {
            $addons = json_decode($addons, true) ?? [];
        }
        
        if (!empty($addons) && is_array($addons)) {
            $parts[] = "Addons: " . implode(', ', $addons);
        }

        return !empty($parts) ? implode(' | ', $parts) : 'Payment';
    }

    /**
     * Get gateway identifier.
     */
    public function getName(): string
    {
        return 'stripe';
    }

    /**
     * Get Stripe Price ID for a plan from config.
     * 
     * This is a preparation method for future Stripe Subscription integration.
     * Currently NOT used in the PaymentIntent flow.
     * 
     * @param string $plan Plan name ('team' or 'enterprise')
     * @return string|null Stripe Price ID or null if not configured
     */
    protected function getStripePriceIdForPlan(string $plan): ?string
    {
        return config("billing.plans.{$plan}.stripe_price_id");
    }

    /**
     * Get Stripe Price ID for an addon from config.
     * 
     * This is a preparation method for future Stripe Subscription integration.
     * Currently NOT used in the PaymentIntent flow.
     * 
     * @param string $addon Addon name ('planning' or 'ai')
     * @return string|null Stripe Price ID or null if not configured
     */
    protected function getStripePriceIdForAddon(string $addon): ?string
    {
        return config("billing.addons.{$addon}.stripe_price_id");
    }
}


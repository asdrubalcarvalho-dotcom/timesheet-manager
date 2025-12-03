<?php

namespace App\Services\Payments;

use App\Models\Tenant;
use App\Models\BillingProfile;
use App\Services\Billing\PlanManager;
use Modules\Billing\Models\Payment;
use Stripe\Stripe as StripeClient;
use Stripe\Customer;
use Stripe\PaymentIntent;
use Stripe\PaymentMethod;
use Illuminate\Support\Str;

/**
 * StripeCardGateway
 *
 * Production-ready Stripe integration for credit/debit card payments.
 * Implements payment gateway interface + extended payment method management.
 *
 * Features:
 * - Customer creation per tenant
 * - Payment method storage & management
 * - PaymentIntent-based charges
 * - Default payment method handling
 */
class StripeCardGateway implements PaymentGatewayInterface
{
    protected PlanManager $planManager;

    public function __construct(PlanManager $planManager)
    {
        $this->planManager = $planManager;
        
        // Initialize Stripe API key
        StripeClient::setApiKey(config('payments.stripe.secret_key'));
    }

    /**
     * Initialize a payment (create PaymentIntent).
     * Returns transaction ID that can be used with processPayment().
     *
     * @param float $amount
     * @param string $currency
     * @param array $metadata
     * @return array ['transaction_id' => string, 'client_secret' => string]
     */
    public function initializePayment(float $amount, string $currency = 'eur', array $metadata = []): array
    {
        try {
            $paymentIntent = PaymentIntent::create([
                'amount' => (int)($amount * 100), // Convert to cents
                'currency' => strtolower($currency),
                'metadata' => $metadata,
                'automatic_payment_methods' => [
                    'enabled' => true,
                ],
            ]);

            return [
                'transaction_id' => $paymentIntent->id,
                'client_secret' => $paymentIntent->client_secret,
            ];
        } catch (\Exception $e) {
            \Log::error('[StripeCardGateway] Initialize payment failed', [
                'amount' => $amount,
                'currency' => $currency,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Failed to initialize payment: ' . $e->getMessage());
        }
    }

    /**
     * Process (confirm) a payment using PaymentIntent ID.
     *
     * @param string $transactionId PaymentIntent ID
     * @param array $options Additional options (payment_method, etc.)
     * @return array ['success' => bool, 'message' => string, 'charge_id' => string|null]
     */
    public function processPayment(string $transactionId, array $options = []): array
    {
        try {
            $paymentIntent = PaymentIntent::retrieve($transactionId);

            // If payment method provided, attach it
            if (!empty($options['payment_method'])) {
                $paymentIntent->payment_method = $options['payment_method'];
            }

            // Confirm the payment
            $paymentIntent->confirm();

            // Check status
            if ($paymentIntent->status === 'succeeded') {
                return [
                    'success' => true,
                    'message' => 'Payment successful',
                    'charge_id' => $paymentIntent->latest_charge ?? $paymentIntent->id,
                ];
            }

            return [
                'success' => false,
                'message' => 'Payment not completed: ' . $paymentIntent->status,
                'charge_id' => null,
            ];
        } catch (\Exception $e) {
            \Log::error('[StripeCardGateway] Process payment failed', [
                'transaction_id' => $transactionId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
                'charge_id' => null,
            ];
        }
    }

    /**
     * Store (attach) a payment method to tenant's Stripe customer.
     *
     * @param Tenant $tenant
     * @param string $paymentMethodId Stripe PaymentMethod ID (pm_xxx)
     * @return array ['success' => bool, 'message' => string]
     */
    public function storePaymentMethod(Tenant $tenant, string $paymentMethodId): array
    {
        try {
            $billingProfile = $this->ensureBillingProfile($tenant);
            $customerId = $billingProfile->stripe_customer_id;

            if (!$customerId) {
                throw new \RuntimeException('No Stripe customer ID found for tenant');
            }

            // Attach payment method to customer
            $paymentMethod = PaymentMethod::retrieve($paymentMethodId);
            $paymentMethod->attach(['customer' => $customerId]);

            // If first payment method, set as default
            if (!$billingProfile->default_payment_method) {
                $this->setDefaultPaymentMethod($tenant, $paymentMethodId);
            }

            \Log::info('[StripeCardGateway] Payment method stored', [
                'tenant_id' => $tenant->id,
                'payment_method_id' => $paymentMethodId,
                'customer_id' => $customerId,
            ]);

            return [
                'success' => true,
                'message' => 'Payment method added successfully',
            ];
        } catch (\Exception $e) {
            \Log::error('[StripeCardGateway] Store payment method failed', [
                'tenant_id' => $tenant->id,
                'payment_method_id' => $paymentMethodId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * List all payment methods for tenant.
     *
     * @param Tenant $tenant
     * @return array
     */
    public function listPaymentMethods(Tenant $tenant): array
    {
        try {
            $billingProfile = $this->ensureBillingProfile($tenant);
            $customerId = $billingProfile->stripe_customer_id;

            if (!$customerId) {
                return [];
            }

            $paymentMethods = PaymentMethod::all([
                'customer' => $customerId,
                'type' => 'card',
            ]);

            return array_map(function ($pm) use ($billingProfile) {
                return [
                    'id' => $pm->id,
                    'payment_method_id' => $pm->id,
                    'brand' => $pm->card->brand ?? 'unknown',
                    'last4' => $pm->card->last4 ?? '****',
                    'exp_month' => $pm->card->exp_month ?? null,
                    'exp_year' => $pm->card->exp_year ?? null,
                    'is_default' => $pm->id === $billingProfile->default_payment_method,
                ];
            }, $paymentMethods->data);
        } catch (\Exception $e) {
            \Log::error('[StripeCardGateway] List payment methods failed', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Set default payment method for tenant.
     *
     * @param Tenant $tenant
     * @param string $paymentMethodId
     * @return array ['success' => bool, 'message' => string]
     */
    public function setDefaultPaymentMethod(Tenant $tenant, string $paymentMethodId): array
    {
        try {
            $billingProfile = $this->ensureBillingProfile($tenant);
            $customerId = $billingProfile->stripe_customer_id;

            if (!$customerId) {
                throw new \RuntimeException('No Stripe customer found');
            }

            // Update customer's default payment method
            Customer::update($customerId, [
                'invoice_settings' => [
                    'default_payment_method' => $paymentMethodId,
                ],
            ]);

            // Update billing profile
            $billingProfile->update(['default_payment_method' => $paymentMethodId]);

            \Log::info('[StripeCardGateway] Default payment method set', [
                'tenant_id' => $tenant->id,
                'payment_method_id' => $paymentMethodId,
            ]);

            return [
                'success' => true,
                'message' => 'Default payment method updated',
            ];
        } catch (\Exception $e) {
            \Log::error('[StripeCardGateway] Set default payment method failed', [
                'tenant_id' => $tenant->id,
                'payment_method_id' => $paymentMethodId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Remove (detach) a payment method from tenant.
     *
     * @param Tenant $tenant
     * @param string $paymentMethodId
     * @return array ['success' => bool, 'message' => string]
     */
    public function removePaymentMethod(Tenant $tenant, string $paymentMethodId): array
    {
        try {
            $billingProfile = $this->ensureBillingProfile($tenant);

            // Detach from Stripe
            $paymentMethod = PaymentMethod::retrieve($paymentMethodId);
            $paymentMethod->detach();

            // If was default, clear it
            if ($billingProfile->default_payment_method === $paymentMethodId) {
                $billingProfile->update(['default_payment_method' => null]);
            }

            \Log::info('[StripeCardGateway] Payment method removed', [
                'tenant_id' => $tenant->id,
                'payment_method_id' => $paymentMethodId,
            ]);

            return [
                'success' => true,
                'message' => 'Payment method removed',
            ];
        } catch (\Exception $e) {
            \Log::error('[StripeCardGateway] Remove payment method failed', [
                'tenant_id' => $tenant->id,
                'payment_method_id' => $paymentMethodId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Charge tenant using their default payment method.
     *
     * @param Tenant $tenant
     * @param float $amount
     * @param string $currency
     * @param array $metadata
     * @return array ['success' => bool, 'message' => string, 'charge_id' => string|null]
     */
    public function charge(Tenant $tenant, float $amount, string $currency = 'eur', array $metadata = []): array
    {
        try {
            $billingProfile = $this->ensureBillingProfile($tenant);
            $customerId = $billingProfile->stripe_customer_id;
            $paymentMethodId = $billingProfile->default_payment_method;

            if (!$customerId || !$paymentMethodId) {
                throw new \RuntimeException('No default payment method configured');
            }

            // Create and confirm PaymentIntent
            $paymentIntent = PaymentIntent::create([
                'amount' => (int)($amount * 100), // Convert to cents
                'currency' => strtolower($currency),
                'customer' => $customerId,
                'payment_method' => $paymentMethodId,
                'off_session' => true, // Charge without customer present
                'confirm' => true, // Auto-confirm
                'metadata' => array_merge($metadata, [
                    'tenant_id' => $tenant->id,
                ]),
            ]);

            if ($paymentIntent->status === 'succeeded') {
                \Log::info('[StripeCardGateway] Charge successful', [
                    'tenant_id' => $tenant->id,
                    'amount' => $amount,
                    'charge_id' => $paymentIntent->latest_charge,
                ]);

                return [
                    'success' => true,
                    'message' => 'Payment successful',
                    'charge_id' => $paymentIntent->latest_charge ?? $paymentIntent->id,
                ];
            }

            return [
                'success' => false,
                'message' => 'Payment failed: ' . $paymentIntent->status,
                'charge_id' => null,
            ];
        } catch (\Exception $e) {
            \Log::error('[StripeCardGateway] Charge failed', [
                'tenant_id' => $tenant->id,
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
                'charge_id' => null,
            ];
        }
    }

    /**
     * Create a payment intent (implements interface).
     *
     * @param Tenant $tenant
     * @param float $amount
     * @param array $metadata
     * @return Payment
     */
    public function createPaymentIntent($tenant, float $amount, array $metadata = []): Payment
    {
        if (!$tenant instanceof Tenant) {
            throw new \InvalidArgumentException('Tenant instance is required.');
        }

        $currency = config('billing.currency.code', 'EUR');
        $result = $this->initializePayment($amount, $currency, $metadata);

        $payment = new Payment();
        $payment->tenant_id = $tenant->id;
        $payment->amount = $amount;
        $payment->currency = $currency;
        $payment->status = 'pending';
        $payment->gateway = $this->getName();
        $payment->gateway_reference = $result['transaction_id'];
        $payment->metadata = array_merge($metadata, [
            'client_secret' => $result['client_secret'],
        ]);
        $payment->save();

        return $payment;
    }

    /**
     * Confirm a payment (implements interface).
     *
     * @param Payment $payment
     * @param array $cardData
     * @return Payment
     */
    public function confirmPayment(Payment $payment, array $cardData): Payment
    {
        if ($payment->status !== 'pending') {
            return $payment;
        }

        $transactionId = $payment->gateway_reference;
        $result = $this->processPayment($transactionId, $cardData);

        $payment->status = $result['success'] ? 'paid' : 'failed';
        $payment->metadata = array_merge($payment->metadata ?? [], [
            'gateway_message' => $result['message'],
            'charge_id' => $result['charge_id'] ?? null,
        ]);
        $payment->save();

        // If payment succeeded, apply plan + addons if provided
        if ($result['success']) {
            $metadata = $payment->metadata ?? [];
            $plan = $metadata['plan'] ?? null;
            $addons = $metadata['addons'] ?? [];

            if ($plan) {
                $this->planManager->applyPlan($payment->tenant, $plan, $addons);
            }
        }

        return $payment;
    }

    /**
     * Create a SetupIntent for collecting payment method without immediate charge.
     * Used for adding cards to customer profile.
     *
     * @param Tenant $tenant
     * @return array Contains client_secret for Stripe Elements
     * @throws \Exception
     */
    public function createSetupIntent(Tenant $tenant): array
    {
        try {
            $billingProfile = $this->ensureBillingProfile($tenant);

            if (!$billingProfile || !$billingProfile->stripe_customer_id) {
                throw new \Exception('No Stripe customer found for tenant');
            }

            // Create SetupIntent for future payments
            $setupIntent = $this->stripe->setupIntents->create([
                'customer' => $billingProfile->stripe_customer_id,
                'payment_method_types' => ['card'],
                'usage' => 'off_session', // Allow charging without customer presence
                'metadata' => [
                    'tenant_id' => $tenant->id,
                    'tenant_name' => $tenant->name ?? 'Unknown',
                ],
            ]);

            \Log::info('[StripeCardGateway] SetupIntent created', [
                'setup_intent_id' => $setupIntent->id,
                'customer_id' => $billingProfile->stripe_customer_id,
                'tenant_id' => $tenant->id,
            ]);

            return [
                'client_secret' => $setupIntent->client_secret,
                'setup_intent_id' => $setupIntent->id,
            ];
        } catch (\Stripe\Exception\ApiErrorException $e) {
            \Log::error('[StripeCardGateway] SetupIntent creation failed', [
                'error' => $e->getMessage(),
                'tenant_id' => $tenant->id,
            ]);

            throw new \Exception('Failed to initialize payment form: ' . $e->getMessage());
        }
    }

    /**
     * Get gateway identifier.
     *
     * @return string
     */
    public function getName(): string
    {
        return 'stripe';
    }

    /**
     * Ensure billing profile exists for tenant.
     *
     * @param Tenant $tenant
     * @return BillingProfile
     */
    protected function ensureBillingProfile(Tenant $tenant): BillingProfile
    {
        return $tenant->billingProfile ?? BillingProfile::where('tenant_id', $tenant->id)->first();
    }
}

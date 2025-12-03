<?php

namespace App\Services\Payments;

use App\Models\Tenant;
use App\Services\Billing\PlanManager;
use Illuminate\Support\Str;
use Modules\Billing\Models\Payment;

/**
 * FakeCreditCardGateway
 *
 * Simulated payment gateway for testing and development.
 * Uses test card numbers to determine the outcome and integrates
 * with the billing system via PlanManager.
 *
 * Test Cards:
 * - 4111111111111111: Success
 * - 4000000000000002: Declined
 * - 4000000000000069: Expired card
 * - 4000000000000119: Processing error
 */
class FakeCreditCardGateway implements PaymentGatewayInterface
{
    protected PlanManager $planManager;

    public function __construct(PlanManager $planManager)
    {
        $this->planManager = $planManager;
    }

    /**
     * Create a payment intent for a tenant.
     *
     * This will persist a Payment row in the database with status = 'pending'.
     */
    public function createPaymentIntent($tenant, float $amount, array $metadata = []): Payment
    {
        if (!$tenant instanceof Tenant) {
            throw new \InvalidArgumentException('Tenant instance is required.');
        }

        $payment = new Payment();
        $payment->tenant_id         = $tenant->id;
        $payment->amount            = $amount;
        $payment->currency          = config('billing.currency.code', 'EUR');
        $payment->status            = 'pending';
        $payment->gateway           = $this->getName();
        $payment->gateway_reference = 'fake_' . Str::random(16);
        $payment->metadata          = $metadata;
        $payment->save();

        return $payment;
    }

    /**
     * Confirm a payment (using fake card numbers).
     *
     * On success, applies the requested plan/addons via PlanManager.
     */
    public function confirmPayment(Payment $payment, array $cardData = []): Payment
    {
        // If already processed, just return
        if ($payment->status !== 'pending') {
            return $payment;
        }

        $cardNumber = $cardData['card_number'] ?? '4111111111111111';
        $result     = $this->simulateCardProcessing($cardNumber);

        $payment->status   = $result['success'] ? 'completed' : 'failed';
        $payment->metadata = array_merge($payment->metadata ?? [], [
            'gateway_message' => $result['message'],
            'card_last4'      => substr(preg_replace('/\D/', '', $cardNumber), -4),
        ]);
        
        if ($result['success']) {
            $payment->completed_at = now();
        }
        
        $payment->save();

        // NOTE: Subscription updates are now handled by PaymentSnapshot::applySnapshot()
        // in BillingController::checkoutConfirm() - no need to duplicate logic here

        return $payment;
    }

    /**
     * Simulate card processing with test card numbers.
     */
    protected function simulateCardProcessing(string $cardNumber): array
    {
        // Remove spaces and non-digits
        $cardNumber = preg_replace('/\D/', '', $cardNumber);

        return match ($cardNumber) {
            '4111111111111111' => [
                'success' => true,
                'message' => 'Payment successful.',
            ],
            '4000000000000002' => [
                'success' => false,
                'message' => 'Card declined.',
            ],
            '4000000000000069' => [
                'success' => false,
                'message' => 'Card expired.',
            ],
            '4000000000000119' => [
                'success' => false,
                'message' => 'Processing error.',
            ],
            default => [
                'success' => true,
                'message' => 'Payment successful (auto-approved).',
            ],
        };
    }

    /**
     * Create a SetupIntent for collecting payment method without immediate charge.
     * Returns fake client_secret for testing.
     *
     * @param Tenant $tenant
     * @return array Contains client_secret
     */
    public function createSetupIntent(Tenant $tenant): array
    {
        \Log::info('[FakeCreditCardGateway] SetupIntent created (fake)', [
            'tenant_id' => $tenant->id,
        ]);

        // Generate a properly formatted fake client_secret that Stripe will accept
        // Format: seti_{id}_secret_{secret}
        $setupIntentId = 'seti_' . Str::random(24);
        $secret = Str::random(24);

        return [
            'client_secret' => $setupIntentId . '_secret_' . $secret,
            'setup_intent_id' => $setupIntentId,
        ];
    }

    /**
     * List all payment methods for a tenant.
     * Returns fake payment methods for testing.
     *
     * @param Tenant $tenant
     * @return array
     */
    public function listPaymentMethods(Tenant $tenant): array
    {
        \Log::info('[FakeCreditCardGateway] Listing payment methods (fake)', [
            'tenant_id' => $tenant->id,
        ]);

        // Return fake payment methods for testing
        return [
            [
                'id' => 'pm_fake_' . Str::random(16),
                'type' => 'card',
                'card' => [
                    'brand' => 'visa',
                    'last4' => '4242',
                    'exp_month' => 12,
                    'exp_year' => 2034,
                ],
                'is_default' => true,
            ],
        ];
    }

    /**
     * Store (attach) a payment method to tenant.
     * Returns fake payment method for testing.
     *
     * @param Tenant $tenant
     * @param string $paymentMethodId
     * @return array
     */
    public function storePaymentMethod(Tenant $tenant, string $paymentMethodId): array
    {
        \Log::info('[FakeCreditCardGateway] Storing payment method (fake)', [
            'tenant_id' => $tenant->id,
            'payment_method_id' => $paymentMethodId,
        ]);

        return [
            'success' => true,
            'message' => 'Payment method added successfully (fake)',
            'payment_method' => [
                'id' => $paymentMethodId,
                'type' => 'card',
                'card' => [
                    'brand' => 'visa',
                    'last4' => '4242',
                    'exp_month' => 12,
                    'exp_year' => 2034,
                ],
                'is_default' => false,
            ],
        ];
    }

    /**
     * Set default payment method for tenant.
     * Returns success for testing.
     *
     * @param Tenant $tenant
     * @param string $paymentMethodId
     * @return array
     */
    public function setDefaultPaymentMethod(Tenant $tenant, string $paymentMethodId): array
    {
        \Log::info('[FakeCreditCardGateway] Setting default payment method (fake)', [
            'tenant_id' => $tenant->id,
            'payment_method_id' => $paymentMethodId,
        ]);

        return [
            'success' => true,
            'message' => 'Default payment method updated (fake)',
        ];
    }

    /**
     * Remove (detach) a payment method from tenant.
     * Returns success for testing.
     *
     * @param Tenant $tenant
     * @param string $paymentMethodId
     * @return array
     */
    public function removePaymentMethod(Tenant $tenant, string $paymentMethodId): array
    {
        \Log::info('[FakeCreditCardGateway] Removing payment method (fake)', [
            'tenant_id' => $tenant->id,
            'payment_method_id' => $paymentMethodId,
        ]);

        return [
            'success' => true,
            'message' => 'Payment method removed successfully (fake)',
        ];
    }

    /**
     * Get gateway identifier.
     */
    public function getName(): string
    {
        return 'fake_card';
    }
}

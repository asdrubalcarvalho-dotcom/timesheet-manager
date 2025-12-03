<?php

namespace App\Services\Payments;

use App\Services\Billing\PlanManager;
use InvalidArgumentException;

/**
 * PaymentGatewayFactory
 * 
 * Factory to instantiate the correct payment gateway based on config.
 * 
 * Usage:
 *   $gateway = app(PaymentGatewayFactory::class)->driver();
 *   $gateway = app(PaymentGatewayFactory::class)->driver('stripe');
 */
class PaymentGatewayFactory
{
    protected PlanManager $planManager;

    public function __construct(PlanManager $planManager)
    {
        $this->planManager = $planManager;
    }

    /**
     * Get payment gateway instance by driver name.
     * 
     * @param string|null $driver Driver name ('fake', 'stripe') or null for default
     * @return PaymentGatewayInterface
     * @throws InvalidArgumentException
     */
    public function driver(?string $driver = null): PaymentGatewayInterface
    {
        // Use new BILLING_GATEWAY config, fallback to old PAYMENTS_DRIVER
        $driver = $driver ?? config('billing.gateway', config('payments.driver', 'fake'));

        return match ($driver) {
            'fake', 'fake_card' => $this->createFakeGateway(),
            'stripe' => $this->createStripeGateway(),
            default => throw new InvalidArgumentException("Unsupported payment driver: {$driver}"),
        };
    }

    /**
     * Create fake gateway instance.
     */
    protected function createFakeGateway(): FakeCreditCardGateway
    {
        return new FakeCreditCardGateway($this->planManager);
    }

    /**
     * Create Stripe gateway instance.
     */
    protected function createStripeGateway(): StripeGateway
    {
        return new StripeGateway($this->planManager);
    }

    /**
     * Check if current driver is Stripe.
     */
    public function isStripe(): bool
    {
        $driver = config('billing.gateway', config('payments.driver'));
        return $driver === 'stripe';
    }

    /**
     * Check if current driver is Fake.
     */
    public function isFake(): bool
    {
        $driver = config('billing.gateway', config('payments.driver'));
        return in_array($driver, ['fake', 'fake_card']);
    }
}

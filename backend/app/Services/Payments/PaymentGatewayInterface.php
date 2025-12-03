<?php

namespace App\Services\Payments;

use Modules\Billing\Models\Payment;

/**
 * PaymentGatewayInterface
 * 
 * Contract for payment gateway implementations.
 * All payment processors (Stripe, PayPal, etc.) must implement this interface.
 */
interface PaymentGatewayInterface
{
    /**
     * Create a payment intent for a tenant.
     *
     * @param \App\Models\Tenant $tenant
     * @param float $amount
     * @param array $metadata
     * @return \Modules\Billing\Models\Payment
     */
    public function createPaymentIntent($tenant, float $amount, array $metadata = []): \Modules\Billing\Models\Payment;

    /**
     * Confirm a payment (usually with card data).
     *
     * @param \Modules\Billing\Models\Payment $payment
     * @param array $cardData
     * @return \Modules\Billing\Models\Payment
     */
    public function confirmPayment(\Modules\Billing\Models\Payment $payment, array $cardData): \Modules\Billing\Models\Payment;

    /**
     * Get gateway identifier.
     *
     * @return string
     */
    public function getName(): string;
}

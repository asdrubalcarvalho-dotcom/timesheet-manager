<?php

namespace App\Contracts;

interface PaymentGatewayInterface
{
    /**
     * Initialize a payment session
     *
     * @param float $amount Payment amount
     * @param string $currency Currency code (e.g., 'usd', 'eur')
     * @param array $metadata Additional data (tenant_id, user_id, plan, etc.)
     * @return array ['transaction_id' => string, 'redirect_url' => string|null, 'client_secret' => string|null]
     */
    public function initializePayment(float $amount, string $currency, array $metadata): array;

    /**
     * Process a payment (for gateways that require manual processing)
     *
     * @param string $transactionId Transaction identifier
     * @param array $cardData Card data (card_number, cvv, expiry, etc.) - only for fake gateway
     * @return array ['status' => 'success'|'failed', 'message' => string, 'transaction_id' => string]
     */
    public function processPayment(string $transactionId, array $cardData = []): array;

    /**
     * Get the status of a payment
     *
     * @param string $transactionId Transaction identifier
     * @return array ['status' => 'pending'|'success'|'failed', 'amount' => float, 'currency' => string]
     */
    public function getPaymentStatus(string $transactionId): array;

    /**
     * Get the gateway name
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Check if this gateway requires redirect (like Stripe Checkout)
     *
     * @return bool
     */
    public function requiresRedirect(): bool;
}

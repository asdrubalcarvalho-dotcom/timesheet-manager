<?php

namespace App\Services\Billing;

use App\Models\Payment;
use Illuminate\Support\Facades\Log;

/**
 * InvoiceDispatcher Service
 * 
 * Placeholder service for future ERP integration.
 * This service will be responsible for:
 * - Generating invoices from payment snapshots
 * - Dispatching invoices to external ERP systems
 * - Tracking invoice generation status
 * - Handling invoice failures and retries
 * 
 * Current implementation: Stub with logging only
 * Future implementation: Actual ERP integration
 */
class InvoiceDispatcher
{
    /**
     * Queue an invoice for a payment snapshot.
     * 
     * This method will be called after a payment is confirmed and marked as paid.
     * In the future, this should:
     * - Generate a PDF invoice from the payment snapshot
     * - Send invoice to external ERP system via API
     * - Track invoice number and status
     * - Handle failures and retry logic
     * 
     * Current behavior: Logs payment details only
     * 
     * @param Payment $payment
     * @return void
     */
    public function queueInvoice(Payment $payment): void
    {
        if (!$payment->isPaid()) {
            Log::warning('[InvoiceDispatcher] Cannot queue invoice for unpaid payment', [
                'payment_id' => $payment->id,
                'status' => $payment->status,
            ]);
            return;
        }

        Log::info('[InvoiceDispatcher] Invoice queued (stub implementation)', [
            'payment_id' => $payment->id,
            'tenant_id' => $payment->tenant_id,
            'plan' => $payment->plan,
            'user_count' => $payment->user_count,
            'addons' => $payment->addons,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'cycle_start' => $payment->cycle_start->format('Y-m-d'),
            'cycle_end' => $payment->cycle_end->format('Y-m-d'),
            'stripe_payment_intent_id' => $payment->stripe_payment_intent_id,
        ]);

        // TODO: Implement actual invoice generation and ERP dispatch
        // Example implementation:
        // 
        // 1. Generate PDF invoice
        // $pdf = $this->generateInvoicePDF($payment);
        //
        // 2. Send to ERP system
        // $erpClient = app(ERPClient::class);
        // $invoiceNumber = $erpClient->createInvoice([
        //     'customer_id' => $payment->tenant->erp_customer_id,
        //     'amount' => $payment->amount,
        //     'currency' => $payment->currency,
        //     'line_items' => $this->buildLineItems($payment),
        //     'billing_period' => [
        //         'start' => $payment->cycle_start,
        //         'end' => $payment->cycle_end,
        //     ],
        // ]);
        //
        // 3. Update payment with invoice reference
        // $payment->update([
        //     'metadata' => array_merge($payment->metadata ?? [], [
        //         'invoice_number' => $invoiceNumber,
        //         'invoice_generated_at' => now()->toIso8601String(),
        //     ]),
        // ]);
    }

    /**
     * Get invoice details for a payment snapshot.
     * 
     * Returns human-readable invoice data that can be used for:
     * - Email notifications
     * - PDF generation
     * - API responses
     * 
     * @param Payment $payment
     * @return array
     */
    public function getInvoiceDetails(Payment $payment): array
    {
        $lineItems = [];

        // Base plan line item
        $lineItems[] = [
            'description' => ucfirst($payment->plan) . ' Plan',
            'quantity' => $payment->user_count,
            'unit_price' => $this->calculateUnitPrice($payment),
            'total' => 0, // Will be calculated
        ];

        // Add-on line items
        if ($payment->hasPlanningAddon()) {
            $lineItems[] = [
                'description' => 'Planning Add-on',
                'quantity' => $payment->user_count,
                'unit_price' => 0, // Will need to be calculated from snapshot
                'total' => 0,
            ];
        }

        if ($payment->hasAiAddon()) {
            $lineItems[] = [
                'description' => 'AI Add-on',
                'quantity' => $payment->user_count,
                'unit_price' => 0,
                'total' => 0,
            ];
        }

        return [
            'invoice_date' => $payment->created_at->format('Y-m-d'),
            'billing_period' => [
                'start' => $payment->cycle_start->format('Y-m-d'),
                'end' => $payment->cycle_end->format('Y-m-d'),
            ],
            'tenant' => [
                'id' => $payment->tenant_id,
                'name' => $payment->tenant->name ?? 'Unknown',
            ],
            'line_items' => $lineItems,
            'subtotal' => $payment->amount,
            'tax' => 0, // TODO: Calculate tax based on tenant location
            'total' => $payment->amount,
            'currency' => $payment->currency,
            'payment_method' => 'Stripe',
            'status' => $payment->status,
        ];
    }

    /**
     * Calculate unit price for base plan.
     * 
     * @param Payment $payment
     * @return float
     */
    protected function calculateUnitPrice(Payment $payment): float
    {
        // This should use PriceCalculator in the future
        // For now, rough estimate
        if ($payment->user_count === 0) {
            return 0;
        }

        return round($payment->amount / $payment->user_count, 2);
    }

    /**
     * Check if invoice generation is enabled.
     * 
     * @return bool
     */
    public function isEnabled(): bool
    {
        // TODO: Add config flag for invoice generation
        // return config('billing.invoices.enabled', false);
        return false; // Disabled until ERP integration is implemented
    }

    /**
     * Get invoice generation status for a payment.
     * 
     * @param Payment $payment
     * @return string 'pending'|'generated'|'failed'|'disabled'
     */
    public function getInvoiceStatus(Payment $payment): string
    {
        if (!$this->isEnabled()) {
            return 'disabled';
        }

        $metadata = $payment->metadata ?? [];
        
        if (isset($metadata['invoice_number'])) {
            return 'generated';
        }

        if (isset($metadata['invoice_error'])) {
            return 'failed';
        }

        return 'pending';
    }
}

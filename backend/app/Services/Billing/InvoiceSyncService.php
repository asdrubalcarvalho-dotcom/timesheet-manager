<?php

namespace App\Services\Billing;

use App\Models\BillingInvoice;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

/**
 * InvoiceSyncService
 * 
 * Phase 3: ERP Integration Service
 * 
 * Purpose:
 * - Query pending invoices awaiting ERP processing
 * - Mark invoices as processed after ERP sync
 * - Send notification emails to accounting team
 * - Generate reports for ERP deadline monitoring
 * 
 * Usage:
 * - Called by API endpoints (GET /api/billing/invoices/pending-erp)
 * - Called by artisan commands (billing:notify-erp-pending)
 * - Used in admin dashboards for monitoring
 */
class InvoiceSyncService
{
    /**
     * List invoices pending ERP processing
     * 
     * Returns invoices with:
     * - erp_processed = false
     * - status IN ('open', 'paid')
     * - Ordered by deadline (most urgent first)
     * 
     * @param int|null $limit - Max results (null = all)
     * @return Collection
     */
    public function listPending(?int $limit = null): Collection
    {
        $query = BillingInvoice::pendingErp()
            ->with('tenant')
            ->orderBy('erp_deadline_at', 'asc');

        if ($limit) {
            $query->limit($limit);
        }

        return $query->get()->map(function ($invoice) {
            return [
                'stripe_invoice_id' => $invoice->stripe_invoice_id,
                'tenant_id' => $invoice->tenant_id,
                'tenant_slug' => $invoice->tenant_slug,
                'status' => $invoice->status,
                'amount_due' => $invoice->amount_due,
                'amount_paid' => $invoice->amount_paid,
                'currency' => $invoice->currency,
                'pdf_url' => $invoice->pdf_url,
                'billing_period_start' => $invoice->billing_period_start?->toIso8601String(),
                'billing_period_end' => $invoice->billing_period_end?->toIso8601String(),
                'erp_deadline_at' => $invoice->erp_deadline_at?->toIso8601String(),
                'days_until_deadline' => $invoice->days_until_deadline,
                'is_overdue' => $invoice->isOverdue(),
                'plan' => $invoice->plan,
                'addons' => $invoice->addons,
                'created_at' => $invoice->created_at->toIso8601String(),
            ];
        });
    }

    /**
     * List invoices approaching deadline (within N days)
     * 
     * @param int $days - Days threshold (default: 7)
     * @return Collection
     */
    public function listApproachingDeadline(int $days = 7): Collection
    {
        return BillingInvoice::approachingDeadline($days)
            ->with('tenant')
            ->orderBy('erp_deadline_at', 'asc')
            ->get()
            ->map(function ($invoice) {
                return [
                    'stripe_invoice_id' => $invoice->stripe_invoice_id,
                    'tenant_slug' => $invoice->tenant_slug,
                    'amount_due' => $invoice->amount_due,
                    'pdf_url' => $invoice->pdf_url,
                    'erp_deadline_at' => $invoice->erp_deadline_at?->toIso8601String(),
                    'days_until_deadline' => $invoice->days_until_deadline,
                ];
            });
    }

    /**
     * List overdue invoices (past ERP deadline)
     * 
     * @return Collection
     */
    public function listOverdue(): Collection
    {
        return BillingInvoice::overdue()
            ->with('tenant')
            ->orderBy('erp_deadline_at', 'asc')
            ->get()
            ->map(function ($invoice) {
                return [
                    'stripe_invoice_id' => $invoice->stripe_invoice_id,
                    'tenant_slug' => $invoice->tenant_slug,
                    'amount_due' => $invoice->amount_due,
                    'pdf_url' => $invoice->pdf_url,
                    'erp_deadline_at' => $invoice->erp_deadline_at?->toIso8601String(),
                    'days_overdue' => abs($invoice->days_until_deadline),
                ];
            });
    }

    /**
     * Mark invoice(s) as processed by ERP
     * 
     * @param string|array $stripeInvoiceIds - Single ID or array of IDs
     * @param string|null $notes - Processing notes
     * @return array ['success' => int, 'failed' => int]
     */
    public function markProcessed($stripeInvoiceIds, ?string $notes = null): array
    {
        $ids = is_array($stripeInvoiceIds) ? $stripeInvoiceIds : [$stripeInvoiceIds];
        $success = 0;
        $failed = 0;

        foreach ($ids as $invoiceId) {
            $invoice = BillingInvoice::where('stripe_invoice_id', $invoiceId)->first();

            if (!$invoice) {
                Log::warning('Invoice not found for ERP processing', [
                    'invoice_id' => $invoiceId,
                ]);
                $failed++;
                continue;
            }

            if ($invoice->markAsProcessed($notes)) {
                $success++;
                Log::info('Invoice marked as ERP processed', [
                    'invoice_id' => $invoiceId,
                    'tenant_slug' => $invoice->tenant_slug,
                ]);
            } else {
                $failed++;
            }
        }

        return [
            'success' => $success,
            'failed' => $failed,
        ];
    }

    /**
     * Send email notification to ERP team
     * 
     * Sends summary of pending invoices requiring processing
     * 
     * @param int $deadlineDays - Include invoices with deadline <= N days (default: 15)
     * @return bool
     */
    public function sendNotifications(int $deadlineDays = 15): bool
    {
        $notifyEmail = config('billing.erp_sync.notify_email');

        if (!$notifyEmail) {
            Log::warning('ERP notification email not configured (BILLING_ERP_NOTIFY_EMAIL)');
            return false;
        }

        // Get invoices approaching deadline
        $pendingInvoices = $this->listApproachingDeadline($deadlineDays);
        $overdueInvoices = $this->listOverdue();

        if ($pendingInvoices->isEmpty() && $overdueInvoices->isEmpty()) {
            Log::info('No pending invoices requiring ERP notification');
            return true;
        }

        try {
            // Build email data
            $emailData = [
                'pending_count' => $pendingInvoices->count(),
                'overdue_count' => $overdueInvoices->count(),
                'pending_invoices' => $pendingInvoices->toArray(),
                'overdue_invoices' => $overdueInvoices->toArray(),
                'total_pending_amount' => $pendingInvoices->sum('amount_due'),
                'total_overdue_amount' => $overdueInvoices->sum('amount_due'),
                'currency' => config('billing.currency.symbol', '€'),
            ];

            // TODO: Implement actual email sending
            // For now, just log the notification
            Log::info('ERP notification email (not sent - implement Mail::send)', [
                'to' => $notifyEmail,
                'pending_count' => $emailData['pending_count'],
                'overdue_count' => $emailData['overdue_count'],
                'total_amount' => $emailData['total_pending_amount'] + $emailData['total_overdue_amount'],
            ]);

            // Uncomment when email template is created:
            /*
            Mail::send('emails.erp.invoice-notification', $emailData, function ($message) use ($notifyEmail) {
                $message->to($notifyEmail)
                    ->subject('TimePerk - Pending Invoices Requiring ERP Processing');
            });
            */

            return true;

        } catch (\Exception $e) {
            Log::error('Failed to send ERP notification email', [
                'error' => $e->getMessage(),
                'to' => $notifyEmail,
            ]);
            return false;
        }
    }

    /**
     * Get summary statistics for ERP dashboard
     * 
     * @return array
     */
    public function getSummary(): array
    {
        $pending = BillingInvoice::pendingErp()->count();
        $approaching = BillingInvoice::approachingDeadline(7)->count();
        $overdue = BillingInvoice::overdue()->count();
        $processed = BillingInvoice::where('erp_processed', true)->count();

        $pendingAmount = BillingInvoice::pendingErp()->sum('amount_due');
        $overdueAmount = BillingInvoice::overdue()->sum('amount_due');

        return [
            'pending_count' => $pending,
            'approaching_deadline_count' => $approaching,
            'overdue_count' => $overdue,
            'processed_count' => $processed,
            'pending_amount' => $pendingAmount,
            'overdue_amount' => $overdueAmount,
            'currency' => config('billing.currency.code', 'EUR'),
            'legal_deadline_days' => config('billing.erp_sync.legal_deadline_days', 15),
        ];
    }
}

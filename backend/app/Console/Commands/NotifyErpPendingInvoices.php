<?php

namespace App\Console\Commands;

use App\Services\Billing\InvoiceSyncService;
use Illuminate\Console\Command;

/**
 * NotifyErpPendingInvoices
 * 
 * Phase 3: Daily cron job for ERP invoice notifications
 * 
 * Purpose:
 * - Check for invoices approaching ERP processing deadline
 * - Send summary email to accounting team
 * - Log statistics for monitoring
 * 
 * Schedule (in app/Console/Kernel.php):
 * $schedule->command('billing:notify-erp-pending')->daily();
 * 
 * Manual execution:
 * php artisan billing:notify-erp-pending
 * php artisan billing:notify-erp-pending --days=7
 * php artisan billing:notify-erp-pending --force  (bypass feature flag check)
 */
class NotifyErpPendingInvoices extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'billing:notify-erp-pending 
                            {--days=15 : Include invoices with deadline <= N days}
                            {--force : Bypass feature flag check}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send ERP notification for pending invoices requiring accounting system processing';

    /**
     * Execute the console command.
     */
    public function handle(InvoiceSyncService $syncService): int
    {
        $this->info('=== ERP Pending Invoices Notification ===');
        $this->newLine();

        // Guard: Check if ERP sync enabled (unless --force)
        if (!$this->option('force') && !config('billing.erp_sync.enabled')) {
            $this->warn('⚠️  ERP sync disabled (BILLING_ERP_SYNC_ENABLED=false)');
            $this->info('Use --force to bypass this check');
            return self::FAILURE;
        }

        // Get deadline threshold from option
        $days = (int) $this->option('days');
        $this->info("Checking invoices with deadline <= {$days} days...");
        $this->newLine();

        try {
            // Get summary statistics
            $summary = $syncService->getSummary();

            $this->info('📊 Summary Statistics:');
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Total Pending', $summary['pending_count']],
                    ['Approaching Deadline', $summary['approaching_deadline_count']],
                    ['Overdue', $summary['overdue_count']],
                    ['Already Processed', $summary['processed_count']],
                    ['Pending Amount', $summary['currency'] . ' ' . number_format($summary['pending_amount'], 2)],
                    ['Overdue Amount', $summary['currency'] . ' ' . number_format($summary['overdue_amount'], 2)],
                ]
            );
            $this->newLine();

            // Send notifications if there are pending invoices
            if ($summary['pending_count'] > 0 || $summary['overdue_count'] > 0) {
                $this->info('📧 Sending notification email...');
                
                $sent = $syncService->sendNotifications($days);

                if ($sent) {
                    $notifyEmail = config('billing.erp_sync.notify_email');
                    $this->info("✅ Notification sent to: {$notifyEmail}");
                } else {
                    $this->warn('⚠️  Email notification not configured or failed');
                    $this->info('Set BILLING_ERP_NOTIFY_EMAIL in .env');
                }
            } else {
                $this->info('✅ No pending invoices - notification not required');
            }

            $this->newLine();

            // Display overdue invoices (critical)
            if ($summary['overdue_count'] > 0) {
                $this->warn('🚨 CRITICAL: Overdue Invoices');
                $overdueInvoices = $syncService->listOverdue();
                
                $this->table(
                    ['Invoice ID', 'Tenant', 'Amount', 'Days Overdue', 'PDF'],
                    $overdueInvoices->map(function ($invoice) {
                        return [
                            $invoice['stripe_invoice_id'],
                            $invoice['tenant_slug'],
                            '€' . number_format($invoice['amount_due'], 2),
                            abs($invoice['days_overdue']),
                            $invoice['pdf_url'] ? 'Available' : 'N/A',
                        ];
                    })->toArray()
                );
            }

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error('❌ Error: ' . $e->getMessage());
            $this->error($e->getTraceAsString());
            return self::FAILURE;
        }
    }
}


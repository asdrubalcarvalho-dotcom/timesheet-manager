<?php

namespace App\Console\Commands;

use App\Services\Billing\BillingDunningService;
use Illuminate\Console\Command;

/**
 * RunBillingDunning Command
 * 
 * Phase 9: Execute dunning process for failed payment recovery
 * 
 * Usage:
 *   php artisan billing:run-dunning
 * 
 * Scheduled:
 *   Daily at 05:00 via Laravel Scheduler
 */
class RunBillingDunning extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'billing:run-dunning';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process failed payment recovery for past_due subscriptions';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting billing dunning process...');
        
        $dunningService = app(BillingDunningService::class);
        $results = $dunningService->runDunningProcess();
        
        // Output summary
        $this->newLine();
        $this->info('Billing dunning process complete.');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total checked', $results['total_checked']],
                ['Recovered', $results['recovered']],
                ['Failed', $results['failed']],
                ['Canceled', $results['canceled']],
            ]
        );
        
        return Command::SUCCESS;
    }
}

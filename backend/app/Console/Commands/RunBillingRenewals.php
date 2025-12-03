<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Billing\BillingRenewalService;

class RunBillingRenewals extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'billing:run-renewals';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run automatic monthly renewals for all eligible subscriptions.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $service = app(BillingRenewalService::class);
        $result = $service->runForDueSubscriptions();
        $this->info('Billing renewal run complete.');
        $this->info('Total checked: ' . $result['total']);
        $this->info('Succeeded: ' . $result['succeeded']);
        $this->info('Failed: ' . $result['failed']);
    }
}

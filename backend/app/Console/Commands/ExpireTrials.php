<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Billing\PlanManager;
use Illuminate\Console\Command;
use Modules\Billing\Models\Subscription;

class ExpireTrials extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'billing:expire-trials';

    /**
     * The console command description.
     */
    protected $description = 'Expire tenant trials (enter read-only mode) when trial_ends_at has passed';

    /**
     * Execute the console command.
     */
    public function handle(PlanManager $planManager): int
    {
        $this->info('Checking for expired trials...');

        // Query all expired trials from central database
        $expiredSubscriptions = Subscription::query()
            ->where('is_trial', true)
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<=', now())
            ->get();

        if ($expiredSubscriptions->isEmpty()) {
            $this->info('No expired trials found.');
            return self::SUCCESS;
        }

        $count = 0;

        foreach ($expiredSubscriptions as $subscription) {
            $tenant = $subscription->tenant;

            if (!$tenant) {
                $this->warn("Subscription #{$subscription->id} has no tenant, skipping.");
                continue;
            }

            // End trial due to expiration (do NOT downgrade plan)
            $updatedSubscription = $planManager->endTrialForTenant($tenant);

            if ($updatedSubscription) {
                $this->info("Expired trial for tenant {$tenant->id} ({$tenant->slug}), entered read-only mode.");
                $count++;
            } else {
                $this->warn("Failed to end trial for tenant {$tenant->id} ({$tenant->slug}).");
            }
        }

        $this->info("Processed {$count} expired trial(s).");

        return self::SUCCESS;
    }
}

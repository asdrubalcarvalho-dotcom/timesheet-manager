<?php

namespace App\Console\Commands\Tenancy;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class PurgeExpiredTenants extends Command
{
    /**
     * Usage:
     *   php artisan tenants:purge-expired --dry-run
     *   php artisan tenants:purge-expired --retention-days=30
     */
    protected $signature = 'tenants:purge-expired
                            {--dry-run : Do not delete anything; only report}
                            {--retention-days= : Retention window in days (default: env TENANT_RETENTION_DAYS or 30)}
                            {--limit=200 : Max tenants to purge in one run}';

    protected $description = 'Schedule and purge tenants after subscription expiry/cancellation + retention window.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');

        $retentionDays = $this->option('retention-days');
        $retentionDays = is_numeric($retentionDays) ? (int) $retentionDays : (int) env('TENANT_RETENTION_DAYS', 30);
        if ($retentionDays < 0) {
            $retentionDays = 0;
        }

        $this->info('Tenant purge run started');
        $this->line('Retention days: ' . $retentionDays);
        $this->line('Dry run: ' . ($dryRun ? 'yes' : 'no'));

        $now = Carbon::now();

        // 1) Schedule retention for tenants that are already read-only but not yet scheduled.
        $toSchedule = Tenant::query()
            ->with('subscription')
            ->whereNull('scheduled_for_deletion_at')
            ->get();

        $scheduledCount = 0;

        foreach ($toSchedule as $tenant) {
            $state = $tenant->subscription_state ?: 'active';
            $subscription = $tenant->subscription;

            if ($subscription) {
                if ($subscription->is_trial) {
                    if ($subscription->trial_ends_at && $subscription->trial_ends_at->isFuture()) {
                        $state = 'trial';
                    } else {
                        $state = 'expired';
                    }
                }

                if ($subscription->billing_period_ends_at && $subscription->billing_period_ends_at->isPast()) {
                    $state = 'expired';
                }

                if (in_array($subscription->status, ['past_due', 'unpaid'], true)) {
                    $state = 'past_due';
                }

                if (in_array($subscription->status, ['canceled', 'cancelled'], true)) {
                    $state = 'cancelled';
                }

                if ($subscription->status === 'active' && ! $subscription->is_trial) {
                    $periodOk = ! $subscription->billing_period_ends_at || $subscription->billing_period_ends_at->isFuture();
                    $renewalOk = $subscription->next_renewal_at && $subscription->next_renewal_at->isFuture();
                    if ($periodOk || $renewalOk) {
                        $state = 'active';
                    }
                }
            } else {
                if ($tenant->trial_ends_at && $tenant->trial_ends_at->isPast()) {
                    $state = 'expired';
                }
            }

            if (in_array($state, ['active', 'trial'], true)) {
                continue;
            }

            $base = $tenant->subscription_last_status_change_at
                ?? $tenant->deactivated_at
                ?? $tenant->trial_ends_at
                ?? $tenant->updated_at
                ?? $now;

            $retentionUntil = Carbon::parse($base)->addDays($retentionDays);

            if ($dryRun) {
                $this->line("[dry-run] schedule {$tenant->slug} for purge at {$retentionUntil->toDateTimeString()}");
                $scheduledCount++;
                continue;
            }

            $tenant->forceFill([
                'data_retention_until' => $retentionUntil,
                'scheduled_for_deletion_at' => $retentionUntil,
            ])->save();

            $scheduledCount++;
        }

        // 2) Purge tenants whose scheduled deletion date has arrived.
        $purgeCandidates = Tenant::query()
            ->with('subscription')
            ->whereNotNull('scheduled_for_deletion_at')
            ->where('scheduled_for_deletion_at', '<=', $now)
            ->orderBy('scheduled_for_deletion_at', 'asc')
            ->limit($limit)
            ->get();

        $purged = 0;
        $failed = 0;

        foreach ($purgeCandidates as $tenant) {
            // Safety: never purge active/trial tenants.
            $state = $tenant->subscription_state ?: 'active';
            $subscription = $tenant->subscription;
            if ($subscription) {
                if ($subscription->is_trial) {
                    $state = ($subscription->trial_ends_at && $subscription->trial_ends_at->isFuture()) ? 'trial' : 'expired';
                }
                if ($subscription->billing_period_ends_at && $subscription->billing_period_ends_at->isPast()) {
                    $state = 'expired';
                }
                if (in_array($subscription->status, ['past_due', 'unpaid'], true)) {
                    $state = 'past_due';
                }
                if (in_array($subscription->status, ['canceled', 'cancelled'], true)) {
                    $state = 'cancelled';
                }
                if ($subscription->status === 'active' && ! $subscription->is_trial) {
                    $periodOk = ! $subscription->billing_period_ends_at || $subscription->billing_period_ends_at->isFuture();
                    $renewalOk = $subscription->next_renewal_at && $subscription->next_renewal_at->isFuture();
                    if ($periodOk || $renewalOk) {
                        $state = 'active';
                    }
                }
            }

            if (in_array($state, ['active', 'trial'], true)) {
                continue;
            }

            if ($dryRun) {
                $this->line("[dry-run] purge {$tenant->slug}");
                $purged++;
                continue;
            }

            try {
                Artisan::call('tenants:delete', [
                    'slug' => $tenant->slug,
                    '--force' => true,
                ]);

                $purged++;

                Log::info('[tenants:purge-expired] Tenant purged', [
                    'tenant_id' => $tenant->id,
                    'slug' => $tenant->slug,
                    'scheduled_for_deletion_at' => $tenant->scheduled_for_deletion_at?->toISOString(),
                    'data_retention_until' => $tenant->data_retention_until?->toISOString(),
                ]);
            } catch (\Throwable $e) {
                $failed++;
                $this->error("Failed to purge {$tenant->slug}: {$e->getMessage()}");

                Log::error('[tenants:purge-expired] Purge failed', [
                    'tenant_id' => $tenant->id,
                    'slug' => $tenant->slug,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info('Tenant purge run complete');
        $this->line("Scheduled: {$scheduledCount}");
        $this->line("Purged: {$purged}");
        $this->line("Failed: {$failed}");

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands\Tenancy;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ComputeTenantMetricsDaily extends Command
{
    /**
     * Usage:
     *  php artisan tenants:compute-metrics-daily
     *  php artisan tenants:compute-metrics-daily --date=2026-01-25
     *  php artisan tenants:compute-metrics-daily --tenant=slugcheck
     *  php artisan tenants:compute-metrics-daily --dry-run
     */
    protected $signature = 'tenants:compute-metrics-daily
                            {--date= : YYYY-MM-DD (defaults to today)}
                            {--tenant= : Tenant slug to compute only one}
                            {--dry-run : Do not write to central DB}
                            {--limit=500 : Max tenants to process in one run}';

    protected $description = 'Compute daily usage metrics per tenant and upsert into central tenant_metrics_daily table.';

    public function handle(): int
    {
        if (!Schema::connection('mysql')->hasTable('tenant_metrics_daily')) {
            $this->info('Metrics table not found, skipping.');
            return Command::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');

        $dateInput = $this->option('date');
        $asOfDate = $dateInput ? Carbon::parse((string) $dateInput)->startOfDay() : now()->startOfDay();
        $ymd = $asOfDate->toDateString();

        $tenantSlug = $this->option('tenant');

        $query = Tenant::query()
            ->where('status', 'active')
            ->orderBy('created_at', 'asc');

        if (!empty($tenantSlug)) {
            $query->where('slug', $tenantSlug);
        }

        $tenants = $query->limit($limit)->get();

        if ($tenants->isEmpty()) {
            $this->info('No tenants to process.');
            return Command::SUCCESS;
        }

        $this->info(sprintf('Computing metrics for %d tenant(s) @ %s%s', $tenants->count(), $ymd, $dryRun ? ' (dry-run)' : ''));

        $processed = 0;
        $failed = 0;

        foreach ($tenants as $tenant) {
            $processed++;
            $this->line(sprintf('[%d/%d] %s', $processed, $tenants->count(), $tenant->slug));

            try {
                $metrics = $tenant->run(function () use ($asOfDate) {
                    $start = $asOfDate->copy();

                    // IMPORTANT: explicitly use the tenant connection inside the tenant run.
                    // Relying on the default connection can intermittently point to central.
                    $tenantSchema = Schema::connection('tenant');
                    $tenantDb = DB::connection('tenant');

                    $timesheetsTotal = $tenantSchema->hasTable('timesheets')
                        ? $tenantDb->table('timesheets')->count()
                        : 0;

                    $timesheetsToday = ($tenantSchema->hasTable('timesheets') && $tenantSchema->hasColumn('timesheets', 'created_at'))
                        ? $tenantDb->table('timesheets')->where('created_at', '>=', $start)->count()
                        : 0;

                    $expensesTotal = $tenantSchema->hasTable('expenses')
                        ? $tenantDb->table('expenses')->count()
                        : 0;

                    $expensesToday = ($tenantSchema->hasTable('expenses') && $tenantSchema->hasColumn('expenses', 'created_at'))
                        ? $tenantDb->table('expenses')->where('created_at', '>=', $start)->count()
                        : 0;

                    // Users/Technicians: prefer users table if present; fallback to technicians.
                    $usersTotal = 0;
                    $usersActiveToday = 0;
                    $lastLoginAt = null;

                    if ($tenantSchema->hasTable('users')) {
                        $usersTotal = $tenantDb->table('users')->count();

                        if ($tenantSchema->hasColumn('users', 'last_seen_at')) {
                            $usersActiveToday = $tenantDb->table('users')->where('last_seen_at', '>=', $start)->count();
                            $lastLoginAt = $tenantDb->table('users')->max('last_seen_at');
                        } elseif ($tenantSchema->hasColumn('users', 'last_login_at')) {
                            $usersActiveToday = $tenantDb->table('users')->where('last_login_at', '>=', $start)->count();
                            $lastLoginAt = $tenantDb->table('users')->max('last_login_at');
                        }
                    } elseif ($tenantSchema->hasTable('technicians')) {
                        $usersTotal = $tenantDb->table('technicians')->count();

                        if ($tenantSchema->hasColumn('technicians', 'last_seen_at')) {
                            $usersActiveToday = $tenantDb->table('technicians')->where('last_seen_at', '>=', $start)->count();
                            $lastLoginAt = $tenantDb->table('technicians')->max('last_seen_at');
                        } elseif ($tenantSchema->hasColumn('technicians', 'last_login_at')) {
                            $usersActiveToday = $tenantDb->table('technicians')->where('last_login_at', '>=', $start)->count();
                            $lastLoginAt = $tenantDb->table('technicians')->max('last_login_at');
                        }
                    }

                    return [
                        'timesheets_total' => (int) $timesheetsTotal,
                        'timesheets_today' => (int) $timesheetsToday,
                        'expenses_total' => (int) $expensesTotal,
                        'expenses_today' => (int) $expensesToday,
                        'users_total' => (int) $usersTotal,
                        'users_active_today' => (int) $usersActiveToday,
                        'last_login_at' => $lastLoginAt,
                    ];
                });

                if ($dryRun) {
                    $this->line('  dry-run: ' . json_encode($metrics));
                    continue;
                }

                DB::connection('mysql')->table('tenant_metrics_daily')->upsert(
                    [
                        [
                            'tenant_id' => $tenant->id,
                            'date' => $asOfDate->toDateString(),
                            'timesheets_total' => $metrics['timesheets_total'],
                            'timesheets_today' => $metrics['timesheets_today'],
                            'expenses_total' => $metrics['expenses_total'],
                            'expenses_today' => $metrics['expenses_today'],
                            'users_total' => $metrics['users_total'],
                            'users_active_today' => $metrics['users_active_today'],
                            'last_login_at' => $metrics['last_login_at'],
                            'created_at' => now(),
                            'updated_at' => now(),
                        ],
                    ],
                    ['tenant_id', 'date'],
                    [
                        'timesheets_total',
                        'timesheets_today',
                        'expenses_total',
                        'expenses_today',
                        'users_total',
                        'users_active_today',
                        'last_login_at',
                        'updated_at',
                    ]
                );

            } catch (\Throwable $e) {
                $failed++;
                $this->error('  failed: ' . $e->getMessage());

                Log::warning('Failed computing tenant metrics daily', [
                    'tenant_id' => $tenant->id,
                    'tenant_slug' => $tenant->slug,
                    'date' => $ymd,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->newLine();
        $this->info(sprintf('Done. processed=%d failed=%d', $processed, $failed));

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}

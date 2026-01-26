<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Daily tenant usage aggregation (central snapshot for management telemetry)
        // Post-deploy note: after deploy + migrations, run `php artisan tenants:compute-metrics-daily`
        // once to populate same-day "Usage Summary" before the first scheduled run.
        $schedule->command('tenants:compute-metrics-daily')
            ->dailyAt('01:30')
            ->withoutOverlapping()
            ->onOneServer();

        // Trial expiry: move expired trials into read-only mode
        $schedule->command('billing:expire-trials')
            ->dailyAt('02:00')
            ->withoutOverlapping()
            ->onOneServer();

        // Phase 8: Automatic monthly renewals
        $schedule->command('billing:run-renewals')
            ->dailyAt('03:00')
            ->withoutOverlapping()
            ->onOneServer();
        
        // Phase 9: Failed payment recovery (dunning)
        $schedule->command('billing:run-dunning')
            ->dailyAt('05:00')
            ->withoutOverlapping()
            ->onOneServer();

        // Tenant retention & purge
        $schedule->command('tenants:purge-expired')
            ->dailyAt('06:00')
            ->withoutOverlapping()
            ->onOneServer();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');
        require base_path('routes/console.php');
    }
}

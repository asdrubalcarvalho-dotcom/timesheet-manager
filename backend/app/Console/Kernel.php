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

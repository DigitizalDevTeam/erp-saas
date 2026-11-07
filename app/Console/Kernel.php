<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected $commands = [
        \App\Console\Commands\SendStockAlerts::class,
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('stock:alerts')->dailyAt('08:00');
        $schedule->command('billing:generate-invoices')->dailyAt('08:00');
        $schedule->command('billing:send-reminders')->dailyAt('08:30');
        $schedule->command('treasury:recurring-generate')->dailyAt('07:30');
        $schedule->command('treasury:forecast-refresh')->dailyAt('07:45');
        $schedule->command('treasury:prune-report-exports')->dailyAt('03:00');
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}

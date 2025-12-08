<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected $commands = [
        \App\Console\Commands\ImportTelegramUsersFromSlots::class,
    ];
    protected function schedule(Schedule $schedule): void
    {
        // Каждый день в 03:00 создаём слоты на сегодня
        $schedule->command('slots:generate')->dailyAt('03:00');
    }
    
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');
    }
    
}

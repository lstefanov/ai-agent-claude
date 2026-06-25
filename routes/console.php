<?php

use App\Jobs\SyncOllamaModelsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('flows:run-scheduled')->everyMinute();
Schedule::command('flows:watchdog')->everyMinute();
Schedule::job(new SyncOllamaModelsJob)->hourly();
Schedule::command('knowledge:prune-web-cache')->daily();
Schedule::command('flows:run-evals')->dailyAt('03:00');

// AI Организация (Фаза 4): scheduled задачи по cron (всяка минута) + директорски ревюта (на час).
Schedule::command('org:director-ticks')->everyMinute();
Schedule::command('org:director-ticks --ticks')->hourly();
// Фаза 7: седмично ревю на Управителя.
Schedule::command('org:review')->weeklyOn(1, '08:00');

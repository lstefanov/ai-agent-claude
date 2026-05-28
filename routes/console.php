<?php

use App\Jobs\SyncOllamaModelsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('flows:run-scheduled')->everyMinute();
Schedule::job(new SyncOllamaModelsJob)->hourly();

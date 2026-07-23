<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

use App\Models\Monitor;
use App\Models\SeoCheck;
use App\Jobs\CheckMonitorJob;
use App\Jobs\CheckSeoJob;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    Monitor::cursor()->each(function (Monitor $monitor) {
        if (!$monitor->last_checked_at || $monitor->last_checked_at->addSeconds($monitor->interval)->isPast()) {
            CheckMonitorJob::dispatch($monitor);
        }
    });
})->everyMinute();

// SEO checks run three times a day at fixed local wall-clock times (06:00,
// 10:00, 14:00 Europe/Madrid), fully decoupled from the Monitor uptime loop
// above. The schedule itself controls cadence, so every entry is dispatched on
// each run regardless of its per-entry interval.
Schedule::call(function () {
    SeoCheck::cursor()->each(function (SeoCheck $seoCheck) {
        CheckSeoJob::dispatch($seoCheck);
    });
})->cron('0 6,10,14 * * *')->timezone('Europe/Madrid');

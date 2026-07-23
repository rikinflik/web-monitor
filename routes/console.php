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

// SEO checks run on their own, longer per-entry cadence (interval in minutes),
// fully decoupled from the Monitor uptime loop above.
Schedule::call(function () {
    SeoCheck::cursor()->each(function (SeoCheck $seoCheck) {
        if (!$seoCheck->last_checked_at || $seoCheck->last_checked_at->addMinutes($seoCheck->interval)->isPast()) {
            CheckSeoJob::dispatch($seoCheck);
        }
    });
})->everyMinute();

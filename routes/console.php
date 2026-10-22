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

// Alert checks run hourly, their own block fully decoupled from the Monitor
// per-minute loop and the SEO thrice-daily block above. This feature exists
// so a critical security advisory for one of the six tracked ecosystems is
// never missed, which favors a frequent, fixed cadence over cost. Hourly is
// also load-bearing for correctness, not just freshness: AlertCheckService
// deliberately does NOT retry a failed feed fetch within the job (see its
// class docblock) because three of the six sources share a single
// unauthenticated GitHub Advisories budget of 60 requests/hour, and retrying
// inside the same ~10-second backoff window would burn that shared budget on
// guaranteed repeat failures instead of waiting out whatever caused them.
// This hourly run IS the retry — the next scheduled check gives a transient
// failure time to clear at zero extra request cost.
Schedule::command('alerts:check')->hourly();

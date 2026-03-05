<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

use App\Models\Monitor;
use App\Jobs\CheckMonitorJob;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    Monitor::all()->each(function (Monitor $monitor) {
        if (!$monitor->last_checked_at || $monitor->last_checked_at->addSeconds($monitor->interval)->isPast()) {
            CheckMonitorJob::dispatch($monitor);
        }
    });
})->everyMinute();

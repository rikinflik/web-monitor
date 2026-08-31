<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Down reminder backoff
    |--------------------------------------------------------------------------
    |
    | How long to wait before re-emailing about a monitor that is still down.
    | Each entry is the delay, in minutes, before the next reminder; once the
    | list is exhausted the final value repeats until the monitor recovers.
    |
    | The default escalates 15m → 30m → 1h → 2h → 4h and then settles at 4h, so
    | a fresh outage is noisy and a week-long one stays readable.
    |
    | Reminders can never fire more often than the monitor's own check
    | interval, since they are evaluated during a check.
    |
    */

    'down_reminder_backoff_minutes' => [15, 30, 60, 120, 240],

];

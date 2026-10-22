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

    /*
    |--------------------------------------------------------------------------
    | Display timezone
    |--------------------------------------------------------------------------
    |
    | Timestamps are stored in UTC (config/app.php), which is correct but
    | unreadable in an alert an operator scans at a glance. Absolute times in
    | Telegram alerts are rendered in this zone instead.
    |
    */

    'display_timezone' => env('MONITORING_DISPLAY_TIMEZONE', 'Europe/Madrid'),

    /*
    |--------------------------------------------------------------------------
    | Public overview token
    |--------------------------------------------------------------------------
    |
    | A single, unguessable, global token gating GET /overview/{token} — a
    | public (no-login) page listing every monitor currently down plus the
    | tracked technology alerts. "Public" here means "reachable without a
    | session", not "discoverable": there is no index of valid tokens and the
    | page is not linked from anywhere, so knowing the token is the only way
    | in.
    |
    | This is the on/off switch, mirroring how an empty
    | TELEGRAM_ALERT_CHAT_ID disables Telegram alerting: leaving it empty (or
    | unset) disables the route entirely and it 404s, indistinguishable from
    | a wrong token or a route that does not exist. No default is generated
    | here on purpose — a real value must be chosen and set in the
    | environment, never committed.
    |
    */

    'public_overview_token' => env('PUBLIC_OVERVIEW_TOKEN'),

];

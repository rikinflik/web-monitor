<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    | Telegram bot used for operator alerts about monitor outages.
    |
    | `token` is the bot token from @BotFather and is read by the
    | laravel-notification-channels/telegram package. `alert_chat_id` is the
    | ops chat (a person or a group) that receives every outage alert; leaving
    | it empty disables Telegram alerting entirely.
    |
    | This chat is deliberately independent of the per-user email preferences
    | in users.notify_mode: it is the room that watches everything, not a
    | subscriber.
    */
    /*
    | Read by laravel-notification-channels/telegram and merged into the Guzzle
    | client it builds. TELEGRAM_CA_BUNDLE exists because the CA store is not
    | always readable by the user the scheduler runs as: cURL then fails with
    | error 77 and every alert dies silently, while uptime checks carry on
    | because they pass 'verify' => false. Point it at a bundle that user can
    | read; leave it unset to use the system default.
    */
    'telegram' => [
        'http' => [
            'verify' => env('TELEGRAM_CA_BUNDLE', true),
        ],
    ],

    'telegram-bot-api' => [
        'token' => env('TELEGRAM_BOT_TOKEN'),
        'alert_chat_id' => env('TELEGRAM_ALERT_CHAT_ID'),
    ],

];

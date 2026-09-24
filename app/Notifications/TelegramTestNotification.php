<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\Telegram\Enums\ParseMode;
use NotificationChannels\Telegram\TelegramMessage;

/**
 * A harmless "it works" message used by the `telegram:test` command to verify
 * the bot token and chat id end to end, without waiting for a real outage.
 */
class TelegramTestNotification extends Notification
{
    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['telegram'];
    }

    public function toTelegram(object $notifiable): TelegramMessage
    {
        return TelegramMessage::create()
            ->parseMode(ParseMode::HTML)
            ->content(sprintf(
                "\u{2705} <b>%s</b> — Telegram alerts are working\n\nThis is a test triggered by <code>php artisan telegram:test</code>. If you can read this, monitor outage alerts will reach you here.",
                htmlspecialchars((string) config('app.name'), ENT_QUOTES),
            ));
    }
}

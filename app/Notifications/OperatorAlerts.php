<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * The only path from a monitor check to the operator Telegram chat.
 *
 * Alerting must never change the outcome of the check that triggered it: an
 * empty TELEGRAM_ALERT_CHAT_ID means alerting is off, and a throwing channel is
 * logged and swallowed. A check therefore still records its log row and still
 * sends its emails — the alert is the last thing that happens, never the thing
 * that decides whether the rest ran.
 *
 * Keeping the gate and the try/catch in one place is what makes that guarantee
 * checkable: there is no second copy to drift.
 */
final class OperatorAlerts
{
    public function send(BaseNotification $notification): void
    {
        $chatId = config('services.telegram-bot-api.alert_chat_id');

        if ($chatId === null || $chatId === '') {
            return;
        }

        try {
            Notification::route('telegram', $chatId)->notify($notification);
        } catch (Throwable $e) {
            Log::error('Operator alert could not be sent', [
                'notification' => $notification::class,
                'exception' => $e,
            ]);
        }
    }
}

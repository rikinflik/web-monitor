<?php

namespace App\Console\Commands;

use App\Notifications\TelegramTestNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Send a one-off test alert to Telegram to verify TELEGRAM_BOT_TOKEN and
 * TELEGRAM_ALERT_CHAT_ID without waiting for a real monitor to go down.
 *
 * Unlike the automatic alerts routed through OperatorAlerts, delivery errors
 * here are surfaced rather than swallowed: reporting exactly why a send failed
 * is the entire point of a diagnostic command.
 */
final class TestTelegramAlert extends Command
{
    protected $signature = 'telegram:test {chat? : Chat id to send to (defaults to TELEGRAM_ALERT_CHAT_ID)}';

    protected $description = 'Send a test alert to Telegram to verify the bot token and chat id.';

    public function handle(): int
    {
        $token = config('services.telegram-bot-api.token');

        if ($token === null || $token === '') {
            $this->error('TELEGRAM_BOT_TOKEN is not set — create a bot with @BotFather first.');

            return self::FAILURE;
        }

        $chatId = $this->argument('chat');

        if ($chatId === null || $chatId === '') {
            $chatId = config('services.telegram-bot-api.alert_chat_id');
        }

        if ($chatId === null || $chatId === '') {
            $this->error('No chat id — set TELEGRAM_ALERT_CHAT_ID or pass one: php artisan telegram:test <chat>');

            return self::FAILURE;
        }

        try {
            Notification::route('telegram', $chatId)
                ->notify(new TelegramTestNotification);
        } catch (Throwable $e) {
            $this->error('Telegram send failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Test alert sent to chat %s — check your Telegram.', $chatId));

        return self::SUCCESS;
    }
}

<?php

namespace App\Notifications;

use App\Support\AlertText;
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
            // The message, not the exception object: Telegram puts the bot
            // token in the request path, so a serialised exception would write
            // the credential into the log.
            Log::error('Operator alert could not be sent', [
                'notification' => $notification::class,
                'exception' => $e::class,
                'reason' => AlertText::redact($e->getMessage()),
                'at' => $e->getFile().':'.$e->getLine(),
                'context' => $this->failureContext(),
            ]);
        }
    }

    /**
     * The PHP context this send died in.
     *
     * A scheduled check and a panel-run command are different processes with
     * different ini files and different open_basedir, and a CA bundle readable
     * in one can be off limits in the other — cURL reports the same error 77
     * either way. Recording the context here is the only way to tell them
     * apart, because the failing process is never the one being debugged.
     *
     * @return array<string, mixed>
     */
    protected function failureContext(): array
    {
        $bundle = '/etc/ssl/certs/ca-certificates.crt';

        return [
            'sapi' => PHP_SAPI,
            'user' => function_exists('posix_geteuid') && function_exists('posix_getpwuid')
                ? (posix_getpwuid(posix_geteuid())['name'] ?? '?')
                : get_current_user(),
            'php_ini' => php_ini_loaded_file() ?: '(none)',
            'open_basedir' => ini_get('open_basedir') ?: '(not set)',
            'curl.cainfo' => ini_get('curl.cainfo') ?: '(not set)',
            'openssl.cafile' => ini_get('openssl.cafile') ?: '(not set)',
            'bundle_readable' => is_readable($bundle) ? 'yes' : 'NO',
        ];
    }
}

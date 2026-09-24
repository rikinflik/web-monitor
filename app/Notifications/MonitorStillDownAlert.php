<?php

namespace App\Notifications;

use App\Models\Monitor;
use App\Support\AlertText;
use Illuminate\Notifications\Notification;
use NotificationChannels\Telegram\Enums\ParseMode;
use NotificationChannels\Telegram\TelegramMessage;

/**
 * Operator alert pushed to Telegram while a monitor stays down.
 *
 * The Telegram twin of MonitorStillDown, sent on the same backoff configured
 * in config/monitoring.php (15m → 30m → 1h → 2h → 4h, then every 4h). Like its
 * email counterpart it exists to cover the silence between the transitions, so
 * an outage nobody acted on does not go unnoticed for days.
 */
class MonitorStillDownAlert extends Notification
{
    /**
     * @param int $reminderNumber
     *   1 for the first reminder of this outage, incrementing from there.
     */
    public function __construct(public Monitor $monitor, public int $reminderNumber)
    {
        //
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['telegram'];
    }

    public function toTelegram(object $notifiable): TelegramMessage
    {
        $duration = $this->monitor->outageDuration();

        return TelegramMessage::create()
            ->parseMode(ParseMode::HTML)
            ->content(sprintf(
                "\u{1F7E0} <b>%s</b> — Still DOWN (reminder #%d)\n\n<b>Monitor:</b> %s\n<b>URL:</b> %s\n<b>Down for:</b> %s\n<b>Since:</b> %s\n\n<a href=\"%s\">Status page</a>",
                AlertText::escape((string) config('app.name'), 100),
                $this->reminderNumber,
                AlertText::escape($this->monitor->name, 100),
                AlertText::escape($this->monitor->url, 200),
                AlertText::escape($duration ?? 'unknown', 50),
                AlertText::escape($this->downSinceLocal(), 40),
                AlertText::escape(route('public.status', $this->monitor->public_token), 300),
            ));
    }

    /**
     * Outage start in the operator's own wall clock, not the UTC it is stored in.
     */
    protected function downSinceLocal(): string
    {
        return $this->monitor->down_since
            ?->timezone((string) config('monitoring.display_timezone'))
            ->format('Y-m-d H:i') ?? '—';
    }
}

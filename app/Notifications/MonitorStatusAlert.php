<?php

namespace App\Notifications;

use App\Models\Monitor;
use App\Support\AlertText;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use NotificationChannels\Telegram\Enums\ParseMode;
use NotificationChannels\Telegram\TelegramMessage;

/**
 * Operator alert pushed to Telegram when a monitor crosses between up and down.
 *
 * The Telegram twin of MonitorStatusChanged. It is sent as an on-demand
 * notification routed at the configured ops chat, so it does not consult the
 * per-user notify_mode preferences that drive the emails: the ops chat sees
 * every monitor, including outages nobody subscribed to.
 *
 * All dynamic text goes through AlertText because a monitor name, a URL or a
 * Guzzle error is uncontrolled input that must not break Telegram's HTML
 * entity parsing.
 */
class MonitorStatusAlert extends Notification
{
    /**
     * @param string $status
     *   The status just entered: Monitor::STATUS_UP or Monitor::STATUS_DOWN.
     * @param \Illuminate\Support\Carbon|null $downSince
     *   Start of the outage being reported as over. Passed explicitly because
     *   the column is cleared as part of recovering.
     */
    public function __construct(
        public Monitor $monitor,
        public string $status,
        public ?Carbon $downSince = null,
    ) {
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
        return TelegramMessage::create()
            ->parseMode(ParseMode::HTML)
            ->content($this->status === Monitor::STATUS_UP
                ? $this->recoveryContent()
                : $this->outageContent());
    }

    protected function outageContent(): string
    {
        return sprintf(
            "\u{1F534} <b>%s</b> — Monitor DOWN\n\n<b>Monitor:</b> %s\n<b>URL:</b> %s\n<b>Reason:</b> <code>%s</code>\n\n<a href=\"%s\">Status page</a> · reminders follow until it is back up.",
            AlertText::escape((string) config('app.name'), 100),
            AlertText::escape($this->monitor->name, 100),
            AlertText::escape($this->monitor->url, 200),
            AlertText::escape($this->failureReason()),
            AlertText::escape($this->statusPageUrl(), 300),
        );
    }

    protected function recoveryContent(): string
    {
        $duration = $this->monitor->outageDuration($this->downSince);

        return sprintf(
            "\u{2705} <b>%s</b> — Monitor RESTORED\n\n<b>Monitor:</b> %s\n<b>URL:</b> %s\n<b>Was down for:</b> %s\n\n<a href=\"%s\">Status page</a> · no further reminders for this outage.",
            AlertText::escape((string) config('app.name'), 100),
            AlertText::escape($this->monitor->name, 100),
            AlertText::escape($this->monitor->url, 200),
            AlertText::escape($duration ?? 'unknown', 50),
            AlertText::escape($this->statusPageUrl(), 300),
        );
    }

    /**
     * Why the check failed, as recorded by the check that just ran.
     *
     * Read from the log rather than threaded through MonitoringService: a
     * status change always writes a row first, and an alert that says only
     * "DOWN" sends the operator straight to the panel to find out why.
     */
    protected function failureReason(): string
    {
        $log = $this->monitor->logs()->latest('checked_at')->first();

        return $log?->error_message ?? 'no error recorded';
    }

    /**
     * The ops chat is not a logged-in user, so it gets the public status page
     * rather than the owner-only monitor page (see LinksToMonitor).
     */
    protected function statusPageUrl(): string
    {
        return route('public.status', $this->monitor->public_token);
    }
}

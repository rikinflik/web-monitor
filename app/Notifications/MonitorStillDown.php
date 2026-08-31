<?php

namespace App\Notifications;

use App\Models\Monitor;
use App\Notifications\Concerns\LinksToMonitor;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Reminder that a monitor is still down, sent on the configured backoff.
 *
 * MonitorStatusChanged covers the transitions; this one covers the silence in
 * between, so an outage that nobody acted on does not go unnoticed for days.
 */
class MonitorStillDown extends Notification
{
    use Queueable, LinksToMonitor;

    /**
     * @param int $reminderNumber
     *   1 for the first reminder of this outage, incrementing from there.
     */
    public function __construct(public Monitor $monitor, public int $reminderNumber)
    {
        //
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $duration = $this->monitor->outageDuration();

        $message = (new MailMessage)
            ->subject($duration
                ? "Still DOWN: {$this->monitor->name} (down for {$duration})"
                : "Still DOWN: {$this->monitor->name}")
            ->line("The monitor for {$this->monitor->url} is still DOWN.");

        if ($duration && $this->monitor->down_since) {
            $message->line(sprintf(
                'It has been down for %s, since %s.',
                $duration,
                $this->monitor->down_since->toDayDateTimeString(),
            ));
        }

        return $message
            ->action('View Monitor', $this->monitorUrlFor($this->monitor, $notifiable))
            ->line('Reminders keep coming, less and less often, until it is back up.');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'monitor_id' => $this->monitor->id,
            'reminder_number' => $this->reminderNumber,
        ];
    }
}

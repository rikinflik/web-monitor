<?php

namespace App\Notifications;

use App\Models\Monitor;
use App\Notifications\Concerns\LinksToMonitor;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * Sent when a monitor crosses between up and down.
 *
 * The quiet stretch of a long outage is covered by MonitorStillDown instead.
 */
class MonitorStatusChanged extends Notification
{
    use Queueable, LinksToMonitor;

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
        return $this->status === Monitor::STATUS_UP
            ? $this->recoveryMail($notifiable)
            : $this->outageMail($notifiable);
    }

    protected function outageMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Monitor DOWN: {$this->monitor->name}")
            ->line("The monitor for {$this->monitor->url} is now DOWN.")
            ->action('View Monitor', $this->monitorUrlFor($this->monitor, $notifiable))
            ->line('You will get reminders until it is back up.');
    }

    protected function recoveryMail(object $notifiable): MailMessage
    {
        $duration = $this->monitor->outageDuration($this->downSince);

        $message = (new MailMessage)
            ->subject($duration
                ? "Monitor RESTORED: {$this->monitor->name} (was down for {$duration})"
                : "Monitor RESTORED: {$this->monitor->name}")
            ->line("The monitor for {$this->monitor->url} is back UP.");

        if ($duration) {
            $message->line("It was down for {$duration}.");
        }

        return $message
            ->action('View Monitor', $this->monitorUrlFor($this->monitor, $notifiable))
            ->line('No further reminders will be sent for this outage.');
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
            'status' => $this->status,
        ];
    }
}

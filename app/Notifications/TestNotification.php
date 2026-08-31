<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * On-demand email used to prove the mail configuration actually delivers.
 *
 * Deliberately not queued: the profile button reports the SMTP failure back
 * to the user, which only works while the send happens in-request.
 */
class TestNotification extends Notification
{
    use Queueable;

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
        return (new MailMessage)
            ->subject('Web Monitor test email')
            ->line('This is a test email from Web Monitor.')
            ->line('If it reached your inbox, down alerts and reminders will reach it too.')
            ->line(sprintf(
                'Sent %s via the "%s" mailer, from %s.',
                // Includes the zone: the app runs on UTC and a reader in another
                // zone would otherwise wonder whether the mail was stale.
                now()->format('D, d M Y H:i T'),
                config('mail.default'),
                config('mail.from.address'),
            ))
            ->line('Nothing is wrong — you asked for this from your profile page.');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [];
    }
}

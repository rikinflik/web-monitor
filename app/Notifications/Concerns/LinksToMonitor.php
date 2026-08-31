<?php

namespace App\Notifications\Concerns;

use App\Models\Monitor;
use App\Models\User;

trait LinksToMonitor
{
    /**
     * Link the recipient to a page they are actually allowed to open.
     *
     * Recipients come from notification preferences, so most of them do not own
     * the monitor; MonitorPolicy::view() is owner-only, which would turn the
     * button into a 403. Non-owners get the public status page instead.
     */
    protected function monitorUrlFor(Monitor $monitor, object $notifiable): string
    {
        $ownsMonitor = $notifiable instanceof User
            && $notifiable->getKey() === $monitor->user_id;

        return $ownsMonitor
            ? url("/monitors/{$monitor->id}")
            : route('public.status', $monitor->public_token);
    }
}

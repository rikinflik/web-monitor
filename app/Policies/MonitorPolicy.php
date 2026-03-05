<?php

namespace App\Policies;

use App\Models\Monitor;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class MonitorPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Monitor $monitor): bool
    {
        return $user->id === $monitor->user_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Monitor $monitor): bool
    {
        return $user->id === $monitor->user_id;
    }

    public function delete(User $user, Monitor $monitor): bool
    {
        return $user->id === $monitor->user_id;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Monitor $monitor): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Monitor $monitor): bool
    {
        return false;
    }
}

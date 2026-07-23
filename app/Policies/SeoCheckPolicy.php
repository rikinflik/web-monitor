<?php

namespace App\Policies;

use App\Models\SeoCheck;
use App\Models\User;

/**
 * Authorizes SEO check actions via the linked monitor's ownership.
 *
 * Auto-discovered by Laravel's SeoCheck -> SeoCheckPolicy naming convention.
 */
class SeoCheckPolicy
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
    public function view(User $user, SeoCheck $seoCheck): bool
    {
        return $user->id === $seoCheck->monitor->user_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Used by the manual re-check action.
     */
    public function update(User $user, SeoCheck $seoCheck): bool
    {
        return $user->id === $seoCheck->monitor->user_id;
    }

    public function delete(User $user, SeoCheck $seoCheck): bool
    {
        return $user->id === $seoCheck->monitor->user_id;
    }
}

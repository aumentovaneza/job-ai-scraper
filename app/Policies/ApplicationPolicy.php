<?php

namespace App\Policies;

use App\Models\Application;
use App\Models\User;

/**
 * Ownership checks for applications (PLAN.md §7). The BelongsToUser global scope
 * hides other users' rows from queries/route-binding; these gates are the
 * explicit, tested belt-and-suspenders on every mutating endpoint. `update`
 * covers stage moves, notes, and contact attachment.
 */
class ApplicationPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Application $application): bool
    {
        return $application->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Application $application): bool
    {
        return $application->user_id === $user->id;
    }

    public function delete(User $user, Application $application): bool
    {
        return $application->user_id === $user->id;
    }
}

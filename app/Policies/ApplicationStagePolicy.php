<?php

namespace App\Policies;

use App\Models\ApplicationStage;
use App\Models\User;

/**
 * Ownership checks for pipeline stages (PLAN.md §7). The BelongsToUser global
 * scope already hides other users' rows from queries/route-binding; these gates
 * are the explicit, tested belt-and-suspenders on every mutating endpoint.
 */
class ApplicationStagePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ApplicationStage $stage): bool
    {
        return $stage->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, ApplicationStage $stage): bool
    {
        return $stage->user_id === $user->id;
    }

    public function delete(User $user, ApplicationStage $stage): bool
    {
        return $stage->user_id === $user->id;
    }
}

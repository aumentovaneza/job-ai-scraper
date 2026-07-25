<?php

namespace App\Policies;

use App\Models\JobSource;
use App\Models\User;

/**
 * Ownership checks for job sources (PLAN.md §7). The BelongsToUser global scope
 * already hides other users' rows from queries/route-binding, but these gates
 * are the explicit, tested belt-and-suspenders on every mutating endpoint.
 */
class JobSourcePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, JobSource $source): bool
    {
        return $source->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, JobSource $source): bool
    {
        return $source->user_id === $user->id;
    }

    public function delete(User $user, JobSource $source): bool
    {
        return $source->user_id === $user->id;
    }
}

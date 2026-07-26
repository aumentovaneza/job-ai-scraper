<?php

namespace App\Policies;

use App\Models\FollowUp;
use App\Models\User;

/**
 * Ownership checks for follow-ups (PLAN.md §7). The BelongsToUser global scope
 * hides other users' rows; this gate is the explicit belt-and-suspenders on the
 * mutating endpoint.
 */
class FollowUpPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function update(User $user, FollowUp $followUp): bool
    {
        return $followUp->user_id === $user->id;
    }
}

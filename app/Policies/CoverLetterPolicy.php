<?php

namespace App\Policies;

use App\Models\CoverLetter;
use App\Models\User;

/**
 * Ownership checks for cover letters (PLAN §7). The BelongsToUser global scope
 * already hides other users' rows from route binding; these gates are the
 * explicit, tested belt-and-suspenders on every action.
 */
class CoverLetterPolicy
{
    public function view(User $user, CoverLetter $coverLetter): bool
    {
        return $coverLetter->user_id === $user->id;
    }

    public function update(User $user, CoverLetter $coverLetter): bool
    {
        return $coverLetter->user_id === $user->id;
    }
}

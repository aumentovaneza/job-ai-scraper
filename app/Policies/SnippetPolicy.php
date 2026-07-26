<?php

namespace App\Policies;

use App\Models\Snippet;
use App\Models\User;

/**
 * Ownership checks for the snippet library (T-54, PLAN §7). The BelongsToUser
 * global scope hides other users' snippets; these gates are the explicit,
 * tested belt-and-suspenders on every mutating endpoint.
 */
class SnippetPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function update(User $user, Snippet $snippet): bool
    {
        return $snippet->user_id === $user->id;
    }

    public function delete(User $user, Snippet $snippet): bool
    {
        return $snippet->user_id === $user->id;
    }
}

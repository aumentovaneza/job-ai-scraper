<?php

namespace App\Policies;

use App\Models\CoverLetterVersion;
use App\Models\User;

/**
 * Ownership checks for individual cover-letter versions (PLAN §7) — the editor
 * save target and the export endpoint. User-scoped belt-and-suspenders on top of
 * the BelongsToUser global scope.
 */
class CoverLetterVersionPolicy
{
    public function view(User $user, CoverLetterVersion $version): bool
    {
        return $version->user_id === $user->id;
    }

    public function update(User $user, CoverLetterVersion $version): bool
    {
        return $version->user_id === $user->id;
    }
}

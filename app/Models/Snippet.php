<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A reusable letter building block (T-54) — a standard closing, a referral
 * mention, etc. — the user inserts into the cover-letter editor. User-scoped via
 * the BelongsToUser global scope.
 */
#[Fillable(['user_id', 'label', 'content_md', 'position'])]
class Snippet extends Model
{
    use BelongsToUser;

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }
}

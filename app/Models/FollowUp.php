<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id', 'application_id', 'due_at', 'kind', 'status', 'draft_content',
])]
class FollowUp extends Model
{
    use BelongsToUser, HasFactory;

    /** Statuses that mean the follow-up is still awaiting the user's action. */
    public const ACTIVE_STATUSES = ['pending', 'drafted'];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id', 'application_id', 'name', 'role', 'email', 'linkedin_url', 'notes',
])]
class Contact extends Model
{
    use BelongsToUser, HasFactory;

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}

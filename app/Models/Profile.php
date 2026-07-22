<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'user_id', 'headline', 'summary', 'resume_file_id', 'resume_text',
    'voice_profile', 'target_roles', 'target_locations', 'target_comp_min_cents',
])]
class Profile extends Model
{
    use BelongsToUser;

    protected function casts(): array
    {
        return [
            'voice_profile' => 'array',
            'target_roles' => 'array',
            'target_locations' => 'array',
            'target_comp_min_cents' => 'integer',
        ];
    }
}

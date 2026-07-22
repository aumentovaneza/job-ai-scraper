<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'application_id', 'active_version_id'])]
class CoverLetter extends Model
{
    use BelongsToUser;

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(CoverLetterVersion::class);
    }

    public function activeVersion(): BelongsTo
    {
        return $this->belongsTo(CoverLetterVersion::class, 'active_version_id');
    }
}

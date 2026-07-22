<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id', 'cover_letter_id', 'content_md', 'generation_params',
    'variant_label', 'parent_version_id', 'was_sent',
])]
class CoverLetterVersion extends Model
{
    use BelongsToUser;

    protected function casts(): array
    {
        return [
            'generation_params' => 'array',
            'was_sent' => 'boolean',
        ];
    }

    public function coverLetter(): BelongsTo
    {
        return $this->belongsTo(CoverLetter::class);
    }

    public function parentVersion(): BelongsTo
    {
        return $this->belongsTo(CoverLetterVersion::class, 'parent_version_id');
    }
}

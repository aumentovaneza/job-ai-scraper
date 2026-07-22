<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id', 'job_posting_id', 'score', 'reasoning',
    'strengths', 'gaps', 'computed_at', 'prompt_version',
])]
class MatchScore extends Model
{
    use BelongsToUser;

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'strengths' => 'array',
            'gaps' => 'array',
            'computed_at' => 'datetime',
        ];
    }

    public function jobPosting(): BelongsTo
    {
        return $this->belongsTo(JobPosting::class);
    }
}

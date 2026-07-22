<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bridge between a shared JobPosting and a user's JobSource. Not user-scoped
 * directly — reached through the owning JobSource.
 */
#[Fillable(['job_posting_id', 'job_source_id', 'source_url', 'first_seen_at'])]
class JobSourceHit extends Model
{
    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
        ];
    }

    public function jobPosting(): BelongsTo
    {
        return $this->belongsTo(JobPosting::class);
    }

    public function jobSource(): BelongsTo
    {
        return $this->belongsTo(JobSource::class);
    }
}

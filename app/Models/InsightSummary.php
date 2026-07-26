<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A weekly Claude-written narrative over the user's application analytics (T-61).
 * `metrics` snapshots the numbers the narrative described, so the dashboard can
 * show "as of last Monday" figures alongside the live ones.
 */
#[Fillable([
    'user_id', 'summary_md', 'metrics', 'period_start', 'period_end', 'generated_at',
])]
class InsightSummary extends Model
{
    use BelongsToUser;

    protected function casts(): array
    {
        return [
            'metrics' => 'array',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'generated_at' => 'datetime',
        ];
    }
}

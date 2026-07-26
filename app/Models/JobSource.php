<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id', 'type', 'url', 'config', 'cron_schedule', 'active', 'last_scraped_at',
    'hires_internationally', 'timezone_overlap',
])]
class JobSource extends Model
{
    use BelongsToUser, HasFactory;

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'active' => 'boolean',
            'hires_internationally' => 'boolean',
            'last_scraped_at' => 'datetime',
        ];
    }

    /**
     * Source-level acceptability penalty (0 = fully acceptable, higher = worse).
     * Downstream ranking pushes postings whose only sources score high to the
     * bottom so the matcher doesn't spend effort on jobs the user can't accept.
     */
    public function acceptabilityPenalty(): int
    {
        $penalty = $this->hires_internationally ? 0 : 2;

        $penalty += match ($this->timezone_overlap) {
            'partial' => 1,
            'strict' => 2,
            default => 0,
        };

        return $penalty;
    }

    public function hits(): HasMany
    {
        return $this->hasMany(JobSourceHit::class);
    }
}

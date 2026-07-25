<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id', 'type', 'url', 'config', 'cron_schedule', 'active', 'last_scraped_at',
])]
class JobSource extends Model
{
    use BelongsToUser, HasFactory;

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'active' => 'boolean',
            'last_scraped_at' => 'datetime',
        ];
    }

    public function hits(): HasMany
    {
        return $this->hasMany(JobSourceHit::class);
    }
}

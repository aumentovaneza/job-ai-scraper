<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id', 'application_id', 'type', 'from_stage_id',
    'to_stage_id', 'actor', 'occurred_at', 'metadata',
])]
class ApplicationEvent extends Model
{
    use BelongsToUser;

    /** Application first created from a job (carries the initial stage). */
    public const TYPE_CREATED = 'created';

    /** Moved between pipeline stages (carries from/to stage). */
    public const TYPE_STAGE_CHANGED = 'stage_changed';

    /** A free-text note the user logged (body in metadata). */
    public const TYPE_NOTE = 'note';

    /** A contact was attached (contact id/name in metadata). */
    public const TYPE_CONTACT_ADDED = 'contact_added';

    /** A follow-up was drafted by the system (T-45). */
    public const TYPE_FOLLOW_UP_DRAFTED = 'follow_up_drafted';

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function fromStage(): BelongsTo
    {
        return $this->belongsTo(ApplicationStage::class, 'from_stage_id');
    }

    public function toStage(): BelongsTo
    {
        return $this->belongsTo(ApplicationStage::class, 'to_stage_id');
    }
}

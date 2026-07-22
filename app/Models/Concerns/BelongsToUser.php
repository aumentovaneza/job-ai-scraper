<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Multi-tenant isolation primitive (PLAN.md §7). Applied to every user-owned
 * model. When a user is authenticated (the web/SPA path), a global scope
 * transparently filters every query to that user's rows, and new records get
 * their user_id stamped automatically.
 *
 * In unauthenticated contexts (console commands, queue workers) the scope is a
 * no-op — system jobs legitimately operate across users and set user_id
 * explicitly. Ownership on API endpoints is additionally enforced by Policies
 * (T-05 onward); the "user B cannot access user A's data" feature test is a
 * mandatory checklist item for every user-scoped endpoint.
 */
trait BelongsToUser
{
    protected static function bootBelongsToUser(): void
    {
        static::addGlobalScope('user', function (Builder $builder) {
            if (Auth::check()) {
                $builder->where($builder->getModel()->getTable().'.user_id', Auth::id());
            }
        });

        static::creating(function ($model) {
            if (empty($model->user_id) && Auth::check()) {
                $model->user_id = Auth::id();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

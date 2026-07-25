<?php

namespace App\Exceptions\Ai;

use RuntimeException;

/**
 * Thrown pre-call when a user has reached their daily or weekly AI spend cap
 * (PLAN.md §7). One user's exhausted budget must never block another user's
 * work, so this is always scoped to a single user and enforced before any
 * request leaves the app.
 */
class AiBudgetExceededException extends RuntimeException
{
    public function __construct(
        public readonly string $window,        // 'daily' | 'weekly'
        public readonly int $spentCents,
        public readonly int $capCents,
    ) {
        parent::__construct(sprintf(
            'AI %s spend cap reached: $%.2f of $%.2f used.',
            $window,
            $spentCents / 100,
            $capCents / 100,
        ));
    }
}

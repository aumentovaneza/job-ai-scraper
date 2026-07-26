<?php

namespace App\Services\Ai;

use App\Models\AiCall;
use App\Models\User;

/**
 * The soft regeneration cap for cover letters (T-56). Unlike the hard spend cap
 * (enforced pre-call inside the AiClient), this never blocks — once the user has
 * generated letters SOFT_CAP times today it just surfaces a nudge suggesting they
 * edit an existing draft instead of regenerating.
 *
 * "Today" is measured in the user's own timezone, matching SpendTracker so the
 * two windows agree.
 */
class LetterQuota
{
    /** Letter-related ai_calls purposes counted toward the daily soft cap. */
    public const PURPOSES = ['letter', 'letter_paragraph'];

    /** Regenerations per day before we start nudging. */
    public const SOFT_CAP = 5;

    /**
     * How many letter-related model calls the user has made today (their tz).
     */
    public function countToday(User $user): int
    {
        $start = now($user->timezone ?: 'UTC')->startOfDay();

        return AiCall::query()
            ->where('user_id', $user->id)
            ->whereIn('purpose', self::PURPOSES)
            ->where('created_at', '>=', $start)
            ->count();
    }

    /**
     * A nudge string once the soft cap is reached, or null while under it. The
     * action still proceeds — this is advisory only.
     */
    public function nudgeFor(User $user): ?string
    {
        if ($this->countToday($user) < self::SOFT_CAP) {
            return null;
        }

        return "You've generated letters ".self::SOFT_CAP.' times today — '
            .'try editing an existing draft instead of regenerating.';
    }
}

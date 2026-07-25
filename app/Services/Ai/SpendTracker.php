<?php

namespace App\Services\Ai;

use App\Exceptions\Ai\AiBudgetExceededException;
use App\Models\AiCall;
use App\Models\User;

/**
 * Per-user AI spend accounting over the ai_calls ledger (T-11, PLAN.md §7).
 *
 * Day and week boundaries are computed in the user's own timezone so "today" and
 * "this week" mean what the user expects. Caps are opt-in: a null cap means the
 * user hasn't configured one and spend is unlimited for that window.
 */
class SpendTracker
{
    public function spentTodayCents(User $user): int
    {
        $start = now($user->timezone ?: 'UTC')->startOfDay();

        return $this->sumSince($user, $start);
    }

    public function spentThisWeekCents(User $user): int
    {
        $start = now($user->timezone ?: 'UTC')->startOfWeek();

        return $this->sumSince($user, $start);
    }

    /**
     * Enforce the pre-call gate. Throws if a configured daily or weekly cap has
     * already been reached. One user's exhausted budget never touches another's.
     */
    public function assertWithinCaps(User $user): void
    {
        $daily = $user->daily_ai_spend_cap_cents;
        if ($daily !== null) {
            $spent = $this->spentTodayCents($user);
            if ($spent >= $daily) {
                throw new AiBudgetExceededException('daily', $spent, $daily);
            }
        }

        $weekly = $user->weekly_ai_spend_cap_cents;
        if ($weekly !== null) {
            $spent = $this->spentThisWeekCents($user);
            if ($spent >= $weekly) {
                throw new AiBudgetExceededException('weekly', $spent, $weekly);
            }
        }
    }

    private function sumSince(User $user, \DateTimeInterface $start): int
    {
        // Query by explicit user_id: in queued jobs (unauthenticated) the
        // BelongsToUser scope is a no-op, so scoping must be explicit here.
        return (int) AiCall::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', $start)
            ->sum('cost_cents');
    }
}

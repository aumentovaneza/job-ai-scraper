<?php

namespace App\Support;

use App\Models\ApplicationStage;
use App\Models\User;

/**
 * The canonical starter pipeline seeded for every new user (T-40, PLAN.md §4).
 * Users can rename/reorder/add/remove stages afterwards via the stages CRUD, so
 * these are only defaults — the seed runs once at account creation.
 *
 * `is_terminal` marks an end state (no further movement expected); `is_success`
 * marks a terminal state that counts as a win for conversion analytics (T-60).
 */
class DefaultStages
{
    /**
     * Ordered default stages: [name, is_terminal, is_success].
     *
     * @var list<array{0: string, 1: bool, 2: bool}>
     */
    public const STAGES = [
        ['Saved', false, false],
        ['Applied', false, false],
        ['Recruiter Reply', false, false],
        ['Phone Screen', false, false],
        ['Technical', false, false],
        ['Onsite', false, false],
        ['Offer', false, false],
        ['Accepted', true, true],
        ['Rejected', true, false],
        ['Withdrawn', true, false],
    ];

    /**
     * Seed the default pipeline for a user. Idempotent: a no-op if the user
     * already has any stages, so it is safe to call from both the invite-accept
     * flow and the owner seeder (which may re-run on every deploy).
     */
    public static function seedFor(User $user): void
    {
        if (ApplicationStage::withoutGlobalScopes()->where('user_id', $user->id)->exists()) {
            return;
        }

        $now = now();

        $rows = [];
        foreach (self::STAGES as $position => [$name, $isTerminal, $isSuccess]) {
            $rows[] = [
                'user_id' => $user->id,
                'name' => $name,
                'position' => $position,
                'is_terminal' => $isTerminal,
                'is_success' => $isSuccess,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        ApplicationStage::insert($rows);
    }
}

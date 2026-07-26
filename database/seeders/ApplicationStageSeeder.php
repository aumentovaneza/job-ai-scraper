<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\DefaultStages;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Seeds the default application pipeline (T-40, PLAN.md §4) for every user that
 * has none. Backfills accounts provisioned before the stages feature existed and
 * repairs any user whose pipeline was never seeded.
 *
 * Idempotent — {@see DefaultStages::seedFor()} is a no-op for a user that already
 * has stages, so this is safe to re-run on every deploy.
 */
class ApplicationStageSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $seeded = 0;

        User::query()->chunkById(200, function ($users) use (&$seeded): void {
            foreach ($users as $user) {
                if ($user->applicationStages()->exists()) {
                    continue;
                }

                DefaultStages::seedFor($user);
                $seeded++;
            }
        });

        $this->command?->info("Default pipeline seeded for {$seeded} user(s).");
    }
}

<?php

namespace App\Console\Commands;

use App\Jobs\GenerateWeeklyDigestJob;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Dispatches the weekly insights digest for every user (Phase 6, T-61/T-63).
 * Registered to run weekly in bootstrap/app.php. Each user's digest is built off
 * the request path by GenerateWeeklyDigestJob, which computes analytics, narrates
 * them with Claude, stores the summary, and emails it — skipping empty accounts.
 *
 *   php artisan insights:digest              # all users
 *   php artisan insights:digest --user=5     # a single user (useful for testing)
 */
class InsightsDigestCommand extends Command
{
    protected $signature = 'insights:digest
        {--user= : Only build the digest for this user id}';

    protected $description = 'Generate and email the weekly insights digest for each user';

    public function handle(): int
    {
        $only = $this->option('user');

        $count = 0;
        User::query()
            ->when($only, fn ($q) => $q->whereKey($only))
            ->select('id')
            ->chunkById(200, function ($users) use (&$count) {
                foreach ($users as $user) {
                    GenerateWeeklyDigestJob::dispatch($user->id);
                    $count++;
                }
            });

        $this->info("Dispatched {$count} weekly digest job(s).");

        return self::SUCCESS;
    }
}

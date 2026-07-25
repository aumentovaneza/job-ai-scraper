<?php

namespace App\Console\Commands;

use App\Jobs\ScrapeAtsFeedJob;
use App\Jobs\ScrapeCareerPageJob;
use App\Models\JobSource;
use Cron\CronExpression;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Dispatches per-source scrape jobs for every active JobSource whose
 * `cron_schedule` is due right now (T-26). Registered to run every minute in
 * bootstrap/app.php, so each source effectively honours its own cron.
 *
 *   php artisan scrape:sources            # due sources across all users
 *   php artisan scrape:sources --force    # ignore cron, dispatch all active
 *   php artisan scrape:sources --source=5 # a single source (implies --force)
 */
class ScrapeSourcesCommand extends Command
{
    protected $signature = 'scrape:sources {--source= : Only this JobSource id} {--force : Ignore cron_schedule and dispatch every active source}';

    protected $description = 'Dispatch scrape jobs for job sources that are due';

    public function handle(): int
    {
        $force = (bool) $this->option('force') || filled($this->option('source'));

        $sources = JobSource::withoutGlobalScopes()
            ->where('active', true)
            ->when($this->option('source'), fn (Builder $q, $id) => $q->whereKey($id))
            ->get();

        $dispatched = 0;

        foreach ($sources as $source) {
            if (! $force && ! $this->isDue($source)) {
                continue;
            }

            if ($this->dispatchFor($source)) {
                $dispatched++;
            }
        }

        $this->info("Dispatched {$dispatched} scrape job(s) from {$sources->count()} active source(s).");

        return self::SUCCESS;
    }

    protected function isDue(JobSource $source): bool
    {
        $schedule = $source->cron_schedule;

        if (blank($schedule)) {
            return false; // no schedule → only runs via --force/--source
        }

        try {
            return (new CronExpression($schedule))->isDue(now());
        } catch (Throwable $e) {
            $this->warn("Source #{$source->id} has an invalid cron schedule ('{$schedule}'): {$e->getMessage()}");

            return false;
        }
    }

    protected function dispatchFor(JobSource $source): bool
    {
        return match ($source->type) {
            'ats_feed' => tap(true, fn () => ScrapeAtsFeedJob::dispatch($source->id)),
            'career_page' => tap(true, fn () => ScrapeCareerPageJob::dispatch($source->id)),
            default => tap(false, fn () => $this->warn("Source #{$source->id} has unsupported type '{$source->type}', skipping.")),
        };
    }
}

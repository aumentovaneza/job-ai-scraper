<?php

namespace App\Console\Commands;

use App\Jobs\GenerateFollowUpJob;
use App\Models\Application;
use App\Models\ApplicationEvent;
use App\Models\FollowUp;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Scans for applications that have gone quiet and dispatches GenerateFollowUpJob
 * to draft a nudge for each (T-45). Registered to run nightly in
 * bootstrap/app.php.
 *
 *   php artisan follow-ups:draft                 # stale (>7d) applied applications
 *   php artisan follow-ups:draft --days=14       # custom staleness window
 *   php artisan follow-ups:draft --application=5 # a single application (ignores staleness)
 *
 * Candidate filters are cheap SQL; the job re-verifies staleness, terminal state,
 * and existing drafts before spending a Claude call.
 */
class DraftFollowUpsCommand extends Command
{
    protected $signature = 'follow-ups:draft
        {--days=7 : Days without a stage change before a follow-up is suggested}
        {--application= : Only this application id (implies staleness is ignored)}';

    protected $description = 'Draft follow-up nudges for applications that have gone quiet';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $only = $this->option('application');
        $threshold = now()->subDays($days);

        $applications = Application::withoutGlobalScopes()
            ->whereNotNull('applied_at')
            // Skip applications already terminal (accepted/rejected/withdrawn).
            ->where(fn (Builder $q) => $q
                ->whereNull('current_stage_id')
                ->orWhereHas('currentStage', fn (Builder $s) => $s->where('is_terminal', false)))
            // Skip applications that already have a follow-up awaiting action.
            ->whereDoesntHave('followUps', fn (Builder $f) => $f->whereIn('status', FollowUp::ACTIVE_STATUSES))
            ->when($only, fn (Builder $q) => $q->whereKey($only))
            ->when(! $only, fn (Builder $q) => $q->whereRaw(
                '(select max(occurred_at) from application_events ae '
                .'where ae.application_id = applications.id and ae.type in (?, ?)) <= ?',
                [ApplicationEvent::TYPE_CREATED, ApplicationEvent::TYPE_STAGE_CHANGED, $threshold],
            ))
            ->get();

        foreach ($applications as $application) {
            GenerateFollowUpJob::dispatch($application->id, $days);
        }

        $this->info("Dispatched {$applications->count()} follow-up draft job(s).");

        return self::SUCCESS;
    }
}

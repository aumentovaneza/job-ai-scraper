<?php

namespace App\Console\Commands;

use App\Jobs\EnrichJobJob;
use App\Jobs\MatchJobToProfileJob;
use App\Models\JobPosting;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Manually re-run AI enrichment and/or match-scoring over existing postings.
 *
 * Normally enrichment + scoring fire only once, as the tail of the scrape
 * pipeline (DedupeJobJob → EnrichJobJob → MatchJobToProfileJob), and both
 * short-circuit on their caches. This command lets an operator re-drive that
 * work on demand — e.g. after a prompt-version bump.
 *
 *   php artisan jobs:reprocess                       # re-enrich all postings (fans out scoring)
 *   php artisan jobs:reprocess --posting=5 --force   # force a fresh enrich+score for one posting
 *   php artisan jobs:reprocess --score-only --force  # re-score only, skip enrichment
 *   php artisan jobs:reprocess --score-only --user=3 # re-score only, for one user
 *
 * Requires queue workers on the `ai` queue (Horizon).
 */
class ReprocessJobsCommand extends Command
{
    protected $signature = 'jobs:reprocess
        {--posting= : Only this JobPosting id (default: all postings with jd_text)}
        {--user= : Score only for this user id (default: all tracking users)}
        {--score-only : Skip enrichment; re-dispatch scoring only}
        {--force : Bypass enrichment/score caches (re-spend on Claude)}';

    protected $description = 'Re-run AI enrichment and/or match-scoring over existing job postings';

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $scoreOnly = (bool) $this->option('score-only');
        $userFilter = filled($this->option('user')) ? (int) $this->option('user') : null;

        $postings = JobPosting::query()
            ->whereNotNull('jd_text')
            ->when($this->option('posting'), fn (Builder $q, $id) => $q->whereKey($id))
            ->get(['id']);

        $dispatched = 0;

        foreach ($postings as $posting) {
            if ($scoreOnly) {
                foreach ($this->trackingUserIds($posting->id, $userFilter) as $userId) {
                    MatchJobToProfileJob::dispatch($userId, $posting->id, $force);
                    $dispatched++;
                }

                continue;
            }

            // Enrichment is user-agnostic but the Claude call is BYOK-funded, so
            // it needs a tracking user to pay. Skip postings nobody tracks.
            $fundingUserId = $this->trackingUserIds($posting->id, $userFilter)->first();

            if ($fundingUserId === null) {
                continue;
            }

            EnrichJobJob::dispatch($posting->id, $fundingUserId, $force);
            $dispatched++;
        }

        $verb = $scoreOnly ? 'scoring' : 'enrichment';
        $this->info("Dispatched {$dispatched} {$verb} job(s) across {$postings->count()} posting(s).");

        return self::SUCCESS;
    }

    /**
     * User ids tracking this posting (via their job sources), optionally
     * narrowed to a single user. Runs on the raw tables to bypass the
     * JobSource BelongsToUser scope, mirroring EnrichJobJob::dispatchMatching.
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    private function trackingUserIds(int $postingId, ?int $userFilter)
    {
        return DB::table('job_source_hits')
            ->join('job_sources', 'job_sources.id', '=', 'job_source_hits.job_source_id')
            ->where('job_source_hits.job_posting_id', $postingId)
            ->when($userFilter, fn ($q, $id) => $q->where('job_sources.user_id', $id))
            ->distinct()
            ->pluck('job_sources.user_id')
            ->map(fn ($id) => (int) $id);
    }
}

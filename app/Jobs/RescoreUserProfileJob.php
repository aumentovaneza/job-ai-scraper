<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Re-score every posting a user tracks after their profile/resume changes.
 *
 * A profile edit changes the profile_version baked into each MatchScore's
 * input_hash (see MatchJobToProfileJob::inputHash), which invalidates the
 * cache — but nothing re-dispatches scoring, so stale scores would linger until
 * the posting is next freshly scraped. ProfileController fans this out on any
 * matching-relevant profile/resume change so the user's scores stay current.
 *
 * Fan-out only: no Claude call here (runs on the default queue). Each
 * MatchJobToProfileJob is dispatched WITHOUT force — the changed profile_version
 * already invalidates the hash, so genuinely-unchanged inputs still hit the
 * cache and only affected scores re-spend.
 */
class RescoreUserProfileJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $userId,
    ) {}

    public function handle(): void
    {
        // Postings this user tracks (via their own sources) that have been
        // enriched — mirror EnrichJobJob::dispatchMatching, but keyed on the
        // user rather than the posting. Runs on the raw tables because
        // JobSource carries a BelongsToUser scope that would filter to nothing
        // in this unauthenticated worker context.
        $postingIds = DB::table('job_source_hits')
            ->join('job_sources', 'job_sources.id', '=', 'job_source_hits.job_source_id')
            ->join('job_postings', 'job_postings.id', '=', 'job_source_hits.job_posting_id')
            ->where('job_sources.user_id', $this->userId)
            ->whereNotNull('job_postings.enrichment')
            ->distinct()
            ->pluck('job_source_hits.job_posting_id');

        foreach ($postingIds as $postingId) {
            MatchJobToProfileJob::dispatch($this->userId, (int) $postingId);
        }
    }
}

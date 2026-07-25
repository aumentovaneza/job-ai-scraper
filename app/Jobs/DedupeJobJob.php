<?php

namespace App\Jobs;

use App\Models\JobSource;
use App\Services\JobIngestionService;
use App\Support\NormalizedJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Dedup + persist a single scraped posting (T-24). Scrape jobs fan out one of
 * these per normalized posting; it computes the canonical `source_hash`,
 * upserts the JobPosting, records the JobSourceHit, and — only when the posting
 * is brand new — kicks off the Voyage embedding pass (T-25).
 *
 * Idempotent: re-running with the same payload just refreshes `last_seen_at`
 * and the (unique) JobSourceHit, so at-least-once delivery is safe.
 */
class DedupeJobJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $jobData  serialized NormalizedJob
     */
    public function __construct(
        public readonly array $jobData,
        public readonly int $jobSourceId,
    ) {
        $this->onQueue('scraping');
    }

    public function handle(JobIngestionService $ingestion): void
    {
        $source = JobSource::withoutGlobalScopes()->find($this->jobSourceId);

        if ($source === null) {
            return; // source was deleted between scrape and dedup
        }

        $result = $ingestion->ingest(NormalizedJob::fromArray($this->jobData), $source);

        if ($result->isNew) {
            EmbedJobPostingJob::dispatch($result->posting->id);
            // The scraping user's BYOK key funds enrichment; the result is
            // cached on the shared posting for every user (T-31).
            EnrichJobJob::dispatch($result->posting->id, $source->user_id);
        }
    }
}

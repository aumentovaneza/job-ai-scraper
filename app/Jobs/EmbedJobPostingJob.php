<?php

namespace App\Jobs;

use App\Models\JobPosting;
use App\Models\JobSourceHit;
use App\Services\VoyageClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Compute a Voyage embedding for a newly-created posting and run the semantic
 * second-pass dedup (T-25): embed `title + jd_text[:2000]`, store it in the
 * pgvector `embedding` column, then look for an existing posting with cosine
 * similarity > 0.92 and merge into the older canonical record.
 *
 * Embedding/vector search is Postgres-only; on other drivers (sqlite tests) or
 * when Voyage isn't configured, this job is a no-op so the pipeline still works.
 */
class EmbedJobPostingJob implements ShouldQueue
{
    use Queueable;

    /** Cosine similarity above which two postings are considered the same job. */
    protected const MERGE_THRESHOLD = 0.92;

    public int $tries = 3;

    public function __construct(public readonly int $jobPostingId)
    {
        $this->onQueue('scraping');
    }

    public function handle(VoyageClient $voyage): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql' || ! $voyage->configured()) {
            return;
        }

        $posting = JobPosting::find($this->jobPostingId);

        if ($posting === null) {
            return;
        }

        $vector = $voyage->embed($this->embeddingInput($posting));

        if ($vector === []) {
            return;
        }

        $literal = '['.implode(',', $vector).']';

        DB::update(
            'UPDATE job_postings SET embedding = ?::vector WHERE id = ?',
            [$literal, $posting->id],
        );

        $this->mergeNearDuplicate($posting->id, $literal);
    }

    /**
     * Find the nearest other embedded posting; if it's within the merge
     * threshold, fold this posting's source hits into the older canonical one
     * and delete this (duplicate) record.
     */
    protected function mergeNearDuplicate(int $postingId, string $literal): void
    {
        $match = DB::selectOne(
            <<<'SQL'
            SELECT id, first_seen_at, 1 - (embedding <=> ?::vector) AS similarity
            FROM job_postings
            WHERE id <> ? AND embedding IS NOT NULL
            ORDER BY embedding <=> ?::vector
            LIMIT 1
            SQL,
            [$literal, $postingId, $literal],
        );

        if ($match === null || (float) $match->similarity < self::MERGE_THRESHOLD) {
            return;
        }

        // Keep the older posting as canonical; drop the newer duplicate.
        [$canonicalId, $duplicateId] = $match->id < $postingId
            ? [$match->id, $postingId]
            : [$postingId, $match->id];

        DB::transaction(function () use ($canonicalId, $duplicateId): void {
            $existingSourceIds = JobSourceHit::where('job_posting_id', $canonicalId)
                ->pluck('job_source_id')
                ->all();

            // Move hits from unique sources over; discard hits that would
            // collide with the canonical's existing (posting, source) pair.
            JobSourceHit::where('job_posting_id', $duplicateId)
                ->whereNotIn('job_source_id', $existingSourceIds)
                ->update(['job_posting_id' => $canonicalId]);

            JobPosting::whereKey($duplicateId)->delete();
        });

        Log::info('Merged semantic duplicate posting', [
            'canonical_id' => $canonicalId,
            'duplicate_id' => $duplicateId,
            'similarity' => round((float) $match->similarity, 4),
        ]);
    }

    protected function embeddingInput(JobPosting $posting): string
    {
        return trim($posting->title.' '.Str::limit((string) $posting->jd_text, 2000, ''));
    }
}

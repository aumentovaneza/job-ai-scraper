<?php

namespace App\Services;

use App\Models\JobPosting;
use App\Models\JobSource;
use App\Models\JobSourceHit;
use App\Support\NormalizedJob;
use Illuminate\Support\Facades\DB;

/**
 * The single place canonical dedup happens (T-24). Given a normalized posting
 * and the source it came from:
 *   - derive `source_hash` = sha256(company + normalized title + location)
 *   - if a JobPosting already exists for that hash, refresh its "last seen"
 *     footprint instead of creating a duplicate
 *   - otherwise create the canonical posting
 *   - either way, record a JobSourceHit linking the posting to this source
 *     (the same job surfacing from multiple boards is one posting, many hits)
 *
 * Runs inside a transaction so a concurrent scrape can't create two postings
 * for the same hash. `isNew` tells the caller whether to kick off embedding.
 */
class JobIngestionService
{
    public function ingest(NormalizedJob $job, JobSource $source): IngestionResult
    {
        $hash = $job->sourceHash();
        $now = now();

        return DB::transaction(function () use ($job, $source, $hash, $now): IngestionResult {
            $existing = JobPosting::where('source_hash', $hash)->lockForUpdate()->first();

            if ($existing) {
                $existing->fill($this->refreshableAttributes($job));
                $existing->last_seen_at = $now;
                $existing->save();

                $posting = $existing;
                $isNew = false;
            } else {
                $posting = JobPosting::create(array_merge(
                    ['source_hash' => $hash],
                    $this->refreshableAttributes($job),
                    ['first_seen_at' => $now, 'last_seen_at' => $now],
                ));

                $isNew = true;
            }

            JobSourceHit::firstOrCreate(
                ['job_posting_id' => $posting->id, 'job_source_id' => $source->id],
                ['source_url' => $job->sourceUrl, 'first_seen_at' => $now],
            );

            return new IngestionResult($posting, $isNew);
        });
    }

    /**
     * Fields safe to (re)write on every sighting. Excludes source_hash,
     * first_seen_at, and the enrichment/embedding columns owned by later phases.
     *
     * @return array<string, mixed>
     */
    protected function refreshableAttributes(NormalizedJob $job): array
    {
        return [
            'title' => $job->title,
            'company' => $job->company,
            'location' => $job->location,
            'remote_type' => $job->remoteType,
            'salary_min' => $job->salaryMin,
            'salary_max' => $job->salaryMax,
            'salary_currency' => $job->salaryCurrency,
            'jd_text' => $job->jdText,
            'jd_html_snapshot' => $job->jdHtmlSnapshot,
            'apply_url' => $job->applyUrl,
            'posted_at' => $job->postedAt,
            'tags' => $job->tags ?: null,
            'raw_extract' => $job->rawExtract ?: null,
        ];
    }
}

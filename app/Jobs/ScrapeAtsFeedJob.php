<?php

namespace App\Jobs;

use App\Models\JobSource;
use App\Services\Ats\AtsFeedScraper;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Scrape one ATS feed source (Greenhouse/Lever/Workable/Ashby) — a direct JSON
 * fetch, no Firecrawl (T-22). Normalizes every posting and fans out a
 * DedupeJobJob per posting so canonicalization/embedding stay isolated and
 * independently retryable. Marks the source scraped on success.
 */
class ScrapeAtsFeedJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $jobSourceId)
    {
        $this->onQueue('scraping');
    }

    public function handle(AtsFeedScraper $scraper): void
    {
        $source = JobSource::withoutGlobalScopes()->find($this->jobSourceId);

        if ($source === null || ! $source->active) {
            return;
        }

        $postings = $scraper->scrape($source);

        foreach ($postings as $posting) {
            DedupeJobJob::dispatch($posting->toArray(), $source->id);
        }

        $source->forceFill(['last_scraped_at' => now()])->save();

        Log::info('Scraped ATS feed', [
            'job_source_id' => $source->id,
            'provider' => $source->config['provider'] ?? null,
            'postings' => count($postings),
        ]);
    }
}

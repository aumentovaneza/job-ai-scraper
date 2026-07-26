<?php

namespace App\Jobs;

use App\Models\JobSource;
use App\Services\JsonApi\JsonApiScraper;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Scrape one generic JSON-API source (RemoteOK/Remotive/Himalayas/Arbeitnow or
 * any config-driven JSON feed) — a direct JSON fetch, no Firecrawl (T-31).
 * Normalizes every posting via the source's field map and fans out a
 * DedupeJobJob per posting so canonicalization/embedding stay isolated and
 * independently retryable. Marks the source scraped on success.
 */
class ScrapeJsonApiJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $jobSourceId)
    {
        $this->onQueue('scraping');
    }

    public function handle(JsonApiScraper $scraper): void
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

        Log::info('Scraped JSON API', [
            'job_source_id' => $source->id,
            'url' => $source->url,
            'postings' => count($postings),
        ]);
    }
}

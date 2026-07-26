<?php

namespace App\Jobs;

use App\Models\JobSource;
use App\Services\RssFeedScraper;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Scrape one RSS/Atom job feed source (T-21) — a direct XML fetch parsed with
 * SimpleXML, no Firecrawl. Same fan-out shape as the ATS job: normalize every
 * item, then dispatch a DedupeJobJob per posting so canonicalization/embedding
 * stay isolated and independently retryable. Marks the source scraped on success.
 */
class ScrapeRssJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $jobSourceId)
    {
        $this->onQueue('scraping');
    }

    public function handle(RssFeedScraper $scraper): void
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

        Log::info('Scraped RSS feed', [
            'job_source_id' => $source->id,
            'url' => $source->url,
            'postings' => count($postings),
        ]);
    }
}

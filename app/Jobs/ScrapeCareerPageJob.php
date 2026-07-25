<?php

namespace App\Jobs;

use App\Models\JobSource;
use App\Services\CareerPageScraper;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Scrape one company career page via Firecrawl `/extract` with a structured
 * schema (T-23). Same fan-out shape as the ATS job: normalize, then dispatch a
 * DedupeJobJob per posting, then mark the source scraped.
 */
class ScrapeCareerPageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $jobSourceId)
    {
        $this->onQueue('scraping');
    }

    public function handle(CareerPageScraper $scraper): void
    {
        $source = JobSource::withoutGlobalScopes()->find($this->jobSourceId);

        if ($source === null || ! $source->active) {
            return;
        }

        if (! $scraper->configured()) {
            Log::warning('Skipping career-page scrape: Firecrawl not configured', [
                'job_source_id' => $source->id,
            ]);

            return;
        }

        $postings = $scraper->scrape($source);

        foreach ($postings as $posting) {
            DedupeJobJob::dispatch($posting->toArray(), $source->id);
        }

        $source->forceFill(['last_scraped_at' => now()])->save();

        Log::info('Scraped career page', [
            'job_source_id' => $source->id,
            'url' => $source->url,
            'postings' => count($postings),
        ]);
    }
}

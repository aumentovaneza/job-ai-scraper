<?php

namespace App\Services\Ats;

use App\Models\JobSource;
use App\Support\NormalizedJob;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Fetches and normalizes an ATS job feed (T-22). Direct JSON — no Firecrawl.
 * Pure read: returns NormalizedJob[] and never writes to the DB, so it backs
 * both the queued ScrapeAtsFeedJob and the synchronous test-scrape endpoint.
 */
class AtsFeedScraper
{
    /** @var array<string, AtsProvider> */
    protected array $providers;

    public function __construct()
    {
        $this->providers = collect([
            new GreenhouseProvider,
            new LeverProvider,
            new WorkableProvider,
            new AshbyProvider,
        ])->keyBy(fn (AtsProvider $p) => $p->key())->all();
    }

    /**
     * @return array<int, string>
     */
    public function supportedProviders(): array
    {
        return array_keys($this->providers);
    }

    public function providerFor(JobSource $source): AtsProvider
    {
        $key = $source->config['provider'] ?? null;

        if (blank($key) || ! isset($this->providers[$key])) {
            throw new InvalidArgumentException(
                'Unsupported ATS provider: '.var_export($key, true).'. Supported: '.implode(', ', $this->supportedProviders())
            );
        }

        return $this->providers[$key];
    }

    /**
     * Fetch the feed for a source and return normalized postings.
     *
     * @return array<int, NormalizedJob>
     */
    public function scrape(JobSource $source): array
    {
        $provider = $this->providerFor($source);
        $url = $provider->feedUrl($source);

        try {
            $response = Http::acceptJson()
                ->timeout(30)
                ->retry(3, 500, throw: false)
                ->get($url);
        } catch (Throwable $e) {
            Log::error('ATS feed request failed', ['url' => $url, 'exception' => $e->getMessage()]);

            throw new RuntimeException("ATS feed request failed for {$url}: {$e->getMessage()}", previous: $e);
        }

        if ($response->failed()) {
            Log::error('ATS feed returned an error response', ['url' => $url, 'status' => $response->status()]);

            throw new RuntimeException("ATS feed {$url} returned HTTP {$response->status()}.");
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new RuntimeException("ATS feed {$url} returned a non-JSON payload.");
        }

        return $provider->parse($body, $source);
    }
}

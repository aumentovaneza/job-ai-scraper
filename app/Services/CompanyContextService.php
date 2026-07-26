<?php

namespace App\Services;

use App\Exceptions\FirecrawlException;
use App\Models\CompanyContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Grounds cover letters in real company facts (T-50). When a letter is
 * generated we fetch the company's homepage + `/about` + `/careers` via
 * Firecrawl `/scrape`, distill the markdown to plain factual text, and cache
 * it per company for CompanyContext::FRESH_DAYS so repeated letters for the
 * same company don't re-scrape.
 *
 * This is the hallucination guard from PLAN §7: company facts in letters come
 * from scraped pages, not the model's memory. Callers must degrade gracefully
 * — a letter can still be generated when this returns null (Firecrawl not
 * configured, no seed URL, or every page scrape failed).
 *
 * Scraped page content is untrusted data: callers must treat the returned text
 * as facts to reference, never as instructions to follow.
 */
class CompanyContextService
{
    /**
     * Relative paths scraped off the homepage origin, in priority order.
     *
     * @var array<int, string>
     */
    protected const PATHS = ['/', '/about', '/careers'];

    /**
     * Max characters of distilled facts we keep so the blob fits a prompt.
     */
    protected const MAX_CHARS = 6000;

    public function __construct(
        private readonly FirecrawlClient $firecrawl,
    ) {}

    /**
     * Return distilled company facts text, or null when nothing usable is
     * available. Serves fresh cached facts without scraping; otherwise scrapes
     * (when Firecrawl is configured and a seed URL is given) and caches the
     * result. Never throws — per-page scrape failures are tolerated.
     */
    public function factsFor(string $company, ?string $seedUrl = null): ?string
    {
        $companyKey = $this->normalizeKey($company);

        if ($companyKey === '') {
            return null;
        }

        $existing = CompanyContext::query()->where('company_key', $companyKey)->first();

        if ($existing !== null && $existing->isFresh()) {
            return $existing->facts;
        }

        // Without Firecrawl or a seed URL we can't refresh — serve any cached
        // facts (even stale) rather than nothing, and never throw.
        if (! $this->firecrawl->configured() || blank($seedUrl)) {
            return $existing?->facts;
        }

        $origin = $this->originFrom($seedUrl);

        if ($origin === null) {
            return $existing?->facts;
        }

        [$facts, $sourceUrls] = $this->scrapeOrigin($origin);

        if ($facts === null) {
            Log::warning('CompanyContextService: all page scrapes failed', [
                'company' => $company,
                'origin' => $origin,
            ]);

            return $existing?->facts;
        }

        CompanyContext::query()->updateOrCreate(
            ['company_key' => $companyKey],
            [
                'company' => $company,
                'facts' => $facts,
                'source_urls' => $sourceUrls,
                'fetched_at' => now(),
            ],
        );

        return $facts;
    }

    /**
     * Scrape the candidate pages off an origin, tolerating per-page failures.
     * Returns [distilled facts|null, source urls that succeeded].
     *
     * @return array{0: ?string, 1: array<int, string>}
     */
    protected function scrapeOrigin(string $origin): array
    {
        $chunks = [];
        $sourceUrls = [];

        foreach (self::PATHS as $path) {
            $url = $origin.$path;

            try {
                $data = $this->firecrawl->scrape($url);
            } catch (FirecrawlException $e) {
                Log::info('CompanyContextService: page scrape failed, skipping', [
                    'url' => $url,
                    'exception' => $e->getMessage(),
                ]);

                continue;
            }

            $markdown = trim((string) ($data['markdown'] ?? ''));

            if ($markdown === '') {
                continue;
            }

            $chunks[] = $markdown;
            $sourceUrls[] = $url;
        }

        if ($chunks === []) {
            return [null, []];
        }

        return [$this->distill(implode("\n\n", $chunks)), $sourceUrls];
    }

    /**
     * Collapse excessive whitespace and truncate to a prompt-friendly cap.
     */
    protected function distill(string $text): string
    {
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return Str::limit(trim($text), self::MAX_CHARS, '');
    }

    /**
     * Derive the homepage origin (scheme + host [+ port]) from a seed URL, or
     * null when the URL has no usable scheme/host.
     */
    protected function originFrom(string $seedUrl): ?string
    {
        $parts = parse_url(trim($seedUrl));

        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        $origin = $parts['scheme'].'://'.$parts['host'];

        if (! empty($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        return $origin;
    }

    /**
     * Normalize a company name to a stable cache key.
     */
    protected function normalizeKey(string $company): string
    {
        return Str::lower(trim($company));
    }
}

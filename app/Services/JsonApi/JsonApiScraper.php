<?php

namespace App\Services\JsonApi;

use App\Models\JobSource;
use App\Support\NormalizedJob;
use App\Support\NormalizesJobFields;
use App\Support\UrlGuard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use RuntimeException;
use Throwable;

/**
 * Generic JSON-API scraper (T-31). One config-driven adapter for any clean JSON
 * job feed — RemoteOK, Remotive, Himalayas, Arbeitnow, and as an escape hatch
 * for ATSes we haven't hardcoded — instead of a class per source.
 *
 * The source's `config` carries:
 *   - items_path: dot-path to the array of items (null = the body is the array)
 *   - headers:    request headers to merge (e.g. a User-Agent RemoteOK requires)
 *   - field_map:  target NormalizedJob field => source dot-path, OR an array of
 *                 candidate paths (first non-blank wins). The map says *where*;
 *                 this class hardcodes *how* to coerce each known target.
 *
 * Pure read: returns NormalizedJob[] and never writes to the DB, so it backs
 * both the queued ScrapeJsonApiJob and the synchronous test-scrape endpoint.
 */
class JsonApiScraper
{
    use NormalizesJobFields;

    /** Seconds before a queued fetch gives up. */
    public const DEFAULT_TIMEOUT = 30;

    /** Shorter budget for the synchronous test-scrape (it pins a web worker). */
    public const PREVIEW_TIMEOUT = 10;

    protected const MAX_REDIRECTS = 3;

    protected const MAX_BYTES = 8_000_000;

    /** Every NormalizedJob field a field_map may target. */
    public const ALLOWED_TARGETS = [
        'title', 'company', 'location', 'remote_type', 'salary', 'salary_min',
        'salary_max', 'salary_currency', 'jd_text', 'jd_html_snapshot',
        'apply_url', 'source_url', 'posted_at', 'tags',
    ];

    /**
     * Fetch the feed for a source and return normalized postings.
     *
     * @return array<int, NormalizedJob>
     */
    public function scrape(JobSource $source, ?int $timeout = null): array
    {
        $url = trim((string) $source->url);
        UrlGuard::assertPublicHttpUrl($url);

        $config = is_array($source->config) ? $source->config : [];
        $headers = is_array($config['headers'] ?? null) ? $config['headers'] : [];

        try {
            $response = Http::acceptJson()
                ->withHeaders($headers)
                ->timeout($timeout ?? self::DEFAULT_TIMEOUT)
                ->withOptions([
                    'allow_redirects' => [
                        'max' => self::MAX_REDIRECTS,
                        'on_redirect' => $this->guardRedirects(),
                    ],
                ])
                ->retry(3, 500, throw: false)
                ->get($url);
        } catch (Throwable $e) {
            Log::error('JSON API request failed', ['url' => $url, 'exception' => $e->getMessage()]);

            throw new RuntimeException("JSON API request failed for {$url}: {$e->getMessage()}", previous: $e);
        }

        if ($response->failed()) {
            Log::error('JSON API returned an error response', ['url' => $url, 'status' => $response->status()]);

            throw new RuntimeException("JSON API {$url} returned HTTP {$response->status()}.");
        }

        $length = (int) $response->header('Content-Length');
        if ($length > self::MAX_BYTES || strlen($response->body()) > self::MAX_BYTES) {
            throw new RuntimeException("JSON API {$url} returned a payload larger than the ".self::MAX_BYTES.' byte limit.');
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new RuntimeException("JSON API {$url} returned a non-JSON payload.");
        }

        $items = $this->extractItems($body, $config['items_path'] ?? null, $url);

        $jobs = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue; // scalars in the item list (e.g. a stray string) are not postings
            }

            $job = $this->map($item, $config['field_map'] ?? []);

            if ($job !== null) {
                $jobs[] = $job;
            }
        }

        return $jobs;
    }

    /**
     * Locate the array of items in the decoded body. `items_path` null/empty
     * means the body itself is the list (e.g. RemoteOK's top-level array).
     *
     * @param  array<mixed>  $body
     * @return array<mixed>
     */
    protected function extractItems(array $body, mixed $itemsPath, string $url): array
    {
        if (blank($itemsPath)) {
            return array_is_list($body) ? $body : [];
        }

        $items = data_get($body, (string) $itemsPath);

        if (! is_array($items)) {
            Log::warning('JSON API items_path did not resolve to an array', [
                'url' => $url,
                'items_path' => $itemsPath,
            ]);

            return [];
        }

        return $items;
    }

    /**
     * Map a single source item to a NormalizedJob via the field map, or null to
     * skip it (missing company / legal-notice rows carry no usable posting).
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, string|array<int, string>>  $fieldMap
     */
    protected function map(array $item, array $fieldMap): ?NormalizedJob
    {
        $companyRaw = $this->readPath($item, $fieldMap['company'] ?? null);
        $company = is_scalar($companyRaw) ? trim((string) $companyRaw) : '';

        if ($company === '') {
            return null; // sourceHash needs a company; blank = weak/colliding hash
        }

        $titleRaw = $this->readPath($item, $fieldMap['title'] ?? null);
        $title = is_scalar($titleRaw) ? trim((string) $titleRaw) : '';
        if ($title === '') {
            $title = 'Untitled role';
        }

        $location = $this->firstFilled($this->readPath($item, $fieldMap['location'] ?? null));
        $tags = $this->coerceTags($this->readPath($item, $fieldMap['tags'] ?? null));

        // When remote_type is mapped we respect its coerced value verbatim — an
        // explicit false means "not remote", not "infer onsite from the title".
        // Only an unmapped remote_type falls back to inference.
        $remoteType = array_key_exists('remote_type', $fieldMap)
            ? $this->coerceRemoteType($this->readPath($item, $fieldMap['remote_type']))
            : $this->inferRemoteType($location, $title, implode(' ', $tags));

        [$salaryMin, $salaryMax, $salaryCurrency] = $this->resolveSalary($item, $fieldMap);

        return new NormalizedJob(
            title: $title,
            company: $company,
            location: $location,
            remoteType: $remoteType,
            salaryMin: $salaryMin,
            salaryMax: $salaryMax,
            salaryCurrency: $salaryCurrency,
            jdText: $this->resolveText($this->readPath($item, $fieldMap['jd_text'] ?? null)),
            jdHtmlSnapshot: $this->resolveHtml($this->readPath($item, $fieldMap['jd_html_snapshot'] ?? null)),
            applyUrl: $applyUrl = $this->coerceUrl($this->readPath($item, $fieldMap['apply_url'] ?? null)),
            postedAt: $this->toIso($this->readPath($item, $fieldMap['posted_at'] ?? null)),
            sourceUrl: $this->coerceUrl($this->readPath($item, $fieldMap['source_url'] ?? null)) ?? $applyUrl,
            tags: $tags,
            rawExtract: $item,
        );
    }

    /**
     * Resolve salary_min/max/currency. Explicit min/max paths win; a freeform
     * `salary` path is parsed only to fill gaps they leave.
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, string|array<int, string>>  $fieldMap
     * @return array{0: ?int, 1: ?int, 2: ?string}
     */
    protected function resolveSalary(array $item, array $fieldMap): array
    {
        $min = array_key_exists('salary_min', $fieldMap)
            ? $this->coerceSalaryInt($this->readPath($item, $fieldMap['salary_min']))
            : null;
        $max = array_key_exists('salary_max', $fieldMap)
            ? $this->coerceSalaryInt($this->readPath($item, $fieldMap['salary_max']))
            : null;
        $currency = array_key_exists('salary_currency', $fieldMap)
            ? $this->coerceCurrency($this->readPath($item, $fieldMap['salary_currency']))
            : null;

        if (array_key_exists('salary', $fieldMap) && $min === null && $max === null) {
            [$pMin, $pMax, $pCurrency] = $this->parseSalary($this->readPath($item, $fieldMap['salary']));
            $min = $pMin;
            $max = $pMax;
            $currency ??= $pCurrency;
        }

        return [$min, $max, $currency];
    }

    protected function resolveText(mixed $value): ?string
    {
        if (is_string($value)) {
            return $this->htmlToText($value);
        }

        return is_scalar($value) ? trim((string) $value) ?: null : null;
    }

    protected function resolveHtml(mixed $value): ?string
    {
        return is_string($value)
            ? html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')
            : null;
    }

    /**
     * Read a field_map spec — a single dot-path or a list of candidate paths —
     * returning the first non-blank value found.
     *
     * @param  array<string, mixed>  $item
     */
    protected function readPath(array $item, mixed $spec): mixed
    {
        if ($spec === null) {
            return null;
        }

        $paths = is_array($spec) ? $spec : [$spec];

        foreach ($paths as $path) {
            if (! is_string($path) || $path === '') {
                continue;
            }

            $value = data_get($item, $path);

            if ($value !== null && $value !== '' && $value !== []) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Guzzle on_redirect callback: re-validate every hop so a public URL can't
     * 302 to an internal address (metadata endpoint, localhost, private range).
     */
    protected function guardRedirects(): callable
    {
        return function (RequestInterface $request, ResponseInterface $response, UriInterface $uri): void {
            UrlGuard::assertPublicHttpUrl((string) $uri);
        };
    }
}

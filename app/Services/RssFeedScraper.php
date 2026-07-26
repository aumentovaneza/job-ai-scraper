<?php

namespace App\Services;

use App\Models\JobSource;
use App\Support\NormalizedJob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use SimpleXMLElement;
use Throwable;

/**
 * Fetches and normalizes a job feed exposed as RSS 2.0 or Atom (T-21). Direct
 * XML fetch parsed with SimpleXML — no Firecrawl, no extra dependency. Pure
 * read: returns NormalizedJob[] and never writes to the DB, so it backs both
 * the queued ScrapeRssJob and the synchronous test-scrape endpoint, exactly
 * like AtsFeedScraper / CareerPageScraper.
 */
class RssFeedScraper
{
    /**
     * Fetch the feed for a source and return normalized postings.
     *
     * @return array<int, NormalizedJob>
     */
    public function scrape(JobSource $source): array
    {
        $url = (string) $source->url;

        try {
            $response = Http::timeout(30)
                ->retry(3, 500, throw: false)
                ->get($url);
        } catch (Throwable $e) {
            Log::error('RSS feed request failed', ['url' => $url, 'exception' => $e->getMessage()]);

            throw new RuntimeException("RSS feed request failed for {$url}: {$e->getMessage()}", previous: $e);
        }

        if ($response->failed()) {
            Log::error('RSS feed returned an error response', ['url' => $url, 'status' => $response->status()]);

            throw new RuntimeException("RSS feed {$url} returned HTTP {$response->status()}.");
        }

        return $this->parse($response->body(), $source);
    }

    /**
     * Parse a raw feed body (RSS 2.0 or Atom) into normalized postings.
     *
     * @return array<int, NormalizedJob>
     */
    public function parse(string $body, JobSource $source): array
    {
        $xml = $this->loadXml($body, (string) $source->url);

        return $this->isAtom($xml)
            ? $this->parseAtom($xml, $source)
            : $this->parseRss($xml, $source);
    }

    protected function loadXml(string $body, string $url): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_string(trim($body));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($xml === false) {
            throw new RuntimeException("RSS feed {$url} did not return valid XML.");
        }

        return $xml;
    }

    /** Atom feeds use <feed> as the root element; RSS uses <rss> (or <rdf>). */
    protected function isAtom(SimpleXMLElement $xml): bool
    {
        return $xml->getName() === 'feed';
    }

    /**
     * @return array<int, NormalizedJob>
     */
    protected function parseRss(SimpleXMLElement $xml, JobSource $source): array
    {
        // RSS 2.0 nests items under <channel>; RDF/RSS 1.0 keeps them at the root.
        $channel = isset($xml->channel) ? $xml->channel : $xml;
        $feedTitle = $this->firstFilled((string) ($channel->title ?? ''));

        $jobs = [];

        foreach ($channel->item as $item) {
            $namespaces = $item->getNamespaces(true);
            $content = isset($namespaces['content'])
                ? (string) $item->children($namespaces['content'])->encoded
                : '';
            $html = $this->firstFilled($content, (string) ($item->description ?? ''));

            $jobs[] = $this->normalize(
                title: $this->firstFilled((string) ($item->title ?? '')),
                link: $this->firstFilled((string) ($item->link ?? ''), (string) ($item->guid ?? '')),
                html: $html,
                postedAt: $this->firstFilled((string) ($item->pubDate ?? '')),
                rawExtract: $this->itemToArray($item),
                feedTitle: $feedTitle,
                source: $source,
            );
        }

        return $this->finalize($jobs);
    }

    /**
     * @return array<int, NormalizedJob>
     */
    protected function parseAtom(SimpleXMLElement $xml, JobSource $source): array
    {
        $feedTitle = $this->firstFilled((string) ($xml->title ?? ''));

        $jobs = [];

        foreach ($xml->entry as $entry) {
            $html = $this->firstFilled((string) ($entry->content ?? ''), (string) ($entry->summary ?? ''));

            $jobs[] = $this->normalize(
                title: $this->firstFilled((string) ($entry->title ?? '')),
                link: $this->atomLink($entry),
                html: $html,
                postedAt: $this->firstFilled((string) ($entry->published ?? ''), (string) ($entry->updated ?? '')),
                rawExtract: $this->itemToArray($entry),
                feedTitle: $feedTitle,
                source: $source,
            );
        }

        return $this->finalize($jobs);
    }

    /**
     * Prefer the alternate link's href; fall back to the first link with an href.
     */
    protected function atomLink(SimpleXMLElement $entry): ?string
    {
        $fallback = null;

        foreach ($entry->link as $link) {
            $href = (string) ($link['href'] ?? '');

            if ($href === '') {
                continue;
            }

            $rel = (string) ($link['rel'] ?? 'alternate');

            if ($rel === 'alternate') {
                return $href;
            }

            $fallback ??= $href;
        }

        return $fallback;
    }

    /**
     * @param  array<string, mixed>  $rawExtract
     */
    protected function normalize(
        ?string $title,
        ?string $link,
        ?string $html,
        ?string $postedAt,
        array $rawExtract,
        ?string $feedTitle,
        JobSource $source,
    ): NormalizedJob {
        $jd = $this->htmlToText($html);

        $company = $this->firstFilled(
            $source->config['company'] ?? null,
            $feedTitle,
            parse_url((string) $source->url, PHP_URL_HOST) ?: null,
        ) ?? 'Unknown';

        return new NormalizedJob(
            title: $title ?? 'Untitled role',
            company: $company,
            location: null,
            remoteType: $this->inferRemoteType($title, $jd),
            jdText: $jd,
            jdHtmlSnapshot: filled($html)
                ? html_entity_decode((string) $html, ENT_QUOTES | ENT_HTML5, 'UTF-8')
                : null,
            applyUrl: $link,
            postedAt: $this->toIso($postedAt),
            sourceUrl: $link ?? $source->url,
            rawExtract: $rawExtract,
        );
    }

    /**
     * Drop entries without a usable title (mirrors CareerPageScraper) and
     * reindex.
     *
     * @param  array<int, NormalizedJob>  $jobs
     * @return array<int, NormalizedJob>
     */
    protected function finalize(array $jobs): array
    {
        return array_values(array_filter(
            $jobs,
            fn (NormalizedJob $job) => filled($job->title) && $job->title !== 'Untitled role',
        ));
    }

    /**
     * Flatten a feed item's immediate children into a plain array for rawExtract.
     *
     * @return array<string, mixed>
     */
    protected function itemToArray(SimpleXMLElement $item): array
    {
        $data = [];

        foreach ($item->children() as $child) {
            $data[$child->getName()] = trim((string) $child);
        }

        return $data;
    }

    /** Strip tags/entities from an HTML fragment into readable plain text. */
    protected function htmlToText(?string $html): ?string
    {
        if (blank($html)) {
            return null;
        }

        $decoded = html_entity_decode((string) $html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $decoded = preg_replace('/<\s*(br|\/p|\/div|\/li|\/h[1-6])\s*>/i', "\n", $decoded) ?? $decoded;
        $text = trim(strip_tags($decoded));
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return $text === '' ? null : $text;
    }

    /** Infer remote | hybrid | onsite from free-text hints. Null when nothing matches. */
    protected function inferRemoteType(?string ...$hints): ?string
    {
        $haystack = mb_strtolower(implode(' ', array_filter($hints)));

        if ($haystack === '') {
            return null;
        }

        return match (true) {
            str_contains($haystack, 'hybrid') => 'hybrid',
            str_contains($haystack, 'remote') || str_contains($haystack, 'anywhere') => 'remote',
            str_contains($haystack, 'onsite') || str_contains($haystack, 'on-site') || str_contains($haystack, 'in office') || str_contains($haystack, 'in-office') => 'onsite',
            default => null,
        };
    }

    /** Coerce an RSS/Atom date string into an ISO-8601 string, or null. */
    protected function toIso(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value)->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }

    /** First non-blank string among the arguments, or null. */
    protected function firstFilled(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }
}

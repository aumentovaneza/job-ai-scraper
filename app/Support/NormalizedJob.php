<?php

namespace App\Support;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Provider-agnostic shape a scraper emits for a single posting, before it is
 * deduped and persisted as a canonical JobPosting (T-22/T-23/T-24).
 *
 * Scrapers (ATS feeds, Firecrawl career pages) each normalize their own payload
 * into this DTO. The ingestion pipeline then derives the `source_hash`, upserts,
 * and records a JobSourceHit — so dedup logic lives in exactly one place and is
 * identical regardless of where the posting came from.
 *
 * @implements Arrayable<string, mixed>
 */
final class NormalizedJob implements Arrayable
{
    public function __construct(
        public readonly string $title,
        public readonly string $company,
        public readonly ?string $location = null,
        public readonly ?string $remoteType = null,
        public readonly ?int $salaryMin = null,
        public readonly ?int $salaryMax = null,
        public readonly ?string $salaryCurrency = null,
        public readonly ?string $jdText = null,
        public readonly ?string $jdHtmlSnapshot = null,
        public readonly ?string $applyUrl = null,
        public readonly ?string $postedAt = null,
        public readonly ?string $sourceUrl = null,
        /** @var array<int, string> */
        public readonly array $tags = [],
        /** @var array<string, mixed> */
        public readonly array $rawExtract = [],
    ) {}

    /**
     * Rehydrate from the array form carried through the queue (jobs serialize
     * their payloads to plain arrays, not objects).
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            title: (string) ($data['title'] ?? ''),
            company: (string) ($data['company'] ?? ''),
            location: $data['location'] ?? null,
            remoteType: $data['remote_type'] ?? null,
            salaryMin: isset($data['salary_min']) ? (int) $data['salary_min'] : null,
            salaryMax: isset($data['salary_max']) ? (int) $data['salary_max'] : null,
            salaryCurrency: $data['salary_currency'] ?? null,
            jdText: $data['jd_text'] ?? null,
            jdHtmlSnapshot: $data['jd_html_snapshot'] ?? null,
            applyUrl: $data['apply_url'] ?? null,
            postedAt: $data['posted_at'] ?? null,
            sourceUrl: $data['source_url'] ?? null,
            tags: $data['tags'] ?? [],
            rawExtract: $data['raw_extract'] ?? [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'company' => $this->company,
            'location' => $this->location,
            'remote_type' => $this->remoteType,
            'salary_min' => $this->salaryMin,
            'salary_max' => $this->salaryMax,
            'salary_currency' => $this->salaryCurrency,
            'jd_text' => $this->jdText,
            'jd_html_snapshot' => $this->jdHtmlSnapshot,
            'apply_url' => $this->applyUrl,
            'posted_at' => $this->postedAt,
            'source_url' => $this->sourceUrl,
            'tags' => $this->tags,
            'raw_extract' => $this->rawExtract,
        ];
    }

    /**
     * Canonical dedup key: sha256 of company + normalized title + location
     * (PLAN.md T-24). Normalization lowercases and collapses whitespace so
     * cosmetic differences across boards don't create duplicate postings.
     */
    public function sourceHash(): string
    {
        $normalize = static fn (?string $value): string => trim(
            preg_replace('/\s+/u', ' ', mb_strtolower((string) $value)) ?? ''
        );

        return hash('sha256', implode('|', [
            $normalize($this->company),
            $normalize($this->title),
            $normalize($this->location),
        ]));
    }
}

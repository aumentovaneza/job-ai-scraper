<?php

namespace App\Services;

use App\Models\JobSource;
use App\Support\NormalizedJob;

/**
 * Scrapes a company career page into normalized postings via Firecrawl
 * `/extract` with a structured schema (T-23). Pure read — returns
 * NormalizedJob[] with no DB writes, so it backs both the queued
 * ScrapeCareerPageJob and the synchronous test-scrape endpoint.
 */
class CareerPageScraper
{
    public function __construct(private readonly FirecrawlClient $firecrawl) {}

    public function configured(): bool
    {
        return $this->firecrawl->configured();
    }

    /**
     * @return array<int, NormalizedJob>
     */
    public function scrape(JobSource $source): array
    {
        $data = $this->firecrawl->extract([$source->url], $this->schema(), $this->prompt());

        $jobs = $data['jobs'] ?? [];

        return collect($jobs)
            ->filter(fn ($job) => is_array($job) && filled($job['title'] ?? null))
            ->map(fn (array $job) => $this->normalize($job, $source))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $job
     */
    protected function normalize(array $job, JobSource $source): NormalizedJob
    {
        $remote = $job['remote_type'] ?? null;
        $remote = in_array($remote, ['remote', 'hybrid', 'onsite'], true) ? $remote : null;

        return new NormalizedJob(
            title: (string) $job['title'],
            company: (string) ($job['company'] ?? $source->config['company'] ?? parse_url($source->url, PHP_URL_HOST) ?? 'Unknown'),
            location: $job['location'] ?? null,
            remoteType: $remote,
            salaryMin: isset($job['salary_min']) ? (int) $job['salary_min'] : null,
            salaryMax: isset($job['salary_max']) ? (int) $job['salary_max'] : null,
            salaryCurrency: $job['salary_currency'] ?? null,
            jdText: $job['jd_text'] ?? null,
            applyUrl: $job['apply_url'] ?? $source->url,
            postedAt: $job['posted_at'] ?? null,
            sourceUrl: $job['apply_url'] ?? $source->url,
            rawExtract: $job,
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'jobs' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'company' => ['type' => 'string'],
                            'location' => ['type' => 'string'],
                            'remote_type' => ['type' => 'string', 'enum' => ['remote', 'hybrid', 'onsite']],
                            'salary_min' => ['type' => 'number'],
                            'salary_max' => ['type' => 'number'],
                            'salary_currency' => ['type' => 'string'],
                            'jd_text' => ['type' => 'string'],
                            'apply_url' => ['type' => 'string'],
                            'posted_at' => ['type' => 'string'],
                        ],
                        'required' => ['title'],
                    ],
                ],
            ],
            'required' => ['jobs'],
        ];
    }

    protected function prompt(): string
    {
        return 'Extract every open job posting listed on this career page. For each, capture the '
            .'title, company, location, whether it is remote/hybrid/onsite, salary range if shown, '
            .'a plain-text job description, the direct apply URL, and the posted date if available. '
            .'Do not invent postings that are not on the page.';
    }
}

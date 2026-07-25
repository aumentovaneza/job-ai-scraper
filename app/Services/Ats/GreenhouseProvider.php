<?php

namespace App\Services\Ats;

use App\Models\JobSource;
use App\Support\NormalizedJob;

/**
 * Greenhouse public board API.
 * Feed: https://boards-api.greenhouse.io/v1/boards/{token}/jobs?content=true
 * Payload: { jobs: [{ id, title, updated_at, location: {name}, content, absolute_url }] }
 * `content` is an HTML-encoded job description.
 */
class GreenhouseProvider extends AbstractAtsProvider
{
    public function key(): string
    {
        return 'greenhouse';
    }

    public function feedUrl(JobSource $source): string
    {
        return "https://boards-api.greenhouse.io/v1/boards/{$this->boardToken($source)}/jobs?content=true";
    }

    public function parse(array $body, JobSource $source): array
    {
        $company = $this->companyName($source);

        return array_map(function (array $job) use ($company): NormalizedJob {
            $location = $job['location']['name'] ?? null;
            $jd = $this->htmlToText($job['content'] ?? null);

            return new NormalizedJob(
                title: (string) ($job['title'] ?? 'Untitled role'),
                company: $company,
                location: $location,
                remoteType: $this->inferRemoteType($location, $job['title'] ?? null),
                jdText: $jd,
                jdHtmlSnapshot: is_string($job['content'] ?? null)
                    ? html_entity_decode($job['content'], ENT_QUOTES | ENT_HTML5, 'UTF-8')
                    : null,
                applyUrl: $job['absolute_url'] ?? null,
                postedAt: $this->toIso($job['updated_at'] ?? null),
                sourceUrl: $job['absolute_url'] ?? null,
                rawExtract: $job,
            );
        }, $body['jobs'] ?? []);
    }
}

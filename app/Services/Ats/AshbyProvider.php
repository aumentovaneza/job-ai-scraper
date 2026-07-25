<?php

namespace App\Services\Ats;

use App\Models\JobSource;
use App\Support\NormalizedJob;

/**
 * Ashby public job board API.
 * Feed: https://api.ashbyhq.com/posting-api/job-board/{token}?includeCompensation=true
 * Payload: { jobs: [{ title, location, isRemote, descriptionHtml,
 *   descriptionPlain, applyUrl, jobUrl, publishedAt, employmentType,
 *   compensation }] }
 */
class AshbyProvider extends AbstractAtsProvider
{
    public function key(): string
    {
        return 'ashby';
    }

    public function feedUrl(JobSource $source): string
    {
        return "https://api.ashbyhq.com/posting-api/job-board/{$this->boardToken($source)}?includeCompensation=true";
    }

    public function parse(array $body, JobSource $source): array
    {
        $company = $this->companyName($source);

        return array_map(function (array $job) use ($company): NormalizedJob {
            $location = $job['location'] ?? null;

            $remoteType = ($job['isRemote'] ?? false) === true
                ? 'remote'
                : $this->inferRemoteType($location, $job['title'] ?? null);

            $jd = $this->firstFilled($job['descriptionPlain'] ?? null)
                ?? $this->htmlToText($job['descriptionHtml'] ?? null);

            return new NormalizedJob(
                title: (string) ($job['title'] ?? 'Untitled role'),
                company: $company,
                location: $location,
                remoteType: $remoteType,
                jdText: $jd,
                jdHtmlSnapshot: $job['descriptionHtml'] ?? null,
                applyUrl: $this->firstFilled($job['applyUrl'] ?? null, $job['jobUrl'] ?? null),
                postedAt: $this->toIso($job['publishedAt'] ?? null),
                sourceUrl: $this->firstFilled($job['jobUrl'] ?? null, $job['applyUrl'] ?? null),
                rawExtract: $job,
            );
        }, $body['jobs'] ?? []);
    }
}

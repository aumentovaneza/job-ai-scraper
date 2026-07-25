<?php

namespace App\Services\Ats;

use App\Models\JobSource;
use App\Support\NormalizedJob;
use Illuminate\Support\Arr;

/**
 * Workable public account API.
 * Feed: https://www.workable.com/api/accounts/{token}?details=true
 * Payload: { name, jobs: [{ title, location: {city, region, country, ...},
 *   workplace, employment_type, description, requirements, url/shortlink,
 *   application_url, published_on/created_at }] }
 */
class WorkableProvider extends AbstractAtsProvider
{
    public function key(): string
    {
        return 'workable';
    }

    public function feedUrl(JobSource $source): string
    {
        return "https://www.workable.com/api/accounts/{$this->boardToken($source)}?details=true";
    }

    public function parse(array $body, JobSource $source): array
    {
        // Workable carries its own account name; prefer it over the token.
        $company = $this->firstFilled($body['name'] ?? null) ?? $this->companyName($source);

        return array_map(function (array $job) use ($company): NormalizedJob {
            $location = $this->formatLocation($job['location'] ?? []);
            $workplace = $job['workplace'] ?? null;

            $jd = collect([
                $this->htmlToText($job['description'] ?? null),
                $this->htmlToText($job['requirements'] ?? null),
            ])->filter()->implode("\n\n");

            return new NormalizedJob(
                title: (string) ($job['title'] ?? 'Untitled role'),
                company: $company,
                location: $location,
                remoteType: $this->inferRemoteType($workplace, $location, $job['title'] ?? null),
                jdText: $jd === '' ? null : $jd,
                jdHtmlSnapshot: $job['description'] ?? null,
                applyUrl: $this->firstFilled($job['application_url'] ?? null, $job['shortlink'] ?? null, $job['url'] ?? null),
                postedAt: $this->toIso($job['published_on'] ?? $job['created_at'] ?? null),
                sourceUrl: $this->firstFilled($job['shortlink'] ?? null, $job['url'] ?? null),
                rawExtract: $job,
            );
        }, $body['jobs'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $location
     */
    protected function formatLocation(array $location): ?string
    {
        if ($location === []) {
            return null;
        }

        $parts = Arr::only($location, ['city', 'region', 'country']);

        $joined = collect($parts)->filter()->implode(', ');

        return $joined === '' ? null : $joined;
    }
}

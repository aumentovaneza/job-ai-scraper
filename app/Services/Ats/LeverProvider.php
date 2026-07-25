<?php

namespace App\Services\Ats;

use App\Models\JobSource;
use App\Support\NormalizedJob;

/**
 * Lever postings API.
 * Feed: https://api.lever.co/v0/postings/{token}?mode=json
 * Payload: a top-level array of
 *   { text, categories: {location, team, commitment}, descriptionPlain,
 *     description, hostedUrl, applyUrl, createdAt (ms) }
 */
class LeverProvider extends AbstractAtsProvider
{
    public function key(): string
    {
        return 'lever';
    }

    public function feedUrl(JobSource $source): string
    {
        return "https://api.lever.co/v0/postings/{$this->boardToken($source)}?mode=json";
    }

    public function parse(array $body, JobSource $source): array
    {
        $company = $this->companyName($source);

        // Lever returns a bare array; Http::json() gives us that array directly.
        $postings = array_is_list($body) ? $body : ($body['data'] ?? []);

        return array_map(function (array $job) use ($company): NormalizedJob {
            $categories = $job['categories'] ?? [];
            $location = $categories['location'] ?? null;
            $workplace = $job['workplaceType'] ?? null;

            $jd = $this->firstFilled($job['descriptionPlain'] ?? null)
                ?? $this->htmlToText($job['description'] ?? null);

            return new NormalizedJob(
                title: (string) ($job['text'] ?? 'Untitled role'),
                company: $company,
                location: $location,
                remoteType: $this->inferRemoteType($workplace, $location, $categories['commitment'] ?? null, $job['text'] ?? null),
                jdText: $jd,
                jdHtmlSnapshot: $job['description'] ?? null,
                applyUrl: $this->firstFilled($job['applyUrl'] ?? null, $job['hostedUrl'] ?? null),
                postedAt: $this->toIso($job['createdAt'] ?? null),
                sourceUrl: $job['hostedUrl'] ?? null,
                rawExtract: $job,
            );
        }, $postings);
    }
}

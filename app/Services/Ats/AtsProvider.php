<?php

namespace App\Services\Ats;

use App\Models\JobSource;
use App\Support\NormalizedJob;

/**
 * One ATS provider integration (Greenhouse, Lever, Workable, Ashby). Each knows
 * how to build its public JSON feed URL from a JobSource and how to normalize
 * the returned payload into NormalizedJob DTOs (T-22).
 */
interface AtsProvider
{
    /** Stable provider key stored on `job_sources.config.provider`. */
    public function key(): string;

    /** Public JSON feed URL for this source's board token. */
    public function feedUrl(JobSource $source): string;

    /**
     * Normalize the decoded JSON body into postings.
     *
     * @param  array<string, mixed>  $body
     * @return array<int, NormalizedJob>
     */
    public function parse(array $body, JobSource $source): array;
}

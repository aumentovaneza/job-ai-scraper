<?php

namespace App\Services;

use App\Models\JobPosting;

/**
 * Outcome of ingesting one normalized posting (T-24): the canonical JobPosting
 * and whether it was created fresh (→ queue an embedding pass) or matched an
 * existing record (→ a new JobSourceHit only).
 */
final class IngestionResult
{
    public function __construct(
        public readonly JobPosting $posting,
        public readonly bool $isNew,
    ) {}
}

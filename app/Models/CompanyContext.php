<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Cached company facts distilled from Firecrawl-scraped pages (T-50). Shared
 * canonical record — NOT user-scoped (the same company facts serve every
 * user's letters, like `job_postings`). Refreshed at most once every
 * FRESH_DAYS; letters read these facts instead of Claude's memory so company
 * claims are grounded in scraped pages (hallucination guard, PLAN §7).
 */
#[Fillable([
    'company_key', 'company', 'facts', 'source_urls', 'fetched_at',
])]
class CompanyContext extends Model
{
    /**
     * How long a cached context is considered fresh before we re-scrape.
     */
    public const FRESH_DAYS = 30;

    protected function casts(): array
    {
        return [
            'source_urls' => 'array',
            'fetched_at' => 'datetime',
        ];
    }

    /**
     * Whether the cached facts are recent enough to reuse without re-scraping.
     */
    public function isFresh(): bool
    {
        return $this->fetched_at !== null
            && $this->fetched_at->gt(now()->subDays(self::FRESH_DAYS));
    }
}

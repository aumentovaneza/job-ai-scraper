<?php

namespace App\Services;

use App\Models\JobPosting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Keyword search + filtering over the shared JobPosting catalog (T-06).
 *
 * On Postgres this uses the generated `search_vector` tsvector column with
 * `plainto_tsquery`, ordering by `ts_rank`. On other drivers (the sqlite test
 * path, light local tooling) it degrades to a portable LIKE match so the same
 * API works everywhere. Phase 3 will layer pgvector semantic search on top of
 * this same builder.
 *
 * Supported filters (all optional):
 *   - q            string  free-text query
 *   - source_id    int     only postings surfaced by this JobSource (must be owned)
 *   - remote_type  string  remote | hybrid | onsite
 *   - salary_min   int     postings whose upper salary band is at least this
 *   - posted_after string  ISO date/datetime; postings posted on/after this
 */
class JobSearchService
{
    public function search(array $filters = []): Builder
    {
        $query = JobPosting::query();

        $term = isset($filters['q']) ? trim((string) $filters['q']) : '';
        $ranked = false;

        if ($term !== '') {
            $ranked = $this->applyTextSearch($query, $term);
        }

        if (! empty($filters['source_id'])) {
            $query->whereHas('sourceHits', function (Builder $q) use ($filters) {
                $q->where('job_source_id', $filters['source_id']);
            });
        }

        if (! empty($filters['remote_type'])) {
            $query->where('remote_type', $filters['remote_type']);
        }

        if (! empty($filters['salary_min'])) {
            // A posting matches the floor if its upper band could pay at least it.
            $query->where('salary_max', '>=', (int) $filters['salary_min']);
        }

        if (! empty($filters['posted_after'])) {
            $query->where('posted_at', '>=', $filters['posted_after']);
        }

        // Ranked results already carry a relevance order; otherwise newest first.
        if (! $ranked) {
            $query->orderByDesc('posted_at')->orderByDesc('id');
        }

        return $query;
    }

    /**
     * Apply the free-text predicate. Returns true when the query is already
     * ordered by relevance (Postgres path), false when the caller should fall
     * back to a recency ordering.
     */
    protected function applyTextSearch(Builder $query, string $term): bool
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            $query
                ->whereRaw("search_vector @@ plainto_tsquery('english', ?)", [$term])
                ->orderByRaw("ts_rank(search_vector, plainto_tsquery('english', ?)) DESC", [$term]);

            return true;
        }

        // Portable fallback (sqlite/mysql): match against the source columns the
        // tsvector is generated from (title + jd_text).
        $like = '%'.$this->escapeLike($term).'%';

        $query->where(function (Builder $q) use ($like) {
            $q->where('title', 'like', $like)
                ->orWhere('jd_text', 'like', $like);
        });

        return false;
    }

    protected function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}

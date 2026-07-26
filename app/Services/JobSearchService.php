<?php

namespace App\Services;

use App\Models\JobPosting;
use App\Models\User;
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
 *
 * When a $user is supplied, results are additionally ordered so postings whose
 * only surfacing sources are low-acceptability (won't hire internationally /
 * demand timezone overlap) sink to the bottom — a source-level downrank so the
 * matcher isn't spent on jobs the user can't accept.
 */
class JobSearchService
{
    public function search(array $filters = [], ?User $user = null): Builder
    {
        $query = JobPosting::query();

        $term = isset($filters['q']) ? trim((string) $filters['q']) : '';
        $ranked = false;

        if ($term !== '') {
            $ranked = $this->applyTextPredicate($query, $term);
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

        // Leading sort: source-level acceptability (acceptable buckets first),
        // then relevance / recency within each bucket.
        if ($user !== null) {
            $this->applyAcceptabilityOrder($query, $user);
        }

        if ($ranked) {
            $this->applyTextOrder($query, $term);
        } else {
            $query->orderByDesc('posted_at')->orderByDesc('id');
        }

        return $query;
    }

    /**
     * Apply the free-text predicate. Returns true on Postgres (where a separate
     * ts_rank ordering is available), false when the caller should fall back to
     * a recency ordering.
     */
    protected function applyTextPredicate(Builder $query, string $term): bool
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            $query->whereRaw("search_vector @@ plainto_tsquery('english', ?)", [$term]);

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

    /** Order by Postgres full-text relevance (only valid on the pgsql path). */
    protected function applyTextOrder(Builder $query, string $term): void
    {
        $query->orderByRaw("ts_rank(search_vector, plainto_tsquery('english', ?)) DESC", [$term]);
    }

    /**
     * Downrank by source acceptability. A posting's penalty is the MIN over the
     * user's own sources that surfaced it (the best source wins); postings not
     * linked to any of the user's sources score 0 (neutral). Mirrors
     * JobSource::acceptabilityPenalty() in SQL and is portable across pgsql
     * (boolean column) and the sqlite test path (0/1).
     */
    protected function applyAcceptabilityOrder(Builder $query, User $user): void
    {
        $penalty = <<<'SQL'
            coalesce((
                select min(
                    (case when js.hires_internationally then 0 else 2 end)
                    + (case js.timezone_overlap when 'strict' then 2 when 'partial' then 1 else 0 end)
                )
                from job_source_hits jsh
                join job_sources js on js.id = jsh.job_source_id
                where jsh.job_posting_id = job_postings.id and js.user_id = ?
            ), 0) asc
        SQL;

        $query->orderByRaw($penalty, [$user->id]);
    }

    protected function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Shared canonical job posting. NOT user-scoped — per-user context lives in
 * MatchScore/Application. The `embedding` (vector) and `search_vector`
 * (generated tsvector) columns are managed via raw SQL, not Eloquent casts.
 */
#[Fillable([
    'source_hash', 'title', 'company', 'location', 'remote_type',
    'salary_min', 'salary_max', 'salary_currency', 'jd_text', 'jd_html_snapshot',
    'apply_url', 'posted_at', 'first_seen_at', 'last_seen_at', 'raw_extract', 'enrichment', 'tags',
])]
class JobPosting extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'salary_min' => 'integer',
            'salary_max' => 'integer',
            'posted_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'raw_extract' => 'array',
            'enrichment' => 'array',
            'tags' => 'array',
        ];
    }

    public function sourceHits(): HasMany
    {
        return $this->hasMany(JobSourceHit::class);
    }

    public function matchScores(): HasMany
    {
        return $this->hasMany(MatchScore::class);
    }

    /**
     * The current viewer's fit score for this posting. Because MatchScore carries
     * the BelongsToUser global scope, eager-loading `matchScore` in an
     * authenticated request resolves to the caller's own row (there's a unique
     * (user_id, job_posting_id) constraint, so at most one). Do not rely on this
     * in an unauthenticated worker context — use matchScores() there.
     */
    public function matchScore(): HasOne
    {
        return $this->hasOne(MatchScore::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }
}

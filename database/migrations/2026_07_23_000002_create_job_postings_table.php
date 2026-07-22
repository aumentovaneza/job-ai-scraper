<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Canonical, SHARED job records. Named `job_postings` (not `jobs`) to avoid
     * colliding with Laravel's built-in queue `jobs` table. This is the one
     * table that is NOT user-scoped — the same posting is shared across users;
     * per-user context lives in `match_scores` and downstream tables.
     */
    public function up(): void
    {
        Schema::create('job_postings', function (Blueprint $table) {
            $table->id();
            $table->string('source_hash')->unique(); // sha256(company + normalized_title + location)
            $table->string('title');
            $table->string('company');
            $table->string('location')->nullable();
            $table->string('remote_type')->nullable(); // remote | hybrid | onsite
            $table->integer('salary_min')->nullable();
            $table->integer('salary_max')->nullable();
            $table->string('salary_currency', 3)->nullable();
            $table->text('jd_text')->nullable();
            $table->longText('jd_html_snapshot')->nullable();
            $table->text('apply_url')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->jsonb('raw_extract')->nullable();   // full Firecrawl payload
            $table->jsonb('enrichment')->nullable();     // Claude's structured analysis
            $table->timestamps();

            $table->index('company');
            $table->index('remote_type');
            $table->index('posted_at');
        });

        // Postgres-specific: pgvector embedding + generated tsvector FTS column.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE job_postings ADD COLUMN embedding vector(1024)');

            DB::statement(<<<'SQL'
                ALTER TABLE job_postings
                ADD COLUMN search_vector tsvector
                GENERATED ALWAYS AS (
                    to_tsvector('english', coalesce(title, '') || ' ' || coalesce(jd_text, ''))
                ) STORED
            SQL);

            DB::statement('CREATE INDEX job_postings_search_vector_idx ON job_postings USING GIN (search_vector)');
            DB::statement('CREATE INDEX job_postings_embedding_idx ON job_postings USING hnsw (embedding vector_cosine_ops)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('job_postings');
    }
};

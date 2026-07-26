<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * First-class, queryable tags on the canonical posting. JSON-API sources
 * (RemoteOK/Remotive/Arbeitnow) expose structured tag lists that RSS would
 * throw away; storing them as their own jsonb column (like raw_extract /
 * enrichment) keeps them queryable rather than buried in the raw payload.
 * FTS/tsvector inclusion is a deliberate follow-up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_postings', function (Blueprint $table) {
            $table->jsonb('tags')->nullable()->after('raw_extract');
        });
    }

    public function down(): void
    {
        Schema::table('job_postings', function (Blueprint $table) {
            $table->dropColumn('tags');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Record which versioned prompt (e.g. `enrich_job.v1`) produced each Claude
     * call, so any AI output stays reproducible from its stored inputs (T-30,
     * PLAN.md §7). Null for calls that don't originate from a prompt template
     * (e.g. Voyage embeddings).
     */
    public function up(): void
    {
        Schema::table('ai_calls', function (Blueprint $table) {
            $table->string('prompt_version')->nullable()->after('purpose');
        });
    }

    public function down(): void
    {
        Schema::table('ai_calls', function (Blueprint $table) {
            $table->dropColumn('prompt_version');
        });
    }
};

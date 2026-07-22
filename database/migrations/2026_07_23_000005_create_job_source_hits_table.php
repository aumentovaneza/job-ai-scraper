<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Many-to-many bridge: the same canonical job posting can surface from
     * multiple sources/boards. Shared (not user-scoped) — access is mediated
     * through the owning job_source.
     */
    public function up(): void
    {
        Schema::create('job_source_hits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_posting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_source_id')->constrained()->cascadeOnDelete();
            $table->text('source_url')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['job_posting_id', 'job_source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_source_hits');
    }
};

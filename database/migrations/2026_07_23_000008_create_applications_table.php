<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_posting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('current_stage_id')->nullable()->constrained('application_stages')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->foreignId('source')->nullable()->constrained('job_sources')->nullOnDelete();
            // Cross-references resolved once their tables exist (nullable, no FK to avoid
            // circular migration ordering): resume version + active cover-letter version.
            $table->unsignedBigInteger('resume_version_id')->nullable();
            $table->unsignedBigInteger('active_letter_version_id')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'job_posting_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applications');
    }
};

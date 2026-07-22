<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('headline')->nullable();
            $table->text('summary')->nullable();
            // Points at a stored resume file (resume ingestion lands in T-13; no FK yet).
            $table->unsignedBigInteger('resume_file_id')->nullable();
            $table->longText('resume_text')->nullable();
            $table->jsonb('voice_profile')->nullable();     // writing style extracted from samples
            $table->jsonb('target_roles')->nullable();
            $table->jsonb('target_locations')->nullable();
            $table->integer('target_comp_min_cents')->nullable();
            $table->timestamps();

            $table->unique('user_id'); // one profile per user
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profiles');
    }
};

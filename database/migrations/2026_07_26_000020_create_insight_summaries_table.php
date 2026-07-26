<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Weekly Claude-written narrative summaries for the insights dashboard + digest
 * email (T-61/T-63). One row is written per user per weekly run, keeping a light
 * history; the dashboard and email read the most recent by generated_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insight_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->longText('summary_md')->nullable();
            $table->jsonb('metrics')->nullable();   // stats snapshot the summary described
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->index(['user_id', 'generated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insight_summaries');
    }
};

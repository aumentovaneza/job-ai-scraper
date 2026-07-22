<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only event log — the source of truth for the application timeline
     * (T-42). `applications.current_stage_id` is a denormalized projection of
     * these events. Carries user_id so the BelongsToUser scope applies uniformly.
     */
    public function up(): void
    {
        Schema::create('application_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->foreignId('from_stage_id')->nullable()->constrained('application_stages')->nullOnDelete();
            $table->foreignId('to_stage_id')->nullable()->constrained('application_stages')->nullOnDelete();
            $table->string('actor')->default('user'); // user | system | claude
            $table->timestamp('occurred_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['application_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_events');
    }
};

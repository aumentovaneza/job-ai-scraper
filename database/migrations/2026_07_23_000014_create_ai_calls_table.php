<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Audit log of every Claude/Voyage call, scoped to the user whose key was
     * used. Drives per-user spend caps (T-11) and analytics.
     */
    public function up(): void
    {
        Schema::create('ai_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider'); // anthropic | voyage
            $table->string('model')->nullable();
            $table->string('endpoint')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->integer('cost_cents')->default(0);
            $table->string('purpose')->nullable(); // enrich | match | letter | analysis
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('status')->default('ok');
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_calls');
    }
};

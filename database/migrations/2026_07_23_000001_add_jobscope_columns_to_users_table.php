<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Per-user BYOK: Anthropic key encrypted at rest (Laravel Crypt).
            $table->text('encrypted_anthropic_key')->nullable();
            $table->timestamp('anthropic_key_verified_at')->nullable();

            // Per-user AI spend caps (null = not yet configured; enforced in T-11/T-15).
            $table->integer('daily_ai_spend_cap_cents')->nullable();
            $table->integer('weekly_ai_spend_cap_cents')->nullable();

            $table->string('timezone')->default('UTC');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'encrypted_anthropic_key',
                'anthropic_key_verified_at',
                'daily_ai_spend_cap_cents',
                'weekly_ai_spend_cap_cents',
                'timezone',
            ]);
        });
    }
};

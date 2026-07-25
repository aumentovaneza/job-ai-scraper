<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Per-user BYOK for OpenAI, mirroring the Anthropic columns: key
            // encrypted at rest (Laravel Crypt) plus a liveness-verification stamp.
            $table->text('encrypted_openai_key')->nullable();
            $table->timestamp('openai_key_verified_at')->nullable();

            // Which provider the user's AI work runs against. Defaults to anthropic
            // so existing users keep their current behaviour.
            $table->string('ai_provider')->default('anthropic');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'encrypted_openai_key',
                'openai_key_verified_at',
                'ai_provider',
            ]);
        });
    }
};

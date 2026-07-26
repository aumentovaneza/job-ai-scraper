<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_contexts', function (Blueprint $table) {
            $table->id();
            // Shared canonical record (like `jobs`) — NOT user-scoped. Company
            // facts are the same for every user, so we scrape/cache once (T-50).
            $table->string('company_key')->unique(); // normalized lowercase/trimmed name
            $table->string('company'); // original display name
            $table->longText('facts')->nullable(); // distilled plain-text facts
            $table->jsonb('source_urls')->nullable(); // pages the facts came from
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_contexts');
    }
};

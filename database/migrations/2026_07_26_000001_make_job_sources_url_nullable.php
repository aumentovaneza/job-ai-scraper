<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ATS feed sources (T-21/T-22) are addressed by `config.provider` +
     * `config.board_token`; the feed URL is derived, so `url` is optional for
     * them. Career-page/RSS sources still supply a URL (enforced in the form
     * request), but the column itself no longer requires one.
     */
    public function up(): void
    {
        Schema::table('job_sources', function (Blueprint $table) {
            $table->text('url')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('job_sources', function (Blueprint $table) {
            $table->text('url')->nullable(false)->change();
        });
    }
};

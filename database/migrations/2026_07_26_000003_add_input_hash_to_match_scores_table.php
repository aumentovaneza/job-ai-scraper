<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T-34 result cache: fingerprint the inputs that produced a match score
 * (jd_text + profile_version + prompt_version) so MatchJobToProfileJob can skip
 * a repeat Claude call when nothing that feeds the score has changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('match_scores', function (Blueprint $table) {
            $table->string('input_hash')->nullable()->after('prompt_version')->index();
        });
    }

    public function down(): void
    {
        Schema::table('match_scores', function (Blueprint $table) {
            $table->dropIndex(['input_hash']);
            $table->dropColumn('input_hash');
        });
    }
};

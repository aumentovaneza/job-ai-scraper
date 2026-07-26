<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Source-level acceptability flags. A source that never yields a role the user
 * can legally take (from Manila/Madrid) should be downranked at the SOURCE
 * level so ranking effort isn't spent on jobs the user can't accept — rather
 * than inferred per-job downstream.
 *
 *   - hires_internationally: default true (most remote boards do; avoids
 *     penalizing every existing source).
 *   - timezone_overlap: how much working-hours overlap the source's roles
 *     demand — any | partial | strict. Null = unknown → no penalty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_sources', function (Blueprint $table) {
            $table->boolean('hires_internationally')->default(true)->after('active');
            $table->string('timezone_overlap')->nullable()->after('hires_internationally');
        });
    }

    public function down(): void
    {
        Schema::table('job_sources', function (Blueprint $table) {
            $table->dropColumn(['hires_internationally', 'timezone_overlap']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cover_letters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            // Active version FK added after cover_letter_versions exists (nullable, no FK here).
            $table->unsignedBigInteger('active_version_id')->nullable();
            $table->timestamps();

            $table->unique('application_id'); // one letter per application
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cover_letters');
    }
};

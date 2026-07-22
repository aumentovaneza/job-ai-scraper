<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cover_letter_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cover_letter_id')->constrained()->cascadeOnDelete();
            $table->longText('content_md')->nullable();
            $table->jsonb('generation_params')->nullable();
            $table->string('variant_label')->nullable(); // story_led | results_led | culture_led | custom
            $table->foreignId('parent_version_id')->nullable()->constrained('cover_letter_versions')->nullOnDelete();
            $table->boolean('was_sent')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cover_letter_versions');
    }
};

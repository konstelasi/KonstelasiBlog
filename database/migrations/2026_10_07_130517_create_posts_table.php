<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per post, with both languages side by side. The bodies are
     * Markdown, which the Astro site renders at build time.
     */
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('status', 16)->default('draft')->index();
            $table->dateTime('published_at')->nullable();
            $table->string('author');
            $table->string('title_en')->nullable();
            $table->text('description_en')->nullable();
            $table->longText('body_en')->nullable();
            $table->string('title_id')->nullable();
            $table->text('description_id')->nullable();
            $table->longText('body_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};

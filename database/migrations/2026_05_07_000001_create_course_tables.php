<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sbn_courses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('wp_id')->nullable()->unique(); // WP post ID for import idempotency
            $table->string('slug')->unique();
            $table->string('title');
            $table->text('excerpt')->nullable();
            $table->longText('description')->nullable();
            $table->json('genres')->nullable();  // ['bossa-nova', 'jazz']
            $table->json('levels')->nullable();  // ['basic', 'intermediate']
            $table->string('style')->nullable(); // _sbn_style fallback
            $table->string('level')->nullable(); // _sbn_level fallback
            $table->json('topics')->nullable();  // _sbn_topics e.g. ['rhythm','harmony']
            $table->boolean('is_free')->default(false);
            $table->foreignId('product_id')->nullable()->constrained('sbn_products')->onDelete('set null');
            $table->string('featured_image_path')->nullable();
            $table->integer('sort_order')->default(0);
            $table->string('status')->default('publish'); // publish|draft
            $table->timestamps();
        });

        Schema::create('sbn_lessons', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('wp_id')->nullable()->unique();
            $table->foreignId('course_id')->constrained('sbn_courses')->onDelete('cascade');
            $table->string('slug');
            $table->string('title');
            $table->longText('content')->nullable();
            $table->string('section_title')->nullable(); // groups lessons in sidebar
            $table->boolean('is_preview')->default(false);
            $table->integer('sort_order')->default(0);
            $table->string('status')->default('publish');
            $table->timestamps();

            $table->unique(['course_id', 'slug']);
            $table->index('course_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sbn_lessons');
        Schema::dropIfExists('sbn_courses');
    }
};

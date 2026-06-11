<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sbn_chord_progressions', function (Blueprint $table) {
            $table->id();
            $table->text('name')->default('');
            $table->text('category')->default('jazz');
            $table->text('numerals')->default('');
            $table->text('description')->nullable();
            $table->text('typical_genres')->default('');
            $table->text('tags')->default('');
            $table->integer('sort_order')->default(0);
            $table->boolean('featured')->default(false);
            $table->text('tonality')->default('both');
            $table->text('match_mode')->default('strict');
            $table->string('slug')->unique();
            $table->text('alt_numerals')->nullable();
            $table->text('video_snippets')->nullable();
            $table->integer('difficulty')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sbn_chord_progressions');
    }
};

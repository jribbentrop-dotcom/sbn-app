<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('fretboards', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('display_mode', ['chord', 'scale', 'sequence'])->default('chord');
            $table->enum('theme', ['dark', 'light'])->default('dark');
            $table->unsignedTinyInteger('fret_count')->default(12);
            $table->unsignedTinyInteger('start_fret')->default(1);
            $table->boolean('show_guide_tones')->default(false);
            $table->boolean('show_rh_fingers')->default(false);
            // Array of voicing frames: [{label, frets, fingers, interval_labels}]
            $table->json('voicings')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fretboards');
    }
};

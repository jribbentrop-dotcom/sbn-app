<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sbn_rhythm_patterns')) {
            return;
        }
        Schema::create('sbn_rhythm_patterns', function (Blueprint $table) {
            $table->id();
            $table->text('slug')->unique();
            $table->text('name');
            $table->text('description')->nullable();
            $table->text('category')->default('general');
            $table->text('time_signature')->default('4/4');
            $table->integer('beats')->default(8);
            $table->text('rhythm_pattern');
            $table->text('thumb_pattern')->default('');
            $table->integer('default_bpm')->default(120);
            $table->text('sound')->default('clave');
            $table->text('mp3_file')->default('');
            $table->boolean('is_default')->default(false);
            $table->integer('sort_order')->default(0);
            $table->text('grid_type')->default('sixteenth');
            $table->text('perc_sound')->default('none');
            $table->text('perc_top')->default('none');
            $table->text('perc_bass')->default('none');
            $table->text('video_snippets')->nullable();
            $table->integer('difficulty')->nullable();
            $table->tinyInteger('picking_mode')->default(0);
            $table->string('finger_index')->nullable();
            $table->string('finger_middle')->nullable();
            $table->string('finger_ring')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sbn_rhythm_patterns');
    }
};

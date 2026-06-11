<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sbn_chord_diagrams', function (Blueprint $table) {
            $table->id();
            $table->text('slug')->unique();
            $table->text('name');
            $table->text('root_note');
            $table->text('quality');
            $table->text('extensions')->default('');
            $table->text('voicing_category')->default('drop2');
            $table->text('string_set')->default('5432');
            $table->text('bass_note')->default('');
            $table->text('shape_family')->default('');
            $table->boolean('is_fixed_position')->default(false);
            $table->integer('start_fret')->default(1);
            $table->text('diagram_data');
            $table->text('interval_labels')->default('');
            $table->text('notes')->default('');
            $table->text('description')->nullable();
            $table->boolean('is_default')->default(false);
            $table->integer('sort_order')->default(0);
            $table->integer('popularity')->default(0);
            $table->tinyInteger('difficulty')->default(0);
            $table->text('root_string')->default('roota');
            $table->text('inversion')->default('root');
            $table->text('alt_names')->default('');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sbn_chord_diagrams');
    }
};

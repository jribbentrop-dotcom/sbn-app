<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sbn_voicing_usage')) {
            return;
        }
        Schema::create('sbn_voicing_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leadsheet_id')->constrained('sbn_leadsheets')->cascadeOnDelete();
            $table->foreignId('chord_diagram_id')->constrained('sbn_chord_diagrams')->cascadeOnDelete();
            $table->text('chord_name');
            $table->text('fret_string');
            $table->integer('position')->default(1);
            $table->text('root_note');
            $table->text('quality');
            $table->text('voicing_category')->default('');
            $table->text('root_string')->default('');
            $table->text('inversion')->default('');
            $table->text('bass_note')->default('');
            $table->text('base_quality')->default('');
            $table->text('extensions')->default('');
            $table->text('added_notes')->default('');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sbn_voicing_usage');
    }
};

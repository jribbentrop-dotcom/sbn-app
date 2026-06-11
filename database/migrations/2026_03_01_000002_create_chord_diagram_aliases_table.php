<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sbn_chord_diagram_aliases')) {
            return;
        }
        Schema::create('sbn_chord_diagram_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('diagram_id')->constrained('sbn_chord_diagrams')->cascadeOnDelete();
            $table->text('alt_name');
            $table->text('alt_root_note');
            $table->text('alt_quality');
            $table->text('alt_extensions')->default('');
            $table->text('alt_bass_note')->default('');
            $table->text('interval_labels')->default('');
            $table->text('notes')->default('');
            $table->text('description')->default('');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sbn_chord_diagram_aliases');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5d — Progression detection results table.
 *
 * Stores detected progression occurrences within leadsheets.
 * Ported from WP class-chord-progressions.php activate().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sbn_progression_occurrences')) {
            return; // Already exists from WP import
        }

        Schema::create('sbn_progression_occurrences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('progression_id');
            $table->unsignedBigInteger('leadsheet_id');
            $table->string('section_id', 10)->default('A');
            $table->integer('start_measure')->default(0);
            $table->integer('length_measures')->default(1);
            $table->string('detected_root', 5)->default('C');
            $table->float('confidence')->default(1.0);
            $table->timestamp('created_at')->useCurrent();

            $table->index('progression_id');
            $table->index('leadsheet_id');
            $table->index(['leadsheet_id', 'section_id'], 'leadsheet_section');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sbn_progression_occurrences');
    }
};

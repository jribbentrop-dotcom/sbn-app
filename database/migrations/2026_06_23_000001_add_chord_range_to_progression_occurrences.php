<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sbn_progression_occurrences', function (Blueprint $table) {
            if (!Schema::hasColumn('sbn_progression_occurrences', 'start_chord')) {
                $table->integer('start_chord')->default(0)->after('length_measures');
            }
            if (!Schema::hasColumn('sbn_progression_occurrences', 'end_chord')) {
                $table->integer('end_chord')->default(0)->after('start_chord');
            }
            if (!Schema::hasColumn('sbn_progression_occurrences', 'end_chord_start')) {
                $table->integer('end_chord_start')->default(0)->after('end_chord');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sbn_progression_occurrences', function (Blueprint $table) {
            $table->dropColumn(['start_chord', 'end_chord', 'end_chord_start']);
        });
    }
};

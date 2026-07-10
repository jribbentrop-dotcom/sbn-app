<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The record's native root note (e.g. "E" for an E minor pentatonic
     * scale), used to compute a semitone offset when a course tag requests
     * a different key via <sbn-fretboard key="G">.
     */
    public function up(): void
    {
        // Guarded: this column was hand-ALTERed onto production before the
        // migration was recorded as run, so on the live DB it already exists.
        // The guard makes up() a safe no-op there and keeps a from-scratch
        // replay working.
        if (Schema::hasColumn('fretboards', 'root_note')) {
            return;
        }

        Schema::table('fretboards', function (Blueprint $table) {
            $table->string('root_note', 3)->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('fretboards', 'root_note')) {
            return;
        }

        Schema::table('fretboards', function (Blueprint $table) {
            $table->dropColumn('root_note');
        });
    }
};

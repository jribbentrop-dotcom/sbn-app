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
        Schema::table('fretboards', function (Blueprint $table) {
            $table->string('root_note', 3)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('fretboards', function (Blueprint $table) {
            $table->dropColumn('root_note');
        });
    }
};

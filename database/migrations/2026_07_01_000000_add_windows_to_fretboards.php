<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the `windows` JSON column (named camera-pan ranges for `positions`
     * display mode) and relax `display_mode` to a plain string so the new
     * `positions` value is accepted without an enum/CHECK rebuild.
     */
    public function up(): void
    {
        Schema::table('fretboards', function (Blueprint $table) {
            // Named fret windows for positions mode: [{label, from, to}]
            $table->json('windows')->nullable()->after('voicings');
        });

        // Drop the enum CHECK constraint on display_mode by converting it to a
        // plain string. SQLite rebuilds the table; doctrine/dbal not required
        // for a string→string change under Laravel's SQLite grammar.
        Schema::table('fretboards', function (Blueprint $table) {
            $table->string('display_mode')->default('chord')->change();
        });
    }

    public function down(): void
    {
        Schema::table('fretboards', function (Blueprint $table) {
            $table->dropColumn('windows');
        });
        Schema::table('fretboards', function (Blueprint $table) {
            $table->enum('display_mode', ['chord', 'scale', 'sequence'])->default('chord')->change();
        });
    }
};

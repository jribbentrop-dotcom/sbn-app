<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Guarded (schema-consolidation reconciliation): this column was later
        // folded into its create-table migration, so it already exists on a
        // from-scratch replay. No-op there and on the live DB; keeps a fresh
        // migrate / :memory: test from dying on "duplicate column name".
        if (Schema::hasColumn('sbn_chord_progressions', 'alt_numerals')) {
            return;
        }
        Schema::table('sbn_chord_progressions', function (Blueprint $table) {
            $table->json('alt_numerals')->nullable()->after('numerals');
        });
    }

    public function down(): void
    {
        Schema::table('sbn_chord_progressions', function (Blueprint $table) {
            $table->dropColumn('alt_numerals');
        });
    }
};

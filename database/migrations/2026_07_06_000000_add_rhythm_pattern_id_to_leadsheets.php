<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Real FK from a leadsheet (and its per-arrangement versions) to the
 * sbn_rhythm_patterns row it should play/display, replacing the fragile
 * string-match convention (sbn_leadsheets.rhythm === sbn_rhythm_patterns.slug).
 * The `rhythm` text column stays on both tables for backward compatibility
 * (style-slug resolution, existing queries) — this migration only adds the
 * new column and backfills it from clean slug matches.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sbn_leadsheets', function (Blueprint $table) {
            if (!Schema::hasColumn('sbn_leadsheets', 'rhythm_pattern_id')) {
                $table->unsignedBigInteger('rhythm_pattern_id')->nullable()->after('rhythm');
            }
        });

        Schema::table('sbn_leadsheet_versions', function (Blueprint $table) {
            if (!Schema::hasColumn('sbn_leadsheet_versions', 'rhythm_pattern_id')) {
                $table->unsignedBigInteger('rhythm_pattern_id')->nullable()->after('rhythm');
            }
        });

        // Backfill: only where the free-text rhythm value matches a pattern
        // slug exactly (case-insensitive). Anything that doesn't resolve
        // cleanly is left null rather than guessed at.
        DB::statement("
            UPDATE sbn_leadsheets
            SET rhythm_pattern_id = (
                SELECT id FROM sbn_rhythm_patterns
                WHERE lower(sbn_rhythm_patterns.slug) = lower(sbn_leadsheets.rhythm)
            )
            WHERE rhythm IS NOT NULL AND rhythm != ''
        ");

        DB::statement("
            UPDATE sbn_leadsheet_versions
            SET rhythm_pattern_id = (
                SELECT id FROM sbn_rhythm_patterns
                WHERE lower(sbn_rhythm_patterns.slug) = lower(sbn_leadsheet_versions.rhythm)
            )
            WHERE rhythm IS NOT NULL AND rhythm != ''
        ");
    }

    public function down(): void
    {
        Schema::table('sbn_leadsheets', function (Blueprint $table) {
            if (Schema::hasColumn('sbn_leadsheets', 'rhythm_pattern_id')) {
                $table->dropColumn('rhythm_pattern_id');
            }
        });

        Schema::table('sbn_leadsheet_versions', function (Blueprint $table) {
            if (Schema::hasColumn('sbn_leadsheet_versions', 'rhythm_pattern_id')) {
                $table->dropColumn('rhythm_pattern_id');
            }
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Version-scopes the three detection-cache tables (plan §1.1).
 *
 * Progression occurrences and voicing usage/drafts are derived from a version's
 * json_data — a reharm (e.g. Wes) yields different progressions/voicings than Basic,
 * so each cached row belongs to one arrangement. leadsheet_id is KEPT for rollup
 * queries (COUNT(DISTINCT leadsheet_id) song-level popularity — see plan §7 Risk 2).
 *
 * version_id is nullable here; the data migration backfills it to each row's
 * leadsheet default version.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sbn_progression_occurrences', function (Blueprint $table) {
            if (!Schema::hasColumn('sbn_progression_occurrences', 'version_id')) {
                $table->unsignedBigInteger('version_id')->nullable()->after('leadsheet_id');
                $table->index(['version_id', 'section_id'], 'version_section');
            }
        });

        Schema::table('sbn_voicing_usage', function (Blueprint $table) {
            if (!Schema::hasColumn('sbn_voicing_usage', 'version_id')) {
                $table->unsignedBigInteger('version_id')->nullable()->after('leadsheet_id');
                $table->index('version_id', 'voicing_usage_version');
            }
        });

        Schema::table('sbn_voicing_drafts', function (Blueprint $table) {
            if (!Schema::hasColumn('sbn_voicing_drafts', 'version_id')) {
                $table->unsignedBigInteger('version_id')->nullable()->after('leadsheet_id');
                $table->index('version_id', 'voicing_drafts_version');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sbn_progression_occurrences', function (Blueprint $table) {
            if (Schema::hasColumn('sbn_progression_occurrences', 'version_id')) {
                $table->dropIndex('version_section');
                $table->dropColumn('version_id');
            }
        });

        Schema::table('sbn_voicing_usage', function (Blueprint $table) {
            if (Schema::hasColumn('sbn_voicing_usage', 'version_id')) {
                $table->dropIndex('voicing_usage_version');
                $table->dropColumn('version_id');
            }
        });

        Schema::table('sbn_voicing_drafts', function (Blueprint $table) {
            if (Schema::hasColumn('sbn_voicing_drafts', 'version_id')) {
                $table->dropIndex('voicing_drafts_version');
                $table->dropColumn('version_id');
            }
        });
    }
};

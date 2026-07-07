<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-arrangement free-text notes, e.g. "Wes Montgomery's octave-doubled
 * melody line" — distinct from the WORK-level description/harmony_notes/
 * form_notes/voicing_notes on sbn_leadsheets, which stay shared across every
 * arrangement of a song (see docs/SBN-Leadsheet-Reference.md §2.3). No
 * fallback to a leadsheet-level column: empty means the arrangement simply
 * has no notes yet, not "inherit the song's description."
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sbn_leadsheet_versions', function (Blueprint $table) {
            if (!Schema::hasColumn('sbn_leadsheet_versions', 'arrangement_notes')) {
                $table->text('arrangement_notes')->nullable()->after('shortcode_content');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sbn_leadsheet_versions', function (Blueprint $table) {
            if (Schema::hasColumn('sbn_leadsheet_versions', 'arrangement_notes')) {
                $table->dropColumn('arrangement_notes');
            }
        });
    }
};

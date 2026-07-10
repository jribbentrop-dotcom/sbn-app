<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 7 prep: store raw MusicXML for tab data.
     * The tab editor will read from and write back to this column.
     *
     * Guarded with hasColumn: the schema-consolidation pass (2026-06-11,
     * commit d92a585) folded `tab_xml` into the create-leadsheets migration,
     * so on a from-scratch replay the column already exists by the time this
     * runs. Without the guard, a fresh migrate (and every :memory: test) dies
     * on "duplicate column name". On the live DB the column is long since
     * present, so this is a no-op there too. Mirrors the hasTable guard added
     * to the create-table migrations in de88e8d.
     */
    public function up(): void
    {
        if (Schema::hasColumn('sbn_leadsheets', 'tab_xml')) {
            return;
        }

        Schema::table('sbn_leadsheets', function (Blueprint $table) {
            $table->text('tab_xml')->nullable()->after('json_data');
        });
    }

    public function down(): void
    {
        // Don't drop a column the create-table migration owns — its own down()
        // handles that. Only reverse what this migration actually added.
        if (! Schema::hasColumn('sbn_leadsheets', 'tab_xml')) {
            return;
        }

        Schema::table('sbn_leadsheets', function (Blueprint $table) {
            $table->dropColumn('tab_xml');
        });
    }
};

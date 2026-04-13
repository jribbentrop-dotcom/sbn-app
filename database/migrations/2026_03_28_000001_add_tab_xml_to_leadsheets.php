<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 7 prep: store raw MusicXML for tab data.
     * The tab editor will read from and write back to this column.
     */
    public function up(): void
    {
        Schema::table('sbn_leadsheets', function (Blueprint $table) {
            $table->text('tab_xml')->nullable()->after('json_data');
        });
    }

    public function down(): void
    {
        Schema::table('sbn_leadsheets', function (Blueprint $table) {
            $table->dropColumn('tab_xml');
        });
    }
};

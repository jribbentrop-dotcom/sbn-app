<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sbn_chord_progressions', function (Blueprint $table) {
            $table->timestamp('updated_at')->nullable()->after('created_at');
        });

        // Backfill from created_at so existing rows appear in the recently-edited feed
        DB::statement('UPDATE sbn_chord_progressions SET updated_at = created_at WHERE updated_at IS NULL');
    }

    public function down(): void
    {
        Schema::table('sbn_chord_progressions', function (Blueprint $table) {
            $table->dropColumn('updated_at');
        });
    }
};

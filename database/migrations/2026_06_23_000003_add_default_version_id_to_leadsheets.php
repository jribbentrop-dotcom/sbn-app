<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Points each leadsheet at its default arrangement (sbn_leadsheet_versions row).
 * Backfilled by the data migration; routes fall back to this when no ?v= is given.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sbn_leadsheets', function (Blueprint $table) {
            if (!Schema::hasColumn('sbn_leadsheets', 'default_version_id')) {
                $table->unsignedBigInteger('default_version_id')->nullable()->after('slug');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sbn_leadsheets', function (Blueprint $table) {
            if (Schema::hasColumn('sbn_leadsheets', 'default_version_id')) {
                $table->dropColumn('default_version_id');
            }
        });
    }
};

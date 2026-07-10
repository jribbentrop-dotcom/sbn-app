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
        if (Schema::hasColumn('sbn_rhythm_patterns', 'picking_mode')) {
            return;
        }
        Schema::table('sbn_rhythm_patterns', function (Blueprint $table) {
            $table->boolean('picking_mode')->default(false)->after('thumb_pattern');
            $table->string('finger_index',  64)->nullable()->after('picking_mode');
            $table->string('finger_middle', 64)->nullable()->after('finger_index');
            $table->string('finger_ring',   64)->nullable()->after('finger_middle');
        });
    }

    public function down(): void
    {
        Schema::table('sbn_rhythm_patterns', function (Blueprint $table) {
            $table->dropColumn(['picking_mode', 'finger_index', 'finger_middle', 'finger_ring']);
        });
    }
};

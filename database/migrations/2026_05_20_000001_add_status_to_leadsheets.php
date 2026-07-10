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
        if (Schema::hasColumn('sbn_leadsheets', 'status')) {
            return;
        }
        Schema::table('sbn_leadsheets', function (Blueprint $table) {
            // 'publish' = visible in the public song library; 'draft' = admin-only.
            // Existing rows default to draft so nothing goes live unreviewed.
            $table->string('status', 16)->default('draft')->after('cover_image_path');
        });
    }

    public function down(): void
    {
        Schema::table('sbn_leadsheets', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};

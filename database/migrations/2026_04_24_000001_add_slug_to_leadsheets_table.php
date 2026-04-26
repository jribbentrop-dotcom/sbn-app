<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sbn_leadsheets', function (Blueprint $table) {
            $table->string('slug')->nullable()->unique()->after('title');
        });

        // Backfill slugs from title — idempotent (only fills nulls)
        $rows = DB::table('sbn_leadsheets')->whereNull('slug')->get(['id', 'title']);

        $seen = [];
        foreach ($rows as $row) {
            $base = Str::slug($row->title);
            if ($base === '') {
                $base = 'song-' . $row->id;
            }

            $candidate = $base;
            $suffix    = 2;
            while (isset($seen[$candidate]) || DB::table('sbn_leadsheets')->where('slug', $candidate)->exists()) {
                $candidate = $base . '-' . $suffix++;
            }

            $seen[$candidate] = true;
            DB::table('sbn_leadsheets')->where('id', $row->id)->update(['slug' => $candidate]);
        }

        // Now make non-nullable
        Schema::table('sbn_leadsheets', function (Blueprint $table) {
            $table->string('slug')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('sbn_leadsheets', function (Blueprint $table) {
            $table->dropColumn('slug');
        });
    }
};

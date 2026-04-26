<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sbn_chord_progressions', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('name');
        });

        // Backfill slugs from names
        $progressions = DB::table('sbn_chord_progressions')->get();
        foreach ($progressions as $progression) {
            $slug = strtolower($progression->name);
            $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
            $slug = trim($slug, '-');
            
            // Ensure uniqueness
            $originalSlug = $slug;
            $counter = 1;
            while (DB::table('sbn_chord_progressions')->where('slug', $slug)->where('id', '!=', $progression->id)->exists()) {
                $slug = $originalSlug . '-' . $counter;
                $counter++;
            }
            
            DB::table('sbn_chord_progressions')
                ->where('id', $progression->id)
                ->update(['slug' => $slug]);
        }

        // Now make the column unique and not nullable
        Schema::table('sbn_chord_progressions', function (Blueprint $table) {
            $table->string('slug')->nullable(false)->change();
        });

        Schema::table('sbn_chord_progressions', function (Blueprint $table) {
            $table->unique('slug');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sbn_chord_progressions', function (Blueprint $table) {
            $table->dropColumn('slug');
        });
    }
};

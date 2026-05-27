<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sbn_courses', function (Blueprint $table) {
            $table->string('category', 50)->nullable()->after('slug');
        });

        // Backfill category from first genre value where possible
        $map = ['bossa-nova' => 'bossa-nova', 'jazz' => 'jazz', 'classical' => 'classical', 'pop' => 'pop', 'samba' => 'bossa-nova', 'latin' => 'bossa-nova', 'brazilian' => 'bossa-nova'];

        $courses = DB::table('sbn_courses')->whereNotNull('genres')->get(['id', 'genres']);
        foreach ($courses as $course) {
            $genres = json_decode($course->genres, true);
            if (!is_array($genres)) continue;
            foreach ($genres as $genre) {
                $slug = trim(strtolower($genre));
                if (isset($map[$slug])) {
                    DB::table('sbn_courses')->where('id', $course->id)->update(['category' => $map[$slug]]);
                    break;
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('sbn_courses', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
};

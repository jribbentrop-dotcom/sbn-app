<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sbn_chord_progressions', function (Blueprint $table) {
            $table->json('alt_numerals')->nullable()->after('numerals');
        });
    }

    public function down(): void
    {
        Schema::table('sbn_chord_progressions', function (Blueprint $table) {
            $table->dropColumn('alt_numerals');
        });
    }
};

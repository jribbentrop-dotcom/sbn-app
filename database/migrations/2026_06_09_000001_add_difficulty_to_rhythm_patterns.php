<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sbn_rhythm_patterns', function (Blueprint $table) {
            $table->tinyInteger('difficulty')->nullable()->default(null)->after('sort_order');
        });
    }

    public function down(): void
    {
        Schema::table('sbn_rhythm_patterns', function (Blueprint $table) {
            $table->dropColumn('difficulty');
        });
    }
};

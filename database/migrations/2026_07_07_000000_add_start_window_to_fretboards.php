<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Positions mode: which named window (0-indexed into `windows[]`) the
     * camera should open on, instead of always starting at Position 1.
     */
    public function up(): void
    {
        Schema::table('fretboards', function (Blueprint $table) {
            $table->unsignedTinyInteger('start_window')->default(0)->after('windows');
        });
    }

    public function down(): void
    {
        Schema::table('fretboards', function (Blueprint $table) {
            $table->dropColumn('start_window');
        });
    }
};

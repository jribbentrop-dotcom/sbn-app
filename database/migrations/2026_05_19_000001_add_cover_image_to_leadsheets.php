<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sbn_leadsheets', function (Blueprint $table) {
            $table->string('cover_image_path')->nullable()->after('popularity');
        });
    }

    public function down(): void
    {
        Schema::table('sbn_leadsheets', function (Blueprint $table) {
            $table->dropColumn('cover_image_path');
        });
    }
};

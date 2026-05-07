<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sbn_progression_occurrences', function (Blueprint $table) {
            $table->integer('variant_index')->nullable()->after('progression_id');
            $table->string('variant_label', 100)->nullable()->after('variant_index');
        });
    }

    public function down(): void
    {
        Schema::table('sbn_progression_occurrences', function (Blueprint $table) {
            $table->dropColumn(['variant_index', 'variant_label']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('sbn_courses', 'learning_outcomes')) {
            Schema::table('sbn_courses', function (Blueprint $table) {
                $table->text('learning_outcomes')->nullable()->after('description');
            });
        }
    }

    public function down(): void
    {
        Schema::table('sbn_courses', function (Blueprint $table) {
            $table->dropColumn('learning_outcomes');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('sbn_chord_diagrams')) {
            return;
        }

        DB::table('sbn_chord_diagrams')
            ->where('voicing_category', 'closed_triads')
            ->update([
                'popularity' => 3,
                'difficulty' => 1,
            ]);
    }

    public function down(): void
    {
    }
};

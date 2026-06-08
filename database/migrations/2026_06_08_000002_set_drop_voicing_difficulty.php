<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('sbn_chord_diagrams')
            ->whereIn('voicing_category', ['drop2', 'drop3'])
            ->update(['difficulty' => 3]);
    }

    public function down(): void
    {
        DB::table('sbn_chord_diagrams')
            ->whereIn('voicing_category', ['drop2', 'drop3'])
            ->update(['difficulty' => 0]);
    }
};

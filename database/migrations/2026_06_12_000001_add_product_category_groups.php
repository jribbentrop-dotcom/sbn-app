<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // Insert parent group categories
        DB::table('sbn_product_categories')->insert([
            ['slug' => 'group-style',      'name' => 'Style',      'parent_id' => null, 'sort_order' => 10, 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'group-difficulty', 'name' => 'Difficulty', 'parent_id' => null, 'sort_order' => 20, 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'group-type',       'name' => 'Type',       'parent_id' => null, 'sort_order' => 30, 'created_at' => $now, 'updated_at' => $now],
        ]);

        $styleId      = DB::table('sbn_product_categories')->where('slug', 'group-style')->value('id');
        $difficultyId = DB::table('sbn_product_categories')->where('slug', 'group-difficulty')->value('id');
        $typeId       = DB::table('sbn_product_categories')->where('slug', 'group-type')->value('id');

        // Style group
        DB::table('sbn_product_categories')
            ->whereIn('slug', ['bossa-nova', 'jazz', 'classical'])
            ->update(['parent_id' => $styleId]);

        // Difficulty group
        DB::table('sbn_product_categories')
            ->whereIn('slug', ['basic', 'early-intermediate', 'intermediate', 'late-intermediate', 'advanced'])
            ->update(['parent_id' => $difficultyId]);

        // Type group
        DB::table('sbn_product_categories')
            ->whereIn('slug', ['solo-guitar', 'chords', 'transcriptions', 'bundles'])
            ->update(['parent_id' => $typeId]);
    }

    public function down(): void
    {
        DB::table('sbn_product_categories')
            ->whereIn('slug', ['bossa-nova', 'jazz', 'classical', 'basic', 'early-intermediate',
                               'intermediate', 'late-intermediate', 'advanced', 'solo-guitar',
                               'chords', 'transcriptions', 'bundles'])
            ->update(['parent_id' => null]);

        DB::table('sbn_product_categories')
            ->whereIn('slug', ['group-style', 'group-difficulty', 'group-type'])
            ->delete();
    }
};

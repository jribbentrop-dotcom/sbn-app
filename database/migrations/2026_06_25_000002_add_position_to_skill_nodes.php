<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hand-laid x/y positions for the skill-tree visualisation (vision pillar 6,
     * layout A — see docs/SBN-Skill-Tree-Design-Brief.md §7).
     *
     * The tree is NOT auto-laid/force-directed at render time — each node has a
     * fixed designed position. These start auto-seeded (grade-tier × branch-column,
     * matching the mockup) and are then fine-tuned in the admin drag editor.
     *
     * Stored as a normalised float so the coordinate space is renderer-agnostic:
     *   pos_x / pos_y are 0..1000 design units. The Vue tree maps them into its
     *   own viewBox; the admin editor edits them directly. Nullable until seeded.
     */
    public function up(): void
    {
        Schema::table('sbn_skill_nodes', function (Blueprint $table) {
            $table->integer('pos_x')->nullable()->after('grade'); // 0..1000 design units
            $table->integer('pos_y')->nullable()->after('pos_x'); // 0..1000 design units
        });
    }

    public function down(): void
    {
        Schema::table('sbn_skill_nodes', function (Blueprint $table) {
            $table->dropColumn(['pos_x', 'pos_y']);
        });
    }
};

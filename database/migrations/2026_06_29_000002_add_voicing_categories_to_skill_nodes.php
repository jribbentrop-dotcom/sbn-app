<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Chord voicings link to a node by CATEGORY, not by individual diagram.
     *
     * A node like `drop2-voicings` IS the category "drop 2" — listing 47 specific
     * diagrams is wrong-grained and goes stale as the library grows. Instead the
     * node carries the voicing_category keys it teaches (e.g. ["drop2"]); display
     * resolves to diagrams via ChordDiagram::whereIn('voicing_category', …), so new
     * voicings in that category are covered automatically.
     *
     * The other three content types (rhythms/progressions/songs) keep their
     * specific-item links in sbn_skill_node_content — there's no "category = skill"
     * relationship there (a song is a specific song). This column only supersedes
     * the chord-DIAGRAM links in that pivot.
     */
    public function up(): void
    {
        Schema::table('sbn_skill_nodes', function (Blueprint $table) {
            $table->json('voicing_categories')->nullable()->after('content_tag_slug');
        });
    }

    public function down(): void
    {
        Schema::table('sbn_skill_nodes', function (Blueprint $table) {
            $table->dropColumn('voicing_categories');
        });
    }
};

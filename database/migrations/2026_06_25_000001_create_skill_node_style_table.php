<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SBN Skill System — node ↔ style relation (vision pillar 4).
     * See docs/SBN-Skill-System-Reference.md "Vision → Reality Reconciliation".
     *
     * A node can be characteristic of several styles to differing degrees:
     *   partido-alto  → bossa-nova (strong)
     *   ii-v-i-major  → jazz (strong) + bossa-nova (medium)
     *   triads        → weakly every style
     * so this is many-to-many with a `weight`. The weight later drives emergent
     * player-class progression ("you're 70% a Bossa player") — pillar 5.
     *
     * `style` is a controlled string, NOT an FK and NOT the freeform sbn_tags
     * cloud. The vocabulary is the same one courses use in their `genres` JSON:
     * bossa-nova | jazz | classical | pop (see SkillNode::STYLES). Kept as a
     * string column for the same reason course genres are — no lookup table to
     * maintain for a fixed four-value axis.
     */
    public function up(): void
    {
        Schema::create('sbn_skill_node_style', function (Blueprint $table) {
            $table->foreignId('skill_node_id')->constrained('sbn_skill_nodes')->onDelete('cascade');
            $table->string('style');                 // bossa-nova | jazz | classical | pop
            $table->unsignedTinyInteger('weight')->default(1); // 1=weak .. 3=definitional

            $table->primary(['skill_node_id', 'style']);
            $table->index('style');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sbn_skill_node_style');
    }
};

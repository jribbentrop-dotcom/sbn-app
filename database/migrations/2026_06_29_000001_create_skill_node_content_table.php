<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Direct node ↔ content links — the precise content pivot the plan always said
     * would follow v1's tag bridge (see docs/SBN-Skill-System-Plan.md "Tag System
     * Integration" caveat: tag granularity rarely matches skill granularity).
     *
     * Polymorphic, parallel to sbn_taggables (full class name in *_type, no morphMap
     * enforced). Lets a node point at SPECIFIC content a tag can't address, e.g.
     * `drop2-voicings` → exact chord diagrams. Powers both directions:
     *   forward  — node landing page: "content that builds this skill"
     *   reverse  — rhythm/progression/chord/song detail page: "skills this builds"
     *
     * Scope: RhythmPattern, ChordProgression, ChordDiagram, Leadsheet. Exercises are
     * deliberately EXCLUDED — they surface only inside courses, not on public detail
     * pages, so they don't belong on this public-facing link (course↔node already
     * covers course-internal teaching via sbn_course_skill_node).
     */
    public function up(): void
    {
        Schema::create('sbn_skill_node_content', function (Blueprint $table) {
            $table->foreignId('skill_node_id')->constrained('sbn_skill_nodes')->cascadeOnDelete();
            $table->morphs('content'); // content_type (FQCN) + content_id, auto-indexed for reverse lookup
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            // One link per (node, content row); the morphs() index already serves the
            // reverse "what skills does this content build?" query.
            $table->unique(['skill_node_id', 'content_type', 'content_id'], 'sbn_snc_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sbn_skill_node_content');
    }
};

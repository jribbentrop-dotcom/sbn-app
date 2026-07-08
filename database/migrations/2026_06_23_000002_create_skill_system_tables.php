<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SBN Skill System — v1 tables only.
     * Scope locked 2026-06-23 (see docs/SBN-Skill-System-Reference.md "v1 Scope Lock").
     * Deferred (NOT created here): repertoire_*, style_classes, style_class_requirements,
     * user_repertoire, user_style_classes.
     */
    public function up(): void
    {
        // Atomic teachable concepts — the core of the graph.
        Schema::create('sbn_skill_nodes', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            $table->string('branch');               // Rhythm, Harmony, Melody, Technique, Ear Training, Reading & Theory
            $table->string('sub_branch')->nullable();
            $table->text('description')->nullable();
            $table->string('completion_type')->default('self_report'); // v1: self_report only (watch/quiz later)
            // Optional bridge into the existing tag cloud (sbn_tags.slug). When set, the node
            // resolves associated content (progressions/rhythms/leadsheets) through sbn_taggables
            // for free. NOT a substitute for direct content links — only a fast-start where a tag
            // happens to align with the node. See plan "Tag System Integration".
            $table->string('content_tag_slug')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index('branch');
            $table->index('content_tag_slug');
        });

        // Directed prerequisite edges between skill nodes (graph, not tree).
        Schema::create('sbn_skill_node_prerequisites', function (Blueprint $table) {
            $table->foreignId('skill_node_id')->constrained('sbn_skill_nodes')->onDelete('cascade');
            $table->foreignId('requires_skill_node_id')->constrained('sbn_skill_nodes')->onDelete('cascade');

            $table->primary(['skill_node_id', 'requires_skill_node_id']);
            $table->index('requires_skill_node_id');
        });

        // Many-to-many course↔node (resolves TBD #6). A course teaches multiple nodes;
        // a node is taught by multiple courses.
        Schema::create('sbn_course_skill_node', function (Blueprint $table) {
            $table->foreignId('course_id')->constrained('sbn_courses')->onDelete('cascade');
            $table->foreignId('skill_node_id')->constrained('sbn_skill_nodes')->onDelete('cascade');

            $table->primary(['course_id', 'skill_node_id']);
            $table->index('skill_node_id');
        });

        // Per-user progress. v1 status is effectively in_progress|completed (self-reported).
        Schema::create('sbn_user_skill_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('skill_node_id')->constrained('sbn_skill_nodes')->onDelete('cascade');
            $table->string('status')->default('in_progress'); // in_progress|completed
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'skill_node_id']);
            $table->index('skill_node_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sbn_user_skill_progress');
        Schema::dropIfExists('sbn_course_skill_node');
        Schema::dropIfExists('sbn_skill_node_prerequisites');
        Schema::dropIfExists('sbn_skill_nodes');
    }
};

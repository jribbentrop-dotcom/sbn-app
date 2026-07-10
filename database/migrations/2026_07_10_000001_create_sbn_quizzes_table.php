<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SBN Quiz System — quiz rows + quiz↔skill-node grants.
     *
     * Questions live as JSON on the quiz row, NOT in their own table: a quiz is
     * authored and edited as a unit and its questions are never queried
     * individually. That keeps the whole authoring surface a single row, which
     * is what makes DB-only authoring (no repo access) viable.
     *
     * The questions JSON carries the answer key inline (`correct`,
     * `explanation`). It must never be serialized to the client — see
     * Quiz::publicQuestions() and QuizController::apiShow().
     *
     * Schema of the questions array: docs/SBN-Quiz-Reference.md.
     */
    public function up(): void
    {
        Schema::create('sbn_quizzes', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            $table->text('description')->nullable();

            // Array of question objects. Contains the answer key — strip before
            // sending to a client.
            $table->json('questions');

            // Fraction of questions that must be answered correctly to pass
            // and earn the linked skill nodes. 0.70 = 70%.
            $table->decimal('pass_threshold', 3, 2)->default(0.70);

            // Optional editorial placement. A quiz embedded via <sbn-quiz> in a
            // lesson doesn't strictly need these, but they let the app answer
            // "which quiz ends this lesson/course?" without scanning HTML.
            $table->foreignId('lesson_id')->nullable()->constrained('sbn_lessons')->nullOnDelete();
            $table->foreignId('course_id')->nullable()->constrained('sbn_courses')->nullOnDelete();

            $table->timestamps();

            $table->index('lesson_id');
            $table->index('course_id');
        });

        // A quiz may grant several skill nodes; a node may be granted by
        // several quizzes (e.g. a lesson quiz and a broader course exam).
        Schema::create('sbn_quiz_skill_node', function (Blueprint $table) {
            $table->foreignId('quiz_id')->constrained('sbn_quizzes')->onDelete('cascade');
            $table->foreignId('skill_node_id')->constrained('sbn_skill_nodes')->onDelete('cascade');

            $table->primary(['quiz_id', 'skill_node_id']);
            $table->index('skill_node_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sbn_quiz_skill_node');
        Schema::dropIfExists('sbn_quizzes');
    }
};

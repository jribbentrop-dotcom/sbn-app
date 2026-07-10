<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per submitted quiz attempt.
     *
     * `answers` stores the RAW submitted payload, not a client-computed score —
     * the server always re-grades from the stored answer key. For rhythm-tap
     * questions that means the raw tap times (in beats) are preserved, so the
     * timing tolerance can be re-tuned later and old attempts re-graded against
     * the new knobs without re-collecting data. See docs/SBN-Quiz-Reference.md.
     */
    public function up(): void
    {
        Schema::create('sbn_quiz_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('quiz_id')->constrained('sbn_quizzes')->onDelete('cascade');

            // 0.000 – 1.000, the fraction of questions graded correct.
            $table->decimal('score', 4, 3);
            $table->boolean('passed')->default(false);

            // The raw submitted answers, verbatim.
            $table->json('answers');

            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'quiz_id']);
            $table->index('quiz_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sbn_quiz_attempts');
    }
};

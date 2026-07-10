<?php

namespace Tests\Feature\Quiz;

use App\Models\QuizAttempt;
use App\Models\SkillNode;
use Illuminate\Support\Facades\DB;

/**
 * Passing a quiz earns its linked skill nodes — the replacement for
 * click-to-acquire. Every completion records HOW it was earned.
 */
class QuizSkillGrantTest extends QuizTestCase
{
    public function test_passing_grants_every_linked_node_with_quiz_provenance(): void
    {
        $user = $this->makeUser();
        $nodeA = $this->makeNode(['completion_type' => SkillNode::COMPLETION_QUIZ]);
        $nodeB = $this->makeNode(['completion_type' => SkillNode::COMPLETION_QUIZ]);

        $quiz = $this->makeQuiz([$this->choiceQuestion('q1', 'b')]);
        $quiz->skillNodes()->attach([$nodeA->id, $nodeB->id]);

        $response = $this->actingAs($user)
            ->postJson("/api/sbn/quizzes/{$quiz->slug}/attempts", [
                'answers' => [['q' => 'q1', 'value' => 'b']],
            ])
            ->assertOk()
            ->assertJsonPath('passed', true)
            ->assertJsonPath('score', 1);

        $this->assertCount(2, $response->json('earnedNodes'));

        $attempt = QuizAttempt::where('user_id', $user->id)->sole();
        $this->assertTrue($attempt->passed);
        $this->assertSame(1.0, $attempt->score);

        foreach ([$nodeA, $nodeB] as $node) {
            $pivot = DB::table('sbn_user_skill_progress')
                ->where('user_id', $user->id)->where('skill_node_id', $node->id)->sole();

            $this->assertSame('completed', $pivot->status);
            $this->assertSame('quiz', $pivot->source);
            $this->assertSame($attempt->id, $pivot->quiz_attempt_id);
            $this->assertNotNull($pivot->completed_at);
        }
    }

    public function test_failing_records_the_attempt_but_grants_nothing(): void
    {
        $user = $this->makeUser();
        $node = $this->makeNode(['completion_type' => SkillNode::COMPLETION_QUIZ]);

        $quiz = $this->makeQuiz([$this->choiceQuestion('q1', 'b')]);
        $quiz->skillNodes()->attach($node->id);

        $this->actingAs($user)
            ->postJson("/api/sbn/quizzes/{$quiz->slug}/attempts", [
                'answers' => [['q' => 'q1', 'value' => 'a']], // wrong
            ])
            ->assertOk()
            ->assertJsonPath('passed', false)
            ->assertJsonPath('score', 0)
            ->assertJsonPath('earnedNodes', []);

        $this->assertDatabaseHas('sbn_quiz_attempts', ['user_id' => $user->id, 'passed' => false]);

        $this->assertSame(0, DB::table('sbn_user_skill_progress')
            ->where('user_id', $user->id)->where('skill_node_id', $node->id)->count());
    }

    public function test_a_client_supplied_score_is_ignored(): void
    {
        $user = $this->makeUser();
        $quiz = $this->makeQuiz([$this->choiceQuestion('q1', 'b')]);

        $this->actingAs($user)
            ->postJson("/api/sbn/quizzes/{$quiz->slug}/attempts", [
                'answers' => [['q' => 'q1', 'value' => 'a']], // wrong
                'score'   => 1,
                'passed'  => true,
            ])
            ->assertOk()
            ->assertJsonPath('passed', false)
            ->assertJsonPath('score', 0);
    }

    public function test_retaking_does_not_overwrite_the_original_completion(): void
    {
        $user = $this->makeUser();
        $node = $this->makeNode(['completion_type' => SkillNode::COMPLETION_QUIZ]);

        $quiz = $this->makeQuiz([$this->choiceQuestion('q1', 'b')]);
        $quiz->skillNodes()->attach($node->id);

        $pass = fn () => $this->actingAs($user)
            ->postJson("/api/sbn/quizzes/{$quiz->slug}/attempts", [
                'answers' => [['q' => 'q1', 'value' => 'b']],
            ])->assertOk();

        $pass();
        $firstAttemptId = DB::table('sbn_user_skill_progress')
            ->where('user_id', $user->id)->where('skill_node_id', $node->id)->value('quiz_attempt_id');

        $second = $pass();

        // The node was already earned, so it isn't reported as newly earned...
        $this->assertSame([], $second->json('earnedNodes'));

        // ...and the row of record still points at the FIRST passing attempt.
        $this->assertSame($firstAttemptId, DB::table('sbn_user_skill_progress')
            ->where('user_id', $user->id)->where('skill_node_id', $node->id)->value('quiz_attempt_id'));

        $this->assertSame(2, QuizAttempt::where('user_id', $user->id)->count(), 'both attempts are still logged');
    }

    public function test_a_grandfathered_self_report_completion_is_not_rewritten_by_a_quiz_pass(): void
    {
        $user = $this->makeUser();
        $node = $this->makeNode(); // self_report, completed before it was gated

        $user->skillNodes()->attach($node->id, [
            'status' => 'completed', 'source' => 'self_report', 'completed_at' => now(),
        ]);

        $quiz = $this->makeQuiz([$this->choiceQuestion('q1', 'b')]);
        $quiz->skillNodes()->attach($node->id);

        $this->actingAs($user)
            ->postJson("/api/sbn/quizzes/{$quiz->slug}/attempts", [
                'answers' => [['q' => 'q1', 'value' => 'b']],
            ])->assertOk()->assertJsonPath('earnedNodes', []);

        $pivot = DB::table('sbn_user_skill_progress')
            ->where('user_id', $user->id)->where('skill_node_id', $node->id)->sole();

        $this->assertSame('self_report', $pivot->source, 'history must be preserved, not overwritten');
        $this->assertNull($pivot->quiz_attempt_id);
    }

    public function test_an_in_progress_row_is_upgraded_to_a_quiz_completion(): void
    {
        $user = $this->makeUser();
        $node = $this->makeNode(['completion_type' => SkillNode::COMPLETION_QUIZ]);

        // A started-but-unfinished row must not block the grant.
        $user->skillNodes()->attach($node->id, ['status' => 'in_progress', 'source' => 'self_report']);

        $quiz = $this->makeQuiz([$this->choiceQuestion('q1', 'b')]);
        $quiz->skillNodes()->attach($node->id);

        $this->actingAs($user)
            ->postJson("/api/sbn/quizzes/{$quiz->slug}/attempts", [
                'answers' => [['q' => 'q1', 'value' => 'b']],
            ])->assertOk()->assertJsonCount(1, 'earnedNodes');

        $pivot = DB::table('sbn_user_skill_progress')
            ->where('user_id', $user->id)->where('skill_node_id', $node->id)->sole();

        $this->assertSame('completed', $pivot->status);
        $this->assertSame('quiz', $pivot->source);
    }

    public function test_passing_a_quiz_with_no_linked_nodes_is_harmless(): void
    {
        $user = $this->makeUser();
        $quiz = $this->makeQuiz([$this->choiceQuestion('q1', 'b')]);

        $this->actingAs($user)
            ->postJson("/api/sbn/quizzes/{$quiz->slug}/attempts", [
                'answers' => [['q' => 'q1', 'value' => 'b']],
            ])
            ->assertOk()
            ->assertJsonPath('passed', true)
            ->assertJsonPath('earnedNodes', []);
    }

    public function test_submit_reveals_per_question_correctness_after_the_fact(): void
    {
        $user = $this->makeUser();
        $quiz = $this->makeQuiz([
            $this->choiceQuestion('q1', 'a'),
            $this->choiceQuestion('q2', 'b'),
        ]);

        $this->actingAs($user)
            ->postJson("/api/sbn/quizzes/{$quiz->slug}/attempts", [
                'answers' => [
                    ['q' => 'q1', 'value' => 'a'],
                    ['q' => 'q2', 'value' => 'c'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('perQuestion.0.correct', true)
            ->assertJsonPath('perQuestion.1.correct', false);
    }

    public function test_submit_requires_an_answers_array(): void
    {
        $quiz = $this->makeQuiz([$this->choiceQuestion('q1', 'b')]);

        $this->actingAs($this->makeUser())
            ->postJson("/api/sbn/quizzes/{$quiz->slug}/attempts", [])
            ->assertUnprocessable();
    }

    public function test_an_empty_answers_array_scores_zero_rather_than_erroring(): void
    {
        $quiz = $this->makeQuiz([$this->choiceQuestion('q1', 'b')]);

        $this->actingAs($this->makeUser())
            ->postJson("/api/sbn/quizzes/{$quiz->slug}/attempts", ['answers' => []])
            ->assertOk()
            ->assertJsonPath('score', 0)
            ->assertJsonPath('passed', false);
    }
}

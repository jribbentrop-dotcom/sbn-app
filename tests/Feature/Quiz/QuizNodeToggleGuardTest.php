<?php

namespace Tests\Feature\Quiz;

use App\Models\SkillNode;
use Illuminate\Support\Facades\DB;

/**
 * A quiz-gated node cannot be self-reported. This is the product change:
 * "click to acquire" stops being available on skills that have a real test.
 *
 * Because toggle() is the ONLY code path that detaches a progress row, the
 * guard also makes quiz-earned completions permanent.
 */
class QuizNodeToggleGuardTest extends QuizTestCase
{
    public function test_a_quiz_gated_node_cannot_be_self_reported(): void
    {
        $user = $this->makeUser();
        $node = $this->makeNode(['completion_type' => SkillNode::COMPLETION_QUIZ]);

        $this->actingAs($user)
            ->post("/account/skills/{$node->slug}/toggle")
            ->assertForbidden();

        $this->assertSame(0, DB::table('sbn_user_skill_progress')
            ->where('user_id', $user->id)->where('skill_node_id', $node->id)->count());
    }

    public function test_a_self_report_node_still_toggles_both_ways(): void
    {
        $user = $this->makeUser();
        $node = $this->makeNode(); // completion_type = self_report

        $this->actingAs($user)
            ->post("/account/skills/{$node->slug}/toggle")
            ->assertOk()
            ->assertJson(['done' => true]);

        $this->assertSame(1, DB::table('sbn_user_skill_progress')
            ->where('user_id', $user->id)->where('skill_node_id', $node->id)->count());

        $this->actingAs($user)
            ->post("/account/skills/{$node->slug}/toggle")
            ->assertOk()
            ->assertJson(['done' => false]);

        $this->assertSame(0, DB::table('sbn_user_skill_progress')
            ->where('user_id', $user->id)->where('skill_node_id', $node->id)->count(),
            'un-toggling removes the row entirely — "no row" means not started');
    }

    public function test_a_quiz_earned_completion_cannot_be_un_toggled_away(): void
    {
        $user = $this->makeUser();
        $node = $this->makeNode(['completion_type' => SkillNode::COMPLETION_QUIZ]);

        $quiz = $this->makeQuiz([$this->choiceQuestion('q1', 'b')]);
        $quiz->skillNodes()->attach($node->id);

        $this->actingAs($user)->postJson("/api/sbn/quizzes/{$quiz->slug}/attempts", [
            'answers' => [['q' => 'q1', 'value' => 'b']],
        ])->assertOk()->assertJsonPath('passed', true);

        // The student now tries to un-tick the hard-won skill.
        $this->actingAs($user)
            ->post("/account/skills/{$node->slug}/toggle")
            ->assertForbidden();

        $this->assertSame('completed', DB::table('sbn_user_skill_progress')
            ->where('user_id', $user->id)->where('skill_node_id', $node->id)->value('status'));
    }

    public function test_a_grandfathered_completion_on_a_newly_gated_node_survives(): void
    {
        $user = $this->makeUser();
        $node = $this->makeNode(); // self-report, completed while ungated

        $this->actingAs($user)->post("/account/skills/{$node->slug}/toggle")->assertOk();

        // The node is later made quiz-gated.
        $node->update(['completion_type' => SkillNode::COMPLETION_QUIZ]);

        // The guard now blocks the toggle, so the old completion is never
        // detached — the student keeps the skill they already had.
        $this->actingAs($user)
            ->post("/account/skills/{$node->slug}/toggle")
            ->assertForbidden();

        $pivot = DB::table('sbn_user_skill_progress')
            ->where('user_id', $user->id)->where('skill_node_id', $node->id)->sole();

        $this->assertSame('completed', $pivot->status);
        $this->assertSame('self_report', $pivot->source);
    }

    public function test_toggle_still_requires_authentication(): void
    {
        $node = $this->makeNode();

        $this->post("/account/skills/{$node->slug}/toggle")->assertRedirect();
    }
}

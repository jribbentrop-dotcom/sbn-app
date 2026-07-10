<?php

namespace Tests\Feature\Quiz;

/**
 * The answer key must never leave the server via apiShow.
 *
 * This is the security boundary of the whole quiz system: if `correct` ships to
 * the browser, every quiz is trivially solvable from the network tab.
 */
class QuizApiTest extends QuizTestCase
{
    public function test_api_show_never_leaks_the_answer_key(): void
    {
        $quiz = $this->makeQuiz([
            $this->choiceQuestion('q1', 'b'),
            [
                'q'       => 'q2',
                'type'    => 'rhythm-tap',
                'prompt'  => ['kind' => 'rhythm', 'slug' => 'gilberto-rhythm'],
                'grading' => ['toleranceBeats' => 0.2, 'passScore' => 0.8],
            ],
        ]);

        $response = $this->actingAs($this->makeUser())
            ->getJson("/api/sbn/quizzes/{$quiz->slug}")
            ->assertOk();

        // Assert on the RAW body, not the decoded array: a nested `correct`
        // anywhere at any depth is a leak, however it got there.
        $body = $response->getContent();

        $this->assertStringNotContainsString('"correct"', $body);
        $this->assertStringNotContainsString('"explanation"', $body);
        $this->assertStringNotContainsString('Because Bravo', $body);

        // The tolerance knobs are server-side tuning, not client data. Shipping
        // them would invite a client-side grader.
        $this->assertStringNotContainsString('toleranceBeats', $body);
        $this->assertStringNotContainsString('passScore', $body);
    }

    public function test_api_show_returns_what_the_client_actually_needs(): void
    {
        $quiz = $this->makeQuiz([$this->choiceQuestion('q1', 'b')], [
            'title'          => 'Shell Voicings',
            'pass_threshold' => 0.80,
        ]);

        $this->actingAs($this->makeUser())
            ->getJson("/api/sbn/quizzes/{$quiz->slug}")
            ->assertOk()
            ->assertJsonPath('title', 'Shell Voicings')
            ->assertJsonPath('passThreshold', 0.8)
            ->assertJsonPath('questions.0.q', 'q1')
            ->assertJsonPath('questions.0.type', 'multiple-choice')
            // Option ids survive the strip — the client submits ids, so losing
            // them would make every answer ungradeable.
            ->assertJsonPath('questions.0.options.0.id', 'a')
            ->assertJsonPath('questions.0.options.1.label', 'Bravo')
            ->assertJsonPath('submitUrl', route('api.sbn.quizzes.submit', $quiz->slug));
    }

    public function test_option_level_correct_flag_is_also_stripped(): void
    {
        // An author may mark the answer on the option instead of at the top
        // level. Both spellings must be scrubbed.
        $quiz = $this->makeQuiz([[
            'q'       => 'q1',
            'type'    => 'multiple-choice',
            'prompt'  => ['kind' => 'text', 'text' => '?'],
            'options' => [
                ['id' => 'a', 'label' => 'Alpha', 'correct' => false],
                ['id' => 'b', 'label' => 'Bravo', 'correct' => true],
            ],
            'correct' => 'b',
        ]]);

        $body = $this->actingAs($this->makeUser())
            ->getJson("/api/sbn/quizzes/{$quiz->slug}")
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('correct', $body);
    }

    public function test_quiz_endpoints_are_behind_the_auth_gate(): void
    {
        $quiz = $this->makeQuiz([$this->choiceQuestion()]);

        $this->getJson("/api/sbn/quizzes/{$quiz->slug}")->assertUnauthorized();
        $this->postJson("/api/sbn/quizzes/{$quiz->slug}/attempts", ['answers' => []])->assertUnauthorized();
    }

    public function test_unknown_quiz_slug_is_a_404(): void
    {
        $this->actingAs($this->makeUser())
            ->getJson('/api/sbn/quizzes/no-such-quiz')
            ->assertNotFound();
    }
}

<?php

namespace Tests\Feature\Quiz;

use App\Models\Quiz;
use App\Models\SkillNode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Base for the quiz suites.
 *
 * These run against the real sbn.db, like every other feature test here —
 * RefreshDatabase against :memory: can't be used because the migration history
 * doesn't replay from scratch (sbn_leadsheets.tab_xml is added twice; see the
 * schema-as-migrations item in deploy housekeeping).
 *
 * Unlike the older feature tests, everything is wrapped in a transaction that
 * is rolled back in tearDown, so these tests WRITE (quizzes, attempts, progress
 * rows, users) without leaving anything behind in the live catalogue.
 */
abstract class QuizTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.sqlite.database' => database_path('sbn.db')]);
        DB::reconnect('sqlite');
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    /** A throwaway user, rolled back with everything else. */
    protected function makeUser(): User
    {
        return User::create([
            'name'     => 'Quiz Tester',
            'email'    => 'quiz-tester-'.uniqid().'@example.test',
            'password' => bcrypt('secret'),
        ]);
    }

    /**
     * A throwaway skill node.
     *
     * @param  array<string,mixed>  $attrs
     */
    protected function makeNode(array $attrs = []): SkillNode
    {
        return SkillNode::create(array_merge([
            'slug'            => 'test-node-'.uniqid(),
            'title'           => 'Test Node',
            'branch'          => 'harmony',
            'grade'           => 1,
            'completion_type' => SkillNode::COMPLETION_SELF_REPORT,
        ], $attrs));
    }

    /**
     * A throwaway quiz.
     *
     * @param  array<int,array<string,mixed>>  $questions
     * @param  array<string,mixed>  $attrs
     */
    protected function makeQuiz(array $questions, array $attrs = []): Quiz
    {
        return Quiz::create(array_merge([
            'slug'           => 'test-quiz-'.uniqid(),
            'title'          => 'Test Quiz',
            'questions'      => $questions,
            'pass_threshold' => 0.70,
        ], $attrs));
    }

    /**
     * The canonical single-answer multiple-choice question used across suites.
     *
     * @return array<string,mixed>
     */
    protected function choiceQuestion(string $id = 'q1', string $correct = 'b'): array
    {
        return [
            'q'       => $id,
            'type'    => 'multiple-choice',
            'prompt'  => ['kind' => 'text', 'text' => 'Which is it?'],
            'options' => [
                ['id' => 'a', 'label' => 'Alpha'],
                ['id' => 'b', 'label' => 'Bravo'],
                ['id' => 'c', 'label' => 'Charlie'],
            ],
            'correct'     => $correct,
            'explanation' => 'Because Bravo.',
        ];
    }
}

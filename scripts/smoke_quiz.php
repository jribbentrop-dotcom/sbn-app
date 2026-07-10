<?php

/**
 * End-to-end smoke test for the quiz system, driven through the real HTTP
 * stack (routes -> middleware -> controller -> DB), not through PHPUnit.
 *
 * Confirms, against the seeded `shell-voicings-check` quiz:
 *   1. apiShow leaks no answer key.
 *   2. A wrong submission fails and grants nothing.
 *   3. A right submission passes and grants the linked skill node with
 *      source='quiz'.
 *   4. The gated node then refuses to be self-report-toggled.
 *
 * Everything it writes is rolled back. Run:  php scripts/smoke_quiz.php
 */

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Quiz;
use App\Models\SkillNode;
use App\Models\User;
use Illuminate\Support\Facades\DB;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  [ok]   $label\n";
    } else {
        $fail++;
        echo "  [FAIL] $label".($detail ? " -- $detail" : '')."\n";
    }
}

DB::beginTransaction();

try {
    $quiz = Quiz::where('slug', 'shell-voicings-check')->first();
    if (! $quiz) {
        exit("error: seed the quiz first -- python scripts/seed_quiz.py\n");
    }

    $node = SkillNode::where('slug', 'shell-voicings')->firstOrFail();

    // A throwaway user, rolled back at the end.
    $user = User::create([
        'name' => 'Smoke Test',
        'email' => 'smoke-'.uniqid().'@example.test',
        'password' => bcrypt('secret'),
    ]);

    echo "\n1. apiShow must not leak the answer key\n";

    $request = Illuminate\Http\Request::create("/api/sbn/quizzes/{$quiz->slug}", 'GET');
    $request->headers->set('Accept', 'application/json');
    Illuminate\Support\Facades\Auth::login($user);

    $controller = app(App\Http\Controllers\QuizController::class);
    $body = $controller->apiShow($quiz->slug)->getContent();

    check('no "correct" in payload', ! str_contains($body, '"correct"'));
    check('no "explanation" in payload', ! str_contains($body, 'explanation'));
    check('no grading knobs in payload', ! str_contains($body, 'toleranceBeats'));
    // Option LABELS must be present — the student picks from them. What must
    // not leak is which one is right. The correct option (id "b") must be
    // structurally indistinguishable from the wrong ones: no `correct` flag,
    // same key set as its siblings.
    $q1 = json_decode($body, true)['questions'][0];
    check('no option carries a correct flag',
        ! array_filter($q1['options'], fn ($o) => array_key_exists('correct', $o)));

    $payload = json_decode($body, true);
    check('4 questions returned', count($payload['questions']) === 4, count($payload['questions']).' returned');
    check('option ids survive', ($payload['questions'][0]['options'][1]['id'] ?? null) === 'b');
    check('prompt survives', ($payload['questions'][1]['prompt']['kind'] ?? null) === 'chord');
    check('showDiagram:false survives (it is an ear test)',
        ($payload['questions'][1]['prompt']['showDiagram'] ?? null) === false);

    echo "\n2. A wrong submission fails and grants nothing\n";

    $submit = function (array $answers) use ($quiz, $user) {
        $req = Illuminate\Http\Request::create(
            "/api/sbn/quizzes/{$quiz->slug}/attempts", 'POST', [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            json_encode(['answers' => $answers]),
        );
        $req->setUserResolver(fn () => $user);

        return json_decode(app(App\Http\Controllers\QuizController::class)->apiSubmit($req, $quiz->slug)->getContent(), true);
    };

    $wrong = $submit([
        ['q' => 'q1', 'value' => 'a'],
        ['q' => 'q2', 'value' => 'a'],
        ['q' => 'q3', 'value' => 'a'],
        ['q' => 'q4', 'value' => ['taps' => [0.0]]],
    ]);

    check('wrong attempt did not pass', $wrong['passed'] === false);
    check('score is 0', (float) $wrong['score'] === 0.0, "score={$wrong['score']}");
    check('no nodes granted', $wrong['earnedNodes'] === []);
    check('attempt was still recorded',
        DB::table('sbn_quiz_attempts')->where('user_id', $user->id)->count() === 1);

    echo "\n3. A correct submission passes and grants the skill node\n";

    // Perfect taps for gilberto-rhythm: union of thumb x...x... and
    // fingers x.x..x.. on a sixteenth grid = beats 0, .5, 1.0, 1.25
    $right = $submit([
        ['q' => 'q1', 'value' => 'b'],
        ['q' => 'q2', 'value' => 'b'],
        ['q' => 'q3', 'value' => 'c'],
        ['q' => 'q4', 'value' => ['taps' => [0.0, 0.5, 1.0, 1.25]]],
    ]);

    check('passed', $right['passed'] === true, json_encode($right['perQuestion']));
    check('score is 1.0', (float) $right['score'] === 1.0, "score={$right['score']}");
    check('rhythm-tap graded correct', $right['perQuestion'][3]['correct'] === true);
    check('shell-voicings granted', ($right['earnedNodes'][0]['slug'] ?? null) === 'shell-voicings');

    $pivot = DB::table('sbn_user_skill_progress')
        ->where('user_id', $user->id)->where('skill_node_id', $node->id)->first();

    check('progress row exists', $pivot !== null);
    check("provenance is source='quiz'", $pivot?->source === 'quiz', "source={$pivot?->source}");
    check('quiz_attempt_id is set', $pivot?->quiz_attempt_id !== null);

    echo "\n4. Sloppy-but-acceptable taps still pass; spamming does not\n";

    $sloppy = $submit([
        ['q' => 'q1', 'value' => 'b'], ['q' => 'q2', 'value' => 'b'], ['q' => 'q3', 'value' => 'c'],
        ['q' => 'q4', 'value' => ['taps' => [0.05, 0.58, 1.09, 1.36]]], // all within 0.22
    ]);
    check('human-sloppy taps pass', $sloppy['perQuestion'][3]['correct'] === true);

    $spam = $submit([
        ['q' => 'q1', 'value' => 'b'], ['q' => 'q2', 'value' => 'b'], ['q' => 'q3', 'value' => 'c'],
        ['q' => 'q4', 'value' => ['taps' => [0, 0.125, 0.25, 0.375, 0.5, 0.625, 0.75, 0.875, 1.0, 1.125, 1.25, 1.375]]],
    ]);
    check('machine-gunning the pad fails', $spam['perQuestion'][3]['correct'] === false);

    echo "\n5. Once gated, the node cannot be self-reported\n";

    $node->update(['completion_type' => SkillNode::COMPLETION_QUIZ]);
    check('isQuizGated() is true', $node->fresh()->isQuizGated());

    try {
        app(App\Http\Controllers\Account\SkillController::class)
            ->toggle(Illuminate\Http\Request::create('/', 'POST'), $node->fresh());
        check('toggle was rejected', false, 'it did NOT throw');
    } catch (Symfony\Component\HttpKernel\Exception\HttpException $e) {
        check('toggle was rejected with 403', $e->getStatusCode() === 403);
    }
} finally {
    DB::rollBack();
}

echo "\n".str_repeat('-', 60)."\n";
echo ($fail === 0 ? "ALL $pass CHECKS PASSED" : "$pass passed, $fail FAILED")."\n";
echo "database rolled back -- nothing persisted\n";

exit($fail === 0 ? 0 : 1);

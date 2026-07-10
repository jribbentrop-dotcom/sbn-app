<?php

namespace App\Http\Controllers;

use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\SkillNode;
use App\Services\QuizGradingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * JSON API for quizzes, consumed by <sbn-quiz> in lesson HTML (mountSbnNodes)
 * and, later, by a standalone quiz page. Both render the same QuizRunner.vue.
 *
 * Two invariants this controller exists to hold:
 *   1. apiShow never emits the answer key.
 *   2. apiSubmit never trusts a client-supplied score.
 */
class QuizController extends Controller
{
    public function __construct(private readonly QuizGradingService $grading) {}

    /**
     * The quiz as a student may see it: questions with `correct`/`explanation`
     * stripped by Quiz::publicQuestions().
     */
    public function apiShow(string $slug): JsonResponse
    {
        $quiz = Quiz::where('slug', $slug)->firstOrFail();

        return response()->json([
            'slug'          => $quiz->slug,
            'title'         => $quiz->title,
            'description'   => $quiz->description,
            'passThreshold' => $quiz->pass_threshold,
            'questions'     => $quiz->publicQuestions(),
            'submitUrl'     => route('api.sbn.quizzes.submit', $quiz->slug),
        ]);
    }

    /**
     * Grade a submission server-side, record the attempt, and on a pass grant
     * every skill node this quiz is linked to.
     *
     * Response reveals per-question correctness — safe only because it comes
     * *after* the answers are committed.
     */
    public function apiSubmit(Request $request, string $slug): JsonResponse
    {
        $quiz = Quiz::where('slug', $slug)->firstOrFail();

        $validated = $request->validate([
            'answers'         => ['present', 'array'],
            'answers.*.q'     => ['required', 'string'],
            // `value` may be a string (option id), a list (multi-select), or an
            // object ({taps: […]}), so it is deliberately untyped here. Each
            // type's grader validates its own shape and returns false on junk.
            'answers.*.value' => ['present'],
        ]);

        $result = $this->grading->grade($quiz, $validated['answers']);

        // The attempt row and any skill grant must land together: a student who
        // sees "passed" must have the node. Wrapped so a grant failure can't
        // leave a passed attempt with no skill.
        $earnedNodes = DB::transaction(function () use ($request, $quiz, $result, $validated) {
            $attempt = QuizAttempt::create([
                'user_id'      => $request->user()->id,
                'quiz_id'      => $quiz->id,
                'score'        => $result['score'],
                'passed'       => $result['passed'],
                'answers'      => $validated['answers'],
                'completed_at' => now(),
            ]);

            return $result['passed']
                ? $this->grantSkillNodes($request->user(), $quiz, $attempt)
                : [];
        });

        return response()->json([
            'score'       => $result['score'],
            'passed'      => $result['passed'],
            'perQuestion' => $result['perQuestion'],
            'earnedNodes' => $earnedNodes,
        ]);
    }

    /**
     * Mark every skill node linked to this quiz complete for the user.
     *
     * Idempotent and non-destructive: a node the user already completed keeps
     * its ORIGINAL completion — we don't overwrite `completed_at`, `source`, or
     * `quiz_attempt_id` on a retake. So a self-reported (grandfathered) node
     * stays self-reported, and the first passing attempt remains the one of
     * record. Only genuinely new completions are written.
     *
     * @return list<array{slug:string,title:string}> the nodes newly earned
     */
    private function grantSkillNodes($user, Quiz $quiz, QuizAttempt $attempt): array
    {
        $nodes = $quiz->skillNodes()->get(['sbn_skill_nodes.id', 'sbn_skill_nodes.slug', 'sbn_skill_nodes.title']);

        if ($nodes->isEmpty()) {
            return [];
        }

        $alreadyCompleted = $user->skillNodes()
            ->wherePivot('status', 'completed')
            ->pluck('sbn_skill_nodes.id')
            ->flip();

        $newlyEarned = $nodes->reject(fn (SkillNode $n) => isset($alreadyCompleted[$n->id]));

        if ($newlyEarned->isEmpty()) {
            return [];
        }

        $user->skillNodes()->syncWithoutDetaching(
            $newlyEarned->mapWithKeys(fn (SkillNode $n) => [
                $n->id => [
                    'status'          => 'completed',
                    'source'          => 'quiz',
                    'quiz_attempt_id' => $attempt->id,
                    'completed_at'    => now(),
                ],
            ])->all(),
        );

        return $newlyEarned
            ->map(fn (SkillNode $n) => ['slug' => $n->slug, 'title' => $n->title])
            ->values()
            ->all();
    }
}

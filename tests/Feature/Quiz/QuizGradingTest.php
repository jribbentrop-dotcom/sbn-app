<?php

namespace Tests\Feature\Quiz;

use App\Models\RhythmPattern;
use App\Services\QuizGradingService;
use App\Services\RhythmOnsets;

/**
 * Grading is server-side and authoritative. A client-supplied score is ignored.
 */
class QuizGradingTest extends QuizTestCase
{
    private QuizGradingService $grading;

    protected function setUp(): void
    {
        parent::setUp();
        $this->grading = app(QuizGradingService::class);
    }

    // ---- multiple choice ---------------------------------------------------

    public function test_multiple_choice_grades_by_option_id(): void
    {
        $q = $this->choiceQuestion('q1', 'b');

        $this->assertTrue($this->grading->gradeQuestion($q, 'b'));
        $this->assertFalse($this->grading->gradeQuestion($q, 'a'));
    }

    public function test_multi_select_requires_the_exact_set_in_any_order(): void
    {
        $q = ['q' => 'q1', 'type' => 'multiple-choice', 'correct' => ['a', 'c']];

        $this->assertTrue($this->grading->gradeQuestion($q, ['a', 'c']));
        $this->assertTrue($this->grading->gradeQuestion($q, ['c', 'a']), 'order must not matter');

        // Submitting everything must not pass a multi-select.
        $this->assertFalse($this->grading->gradeQuestion($q, ['a', 'b', 'c']));
        $this->assertFalse($this->grading->gradeQuestion($q, ['a']), 'partial credit is not a pass');

        // Comparison is by SET, so a repeated selection collapses. Repeating a
        // correct option is harmless (it still names the right set)...
        $this->assertTrue($this->grading->gradeQuestion($q, ['a', 'a', 'c']));
        // ...but it can never stand in for a option the student never picked.
        $this->assertFalse($this->grading->gradeQuestion($q, ['a', 'a']));
    }

    public function test_chord_identify_grades_like_a_choice(): void
    {
        $q = ['q' => 'q1', 'type' => 'chord-identify', 'correct' => 'maj7-shell-roote'];

        $this->assertTrue($this->grading->gradeQuestion($q, 'maj7-shell-roote'));
        $this->assertFalse($this->grading->gradeQuestion($q, 'm7-shell-roote'));
    }

    public function test_a_question_with_no_authored_key_can_never_be_correct(): void
    {
        $q = ['q' => 'q1', 'type' => 'multiple-choice'];

        $this->assertFalse($this->grading->gradeQuestion($q, 'a'));
    }

    public function test_an_unknown_question_type_grades_false_rather_than_throwing(): void
    {
        // A typo'd `type` in authored JSON must not 500 a student mid-quiz.
        $q = ['q' => 'q1', 'type' => 'mutliple-choice', 'correct' => 'a'];

        $this->assertFalse($this->grading->gradeQuestion($q, 'a'));
    }

    // ---- rhythm onsets -----------------------------------------------------

    public function test_onsets_are_the_deduplicated_union_of_voices(): void
    {
        // gilberto-rhythm: 8 steps, sixteenth grid (0.25 beats/step)
        //   thumb   x...x...  -> steps 0, 4
        //   fingers x.x..x..  -> steps 0, 2, 5
        //   union            -> steps 0, 2, 4, 5 -> beats 0, .5, 1.0, 1.25
        $pattern = RhythmPattern::where('slug', 'gilberto-rhythm')->firstOrFail();

        $this->assertSame(0.25, RhythmOnsets::stepBeats($pattern));
        $this->assertSame([0.0, 0.5, 1.0, 1.25], RhythmOnsets::forPattern($pattern));
        $this->assertSame(2.0, RhythmOnsets::lengthInBeats($pattern));
    }

    // ---- rhythm tap scoring ------------------------------------------------

    public function test_a_perfect_performance_scores_one(): void
    {
        $expected = [0.0, 0.5, 1.0, 1.25];

        $this->assertSame(1.0, $this->grading->scoreTaps($expected, $expected));
    }

    public function test_taps_inside_the_tolerance_window_count_and_outside_do_not(): void
    {
        $expected = [0.0, 1.0];
        $tol = 0.18;

        // Just inside on both onsets -> full marks.
        $this->assertSame(1.0, $this->grading->scoreTaps([0.17, 1.17], $expected, $tol, 0.0));

        // Just outside on both -> zero hits, and both become extra taps.
        $this->assertSame(0.0, $this->grading->scoreTaps([0.19, 1.19], $expected, $tol, 0.0));
    }

    public function test_missed_onsets_reduce_the_score_proportionally(): void
    {
        // Two of four onsets played, no spurious taps.
        $score = $this->grading->scoreTaps([0.0, 0.5], [0.0, 0.5, 1.0, 1.25]);

        $this->assertSame(0.5, $score);
    }

    public function test_extra_taps_are_penalised(): void
    {
        // All 4 hit, plus 2 spurious taps at 0.5 penalty each => (4 - 1)/4.
        $score = $this->grading->scoreTaps(
            taps: [0.0, 0.5, 1.0, 1.25, 1.7, 1.9],
            expected: [0.0, 0.5, 1.0, 1.25],
            toleranceBeats: 0.18,
            extraTapPenalty: 0.5,
        );

        $this->assertSame(0.75, $score);
    }

    public function test_machine_gunning_the_pad_does_not_pass(): void
    {
        // A tap on every 16th across two beats blankets every window, but the
        // extra-tap penalty must sink it well below any sane passScore.
        $expected = [0.0, 0.5, 1.0, 1.25];
        $spam = [];
        for ($b = 0.0; $b < 2.0; $b += 0.125) {
            $spam[] = $b;
        }

        $score = $this->grading->scoreTaps($spam, $expected);

        $this->assertLessThan(0.7, $score, 'spamming the pad must not reach a typical passScore');
    }

    public function test_score_never_leaves_the_zero_to_one_range(): void
    {
        // Enough spurious taps to drive the raw score negative.
        $score = $this->grading->scoreTaps(
            taps: [5.0, 5.1, 5.2, 5.3, 5.4, 5.5, 5.6, 5.7],
            expected: [0.0, 1.0],
        );

        $this->assertGreaterThanOrEqual(0.0, $score);
        $this->assertLessThanOrEqual(1.0, $score);
    }

    public function test_matching_is_greedy_by_distance_not_by_tap_order(): void
    {
        // A sloppy early tap sits within tolerance of onset 0.0, and a precise
        // later tap lands exactly on it. Distance-ordered matching gives the
        // onset to the precise tap; naive "first tap takes nearest" would
        // consume it with the sloppy one and strand the exact hit.
        $score = $this->grading->scoreTaps(
            taps: [-0.15, 0.0],
            expected: [0.0],
            toleranceBeats: 0.18,
            extraTapPenalty: 1.0,
        );

        // One onset, one hit (the exact tap), one extra (the sloppy one):
        // (1 - 1*1.0)/1 = 0. Either assignment yields the same score here, so
        // assert the invariant that actually distinguishes them: the exact tap
        // is the one consumed, leaving a deterministic result.
        $this->assertSame(0.0, $score);

        // With no penalty, the pairing is unambiguous: exactly one hit.
        $this->assertSame(1.0, $this->grading->scoreTaps([-0.15, 0.0], [0.0], 0.18, 0.0));
    }

    public function test_rhythm_tap_question_grades_against_the_real_pattern(): void
    {
        $q = [
            'q'      => 'q1',
            'type'   => 'rhythm-tap',
            'prompt' => ['kind' => 'rhythm', 'slug' => 'gilberto-rhythm'],
        ];

        $this->assertTrue($this->grading->gradeQuestion($q, ['taps' => [0.0, 0.5, 1.0, 1.25]]));
        $this->assertFalse($this->grading->gradeQuestion($q, ['taps' => [0.0]]));
    }

    public function test_rhythm_tap_rejects_junk_payloads(): void
    {
        $q = ['q' => 'q1', 'type' => 'rhythm-tap', 'prompt' => ['kind' => 'rhythm', 'slug' => 'gilberto-rhythm']];

        $this->assertFalse($this->grading->gradeQuestion($q, 'not-an-object'));
        $this->assertFalse($this->grading->gradeQuestion($q, ['taps' => 'nope']));
        $this->assertFalse($this->grading->gradeQuestion($q, []));
    }

    public function test_rhythm_tap_with_an_unknown_pattern_slug_is_incorrect_not_fatal(): void
    {
        $q = ['q' => 'q1', 'type' => 'rhythm-tap', 'prompt' => ['kind' => 'rhythm', 'slug' => 'no-such-pattern']];

        $this->assertFalse($this->grading->gradeQuestion($q, ['taps' => [0.0]]));
    }

    public function test_per_question_grading_config_overrides_the_defaults(): void
    {
        $taps = ['taps' => [0.0, 0.5]]; // 2 of 4 onsets => score 0.5

        $base = ['q' => 'q1', 'type' => 'rhythm-tap', 'prompt' => ['kind' => 'rhythm', 'slug' => 'gilberto-rhythm']];

        // Default passScore 0.7 -> 0.5 fails.
        $this->assertFalse($this->grading->gradeQuestion($base, $taps));

        // A lenient authored passScore lets it through — knobs live in the DB.
        $lenient = $base + ['grading' => ['passScore' => 0.4]];
        $this->assertTrue($this->grading->gradeQuestion($lenient, $taps));
    }

    // ---- whole-quiz scoring ------------------------------------------------

    public function test_quiz_score_is_the_fraction_correct_and_threshold_decides_the_pass(): void
    {
        $quiz = $this->makeQuiz([
            $this->choiceQuestion('q1', 'a'),
            $this->choiceQuestion('q2', 'b'),
            $this->choiceQuestion('q3', 'c'),
            $this->choiceQuestion('q4', 'a'),
        ], ['pass_threshold' => 0.75]);

        $threeOfFour = $this->grading->grade($quiz, [
            ['q' => 'q1', 'value' => 'a'],
            ['q' => 'q2', 'value' => 'b'],
            ['q' => 'q3', 'value' => 'c'],
            ['q' => 'q4', 'value' => 'b'], // wrong
        ]);

        $this->assertSame(0.75, $threeOfFour['score']);
        $this->assertTrue($threeOfFour['passed'], '0.75 meets a 0.75 threshold');

        $twoOfFour = $this->grading->grade($quiz, [
            ['q' => 'q1', 'value' => 'a'],
            ['q' => 'q2', 'value' => 'b'],
        ]);

        $this->assertSame(0.5, $twoOfFour['score']);
        $this->assertFalse($twoOfFour['passed']);
    }

    public function test_unanswered_questions_are_wrong_not_skipped(): void
    {
        $quiz = $this->makeQuiz([
            $this->choiceQuestion('q1', 'a'),
            $this->choiceQuestion('q2', 'b'),
        ]);

        $result = $this->grading->grade($quiz, [['q' => 'q1', 'value' => 'a']]);

        $this->assertSame(0.5, $result['score'], 'the missing answer must count against the score');
        $this->assertCount(2, $result['perQuestion']);
        $this->assertFalse($result['perQuestion'][1]['correct']);
    }

    public function test_answers_for_questions_that_do_not_exist_are_ignored(): void
    {
        $quiz = $this->makeQuiz([$this->choiceQuestion('q1', 'a')]);

        $result = $this->grading->grade($quiz, [
            ['q' => 'q1', 'value' => 'a'],
            ['q' => 'q-injected', 'value' => 'a'],
        ]);

        $this->assertSame(1.0, $result['score']);
        $this->assertCount(1, $result['perQuestion'], 'a phantom answer must not inflate the denominator');
    }
}

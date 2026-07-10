<?php

namespace App\Services;

use App\Models\Quiz;
use App\Models\RhythmPattern;

/**
 * Server-side grading. The single source of truth for whether an answer is right.
 *
 * The client never grades. It submits raw values (an option id, a token, a list
 * of tap times) and this service re-derives correctness from the quiz's stored
 * answer key. A client-computed score is ignored — otherwise a user edits the
 * POST body to `score: 1`.
 *
 * Adding a question type = one `case` in gradeQuestion() plus a private method.
 * See docs/SBN-Quiz-Reference.md for the authored JSON each type expects.
 */
class QuizGradingService
{
    /** Default rhythm-tap knobs. Per-question `grading` overrides any of these. */
    public const RHYTHM_DEFAULTS = [
        // How far off the grid a tap may land and still count, in beats.
        // 0.18 at 90bpm ≈ 120ms. Expect to tune this after real use.
        'toleranceBeats'  => 0.18,
        // Each spurious tap costs this fraction of an onset. Discourages
        // machine-gunning the pad to blanket every window.
        'extraTapPenalty' => 0.5,
        // Fraction of onsets that must be hit for the QUESTION to count correct.
        'passScore'       => 0.7,
    ];

    /**
     * Grade a full submission.
     *
     * @param  array<int,array{q:string,value:mixed}>  $answers
     * @return array{score:float,passed:bool,perQuestion:list<array{q:string,correct:bool}>}
     */
    public function grade(Quiz $quiz, array $answers): array
    {
        // Index the submission by question id so unanswered questions are
        // simply absent (and therefore wrong), and a duplicate `q` can't
        // double-count.
        $byId = [];
        foreach ($answers as $answer) {
            if (isset($answer['q'])) {
                $byId[$answer['q']] = $answer['value'] ?? null;
            }
        }

        $questions = $quiz->questions ?? [];
        $perQuestion = [];
        $correctCount = 0;

        foreach ($questions as $question) {
            $id = $question['q'] ?? null;
            if ($id === null) {
                continue; // malformed authored question — skip, don't crash
            }

            $isCorrect = array_key_exists($id, $byId)
                && $this->gradeQuestion($question, $byId[$id]);

            if ($isCorrect) {
                $correctCount++;
            }

            $perQuestion[] = ['q' => $id, 'correct' => $isCorrect];
        }

        $total = count($perQuestion);
        $score = $total > 0 ? $correctCount / $total : 0.0;

        return [
            'score'       => round($score, 3),
            'passed'      => $total > 0 && $score >= $quiz->pass_threshold,
            'perQuestion' => $perQuestion,
        ];
    }

    /**
     * Dispatch one question to its type's grader.
     *
     * An unknown type grades as INCORRECT rather than throwing: a typo'd `type`
     * in authored JSON must not 500 a student's submission mid-quiz.
     *
     * @param  array<string,mixed>  $question
     */
    public function gradeQuestion(array $question, mixed $value): bool
    {
        return match ($question['type'] ?? '') {
            // chord-identify differs from multiple-choice only in its ANSWER
            // INPUT (a diagram picker vs. option buttons); both submit a single
            // token and grade identically.
            'multiple-choice', 'chord-identify' => $this->gradeChoice($question, $value),
            'rhythm-tap'                        => $this->gradeRhythmTap($question, $value),
            default                             => false,
        };
    }

    // =========================================================================
    // CHOICE
    // =========================================================================

    /**
     * Correct iff the submitted token(s) match the authored `correct` exactly.
     *
     * `correct` may be a scalar ("b") or a list (["b","d"]) for multi-select.
     * The submitted value is compared as a SET, so option order never matters
     * and a client can't pass by submitting every option.
     *
     * @param  array<string,mixed>  $question
     */
    private function gradeChoice(array $question, mixed $value): bool
    {
        $correct = $question['correct'] ?? null;

        if ($correct === null) {
            return false; // no key authored — nothing can be correct
        }

        $expected = array_map('strval', is_array($correct) ? $correct : [$correct]);
        $given    = array_map('strval', is_array($value) ? $value : [$value]);

        // Set equality: same members, no duplicates, any order.
        $expected = array_unique($expected);
        $given    = array_unique($given);

        sort($expected);
        sort($given);

        return $expected === $given;
    }

    // =========================================================================
    // RHYTHM TAP
    // =========================================================================

    /**
     * Grade a tapped rhythm against a stored pattern's onsets.
     *
     * The submitted value is `{"taps": [beat, beat, …]}` — RAW tap times in
     * beats, pattern-relative (count-in already subtracted client-side). Raw
     * taps rather than a score means the tolerance can be re-tuned later and
     * historical attempts re-graded without re-collecting data.
     *
     * Matching is greedy over (tap, onset) pairs sorted by distance, NOT
     * "nearest unused onset per tap in tap order" — the latter is order
     * dependent and can strand a tap that was the better match for an onset
     * already consumed by an earlier, sloppier tap.
     *
     * @param  array<string,mixed>  $question
     */
    private function gradeRhythmTap(array $question, mixed $value): bool
    {
        $taps = is_array($value) ? ($value['taps'] ?? null) : null;

        if (! is_array($taps)) {
            return false;
        }

        $slug = $question['prompt']['slug'] ?? null;
        if (! $slug) {
            return false;
        }

        $pattern = RhythmPattern::where('slug', $slug)->first();
        if (! $pattern) {
            return false;
        }

        $expected = RhythmOnsets::forPattern($pattern);
        if ($expected === []) {
            return false; // a silent pattern can't be tapped
        }

        $config = array_merge(self::RHYTHM_DEFAULTS, $question['grading'] ?? []);

        $score = $this->scoreTaps(
            taps: array_map('floatval', array_values($taps)),
            expected: $expected,
            toleranceBeats: (float) $config['toleranceBeats'],
            extraTapPenalty: (float) $config['extraTapPenalty'],
        );

        return $score >= (float) $config['passScore'];
    }

    /**
     * Fraction of the pattern the student actually played, in [0,1].
     *
     * hits            — expected onsets matched within tolerance
     * extraTaps       — taps that matched nothing (spurious)
     * missed onsets   — implicitly penalized: they never increment `hits`
     *
     * score = clamp((hits − extraTaps × penalty) / |expected|, 0, 1)
     *
     * @param  list<float>  $taps
     * @param  list<float>  $expected
     */
    public function scoreTaps(
        array $taps,
        array $expected,
        float $toleranceBeats = 0.18,
        float $extraTapPenalty = 0.5,
    ): float {
        if ($expected === []) {
            return 0.0;
        }

        // Build every in-tolerance candidate pairing, closest first. Greedy
        // consumption of that list is an optimal matching for this problem
        // (1-D, both sides consumable once, symmetric distance cost).
        $candidates = [];
        foreach ($taps as $t => $tap) {
            foreach ($expected as $e => $onset) {
                $distance = abs($tap - $onset);
                if ($distance <= $toleranceBeats) {
                    $candidates[] = ['d' => $distance, 't' => $t, 'e' => $e];
                }
            }
        }

        usort($candidates, fn ($a, $b) => $a['d'] <=> $b['d']);

        $usedTaps = [];
        $usedOnsets = [];
        $hits = 0;

        foreach ($candidates as $candidate) {
            if (isset($usedTaps[$candidate['t']]) || isset($usedOnsets[$candidate['e']])) {
                continue;
            }
            $usedTaps[$candidate['t']] = true;
            $usedOnsets[$candidate['e']] = true;
            $hits++;
        }

        $extraTaps = count($taps) - count($usedTaps);
        $raw = $hits - $extraTaps * $extraTapPenalty;

        return max(0.0, min(1.0, $raw / count($expected)));
    }
}

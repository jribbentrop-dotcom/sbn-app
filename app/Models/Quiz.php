<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A quiz — an ordered set of questions that, when passed, grants skill nodes.
 *
 * Questions are JSON on this row rather than their own table (see the migration
 * docblock). The array is the entire authoring surface: an agent with DB access
 * and no repo access can create a full interactive, audio-driven quiz by
 * writing one row. Schema: docs/SBN-Quiz-Reference.md.
 *
 * SECURITY: `questions` contains the answer key (`correct`, `explanation`) on
 * every question. Never serialize this attribute toward a client. Use
 * publicQuestions() — and note that `$hidden` covers the accidental
 * `->toJson()` / Inertia-prop path, while publicQuestions() is the deliberate
 * one.
 */
class Quiz extends Model
{
    protected $table = 'sbn_quizzes';

    protected $guarded = ['id'];

    protected $casts = [
        'questions'      => 'array',
        'pass_threshold' => 'float',
    ];

    /**
     * Belt-and-braces: if a Quiz model ever gets handed straight to Inertia or
     * ->toJson(), the key doesn't ride along. The intended read path is
     * publicQuestions().
     */
    protected $hidden = ['questions'];

    /** Question keys that must never reach the client. */
    private const SECRET_KEYS = ['correct', 'explanation', 'grading'];

    // =========================================================================
    // RELATIONS
    // =========================================================================

    /** Skill nodes granted when a user passes this quiz. */
    public function skillNodes(): BelongsToMany
    {
        return $this->belongsToMany(SkillNode::class, 'sbn_quiz_skill_node', 'quiz_id', 'skill_node_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(QuizAttempt::class, 'quiz_id');
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class, 'lesson_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    // =========================================================================
    // ANSWER-KEY BOUNDARY
    // =========================================================================

    /**
     * The questions as the client is allowed to see them: answer key stripped.
     *
     * Removes `correct` and `explanation` from each question, and `correct`
     * from each option. `grading` (the rhythm-tap tolerance knobs) is stripped
     * too — leaking `toleranceBeats` wouldn't reveal an answer, but it also
     * serves no client purpose, and shipping it invites a client-side grader.
     *
     * Option `id`s survive, because the client submits ids. Option ORDER is
     * preserved as authored; when a `shuffle` flag is added later it belongs
     * here, and no client change is needed since ids already travel with
     * options.
     *
     * @return array<int,array<string,mixed>>
     */
    public function publicQuestions(): array
    {
        return array_map(
            fn (array $q) => $this->publicQuestion($q),
            $this->questions ?? [],
        );
    }

    /** @param array<string,mixed> $question */
    private function publicQuestion(array $question): array
    {
        $public = array_diff_key($question, array_flip(self::SECRET_KEYS));

        if (isset($public['options']) && is_array($public['options'])) {
            $public['options'] = array_map(
                // An option may carry `correct: true` in the authored JSON as an
                // alternative to a top-level `correct` list. Strip either way.
                fn ($opt) => is_array($opt) ? array_diff_key($opt, array_flip(self::SECRET_KEYS)) : $opt,
                $public['options'],
            );
        }

        return $public;
    }

    /**
     * Look up one authored question (answer key INTACT) by its `q` id.
     * Server-side grading only.
     *
     * @return array<string,mixed>|null
     */
    public function question(string $id): ?array
    {
        foreach ($this->questions ?? [] as $q) {
            if (($q['q'] ?? null) === $id) {
                return $q;
            }
        }

        return null;
    }
}

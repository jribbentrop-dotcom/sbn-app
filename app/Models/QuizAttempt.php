<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One submitted quiz attempt.
 *
 * `answers` is the raw client payload. The score stored here was computed
 * server-side by QuizGradingService from the quiz's stored answer key — a
 * client-supplied score is never trusted or persisted.
 */
class QuizAttempt extends Model
{
    protected $table = 'sbn_quiz_attempts';

    protected $guarded = ['id'];

    protected $casts = [
        'answers'      => 'array',
        'score'        => 'float',
        'passed'       => 'boolean',
        'completed_at' => 'datetime',
    ];

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class, 'quiz_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseUser extends Model
{
    protected $table = 'course_user';

    protected $guarded = ['id'];

    protected $casts = [
        'granted_at'       => 'datetime',
        'expires_at'       => 'datetime',
        'last_accessed_at' => 'datetime',
    ];

    public const SOURCES = ['purchase', 'manual_grant', 'bundle', 'promo'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function isActive(): bool
    {
        return $this->expires_at === null || $this->expires_at->isFuture();
    }
}

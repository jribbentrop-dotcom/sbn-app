<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable;

    protected $fillable = ['name', 'email', 'password'];
    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'course_user')
            ->withPivot(['source', 'order_id', 'granted_at', 'expires_at', 'last_accessed_at'])
            ->withTimestamps();
    }

    public function conversations(): BelongsToMany
    {
        return $this->belongsToMany(Conversation::class, 'conversation_participants')
            ->withPivot(['joined_at', 'last_read_at', 'muted'])
            ->using(ConversationParticipant::class);
    }

    public function owns(Course $course): bool
    {
        if ($course->is_free) {
            return true;
        }

        return $this->courses()
            ->where('sbn_courses.id', $course->id)
            ->where(function ($q) {
                $q->whereNull('course_user.expires_at')
                  ->orWhere('course_user.expires_at', '>', now());
            })
            ->exists();
    }

    public function isInstructor(): bool
    {
        return (bool) ($this->is_instructor ?? false);
    }
}

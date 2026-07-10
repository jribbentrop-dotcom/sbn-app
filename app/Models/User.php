<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    /**
     * Skill nodes this user has progress on. The `source` pivot records how a
     * completion was earned ('self_report' | 'quiz'); quiz-earned rows also
     * carry the granting `quiz_attempt_id`.
     */
    public function skillNodes(): BelongsToMany
    {
        return $this->belongsToMany(SkillNode::class, 'sbn_user_skill_progress', 'user_id', 'skill_node_id')
            ->withPivot(['status', 'completed_at', 'source', 'quiz_attempt_id'])
            ->withTimestamps();
    }

    /** Quiz attempts this user has submitted. */
    public function quizAttempts(): HasMany
    {
        return $this->hasMany(QuizAttempt::class, 'user_id');
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

    /**
     * Unified access check. For now only resolves `course:{id}` via owns().
     * A future `pro` subscription entitlement plugs into the same method, so
     * library/widget gating can call hasEntitlement('pro') without rework.
     */
    public function hasEntitlement(string $key): bool
    {
        if (str_starts_with($key, 'course:')) {
            $courseId = (int) substr($key, strlen('course:'));
            $course = Course::find($courseId);

            return $course ? $this->owns($course) : false;
        }

        // 'pro' and other recurring entitlements land with subscriptions later.
        return false;
    }

    /**
     * Attach any paid guest orders placed with this user's email to their
     * account, granting the courses those orders cover. Called on login and
     * registration so guest purchases become owned access.
     */
    public function claimGuestOrders(): void
    {
        $orders = \App\Models\Order::query()
            ->whereNull('user_id')
            ->where('guest_email', $this->email)
            ->where('status', \App\Models\Order::STATUS_PAID)
            ->get();

        if ($orders->isEmpty()) {
            return;
        }

        $access = app(\App\Services\CourseAccessService::class);

        foreach ($orders as $order) {
            $order->update(['user_id' => $this->id]);
            $access->grantPurchase($this, $order);
        }
    }
}

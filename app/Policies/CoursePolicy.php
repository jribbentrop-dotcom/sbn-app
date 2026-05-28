<?php

namespace App\Policies;

use App\Models\Course;
use App\Models\User;

class CoursePolicy
{
    public function view(?User $user, Course $course): bool
    {
        if ($course->is_free || !$course->product_id) {
            return true;
        }

        return $user !== null && $user->owns($course);
    }

    public function viewLessons(?User $user, Course $course): bool
    {
        return $this->view($user, $course);
    }

    public function grant(User $user, Course $course): bool
    {
        return $user->isInstructor();
    }
}

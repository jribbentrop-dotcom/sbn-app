<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lesson;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Guards the Task 3 (8.3) wiring: CourseController::player resolves a lesson's
 * concept_slug via EduContentService and passes it as lessonConcept in the
 * Inertia payload.
 */
class CoursePracticeConceptTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.sqlite.database' => database_path('sbn.db')]);
        DB::reconnect('sqlite');
    }

    public function test_player_payload_contains_lesson_concept_key(): void
    {
        $course = Course::published()->firstOrFail();

        $lesson = $course->lessons()->published()->orderBy('sort_order')->first();
        $url = $lesson
            ? "/learn/{$course->slug}/play/{$lesson->slug}"
            : "/learn/{$course->slug}/play";

        $this->get($url)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Courses/Player')
                ->has('lessonConcept')
            );
    }

    public function test_player_lesson_concept_is_null_when_concept_slug_unset(): void
    {
        $course = Course::published()->firstOrFail();
        $lesson = $course->lessons()->published()->orderBy('sort_order')->first();

        // Ensure no concept_slug on this lesson for the test
        if ($lesson) {
            $lesson->update(['concept_slug' => null]);
        }

        $url = $lesson
            ? "/learn/{$course->slug}/play/{$lesson->slug}"
            : "/learn/{$course->slug}/play";

        $this->get($url)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Courses/Player')
                ->where('lessonConcept', null)
            );
    }

    public function test_player_lesson_concept_resolves_when_concept_slug_set(): void
    {
        $course = Course::published()->firstOrFail();
        $lesson = $course->lessons()->published()->orderBy('sort_order')->firstOrFail();

        $lesson->update(['concept_slug' => 'triad']);

        $this->get("/learn/{$course->slug}/play/{$lesson->slug}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Courses/Player')
                ->has('lessonConcept', fn ($concept) => $concept
                    ->where('slug', 'triad')
                    ->where('type', 'concept')
                    ->where('has_widgets', true)
                    ->etc()
                )
            );

        // Restore
        $lesson->update(['concept_slug' => null]);
    }

    public function test_player_lesson_concept_is_null_for_unknown_slug(): void
    {
        $course = Course::published()->firstOrFail();
        $lesson = $course->lessons()->published()->orderBy('sort_order')->firstOrFail();

        $lesson->update(['concept_slug' => 'no-such-concept-xyz']);

        $this->get("/learn/{$course->slug}/play/{$lesson->slug}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Courses/Player')
                ->where('lessonConcept', null)
            );

        // Restore
        $lesson->update(['concept_slug' => null]);
    }
}

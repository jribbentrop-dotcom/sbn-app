<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\Leadsheet;
use App\Models\RhythmPattern;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CourseController extends Controller
{
    public function index()
    {
        $courses = Course::published()
            ->with('product')
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($course) => $this->serializeCourse($course));

        $genres = $courses->pluck('primaryGenre')->unique()->filter()->values();
        $levels = ['basic', 'early-intermediate', 'intermediate', 'late-intermediate', 'advanced'];

        return Inertia::render('Courses/Index', [
            'courses' => $courses,
            'genres' => $genres,
            'levels' => $levels,
        ]);
    }

    public function show(Course $course)
    {
        $lessons = $course->lessons()
            ->published()
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($lesson) => $this->serializeLessonStub($lesson));

        // Related songs: match by genre slug stored in leadsheet rhythm category
        // or simply pull all songs — genre filtering on Leadsheet uses the rhythm slug
        // mapping. For now pull songs that have matching genre or all songs ordered by title.
        $genre = $course->primary_genre; // e.g. "bossa-nova"
        $relatedSongs = Leadsheet::whereNotNull('title')
            ->where(function ($q) use ($genre) {
                // Try course_id first, then genre-style match via rhythm slug prefix
                $q->where('course_id', null); // open to all styles for now
            })
            ->orderByRaw("CASE WHEN rhythm LIKE ? THEN 0 ELSE 1 END", ["%{$genre}%"])
            ->orderBy('title')
            ->limit(6)
            ->get(['id', 'slug', 'title', 'composer', 'song_key', 'tempo', 'rhythm']);

        // Rhythm patterns relevant to this genre/category
        $rhythmCategory = $this->genreToRhythmCategory($genre);
        $rhythmPatterns = RhythmPattern::where('category', $rhythmCategory)
            ->orderBy('sort_order')
            ->limit(4)
            ->get();

        return Inertia::render('Courses/Show', [
            'course'   => $this->serializeCourse($course),
            'lessons'  => $lessons,
            'songs'    => $relatedSongs->map(fn ($s) => [
                'id'       => $s->id,
                'slug'     => $s->slug,
                'title'    => $s->title,
                'composer' => $s->composer,
                'key'      => $s->song_key,
                'tempo'    => $s->tempo,
                'rhythm'   => $s->rhythm,
            ]),
            'rhythms'  => $rhythmPatterns->map(fn ($r) => [
                'id'          => $r->id,
                'slug'        => $r->slug,
                'name'        => $r->name,
                'category'    => $r->category,
                'description' => $r->description,
                'bpm'         => $r->default_bpm,
                'timeSignature' => $r->time_signature,
                'playerData'  => $r->toPlayerData(),
            ]),
        ]);
    }

    /** Map genre slug → rhythm category stored in sbn_rhythm_patterns.category */
    private function genreToRhythmCategory(string $genre): string
    {
        return match ($genre) {
            'bossa-nova', 'samba' => 'brazilian',
            'latin'               => 'latin',
            'jazz'                => 'jazz',
            'blues'               => 'blues',
            default               => 'general',
        };
    }

    public function player(Request $request, Course $course, ?Lesson $lesson = null)
    {
        $allLessons = $course->lessons()
            ->published()
            ->orderBy('sort_order')
            ->get();

        $activeLesson = $lesson;
        if ($activeLesson && (int) $activeLesson->course_id !== (int) $course->id) {
            $activeLesson = null;
        }
        $activeLesson = $activeLesson ?? $allLessons->first();

        $hasAccess = $this->checkAccess($request, $course);

        $lessonData = null;
        if ($activeLesson) {
            $canView = $hasAccess || $activeLesson->is_preview;
            $lessonData = $this->serializeLesson($activeLesson, withContent: $canView);
        }

        return Inertia::render('Courses/Player', [
            'course' => $this->serializeCourse($course),
            'lessons' => $allLessons->map(fn ($lessonItem) => $this->serializeLessonStub($lessonItem)),
            'lesson' => $lessonData,
            'hasAccess' => $hasAccess,
        ]);
    }

    private function checkAccess(Request $request, Course $course): bool
    {
        if ($course->is_free || !$course->product_id) {
            return true;
        }

        // Phase 12 hook: replace this development override with real purchase checks.
        // Part B development keeps paid courses accessible so the player can be tested.
        return true;
    }

    private function serializeCourse(Course $course): array
    {
        return [
            'id' => $course->id,
            'slug' => $course->slug,
            'title' => $course->title,
            'excerpt' => $course->excerpt,
            'genres' => $course->genres ?? [],
            'levels' => $course->levels ?? [],
            'primaryGenre' => $course->primary_genre,
            'primaryLevel' => $course->primary_level,
            'topics' => $course->topics ?? [],
            'isFree' => $course->is_free,
            'isGated' => $course->is_gated,
            'lessonCount' => $course->lesson_count,
            'featuredImagePath' => $course->featured_image_path,
            'productSlug' => $course->product?->slug,
        ];
    }

    private function serializeLessonStub(Lesson $lesson): array
    {
        return [
            'id' => $lesson->id,
            'slug' => $lesson->slug,
            'title' => $lesson->title,
            'sectionTitle' => $lesson->section_title,
            'isPreview' => $lesson->is_preview,
            'sortOrder' => $lesson->sort_order,
            'subsections' => $lesson->subsections,
        ];
    }

    private function serializeLesson(Lesson $lesson, bool $withContent): array
    {
        return [
            ...$this->serializeLessonStub($lesson),
            'content' => $withContent ? $lesson->content : null,
        ];
    }
}

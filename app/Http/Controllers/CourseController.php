<?php

namespace App\Http\Controllers;

use App\Models\ChordProgression;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Leadsheet;
use App\Models\RhythmPattern;
use App\Services\EduContentService;
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
        $relatedSongs = Leadsheet::published()
            ->whereNotNull('title')
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

    public function player(Request $request, Course $course, EduContentService $edu, ?Lesson $lesson = null)
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
        $chordSlugs = [];
        $rhythmTags = [];   // one entry per <sbn-rhythm> tag: { slug, videoSnippet }
        $progressionTags = []; // one entry per <sbn-progression> tag: { slug, key, videoSnippet }
        $lessonConcept = null;
        if ($activeLesson) {
            $canView = $hasAccess || $activeLesson->is_preview;
            $lessonData = $this->serializeLesson($activeLesson, withContent: $canView);
            if ($canView && $activeLesson->content) {
                preg_match_all('/<sbn-chord[^>]+slug="([^"]+)"/i', $activeLesson->content, $matches);
                $chordSlugs = array_values(array_unique($matches[1] ?? []));
                $rhythmTags = $this->parseRhythmTags($activeLesson->content);
                $progressionTags = $this->parseProgressionTags($activeLesson->content);
            }
            if ($activeLesson->concept_slug) {
                $lessonConcept = $edu->topic('concept', $activeLesson->concept_slug)?->toArray();
            }
        }

        // Rhythms shown in the practice panel are the ones referenced by
        // <sbn-rhythm> tags in the lesson content — one panel entry per tag
        // occurrence, in document order. The same pattern may appear twice
        // with different video examples, so this is NOT deduped by slug.
        $rhythms = collect();
        if ($rhythmTags) {
            $slugs  = array_values(array_unique(array_column($rhythmTags, 'slug')));
            $bySlug = RhythmPattern::whereIn('slug', $slugs)->get()->keyBy('slug');

            $rhythms = collect($rhythmTags)
                ->map(function ($tag) use ($bySlug) {
                    $r = $bySlug->get($tag['slug']);
                    if (!$r) {
                        return null;
                    }
                    // Resolve the tag's video-snippet id against the pattern's
                    // library. A dangling id (snippet deleted) resolves to
                    // null — slot hidden. See plan §0.2 / §0.3.
                    $snippet = null;
                    if ($tag['videoSnippet']) {
                        $snippet = collect($r->video_snippets ?? [])
                            ->firstWhere('id', $tag['videoSnippet']);
                    }

                    // PracticePanel reads `videoSnippet` off the pattern
                    // object (RhythmPatternWithMeta), so nest it there.
                    $pattern = $r->toPlayerData();
                    $pattern['videoSnippet'] = $snippet;

                    return [
                        'slug'        => $r->slug,
                        'name'        => $r->name,
                        'description' => $r->description,
                        'pattern'     => $pattern,
                    ];
                })
                ->filter()
                ->values();
        }

        // Progressions referenced by the lesson — one entry per
        // <sbn-progression> tag occurrence, in document order. The panel shows
        // these as a compact reference list (name · key · style); the full
        // viewer is the inline body component, which fetches its own chords.
        $progressions = collect();
        if ($progressionTags) {
            $slugs  = array_values(array_unique(array_column($progressionTags, 'slug')));
            $bySlug = ChordProgression::whereIn('slug', $slugs)->get()->keyBy('slug');

            $progressions = collect($progressionTags)
                ->map(function ($tag) use ($bySlug) {
                    $p = $bySlug->get($tag['slug']);
                    if (!$p) {
                        return null;
                    }
                    // Resolve the tag's video-snippet id against the
                    // progression's library; dangling id → null (slot hidden).
                    $snippet = null;
                    if ($tag['videoSnippet']) {
                        $snippet = collect($p->video_snippets ?? [])
                            ->firstWhere('id', $tag['videoSnippet']);
                    }

                    return [
                        'slug'         => $p->slug,
                        'name'         => $p->name,
                        'key'          => $tag['key'],
                        'category'     => $p->category,
                        'videoSnippet' => $snippet,
                    ];
                })
                ->filter()
                ->values();
        }

        return Inertia::render('Courses/Player', [
            'course'        => $this->serializeCourse($course),
            'lessons'       => $allLessons->map(fn ($lessonItem) => $this->serializeLessonStub($lessonItem)),
            'lesson'        => $lessonData,
            'hasAccess'     => $hasAccess,
            'chordSlugs'    => $chordSlugs,
            'lessonConcept' => $lessonConcept,
            'rhythms'       => $rhythms,
            'progressions'  => $progressions,
        ]);
    }

    /**
     * Parse every <sbn-rhythm> tag in lesson content into an ordered list of
     * { slug, videoSnippet } entries. One entry per tag occurrence — NOT
     * deduped by slug, since the same pattern may be placed twice with
     * different video examples. Attribute order is irrelevant: each tag is
     * matched whole, then `slug` and `video-snippet` extracted from it.
     * See plan §0.2 (Course side) / §0.5 step 6.
     */
    private function parseRhythmTags(string $content): array
    {
        preg_match_all('/<sbn-rhythm\b[^>]*>/i', $content, $tagMatches);

        $tags = [];
        foreach ($tagMatches[0] as $tag) {
            if (!preg_match('/\bslug="([^"]*)"/i', $tag, $slugMatch)) {
                continue; // a rhythm tag with no slug is unusable
            }
            preg_match('/\bvideo-snippet="([^"]*)"/i', $tag, $snippetMatch);

            $tags[] = [
                'slug'         => $slugMatch[1],
                'videoSnippet' => $snippetMatch[1] ?? '',
            ];
        }

        return $tags;
    }

    /**
     * Parse every <sbn-progression> tag in lesson content into an ordered list
     * of { slug, key, videoSnippet } entries. Same per-tag-occurrence,
     * attribute-order-independent contract as parseRhythmTags(). `key` defaults
     * to 'C' (matching mountSbnNodes / apiShow) when the attribute is absent.
     */
    private function parseProgressionTags(string $content): array
    {
        preg_match_all('/<sbn-progression\b[^>]*>/i', $content, $tagMatches);

        $tags = [];
        foreach ($tagMatches[0] as $tag) {
            if (!preg_match('/\bslug="([^"]*)"/i', $tag, $slugMatch)) {
                continue; // a progression tag with no slug is unusable
            }
            preg_match('/\bkey="([^"]*)"/i', $tag, $keyMatch);
            preg_match('/\bvideo-snippet="([^"]*)"/i', $tag, $snippetMatch);

            $tags[] = [
                'slug'         => $slugMatch[1],
                'key'          => ($keyMatch[1] ?? '') ?: 'C',
                'videoSnippet' => $snippetMatch[1] ?? '',
            ];
        }

        return $tags;
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
            'content'      => $withContent ? $lesson->content : null,
            'concept_slug' => $lesson->concept_slug,
        ];
    }
}

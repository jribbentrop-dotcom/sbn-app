<?php

namespace App\Http\Controllers;

use App\Models\ChordProgression;
use App\Models\Course;
use App\Models\Exercise;
use App\Models\Leadsheet;
use App\Models\Lesson;
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

        $categories = collect(\App\Models\ChordProgression::CATEGORIES);
        $levels = ['basic', 'early-intermediate', 'intermediate', 'late-intermediate', 'advanced'];

        return Inertia::render('Courses/Index', [
            'courses'    => $courses,
            'categories' => $categories,
            'levels'     => $levels,
        ]);
    }

    public function show(Course $course)
    {
        $lessons = $course->lessons()
            ->published()
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($lesson) => $this->serializeLessonStub($lesson));

        // Scan per-lesson so we can record the first lesson each tag appears in.
        // Cap at 5 items per type — sidebar is a teaser, not a full inventory.
        $sidebarCap = 5;
        $allLessons = $course->lessons()->published()->orderBy('sort_order')->get();
        $rhythmMap = $chordMap = $exerciseMap = $songMap = $progressionMap = $widgetMap = [];

        foreach ($allLessons as $scanLesson) {
            if (!$scanLesson->content) continue;
            $ls = $scanLesson->slug;

            foreach ([
                'sbn-rhythm'      => &$rhythmMap,
                'sbn-chord'       => &$chordMap,
                'sbn-sheet'       => &$exerciseMap,
                'sbn-song'        => &$songMap,
                'sbn-progression' => &$progressionMap,
                'sbn-widget'      => &$widgetMap,
            ] as $tag => &$bag) {
                if (count($bag) >= $sidebarCap) continue;
                preg_match_all('/<' . $tag . '\b[^>]*\bslug="([^"]+)"/i', $scanLesson->content, $m);
                foreach ($m[1] as $s) {
                    if (!isset($bag[$s])) {
                        $bag[$s] = $ls;
                        if (count($bag) >= $sidebarCap) break;
                    }
                }
            }
            unset($bag);
        }

        $rhythmPatterns = RhythmPattern::whereIn('slug', array_keys($rhythmMap))
            ->orderBy('sort_order')->get();

        $chordDiagrams = \App\Models\ChordDiagram::whereIn('slug', array_keys($chordMap))
            ->orderBy('root_note')->orderBy('name')->get();

        $exercises = \App\Models\Exercise::whereIn('slug', array_keys($exerciseMap))
            ->orderBy('title')->get();

        $songs = \App\Models\Leadsheet::whereIn('slug', array_keys($songMap))
            ->orderBy('title')->get();

        $progressions = \App\Models\ChordProgression::whereIn('slug', array_keys($progressionMap))
            ->orderBy('name')->get();

        $eduService = app(EduContentService::class);
        $widgets = collect(array_keys($widgetMap))->map(function ($slug) use ($eduService) {
            $topic = $eduService->topic('concept', $slug);
            return $topic ? ['slug' => $topic->slug, 'title' => $topic->title] : null;
        })->filter()->values();

        return Inertia::render('Courses/Show', [
            'course'       => $this->serializeCourse($course),
            'lessons'      => $lessons,
            'rhythms'      => $rhythmPatterns->map(fn ($r) => [
                'id'            => $r->id,
                'slug'          => $r->slug,
                'name'          => $r->name,
                'category'      => $r->category,
                'styleSlug'     => $r->styleSlug(),
                'bpm'           => $r->default_bpm,
                'timeSignature' => $r->time_signature,
                'playerData'    => $r->toPlayerData(),
                'lessonSlug'    => $rhythmMap[$r->slug] ?? null,
            ]),
            'chords'       => $chordDiagrams->map(fn ($c) => [
                'slug'       => $c->slug,
                'name'       => $c->name,
                'rootNote'   => $c->root_note,
                'lessonSlug' => $chordMap[$c->slug] ?? null,
            ]),
            'exercises'    => $exercises->map(fn ($e) => [
                'slug'       => $e->slug,
                'title'      => $e->title,
                'keyCenter'  => $e->key_center,
                'type'       => $e->type,
                'lessonSlug' => $exerciseMap[$e->slug] ?? null,
            ]),
            'songs'        => $songs->map(fn ($s) => [
                'slug'       => $s->slug,
                'title'      => $s->title,
                'composer'   => $s->composer ?: null,
                'lessonSlug' => $songMap[$s->slug] ?? null,
            ]),
            'progressions' => $progressions->map(fn ($p) => [
                'slug'       => $p->slug,
                'name'       => $p->name,
                'category'   => $p->category,
                'lessonSlug' => $progressionMap[$p->slug] ?? null,
            ]),
            'widgets'      => $widgets->map(fn ($w) => [
                'slug'       => $w['slug'],
                'title'      => $w['title'],
                'lessonSlug' => $widgetMap[$w['slug']] ?? null,
            ]),
        ]);
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
        if ($hasAccess && $request->user()) {
            app(\App\Services\CourseAccessService::class)->bumpLastAccessed($request->user(), $course);
        }

        $lessonData = null;
        $chordSlugs = [];
        $rhythmTags = [];      // one entry per <sbn-rhythm> tag: { slug, videoSnippet }
        $progressionTags = []; // one entry per <sbn-progression> tag: { slug, key, videoSnippet }
        $sheetSlugs = [];      // unique slugs of <sbn-sheet> tags that have videoSync
        $songSlugs  = [];      // unique slugs of <sbn-song> tags that have videoSync
        $lessonConcepts = [];

        if ($activeLesson) {
            $canView = $hasAccess || $activeLesson->is_preview;
            $lessonData = $this->serializeLesson($activeLesson, withContent: $canView);
        }

        // Partial reload (lesson navigation): only swap the lesson prop.
        // Skip the expensive course-wide scan — sidebar data doesn't change.
        if ($request->header('X-Inertia-Partial-Component') === 'Courses/Player'
            && str_contains($request->header('X-Inertia-Partial-Data', ''), 'lesson')) {
            return Inertia::render('Courses/Player', [
                'course'    => $this->serializeCourse($course),
                'lessons'   => $allLessons->map(fn ($l) => $this->serializeLessonStub($l)),
                'lesson'    => $lessonData,
                'hasAccess' => $hasAccess,
            ]);
        }

        // Scan all accessible lesson content for sidebar components so the
        // practice panel shows the full course, not just the active lesson.
        $chordTags = []; // { slug, root } pairs
        $seenChordKeys = [];
        $seenSheets = [];
        $seenWidgets = [];
        foreach ($allLessons as $scanLesson) {
            $canView = $hasAccess || $scanLesson->is_preview;
            if (!$canView || !$scanLesson->content) continue;

            $lessonSlug = $scanLesson->slug;

            preg_match_all('/<sbn-chord\b[^>]*>/i', $scanLesson->content, $chordTagMatches);
            foreach ($chordTagMatches[0] as $tag) {
                if (!preg_match('/\bslug="([^"]+)"/i', $tag, $sm)) continue;
                preg_match('/\broot="([^"]*)"/i', $tag, $rm);
                $slug = $sm[1];
                $root = $rm[1] ?? '';
                $key = $slug . '|' . $root;
                if (!isset($seenChordKeys[$key])) {
                    $seenChordKeys[$key] = true;
                    $chordTags[] = ['slug' => $slug, 'root' => $root, 'lessonSlug' => $lessonSlug];
                    $chordSlugs[] = $slug;
                }
            }

            foreach ($this->parseRhythmTags($scanLesson->content) as $tag) {
                $rhythmTags[] = array_merge($tag, ['lessonSlug' => $lessonSlug]);
            }

            foreach ($this->parseProgressionTags($scanLesson->content) as $tag) {
                $progressionTags[] = array_merge($tag, ['lessonSlug' => $lessonSlug]);
            }

            preg_match_all('/<sbn-sheet\b[^>]*\bslug="([^"]+)"/i', $scanLesson->content, $sheetMatches);
            foreach ($sheetMatches[1] as $slug) {
                if (!isset($seenSheets[$slug])) { $seenSheets[$slug] = true; $sheetSlugs[] = ['slug' => $slug, 'lessonSlug' => $lessonSlug]; }
            }

            preg_match_all('/<sbn-song\b[^>]*\bslug="([^"]+)"/i', $scanLesson->content, $songMatches);
            foreach ($songMatches[1] as $slug) {
                if (!isset($seenSheets['song:' . $slug])) {
                    $seenSheets['song:' . $slug] = true;
                    $songSlugs[] = ['slug' => $slug, 'lessonSlug' => $lessonSlug];
                }
            }

            preg_match_all('/<sbn-widget\b[^>]*\bslug="([^"]+)"/i', $scanLesson->content, $widgetMatches);
            foreach ($widgetMatches[1] as $widgetSlug) {
                if (isset($seenWidgets[$widgetSlug])) continue;
                $seenWidgets[$widgetSlug] = true;
                $topic = $edu->topic('concept', $widgetSlug);
                if ($topic) $lessonConcepts[] = $topic->toArray();
            }
        }

        // Dedupe rhythm tags by slug+snippet pair so the same pattern isn't
        // listed twice when it appears in multiple lessons identically.
        $seenRhythmKeys = [];
        $rhythmTags = array_values(array_filter($rhythmTags, function ($tag) use (&$seenRhythmKeys) {
            $key = $tag['slug'] . '|' . ($tag['videoSnippet'] ?? '');
            if (isset($seenRhythmKeys[$key])) return false;
            return $seenRhythmKeys[$key] = true;
        }));

        // Dedupe progression tags by slug+key+snippet.
        $seenProgKeys = [];
        $progressionTags = array_values(array_filter($progressionTags, function ($tag) use (&$seenProgKeys) {
            $key = $tag['slug'] . '|' . $tag['key'] . '|' . ($tag['videoSnippet'] ?? '');
            if (isset($seenProgKeys[$key])) return false;
            return $seenProgKeys[$key] = true;
        }));

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
                        'lessonSlug'  => $tag['lessonSlug'] ?? null,
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
                        'lessonSlug'   => $tag['lessonSlug'] ?? null,
                    ];
                })
                ->filter()
                ->values();
        }

        // Sheet exercises that have a videoSync block — one entry per unique slug.
        // PracticePanel shows their video in the sidebar; SheetMiniPlayer reads
        // the full videoSync from the exercise payload (fetched by mountSbnNodes).
        $sheets = collect();
        if (!empty($sheetSlugs)) {
            $slugOnly = array_column($sheetSlugs, 'slug');
            $lessonBySlug = array_column($sheetSlugs, 'lessonSlug', 'slug');
            $sheets = Exercise::whereIn('slug', $slugOnly)
                ->get()
                ->map(function (Exercise $e) use ($lessonBySlug) {
                    $content = $e->content_json ?? [];
                    $vs = $content['videoSync'] ?? null;
                    if (!$vs || empty($vs['videoId'])) return null;
                    return [
                        'slug'       => $e->slug,
                        'title'      => $e->title,
                        'videoId'    => $vs['videoId'],
                        'videoType'  => $vs['videoType'] ?? 'youtube',
                        'lessonSlug' => $lessonBySlug[$e->slug] ?? null,
                    ];
                })
                ->filter()
                ->keyBy('slug');
        }

        // <sbn-song> tags — pull videoSync from the leadsheet's parsed_data.
        // Keyed as "song:{slug}" to avoid collisions with exercise slugs.
        if (!empty($songSlugs)) {
            $slugOnly     = array_column($songSlugs, 'slug');
            $lessonBySlug = array_column($songSlugs, 'lessonSlug', 'slug');
            Leadsheet::whereIn('slug', $slugOnly)
                ->get()
                ->each(function (Leadsheet $ls) use (&$sheets, $lessonBySlug) {
                    $parsed = $ls->parsed_data ?? [];
                    $vs = $parsed['videoSync'] ?? null;
                    if (!$vs || empty($vs['videoId'])) return;
                    $key = 'song:' . $ls->slug;
                    $sheets[$key] = [
                        'slug'       => $key,
                        'title'      => $ls->title,
                        'videoId'    => $vs['videoId'],
                        'videoType'  => $vs['videoType'] ?? 'youtube',
                        'lessonSlug' => $lessonBySlug[$ls->slug] ?? null,
                    ];
                });
        }

        return Inertia::render('Courses/Player', [
            'course'         => $this->serializeCourse($course),
            'lessons'        => $allLessons->map(fn ($lessonItem) => $this->serializeLessonStub($lessonItem)),
            'lesson'         => $lessonData,
            'hasAccess'      => $hasAccess,
            'chordSlugs'     => $chordSlugs,
            'chordTags'      => $chordTags,
            'lessonConcepts' => $lessonConcepts,
            'rhythms'        => $rhythms,
            'progressions'   => $progressions,
            'sheets'         => $sheets,
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

        $user = $request->user();
        if (!$user) {
            return false;
        }

        return $user->owns($course);
    }

    private function serializeCourse(Course $course): array
    {
        return [
            'id' => $course->id,
            'slug' => $course->slug,
            'title' => $course->title,
            'excerpt' => $course->excerpt,
            'description' => $course->description,
            'category'     => $course->category,
            'levels'       => $course->levels ?? [],
            'primaryGenre' => $course->primary_genre,
            'primaryLevel' => $course->primary_level,
            'isFree' => $course->is_free,
            'isGated' => $course->is_gated,
            'lessonCount' => $course->lesson_count,
            'featuredImagePath' => $course->featured_image_path,
            'productSlug'       => $course->product?->slug,
            'learningOutcomes'  => array_values(array_filter(array_map(
                'trim',
                explode("\n", $course->learning_outcomes ?? '')
            ))),
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
        $content = null;
        if ($withContent && $lesson->content) {
            $content = str_replace('&amp;amp;', '&amp;', $lesson->content);
        }
        return [
            ...$this->serializeLessonStub($lesson),
            'content' => $content,
        ];
    }
}

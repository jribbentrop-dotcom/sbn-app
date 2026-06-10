<?php

namespace App\Http\Controllers\Library;

use App\Http\Controllers\Controller;
use App\Models\Leadsheet;
use App\Models\RhythmPattern;
use App\Repositories\CourseRepository;
use Illuminate\Http\Request;
use Inertia\Inertia;

class RhythmLibraryController extends Controller
{

    public function index(Request $request)
    {
        $sort = $request->get('sort', 'popularity');

        $raw = RhythmPattern::withSongCounts();
        $patterns = $raw->map(fn ($p) => $this->serializePattern($p, $p->song_count ?? 0));

        // Sort server-side so the collection order matches what the frontend expects
        if ($sort === 'name') {
            $patterns = $patterns->sortBy('name')->values();
        } elseif ($sort === 'category') {
            $patterns = $patterns->sortBy('category')->values();
        } else {
            $patterns = $patterns->sortByDesc('songCount')->values();
        }

        $categories = $raw->pluck('category')->unique()->sort()->values()->all();
        $timeSignatures = $raw->pluck('time_signature')->unique()->sort()->values()->all();
        $gridTypes = $raw->pluck('grid_type')->unique()->sort()->values()->all();

        return Inertia::render('Library/Rhythms/Index', [
            'patterns'       => $patterns,
            'categories'     => $categories,
            'timeSignatures' => $timeSignatures,
            'gridTypes'      => $gridTypes,
            'totalCount'     => $patterns->count(),
            'activeFilters'  => [
                'sort'     => $sort,
                'search'   => $request->get('search', ''),
                'category' => $request->get('category', ''),
            ],
        ]);
    }

    public function __construct(protected CourseRepository $courseRepo) {}

    public function show(string $slug)
    {
        $pattern = RhythmPattern::where('slug', $slug)->firstOrFail();

        // Get siblings (other patterns in same category)
        $siblings = RhythmPattern::where('category', $pattern->category)
            ->where('id', '!=', $pattern->id)
            ->ordered()
            ->limit(6)
            ->get()
            ->map(fn ($p) => $this->serializePattern($p));

        // Songs that use this rhythm pattern
        $songs = Leadsheet::published()
            ->where('rhythm', $pattern->slug)
            ->orderByDesc('popularity')
            ->limit(8)
            ->select('sbn_leadsheets.id', 'sbn_leadsheets.slug', 'sbn_leadsheets.title', 'sbn_leadsheets.genre', 'sbn_leadsheets.rhythm', 'sbn_leadsheets.popularity', 'sbn_leadsheets.cover_image_path')
            ->get()
            ->map(fn ($s) => $s->toLinkArray());

        // Related courses: tag match first, then category fallback
        $courses = $this->courseRepo->relatedTo($pattern, $pattern->category);

        return Inertia::render('Library/Rhythms/Show', [
            'pattern'  => $this->serializePattern($pattern),
            'siblings' => $siblings,
            'songs'    => $songs,
            'courses'  => $courses,
        ]);
    }

    public function serializePattern(RhythmPattern $pattern, int $songCount = 0): array
    {
        $styleSlug = $pattern->styleSlug();

        return [
            'id'            => $pattern->id,
            'slug'          => $pattern->slug,
            'name'          => $pattern->name,
            'description'   => $pattern->description,
            'category'      => $pattern->category,
            'styleSlug'     => $styleSlug,
            'timeSignature' => $pattern->time_signature,
            'beats'         => $pattern->beats,
            'gridType'      => $pattern->grid_type,
            'bpm'           => $pattern->default_bpm,
            'thumb'         => $pattern->thumb_pattern,
            'fingers'       => $pattern->rhythm_pattern,
            'percTop'       => $pattern->perc_top,
            'percBass'      => $pattern->perc_bass,
            'demoUrl'       => $pattern->mp3_file ? '/audio/rhythm-demos/' . $pattern->mp3_file : null,
            'tags'          => $pattern->tags()->pluck('slug')->all(),
            'songCount'     => $songCount,
            'pickingMode'   => (bool) $pattern->picking_mode,
            'fingerIndex'   => $pattern->finger_index,
            'fingerMiddle'  => $pattern->finger_middle,
            'fingerRing'    => $pattern->finger_ring,
        ];
    }

    // ── Phase 11b: JSON endpoints for mountSbnNodes.ts + palette search ───────

    public function apiShow(string $slug): \Illuminate\Http\JsonResponse
    {
        $pattern = RhythmPattern::where('slug', $slug)->firstOrFail();

        return response()->json($this->serializePattern($pattern));
    }

    public function apiSearch(Request $request): \Illuminate\Http\JsonResponse
    {
        $q = trim((string) $request->get('q', ''));

        $query = RhythmPattern::ordered();
        if ($q !== '') {
            $query->where(function ($qb) use ($q) {
                $qb->where('name', 'like', "%{$q}%")
                   ->orWhere('slug', 'like', "%{$q}%")
                   ->orWhere('category', 'like', "%{$q}%");
            });
        }

        $results = $query->limit(20)->get()->map(fn ($p) => [
            'slug'     => $p->slug,
            'label'    => $p->name,
            'meta'     => $p->category,
            // id + label only — the palette's "Video example" picker lists
            // these; the full snippet objects are resolved later by
            // CourseController::player(). See plan §0.5 step 4.
            'snippets' => collect($p->video_snippets ?? [])
                ->map(fn ($s) => ['id' => $s['id'], 'label' => $s['label']])
                ->values(),
        ]);

        return response()->json(['results' => $results]);
    }
}

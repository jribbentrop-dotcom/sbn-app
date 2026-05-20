<?php

namespace App\Http\Controllers\Library;

use App\Http\Controllers\Controller;
use App\Models\Leadsheet;
use App\Models\RhythmPattern;
use Illuminate\Http\Request;
use Inertia\Inertia;

class RhythmLibraryController extends Controller
{
    /**
     * Map rhythm categories to music-style color tokens. Keys are matched
     * case-insensitively against the pattern's category column.
     */
    private const CATEGORY_TO_STYLE = [
        'brazilian'   => 'samba',
        'bossa nova'  => 'bossa',
        'bossa'       => 'bossa',
        'jazz'        => 'jazz',
        'latin'       => 'latin',
        'cuban'       => 'cuban',
        'blues'       => 'blues',
        'classical'   => 'classical',
        'general'     => 'general',
    ];

    public function index()
    {
        $patterns = RhythmPattern::ordered()
            ->get()
            ->map(fn ($p) => $this->serializePattern($p));

        // Group by category for display
        $grouped = $patterns->groupBy('category');

        // Get unique filter values
        $categories = $patterns->pluck('category')->unique()->sort()->values()->all();
        $timeSignatures = $patterns->pluck('timeSignature')->unique()->sort()->values()->all();
        $gridTypes = $patterns->pluck('gridType')->unique()->sort()->values()->all();

        return Inertia::render('Library/Rhythms/Index', [
            'patterns' => $patterns,
            'grouped' => $grouped,
            'categories' => $categories,
            'timeSignatures' => $timeSignatures,
            'gridTypes' => $gridTypes,
            'totalCount' => $patterns->count(),
        ]);
    }

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
            ->get()
            ->map(fn ($s) => $s->toLinkArray());

        return Inertia::render('Library/Rhythms/Show', [
            'pattern'  => $this->serializePattern($pattern),
            'siblings' => $siblings,
            'songs'    => $songs,
        ]);
    }

    public function serializePattern(RhythmPattern $pattern): array
    {
        $categoryKey = strtolower(trim((string) ($pattern->category ?? '')));
        $styleSlug = self::CATEGORY_TO_STYLE[$categoryKey] ?? 'pop';

        return [
            'id' => $pattern->id,
            'slug' => $pattern->slug,
            'name' => $pattern->name,
            'description' => $pattern->description,
            'category' => $pattern->category,
            'styleSlug' => $styleSlug,
            'timeSignature' => $pattern->time_signature,
            'beats' => $pattern->beats,
            'gridType' => $pattern->grid_type,
            'bpm' => $pattern->default_bpm,
            'thumb' => $pattern->thumb_pattern,
            'fingers' => $pattern->rhythm_pattern,
            'percTop' => $pattern->perc_top,
            'percBass' => $pattern->perc_bass,
            'demoUrl' => $pattern->mp3_file ? '/audio/rhythm-demos/' . $pattern->mp3_file : null,
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

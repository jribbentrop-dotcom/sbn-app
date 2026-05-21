<?php

namespace App\Http\Controllers\Library;

use App\Http\Controllers\Controller;
use App\Models\ChordDiagram;
use App\Models\ChordProgression;
use App\Models\Leadsheet;
use App\Services\ChordShapeCalculator;
use App\Services\HarmonicContext;
use App\Services\ProgressionBuilder;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ProgressionLibraryController extends Controller
{
    public function __construct(
        protected ProgressionBuilder $progressionBuilder,
        protected HarmonicContext $harmonicContext,
        protected ChordShapeCalculator $shapeCalculator,
    ) {
    }

    public function index(Request $request)
    {
        $category = $request->get('category');
        $search = $request->get('search');
        $sort = $request->get('sort', 'popularity');

        $progressions = ChordProgression::withSongCounts($category, $search)
            ->map(fn ($p) => $this->serializeProgression($p));

        // Apply sorting
        if ($sort === 'name') {
            $progressions = $progressions->sortBy('name')->values();
        } elseif ($sort === 'category') {
            $progressions = $progressions->sortBy('category')->values();
        } else {
            // Default: sort by song count (popularity)
            $progressions = $progressions->sortByDesc('songCount')->values();
        }

        return Inertia::render('Library/Progressions/Index', [
            'progressions' => $progressions,
            'categories' => ChordProgression::usedCategories(),
            'tags' => $this->getAllTags(),
            'totalCount' => $progressions->count(),
            'activeFilters' => [
                'category' => $category,
                'search' => $search,
                'sort' => $sort,
            ],
        ]);
    }

    public function show(Request $request, string $slug)
    {
        $progression = ChordProgression::where('slug', $slug)
            ->firstOrFail();

        // Get songs featuring this progression
        $songs = Leadsheet::published()
            ->join('sbn_progression_occurrences as o', 'sbn_leadsheets.id', '=', 'o.leadsheet_id')
            ->where('o.progression_id', $progression->id)
            ->select('sbn_leadsheets.id', 'sbn_leadsheets.slug', 'sbn_leadsheets.title', 'sbn_leadsheets.rhythm', 'sbn_leadsheets.cover_image_path')
            ->orderBy('sbn_leadsheets.title')
            ->get()
            ->map(fn ($s) => $s->toLinkArray());

        // Get siblings (other progressions in same category)
        $siblings = ChordProgression::where('category', $progression->category)
            ->where('id', '!=', $progression->id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn ($p) => $this->serializeProgression($p));

        // Optional: pin a specific chord voicing when arriving from a chord detail page.
        $pinnedSlot    = null;
        $pinnedVoicing = null;
        $chordSlug     = $request->query('chord');
        $highlightSlot = $request->query('highlight');
        if ($chordSlug !== null && $highlightSlot !== null) {
            $pinnedChord = ChordDiagram::where('slug', $chordSlug)->first();
            if ($pinnedChord) {
                $pinnedSlot     = (int) $highlightSlot;
                $transposed     = $this->shapeCalculator->calculateFrets($pinnedChord, $pinnedChord->root_note ?? 'C');
                $diagData       = $transposed['diagram_data']    ?? json_decode($pinnedChord->diagram_data, true) ?? [];
                $startFret      = $transposed['start_fret']      ?? ($pinnedChord->start_fret ?? 1);
                $intervalLabels = $transposed['interval_labels'] ?? ($pinnedChord->interval_labels ?? '');
                $notesField     = $transposed['notes']           ?? ($pinnedChord->notes ?? '');
                $pinnedVoicing  = [
                    'id'               => $pinnedChord->id,
                    'root_note'        => $pinnedChord->root_note ?? 'C',
                    'quality'          => $pinnedChord->quality,
                    'extensions'       => $pinnedChord->extensions ?? '',
                    'voicing_category' => $pinnedChord->voicing_category,
                    'root_string'      => $pinnedChord->root_string,
                    'inversion'        => $pinnedChord->inversion ?? 'root',
                    'start_fret'       => $startFret,
                    'diagram_data'     => $diagData,
                    'interval_labels'  => $intervalLabels,
                    'notes'            => $notesField,
                    'popularity'       => $pinnedChord->popularity ?? 0,
                    'frets'            => null,
                ];
            }
        }

        $context = $this->harmonicContext->buildFromNumerals('C', $progression->numerals);
        $built   = $this->progressionBuilder->buildVoicings($context, [
            'category'      => $progression->category,
            'pinnedSlot'    => $pinnedSlot,
            'pinnedVoicing' => $pinnedVoicing,
        ]);
        $tiles   = array_map(function ($sel) {
            $v = $sel['voicing'] ?? null;
            return [
                'chordName'      => $sel['chord_name'],
                'numeral'        => $sel['roman_numeral'] ?? null,
                'diagramData'    => $v,
                'functionalRole' => $v['functional_role'] ?? null,
                'slug'           => null,
            ];
        }, $built['selections']);

        return Inertia::render('Library/Progressions/Show', [
            'progression' => $this->serializeProgression($progression),
            'songs'       => $songs,
            'siblings'    => $siblings,
            'tiles'       => $tiles,
        ]);
    }

    private function serializeChordDiagram(ChordDiagram $diagram): array
    {
        return [
            'id'               => $diagram->id,
            'slug'             => $diagram->slug,
            'name'             => $diagram->name,
            'root_note'        => $diagram->root_note,
            'quality'          => $diagram->quality,
            'quality_label'    => $diagram->quality_label,
            'extensions'       => $diagram->extensions,
            'voicing_category' => $diagram->voicing_category,
            'category_label'   => $diagram->category_label,
            'root_string'      => $diagram->root_string,
            'root_string_label'=> $diagram->root_string_label,
            'inversion'        => $diagram->inversion ?? 'root',
            'inversion_label'  => $diagram->inversion_label,
            'bass_note'        => $diagram->bass_note,
            'shape_family'     => $diagram->shape_family,
            'start_fret'       => $diagram->start_fret ?? 1,
            'diagram_data'     => json_decode($diagram->diagram_data ?? '{}', true) ?: ['positions' => [], 'barres' => [], 'muted' => [], 'open' => []],
            'interval_labels'  => $diagram->interval_labels,
            'notes'            => $diagram->notes,
            'popularity'       => $diagram->popularity,
            'difficulty'       => $diagram->difficulty,
            'description'      => $diagram->description,
        ];
    }

    public function serializeProgression(ChordProgression $progression): array
    {
        // Map progression categories to style slugs for colors
        $styleSlug = $this->mapCategoryToStyleSlug($progression->category);

        return [
            'id' => $progression->id,
            'slug' => $progression->slug,
            'name' => $progression->name,
            'category' => $progression->category,
            'styleSlug' => $styleSlug,
            'numerals' => $progression->numerals,
            'numeralsDisplay' => $progression->numerals_display,
            'tonality' => $progression->tonality,
            'tags' => $progression->tags_array,
            'description' => $progression->description,
            'typicalGenres' => $progression->typical_genres,
            'chordCount' => count(explode(',', $progression->numerals)),
            'songCount' => $progression->song_count ?? 0,
        ];
    }

    private function mapCategoryToStyleSlug(string $category): string
    {
        $mapping = [
            'jazz' => 'jazz',
            'blues' => 'blues',
            'pop' => 'pop',
            'modal' => 'pop', // Modal uses pop colors
            'classical' => 'classical',
            'latin' => 'latin',
            'other' => 'bossa', // Other uses default bossa color
        ];

        return $mapping[$category] ?? 'bossa';
    }

    private function getAllTags(): array
    {
        $tags = ChordProgression::whereNotNull('tags')
            ->where('tags', '!=', '')
            ->pluck('tags')
            ->flatMap(function ($tags) {
                return array_map('trim', explode(',', $tags));
            })
            ->unique()
            ->sort()
            ->values()
            ->toArray();

        return $tags;
    }

    // ── Phase 11b: JSON endpoints for mountSbnNodes.ts + palette search ───────

    /**
     * Build the resolved chord tiles for a progression in a given key.
     *
     * Progression chords are not stored — they are materialised on the fly by
     * `ProgressionBuilder` from the `numerals` string. Shared by `apiShow()`
     * (mountSbnNodes / palette) and `CourseController::player()` so the course
     * player renders identical voicings to the inline `<sbn-progression>`.
     *
     * Returns the `ProgressionChord[]` shape `ChordProgressionViewer` expects.
     */
    public function buildChordsFor(ChordProgression $progression, string $key = 'C', bool $usePass2 = true): array
    {
        $context = $this->harmonicContext->buildFromNumerals($key, $progression->numerals);
        $built   = $this->progressionBuilder->buildVoicings($context, [
            'category'   => $progression->category,
            'extensions' => $usePass2,
        ]);

        return array_map(fn ($sel) => [
            'chordName'      => $sel['chord_name'],
            'diagramData'    => $sel['voicing'] ?? null,
            'functionalRole' => $sel['voicing']['functional_role'] ?? null,
            'beats'          => 4,
            'slug'           => null,
        ], $built['selections']);
    }

    public function apiShow(Request $request, string $slug): \Illuminate\Http\JsonResponse
    {
        $progression = ChordProgression::where('slug', $slug)->firstOrFail();

        $key      = trim((string) $request->get('key', 'C')) ?: 'C';
        $usePass2 = (bool) $request->boolean('pass2', true);

        $chords = $this->buildChordsFor($progression, $key, $usePass2);

        return response()->json([
            'progression' => $this->serializeProgression($progression),
            'key'         => $key,
            'chords'      => $chords,
        ]);
    }

    public function apiSearch(Request $request): \Illuminate\Http\JsonResponse
    {
        $q = trim((string) $request->get('q', ''));

        $query = ChordProgression::query();
        if ($q !== '') {
            $query->where(function ($qb) use ($q) {
                $qb->where('name', 'like', "%{$q}%")
                   ->orWhere('slug', 'like', "%{$q}%")
                   ->orWhere('category', 'like', "%{$q}%")
                   ->orWhere('numerals', 'like', "%{$q}%");
            });
        }

        $results = $query->orderBy('name')->limit(20)->get()->map(fn ($p) => [
            'slug'     => $p->slug,
            'label'    => $p->name,
            'meta'     => $p->category,
            // id + label only — the palette's "Video example" picker lists
            // these; the full snippet objects are resolved later by
            // CourseController::player(). Mirrors the rhythm apiSearch.
            'snippets' => collect($p->video_snippets ?? [])
                ->map(fn ($s) => ['id' => $s['id'], 'label' => $s['label']])
                ->values(),
        ]);

        return response()->json(['results' => $results]);
    }
}

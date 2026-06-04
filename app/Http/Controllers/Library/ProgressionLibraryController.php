<?php

namespace App\Http\Controllers\Library;

use App\Http\Controllers\Controller;
use App\Models\ChordDiagram;
use App\Models\ChordProgression;
use App\Models\Leadsheet;
use App\Repositories\CourseRepository;
use App\Services\ChordSerializer;
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
        protected ChordSerializer $chordSerializer,
        protected CourseRepository $courseRepo,
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
        $progression = ChordProgression::where('sbn_chord_progressions.slug', $slug)
            ->leftJoin('sbn_progression_occurrences as occ', 'sbn_chord_progressions.id', '=', 'occ.progression_id')
            ->selectRaw('sbn_chord_progressions.*, COUNT(DISTINCT occ.leadsheet_id) as song_count')
            ->groupBy('sbn_chord_progressions.id')
            ->firstOrFail();

        // Get songs featuring this progression
        $songs = Leadsheet::published()
            ->join('sbn_progression_occurrences as o', 'sbn_leadsheets.id', '=', 'o.leadsheet_id')
            ->where('o.progression_id', $progression->id)
            ->select('sbn_leadsheets.id', 'sbn_leadsheets.slug', 'sbn_leadsheets.title', 'sbn_leadsheets.genre', 'sbn_leadsheets.rhythm', 'sbn_leadsheets.cover_image_path')
            ->distinct()
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
        $progressionKey = 'C';
        $chordSlug     = $request->query('chord');
        $highlightSlot = $request->query('highlight');
        if ($chordSlug !== null && $highlightSlot !== null) {
            $pinnedChord = ChordDiagram::where('slug', $chordSlug)->first();
            if ($pinnedChord) {
                $pinnedSlot = (int) $highlightSlot;

                // Resolve the effective root before building the voicing — the ?root=
                // param carries the transposed/alias root the user was viewing.
                $effectiveRoot = $request->query('root', $pinnedChord->root_note ?? 'C');
                if (! in_array($effectiveRoot, \App\Models\ChordDiagram::ROOT_NOTES)) {
                    $effectiveRoot = $pinnedChord->root_note ?? 'C';
                }

                $transposed     = $this->shapeCalculator->calculateFrets($pinnedChord, $effectiveRoot);
                $diagData       = $transposed['diagram_data']    ?? json_decode($pinnedChord->diagram_data, true) ?? [];
                $startFret      = $transposed['start_fret']      ?? ($pinnedChord->start_fret ?? 1);
                $intervalLabels = $transposed['interval_labels'] ?? ($pinnedChord->interval_labels ?? '');
                $notesField     = $transposed['notes']           ?? ($pinnedChord->notes ?? '');
                $pinnedVoicing  = [
                    'id'               => $pinnedChord->id,
                    'root_note'        => $effectiveRoot,
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

                $tokens = array_values(array_filter(array_map('trim', explode(',', $progression->numerals))));
                if (isset($tokens[$pinnedSlot]) && $effectiveRoot !== '') {
                    $progressionKey = $this->harmonicContext->keyFromNumeralAndRoot(
                        $tokens[$pinnedSlot],
                        $effectiveRoot
                    );
                }
            }
        }

        $context = $this->harmonicContext->buildFromNumerals($progressionKey, $progression->numerals);
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

        $courses = $this->courseRepo->relatedTo($progression, $progression->category);

        return Inertia::render('Library/Progressions/Show', [
            'progression' => $this->serializeProgression($progression),
            'songs'       => $songs,
            'siblings'    => $siblings,
            'tiles'       => $tiles,
            'courses'     => $courses,
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
            'chordCount' => count(explode(',', $progression->numerals)),
            'songCount' => $progression->song_count ?? 0,
        ];
    }

    private function mapCategoryToStyleSlug(string $category): string
    {
        $mapping = [
            'bossa-nova' => 'bossa-nova',
            'jazz'       => 'jazz',
            'classical'  => 'classical',
            'pop'        => 'pop',
        ];

        return $mapping[$category] ?? 'bossa-nova';
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
    /**
     * Build the ProgressionChord[] array for ChordProgressionViewer.
     *
     * When $pinnedSlugs is provided (one slug per numeral slot from a video
     * snippet's `chords` field), the builder is bypassed entirely and the
     * library diagrams are used directly. Slots where the slug is empty string
     * or the slug can't be resolved fall back to the built voicing for that slot.
     */
    public function buildChordsFor(
        ChordProgression $progression,
        string $key = 'C',
        bool $usePass2 = true,
        array $pinnedSlugs = []
    ): array {
        $context = $this->harmonicContext->buildFromNumerals($key, $progression->numerals);
        $built   = $this->progressionBuilder->buildVoicings($context, [
            'category'   => $progression->category,
            'extensions' => $usePass2,
        ]);

        $builtSelections = $built['selections'];

        if (empty($pinnedSlugs)) {
            return array_map(fn ($sel) => [
                'chordName'      => $sel['chord_name'],
                'diagramData'    => $sel['voicing'] ?? null,
                'functionalRole' => $sel['voicing']['functional_role'] ?? null,
                'beats'          => 4,
                'slug'           => null,
                'numeral'        => $sel['roman_numeral'] ?? null,
            ], $builtSelections);
        }

        // Resolve non-empty slugs from the library in one query.
        $nonEmpty = array_values(array_filter($pinnedSlugs, fn ($s) => $s !== '' && $s !== null));
        $diagrams = ChordDiagram::whereIn('slug', $nonEmpty)->get()->keyBy('slug');

        $result = [];
        foreach ($builtSelections as $i => $sel) {
            $slug = $pinnedSlugs[$i] ?? '';
            $diagram = ($slug !== '' && $slug !== null) ? ($diagrams->get($slug) ?? null) : null;

            if ($diagram) {
                // A pinned diagram is a MOVABLE SHAPE — its stored
                // diagram_data/root_note is an arbitrary low reference
                // position, not the chord in this key. Transpose it to the
                // root this numeral slot resolves to in $key (the builder
                // already computed it) so the viewer shows e.g. Dmaj7, not
                // the shape's stored Cmaj7. ChordSerializer::serialize runs
                // the shape calculator when given a root override.
                $targetRoot = $sel['voicing']['root_note'] ?? null;
                $serialized = $this->chordSerializer->serialize($diagram, $targetRoot);
                $result[] = [
                    'chordName'      => $serialized['name'],
                    'diagramData'    => $serialized,
                    'functionalRole' => null,
                    'beats'          => 4,
                    'slug'           => $slug,
                    'numeral'        => $sel['roman_numeral'] ?? null,
                ];
            } else {
                // Pinned slug missing or unresolvable — fall back to builder voicing.
                $result[] = [
                    'chordName'      => $sel['chord_name'],
                    'diagramData'    => $sel['voicing'] ?? null,
                    'functionalRole' => $sel['voicing']['functional_role'] ?? null,
                    'beats'          => 4,
                    'slug'           => null,
                    'numeral'        => $sel['roman_numeral'] ?? null,
                ];
            }
        }

        return $result;
    }

    public function apiShow(Request $request, string $slug): \Illuminate\Http\JsonResponse
    {
        $progression = ChordProgression::where('slug', $slug)->firstOrFail();

        $key      = trim((string) $request->get('key', 'C')) ?: 'C';
        $usePass2 = (bool) $request->boolean('pass2', true);

        // Optional pinned chord slugs from a video snippet (comma-separated).
        // When present, buildChordsFor bypasses the builder for those slots.
        $pinnedSlugs = [];
        if ($request->filled('chords')) {
            $pinnedSlugs = array_map('trim', explode(',', (string) $request->get('chords')));
        }

        $chords = $this->buildChordsFor($progression, $key, $usePass2, $pinnedSlugs);

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
            // id + label + key — the palette's "Video example" picker lists
            // these; `key` lets the palette stamp the snippet's playing key
            // onto the inserted <sbn-progression> tag. The full snippet
            // objects are resolved later by CourseController::player().
            'snippets' => collect($p->video_snippets ?? [])
                ->map(fn ($s) => [
                    'id'    => $s['id'],
                    'label' => $s['label'],
                    'key'   => $s['key'] ?? null,
                ])
                ->values(),
        ]);

        return response()->json(['results' => $results]);
    }
}

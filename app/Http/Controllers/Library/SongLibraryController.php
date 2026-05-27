<?php

namespace App\Http\Controllers\Library;

use App\Http\Controllers\Controller;
use App\Models\ChordProgression;
use App\Models\Leadsheet;
use App\Services\ChordVoicingSearch;
use App\Services\EduContentService;
use App\Services\HarmonicContext;
use App\Services\LeadsheetViewerService;
use App\Services\ProgressionBuilder;
use Illuminate\Support\Str;
use Inertia\Inertia;

class SongLibraryController extends Controller
{
    public function __construct(
        protected LeadsheetViewerService $viewerService,
        protected ProgressionBuilder $progressionBuilder,
        protected HarmonicContext $harmonicContext
    ) {}

    /**
     * Map a rhythm slug to a music-style slug for color assignment.
     * Rhythm slugs from the RhythmPattern model → canonical style slugs.
     */
    private function rhythmToStyleSlug(?string $rhythm): string
    {
        if (!$rhythm) {
            return 'bossa';
        }

        $map = [
            'bossa'        => 'bossa',
            'bossa-nova'   => 'bossa',
            'samba'        => 'samba',
            'jazz'         => 'jazz',
            'swing'        => 'jazz',
            'latin'        => 'latin',
            'afro-cuban'   => 'latin',
            'blues'        => 'blues',
            'pop'          => 'pop',
            'ballad'       => 'pop',
            'classical'    => 'classical',
        ];

        // Try exact match first
        if (isset($map[$rhythm])) {
            return $map[$rhythm];
        }

        // Try prefix match (e.g. "bossa-nova-variation" → "bossa")
        foreach ($map as $prefix => $style) {
            if (str_starts_with($rhythm, $prefix)) {
                return $style;
            }
        }

        return 'bossa';
    }

    private function serializeSong(Leadsheet $song): array
    {
        return [
            'id'            => $song->id,
            'slug'          => $song->slug,
            'title'         => $song->title,
            'composer'      => $song->composer,
            'songKey'       => $song->song_key,
            'tempo'         => $song->tempo,
            'timeSignature' => $song->time_signature,
            'rhythm'        => $song->rhythm,
            'styleSlug'     => $song->genre ?? $this->rhythmToStyleSlug($song->rhythm),
            'description'   => $song->description ? Str::limit(strip_tags($song->description), 120) : null,
            'popularity'      => $song->popularity,
            'measureCount'    => $song->measure_count,
            'coverImagePath'  => $song->cover_image_path ? '/images/songs/' . $song->cover_image_path : null,
            'tags'            => $song->tags()->pluck('slug')->all(),
        ];
    }

    public function index()
    {
        // Public library shows published leadsheets only — drafts are admin-only.
        $songs = Leadsheet::published()->orderBy('title')->get();

        $serialized = $songs->map(fn ($s) => $this->serializeSong($s));

        $composers = Leadsheet::published()
            ->whereNotNull('composer')
            ->where('composer', '!=', '')
            ->selectRaw('composer, COUNT(*) as cnt')
            ->groupBy('composer')
            ->orderByDesc('cnt')
            ->limit(40)
            ->pluck('composer')
            ->toArray();

        $keys = Leadsheet::published()
            ->whereNotNull('song_key')
            ->where('song_key', '!=', '')
            ->distinct()
            ->orderBy('song_key')
            ->pluck('song_key')
            ->toArray();

        $rhythms = Leadsheet::published()
            ->whereNotNull('rhythm')
            ->where('rhythm', '!=', '')
            ->distinct()
            ->orderBy('rhythm')
            ->pluck('rhythm')
            ->toArray();

        return Inertia::render('Library/Songs/Index', [
            'songs'      => $serialized,
            'composers'  => $composers,
            'keys'       => $keys,
            'rhythms'    => $rhythms,
            'totalCount' => $songs->count(),
        ]);
    }

    /**
     * Draft leadsheets are admin-only — 404 on any public-facing route.
     */
    private function abortIfDraft(Leadsheet $leadsheet): void
    {
        abort_if($leadsheet->status !== 'publish', 404);
    }

    public function show(Leadsheet $leadsheet, ChordVoicingSearch $search)
    {
        $this->abortIfDraft($leadsheet);

        // Fetch actual voicings from the leadsheet
        $leadsheetVoicings = $leadsheet->parsed_data['chordVoicings'] ?? [];
        $uniqueChords = [];
        $searchCache = [];

        foreach ($leadsheetVoicings as $key => $voicing) {
            if (preg_match('/^(.+)@\d+\.\d+$/', $key, $m)) {
                $chordName = $m[1];
            } else {
                $chordName = $key;
            }

            // If we already have a voicing for this chord name, skip
            if (isset($uniqueChords[$chordName])) {
                continue;
            }

            if (!isset($searchCache[$chordName])) {
                $searchCache[$chordName] = $search->searchByName($chordName);
            }
            $matches = $searchCache[$chordName];

            $best = $this->viewerService->pickBestVoicing($matches, $voicing['frets'] ?? null);

            if ($best) {
                $uniqueChords[$chordName] = $best;
            } else {
                $card = $this->viewerService->synthesizeMinimalCard($chordName, $voicing, $search);
                if (!empty($matches) && isset($matches[0]['slug'])) {
                    $card['slug'] = $matches[0]['slug'];
                } else {
                    $card['slug'] = \App\Models\ChordDiagram::where('quality', $card['quality'])->first()?->slug ?? '';
                }
                $uniqueChords[$chordName] = $card;
            }
        }

        // Sort all unique chords by popularity DESC and take top 4
        $bestVoicings = array_values($uniqueChords);
        usort($bestVoicings, fn ($a, $b) => ($b['popularity'] ?? 0) <=> ($a['popularity'] ?? 0));
        $topChords = [];
        $seenPatterns = [];

        foreach ($bestVoicings as $card) {
            $pattern = $this->viewerService->getVoicingShapePattern($card['diagram_data'] ?? null);
            if ($pattern && in_array($pattern, $seenPatterns)) {
                continue;
            }
            if ($pattern) {
                $seenPatterns[] = $pattern;
            }
            $topChords[] = $card;
            if (count($topChords) >= 4) {
                break;
            }
        }

        // Progressions detected in this song
        $progressions = ChordProgression::query()
            ->join('sbn_progression_occurrences as o', 'sbn_chord_progressions.id', '=', 'o.progression_id')
            ->where('o.leadsheet_id', $leadsheet->id)
            ->select('sbn_chord_progressions.id', 'sbn_chord_progressions.slug', 'sbn_chord_progressions.name', 'sbn_chord_progressions.category', 'sbn_chord_progressions.numerals')
            ->distinct()
            ->orderBy('sbn_chord_progressions.name')
            ->get()
            ->map(function ($p) use ($leadsheet) {
                // Resolve chord diagram tiles via the proper voice-leading path
                $root    = $leadsheet->song_key ?? 'C';
                $context = $this->harmonicContext->buildFromNumerals($root, $p->numerals);
                $built   = $this->progressionBuilder->buildVoicings($context, [
                    'category'   => $p->category,
                    'extensions' => false,
                ]);

                $tiles = array_map(function ($sel) {
                    $v = $sel['voicing'] ?? null;
                    return [
                        'chordName'   => $sel['chord_name'],
                        'numeral'     => $sel['roman_numeral'] ?? null,
                        'diagramData' => $v,
                        'slug'        => null,
                    ];
                }, $built['selections'] ?? []);

                return [
                    'id'             => $p->id,
                    'slug'           => $p->slug,
                    'name'           => $p->name,
                    'category'       => $p->category,
                    'numeralsDisplay'=> $p->numerals_display,
                    'tiles'          => $tiles,
                ];
            });

        $chordNames = $leadsheet->getChordNames();

        $rhythmPattern = \App\Models\RhythmPattern::where('slug', $leadsheet->rhythm)->first();

        return Inertia::render('Library/Songs/Show', [
            'song' => [
                'id'            => $leadsheet->id,
                'slug'          => $leadsheet->slug,
                'title'         => $leadsheet->title,
                'composer'      => $leadsheet->composer,
                'songKey'       => $leadsheet->song_key,
                'tempo'         => $leadsheet->tempo,
                'timeSignature' => $leadsheet->time_signature,
                'description'   => $leadsheet->description,
                'harmonyNotes'  => $leadsheet->harmony_notes,
                'formNotes'     => $leadsheet->form_notes,
                'voicingNotes'  => $leadsheet->voicing_notes,
                'rhythm'        => $leadsheet->rhythm,
                'rhythmName'    => $rhythmPattern?->name ?? $leadsheet->rhythm,
                'rhythmCategory'=> $rhythmPattern?->category ?? 'general',
                'rhythmData'    => $rhythmPattern ? $rhythmPattern->toPlayerData() : null,
                'styleSlug'     => $leadsheet->genre ?? $this->rhythmToStyleSlug($leadsheet->rhythm),
                'measureCount'    => $leadsheet->measure_count,
                'popularity'      => $leadsheet->popularity,
                'difficulty'      => $leadsheet->difficulty,
                'coverImagePath'  => $leadsheet->cover_image_path ? '/images/songs/' . $leadsheet->cover_image_path : null,
            ],
            'chordNames'   => $chordNames,
            'chords'       => $topChords,
            'progressions' => $progressions,
        ]);
    }

    /**
     * Collect every concept topic referenced by any quality's `related` field,
     * keyed by concept slug. Null-resolved slugs (missing concept files) are
     * filtered out so the frontend only sees topics that actually exist.
     *
     * @return array<string, array{slug:string,title:string,summary:string,body_html:string,has_widgets:bool}>
     */
    private function buildEduRelatedConcepts(EduContentService $edu): array
    {
        $allRelated = collect($edu->allChordQualities())
            ->flatMap(fn ($quality) => $quality['related'] ?? [])
            ->unique()
            ->values();

        return collect($allRelated)
            ->mapWithKeys(fn ($slug) => [
                $slug => $edu->topic('concept', $slug)?->toArray(),
            ])
            ->filter()
            ->all();
    }

    public function viewer(Leadsheet $leadsheet, ChordVoicingSearch $search, EduContentService $edu)
    {
        $this->abortIfDraft($leadsheet);
        $enriched = $this->viewerService->enrich($leadsheet, $search);

        return Inertia::render('Library/Songs/Viewer', [
            'leadsheet' => [
                'id'            => $leadsheet->id,
                'slug'          => $leadsheet->slug,
                'title'         => $leadsheet->title,
                'composer'      => $leadsheet->composer,
                'songKey'       => $leadsheet->song_key,
                'tempo'         => $leadsheet->tempo,
                'timeSignature' => $leadsheet->time_signature,
                'rhythm'        => $leadsheet->rhythm,
                'jsonData'      => $leadsheet->parsed_data,
                'harmonyNotes'  => $leadsheet->harmony_notes,
                'formNotes'     => $leadsheet->form_notes,
                'voicingNotes'  => $leadsheet->voicing_notes,
            ],
            ...$enriched,
            'eduChordQualities'   => $edu->allChordQualities(),
            'eduRelatedConcepts'  => $this->buildEduRelatedConcepts($edu),
        ]);
    }

    // ── Phase 11b: JSON endpoints for mountSbnNodes.ts + palette search ───────

    public function apiSearch(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $q = trim((string) $request->get('q', ''));

        $query = Leadsheet::query();
        if ($q !== '') {
            $query->where(function ($qb) use ($q) {
                $qb->where('title', 'like', "%{$q}%")
                   ->orWhere('slug', 'like', "%{$q}%")
                   ->orWhere('composer', 'like', "%{$q}%");
            });
        }

        $results = $query->orderBy('title')->limit(20)->get()->map(fn ($s) => [
            'slug'  => $s->slug,
            'label' => $s->title,
            'meta'  => $s->composer,
        ]);

        return response()->json(['results' => $results]);
    }

    public function apiViewerData(Leadsheet $leadsheet, ChordVoicingSearch $search, EduContentService $edu): \Illuminate\Http\JsonResponse
    {
        $this->abortIfDraft($leadsheet);
        $enriched = $this->viewerService->enrich($leadsheet, $search);

        return response()->json([
            'leadsheet' => [
                'id'            => $leadsheet->id,
                'slug'          => $leadsheet->slug,
                'title'         => $leadsheet->title,
                'composer'      => $leadsheet->composer,
                'songKey'       => $leadsheet->song_key,
                'tempo'         => $leadsheet->tempo,
                'timeSignature' => $leadsheet->time_signature,
                'rhythm'        => $leadsheet->rhythm,
                'jsonData'      => $leadsheet->parsed_data,
                'harmonyNotes'  => $leadsheet->harmony_notes,
                'formNotes'     => $leadsheet->form_notes,
                'voicingNotes'  => $leadsheet->voicing_notes,
            ],
            ...$enriched,
            'eduChordQualities'   => $edu->allChordQualities(),
            'eduRelatedConcepts'  => $this->buildEduRelatedConcepts($edu),
        ]);
    }

    public function cinema(Leadsheet $leadsheet, ChordVoicingSearch $search)
    {
        $this->abortIfDraft($leadsheet);
        $enriched = $this->viewerService->enrich($leadsheet, $search);

        return Inertia::render('Leadsheet/Cinema', [
            'leadsheet' => [
                'id'            => $leadsheet->id,
                'slug'          => $leadsheet->slug,
                'title'         => $leadsheet->title,
                'composer'      => $leadsheet->composer,
                'songKey'       => $leadsheet->song_key,
                'tempo'         => $leadsheet->tempo,
                'timeSignature' => $leadsheet->time_signature,
                'jsonData'      => $leadsheet->parsed_data,
            ],
            'chordCards' => $enriched['chordCards'],
            'classicUrl' => route('library.songs.viewer', ['leadsheet' => $leadsheet->slug]),
        ]);
    }
}

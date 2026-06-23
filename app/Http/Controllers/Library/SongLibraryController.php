<?php

namespace App\Http\Controllers\Library;

use App\Http\Controllers\Controller;
use App\Models\ChordProgression;
use App\Models\Leadsheet;
use App\Models\LeadsheetVersion;
use App\Repositories\CourseRepository;
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
        protected HarmonicContext $harmonicContext,
        protected CourseRepository $courseRepo,
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
            'styleSlug'     => Leadsheet::resolveStyleSlug($song->genre, $song->rhythm),
            'description'   => $song->description ? Str::limit(strip_tags($song->description), 120) : null,
            'popularity'      => $song->popularity,
            'difficulty'      => $song->difficulty,
            'measureCount'    => $song->measure_count,
            'coverImagePath'  => $song->cover_image_path ? '/images/songs/' . $song->cover_image_path : null,
            'tags'            => $song->tags()->pluck('slug')->all(),
            'isPro'           => (bool) $song->is_pro,
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

    /**
     * The full Viewer/Cinema experience (tab, melody, synced playback) is only
     * available for songs explicitly marked is_pro — i.e. cleared/public-domain
     * titles with a real arrangement. Everything else stops at the free
     * chord/progression/rhythm reference page (Show.vue).
     */
    private function abortIfNotPro(Leadsheet $leadsheet): void
    {
        abort_if(!$leadsheet->is_pro, 404);
    }

    /**
     * Resolve the active arrangement for a request.
     *
     * `?v={version_slug}` selects an explicit version (404 if it doesn't belong to
     * this leadsheet); otherwise the leadsheet's default version; otherwise the first
     * version. During the dual-read window every leadsheet has a backfilled 'basic'
     * version, so this always returns a row — but we fall back gracefully to a
     * synthesized version off the legacy columns if one is somehow missing.
     */
    private function resolveVersion(Leadsheet $leadsheet, ?string $versionSlug): LeadsheetVersion
    {
        if ($versionSlug) {
            $version = $leadsheet->versions()->where('version_slug', $versionSlug)->first();
            abort_if(!$version, 404);
            return $version;
        }

        $version = $leadsheet->defaultVersion
            ?? $leadsheet->versions()->first();

        if ($version) {
            return $version;
        }

        // Defensive fallback (no version row yet): wrap the legacy leadsheet columns
        // so callers can read ->parsed_data / ->getChordNames() uniformly.
        return new LeadsheetVersion([
            'leadsheet_id'   => $leadsheet->id,
            'version_slug'   => 'basic',
            'label'          => 'Basic',
            'song_key'       => $leadsheet->song_key,
            'rhythm'         => $leadsheet->rhythm,
            'tempo'          => $leadsheet->tempo,
            'measure_count'  => $leadsheet->measure_count,
            'json_data'      => $leadsheet->json_data,
            'melody_tab_xml' => $leadsheet->tab_xml,
        ]);
    }

    /**
     * Serialize a leadsheet's versions for the Show-page arrangement dropdown.
     */
    private function versionList(Leadsheet $leadsheet, LeadsheetVersion $active): array
    {
        return $leadsheet->versions->map(fn (LeadsheetVersion $v) => [
            'id'         => $v->id,
            'slug'       => $v->version_slug,
            'label'      => $v->label ?: 'Basic',
            'performer'  => $v->performer,
            'difficulty' => $v->difficulty,
            'isActive'   => $v->id === $active->id,
        ])->values()->all();
    }

    public function show(\Illuminate\Http\Request $request, Leadsheet $leadsheet, ChordVoicingSearch $search)
    {
        $this->abortIfDraft($leadsheet);

        $version = $this->resolveVersion($leadsheet, $request->query('v'));
        $songKey = $version->song_key ?: $leadsheet->song_key;
        $rhythm  = $version->rhythm ?: $leadsheet->rhythm;

        // Fetch actual voicings from the active arrangement
        $leadsheetVoicings = $version->parsed_data['chordVoicings'] ?? [];
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
        }

        // Progressions detected in this arrangement (version-scoped)
        $progressions = ChordProgression::query()
            ->join('sbn_progression_occurrences as o', 'sbn_chord_progressions.id', '=', 'o.progression_id')
            ->where('o.version_id', $version->id)
            ->select('sbn_chord_progressions.id', 'sbn_chord_progressions.slug', 'sbn_chord_progressions.name', 'sbn_chord_progressions.category', 'sbn_chord_progressions.numerals')
            ->distinct()
            ->orderBy('sbn_chord_progressions.name')
            ->get()
            ->map(function ($p) use ($songKey) {
                // Resolve chord diagram tiles via the proper voice-leading path
                $root    = $songKey ?? 'C';
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

        $chordNames = $version->getChordNames();

        $rhythmPattern = \App\Models\RhythmPattern::where('slug', $rhythm)->first();

        // Normalise style_slug → course category (courses use full slugs like 'bossa-nova')
        $rawStyle = Leadsheet::resolveStyleSlug($leadsheet->genre, $leadsheet->rhythm);
        $songCourseCategory = match ($rawStyle) {
            'bossa-nova' => 'bossa-nova',
            default       => $rawStyle,
        };
        $courses = $this->courseRepo->relatedTo($leadsheet, $songCourseCategory);

        return Inertia::render('Library/Songs/Show', [
            'song' => [
                'id'            => $leadsheet->id,
                'slug'          => $leadsheet->slug,
                'title'         => $leadsheet->title,
                'composer'      => $leadsheet->composer,
                'songKey'       => $songKey,
                'tempo'         => $version->tempo ?: $leadsheet->tempo,
                'timeSignature' => $leadsheet->time_signature,
                'description'   => $leadsheet->description,
                'harmonyNotes'  => $leadsheet->harmony_notes,
                'formNotes'     => $leadsheet->form_notes,
                'voicingNotes'  => $leadsheet->voicing_notes,
                'rhythm'        => $rhythm,
                'rhythmName'    => $rhythmPattern?->name ?? $rhythm,
                'rhythmCategory'=> $rhythmPattern?->category ?? 'general',
                'rhythmData'    => $rhythmPattern ? $rhythmPattern->toPlayerData() : null,
                'styleSlug'     => Leadsheet::resolveStyleSlug($leadsheet->genre, $rhythm),
                'measureCount'    => $version->measure_count ?: $leadsheet->measure_count,
                'popularity'      => $leadsheet->popularity,
                'difficulty'      => $version->difficulty ?? $leadsheet->difficulty,
                'coverImagePath'  => $leadsheet->cover_image_path ? '/images/songs/' . $leadsheet->cover_image_path : null,
                'isPro'           => (bool) $leadsheet->is_pro,
            ],
            'versions'     => $this->versionList($leadsheet, $version),
            'activeVersion'=> $version->version_slug,
            'chordNames'   => $chordNames,
            'chords'       => $topChords,
            'progressions' => $progressions,
            'courses'      => $courses,
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

    public function viewer(\Illuminate\Http\Request $request, Leadsheet $leadsheet, ChordVoicingSearch $search, EduContentService $edu)
    {
        $this->abortIfDraft($leadsheet);
        $this->abortIfNotPro($leadsheet);

        $version  = $this->resolveVersion($leadsheet, $request->query('v'));
        $enriched = $this->viewerService->enrich($leadsheet, $search, $version);

        return Inertia::render('Library/Songs/Viewer', [
            'leadsheet' => [
                'id'            => $leadsheet->id,
                'slug'          => $leadsheet->slug,
                'title'         => $leadsheet->title,
                'composer'      => $leadsheet->composer,
                'songKey'       => $version->song_key ?: $leadsheet->song_key,
                'tempo'         => $version->tempo ?: $leadsheet->tempo,
                'timeSignature' => $leadsheet->time_signature,
                'rhythm'        => $version->rhythm ?: $leadsheet->rhythm,
                'jsonData'      => $version->parsed_data,
                'harmonyNotes'  => $leadsheet->harmony_notes,
                'formNotes'     => $leadsheet->form_notes,
                'voicingNotes'  => $leadsheet->voicing_notes,
                'hasMelodyTab'  => $version->has_melody_tab,
                'hasChordTab'   => $version->has_chord_tab,
            ],
            'versions'      => $this->versionList($leadsheet, $version),
            'activeVersion' => $version->version_slug,
            ...$enriched,
            'eduChordQualities'   => $edu->allChordQualities(),
            'eduRelatedConcepts'  => $this->buildEduRelatedConcepts($edu),
        ]);
    }

    // ── Phase 11b: JSON endpoints for mountSbnNodes.ts + palette search ───────

    public function apiSheet(\Illuminate\Http\Request $request, string $slug): \Illuminate\Http\JsonResponse
    {
        $leadsheet = Leadsheet::where('slug', $slug)->firstOrFail();

        // Public route: draft leadsheets are author-only. Instructors may pull
        // draft excerpts while authoring lesson content; everyone else 404s,
        // consistent with abortIfDraft() on the other public song routes.
        abort_if($leadsheet->status !== 'publish' && !$request->user()?->is_instructor, 404);

        // Lesson <sbn-song> embeds address a song by slug; ?v= selects an arrangement,
        // otherwise the default version. (is_pro gating below stays work-level.)
        $version = $this->resolveVersion($leadsheet, $request->query('v'));
        $data = $version->parsed_data ?? [];

        // Ticks per measure — same formula as calcTicksPerMeasure() in useTabModel.js.
        $timeSig = $data['timeSignature'] ?? $leadsheet->time_signature ?? '4/4';
        [$num, $den] = array_map('intval', explode('/', $timeSig . '/4'));
        $tpm = $den > 0 ? (int) round($num * 1920 / $den) : 1920;

        $barsParam = trim((string) $request->query('bars', ''));
        $melody    = $data['melody'] ?? null;

        // Lesson content embeds a few bars of a song via <sbn-song slug="…" bars="5-8">
        // (mountSbnNodes.ts) — that excerpt use is fine even for non-pro/copyrighted
        // titles. A full-song request (no bars param) is the one that would hand out
        // the entire arrangement with no gating at all, so block that unless the
        // leadsheet is explicitly marked is_pro.
        abort_if($barsParam === '' && !$leadsheet->is_pro, 404);

        if ($barsParam === '') {
            // Full song — preserve original section structure, lineBreaks, repeats, voltas.
            $sections      = $data['sections'] ?? [];
            $repeatMarkers = $data['repeatMarkers'] ?? (object) [];
            $voltaEndings  = $data['voltaEndings']  ?? (object) [];
        } else {
            // Excerpt — slice measures and rebuild section/lineBreaks for the range.
            if (!preg_match('/^(\d+)-(\d+)$/', $barsParam, $m)) {
                abort(422, 'Invalid bars parameter');
            }
            $start  = max(0, (int) $m[1] - 1);
            $length = max(0, (int) $m[2] - $start);
            $end    = $start + $length - 1; // inclusive, 0-based

            $sections  = [];
            $globalPos = 0;
            foreach ($data['sections'] ?? [] as $sec) {
                $secMeasures = $sec['measures'] ?? [];
                $secCount    = count($secMeasures);
                $secStart    = $globalPos;
                $secEnd      = $globalPos + $secCount - 1;

                if ($secEnd < $start || $secStart > $end) {
                    $globalPos += $secCount;
                    continue;
                }

                $localStart     = max(0, $start - $secStart);
                $localEnd       = min($secCount - 1, $end - $secStart);
                $slicedMeasures = array_slice($secMeasures, $localStart, $localEnd - $localStart + 1);

                // Rebuild lineBreaks: walk the original row counts and keep only
                // the bars that fall within [$localStart, $localEnd].
                $newLb  = null;
                $origLb = $sec['lineBreaks'] ?? null;
                if (is_array($origLb) && count($origLb)) {
                    $newLb = [];
                    $pos   = 0;
                    foreach ($origLb as $rowCount) {
                        $rowEnd  = $pos + $rowCount - 1;
                        $overlap = max(0, min($rowEnd, $localEnd) - max($pos, $localStart) + 1);
                        if ($overlap > 0) {
                            $newLb[] = $overlap;
                        }
                        $pos += $rowCount;
                    }
                }

                $newSec = ['id' => $sec['id'] ?? 'excerpt', 'name' => $sec['name'] ?? '', 'lineBreaks' => $newLb, 'measures' => $slicedMeasures];
                foreach (['rhythmSlug', 'tonality'] as $k) {
                    if (isset($sec[$k])) $newSec[$k] = $sec[$k];
                }
                $sections[] = $newSec;
                $globalPos += $secCount;
            }

            // Filter melody notes to the bar range and re-offset ticks to 0.
            if (is_array($melody) && count($melody)) {
                $startTick = $start * $tpm;
                $endTick   = ($start + $length) * $tpm;
                $melody    = array_values(array_map(
                    fn ($note) => array_merge($note, ['tick' => ($note['tick'] ?? 0) - $startTick]),
                    array_filter($melody, fn ($note) =>
                        ($note['tick'] ?? 0) >= $startTick && ($note['tick'] ?? 0) < $endTick
                    )
                ));
            }

            $repeatMarkers = (object) [];
            $voltaEndings  = (object) [];
        }

        $titleSuffix = $barsParam ? " (bars {$barsParam})" : '';

        // Build videoSync: full song passes through unchanged; excerpts filter
        // mappings to the bar range, re-index measureIndex to 0, and subtract
        // the video-time of the first included mapping so playback starts at 0.
        $rawVideoSync = $data['videoSync'] ?? null;
        if ($rawVideoSync === null || !isset($rawVideoSync['mappings'])) {
            $videoSync = $rawVideoSync;
        } elseif ($barsParam === '') {
            $videoSync = $rawVideoSync;
        } else {
            $allMappings = $rawVideoSync['mappings'];
            usort($allMappings, fn($a, $b) => $a['measureIndex'] <=> $b['measureIndex']);

            // Find the video time of the first bar in range to use as offset.
            $videoTimeOffset = 0.0;
            foreach ($allMappings as $mapping) {
                if ($mapping['measureIndex'] >= $start) {
                    $videoTimeOffset = (float) $mapping['videoTime'];
                    break;
                }
            }

            $slicedMappings = [];
            foreach ($allMappings as $mapping) {
                if ($mapping['measureIndex'] < $start || $mapping['measureIndex'] > $end) continue;
                $slicedMappings[] = [
                    'measureIndex' => $mapping['measureIndex'] - $start,
                    'videoTime'    => (float) $mapping['videoTime'] - $videoTimeOffset,
                ];
            }

            $videoSync = $slicedMappings ? [
                'videoId'         => $rawVideoSync['videoId']   ?? '',
                'videoType'       => $rawVideoSync['videoType'] ?? 'youtube',
                'mappings'        => $slicedMappings,
                'videoTimeOffset' => $videoTimeOffset,
            ] : null;
        }

        return response()->json([
            'sections'      => $sections,
            'melody'        => $melody ?: null,
            'timeSignature' => $data['timeSignature'] ?? $leadsheet->time_signature ?? '4/4',
            'repeatMarkers' => $repeatMarkers,
            'voltaEndings'  => $voltaEndings,
            'chordVoicings' => $data['chordVoicings'] ?? (object) [],
            'videoSync'     => $videoSync,
            'meta'          => [
                'slug'        => $leadsheet->slug,
                'title'       => $leadsheet->title . $titleSuffix,
                'key_center'  => $leadsheet->song_key,
                'time_sig'    => $leadsheet->time_signature ?? '4/4',
                'bpm_default' => $leadsheet->tempo ?? 120,
                'type'        => 'leadsheet-excerpt',
            ],
        ]);
    }

    public function apiSearch(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $q = trim((string) $request->get('q', ''));

        // This route lives in the public api/sbn group. Only instructors (the
        // lesson-palette callers) may see drafts; the public gets published
        // songs only, matching the index/show gating.
        $query = Leadsheet::query();
        if (!$request->user()?->is_instructor) {
            $query->published();
        }
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

    public function apiViewerData(\Illuminate\Http\Request $request, Leadsheet $leadsheet, ChordVoicingSearch $search, EduContentService $edu): \Illuminate\Http\JsonResponse
    {
        $this->abortIfDraft($leadsheet);
        $this->abortIfNotPro($leadsheet);

        $version  = $this->resolveVersion($leadsheet, $request->query('v'));
        $enriched = $this->viewerService->enrich($leadsheet, $search, $version);

        return response()->json([
            'leadsheet' => [
                'id'            => $leadsheet->id,
                'slug'          => $leadsheet->slug,
                'title'         => $leadsheet->title,
                'composer'      => $leadsheet->composer,
                'songKey'       => $version->song_key ?: $leadsheet->song_key,
                'tempo'         => $version->tempo ?: $leadsheet->tempo,
                'timeSignature' => $leadsheet->time_signature,
                'rhythm'        => $version->rhythm ?: $leadsheet->rhythm,
                'jsonData'      => $version->parsed_data,
                'harmonyNotes'  => $leadsheet->harmony_notes,
                'formNotes'     => $leadsheet->form_notes,
                'voicingNotes'  => $leadsheet->voicing_notes,
                'hasMelodyTab'  => $version->has_melody_tab,
                'hasChordTab'   => $version->has_chord_tab,
            ],
            'versions'      => $this->versionList($leadsheet, $version),
            'activeVersion' => $version->version_slug,
            ...$enriched,
            'eduChordQualities'   => $edu->allChordQualities(),
            'eduRelatedConcepts'  => $this->buildEduRelatedConcepts($edu),
        ]);
    }

    public function cinema(\Illuminate\Http\Request $request, Leadsheet $leadsheet, ChordVoicingSearch $search)
    {
        $this->abortIfDraft($leadsheet);
        $this->abortIfNotPro($leadsheet);

        $version  = $this->resolveVersion($leadsheet, $request->query('v'));
        $enriched = $this->viewerService->enrich($leadsheet, $search, $version);

        return Inertia::render('Leadsheet/Cinema', [
            'leadsheet' => [
                'id'            => $leadsheet->id,
                'slug'          => $leadsheet->slug,
                'title'         => $leadsheet->title,
                'composer'      => $leadsheet->composer,
                'songKey'       => $version->song_key ?: $leadsheet->song_key,
                'tempo'         => $version->tempo ?: $leadsheet->tempo,
                'timeSignature' => $leadsheet->time_signature,
                'jsonData'      => $version->parsed_data,
            ],
            'chordCards' => $enriched['chordCards'],
            'classicUrl' => route('library.songs.viewer', ['leadsheet' => $leadsheet->slug]),
        ]);
    }
}

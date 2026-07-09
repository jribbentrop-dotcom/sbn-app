<?php

namespace App\Http\Controllers;

use App\Models\ChordDiagram;
use App\Models\ChordProgression;
use App\Models\Course;
use App\Models\Leadsheet;
use App\Models\RhythmPattern;
use App\Services\ChordSerializer;
use App\Services\ChordVoicingSearch;
use App\Services\LeadsheetViewerService;
use Inertia\Inertia;
use Inertia\Response;

// Hero song config (matches top10 bossa-nova-songs.php entry for Ipanema)
const HERO_SLUG  = 'the-girl-from-ipanema-1';
const HERO_START = 5;
const HERO_END   = 12;

class HomeController extends Controller
{
    public function __construct(
        private LeadsheetViewerService $viewerService,
        private ChordVoicingSearch $search,
        private ChordSerializer $chordSerializer,
    ) {}

    public function index(): Response
    {
        // The library-box rhythm preview shows a specific named pattern rather
        // than "highest bpm in category" — the Gilberto rhythm is the site's
        // signature groove (João Gilberto's bossa nova pattern).
        $pattern = RhythmPattern::where('slug', 'gilberto-rhythm')->first();

        $rhythmPattern = $pattern ? [
            'name'          => $pattern->name,
            'beats'         => $pattern->beats,
            'gridType'      => $pattern->grid_type,
            'bpm'           => $pattern->default_bpm,
            'thumb'         => $pattern->thumb_pattern,
            'fingers'       => $pattern->rhythm_pattern,
            'timeSignature' => $pattern->time_signature,
            'percTop'       => $pattern->perc_top,
            'percBass'      => $pattern->perc_bass,
            'pickingMode'   => (bool) $pattern->picking_mode,
            'fingerIndex'   => $pattern->finger_index,
            'fingerMiddle'  => $pattern->finger_middle,
            'fingerRing'    => $pattern->finger_ring,
        ] : null;

        [$heroBars, $heroRhythm] = $this->buildHeroBars();

        // The hero's rhythm caption/citation/link come from the Top10 Bossa
        // Nova Songs #1 entry (same song/bars/rhythm the hero already uses)
        // — SyncedPlayer gained these props (rhythm caption, citation,
        // rhythm-name link) after this hero was first built; wire them
        // through from the single source of truth rather than duplicating
        // the copy here.
        // The Top10 config's array key ("the-girl-from-ipanema") is a plain
        // identifier, distinct from the DB leadsheet slug in HERO_SLUG
        // ("the-girl-from-ipanema-1") — that entry's own 'syncedPlayer.slug'
        // field is what actually points at the DB row.
        $songConfigPath = config_path('top10/bossa-nova-songs.php');
        $songConfig      = file_exists($songConfigPath) ? (require $songConfigPath)['the-girl-from-ipanema'] ?? [] : [];

        return Inertia::render('Home', [
            'rhythmPattern'      => $rhythmPattern,
            'heroBars'           => $heroBars,
            'heroRhythm'         => $heroRhythm,
            'heroRhythmSlug'     => $songConfig['rhythmSlug'] ?? null,
            'heroRhythmCaption'  => $songConfig['rhythmCaption'] ?? null,
            'heroCitation'       => $songConfig['rhythmCitation'] ?? null,
            'libraryBoxes'       => $this->buildLibraryBoxes($rhythmPattern),
        ]);
    }

    /**
     * Preview content for the homepage "library showcase" boxes — one
     * representative item per top-level library. Rhythm/Progressions mount
     * the real RhythmPattern / ChordProgressionViewer components
     * (non-interactive, no diagram data for Progressions).
     */
    private function buildLibraryBoxes(?array $rhythmPattern): array
    {
        // Five real voicings for the chord box — the flagship Top10 #1
        // (Db6/9/Ab, the opening Ipanema chord) as the center hero, plus two
        // layers of neighbors rendered progressively smaller/more faded
        // either side (SyncedHero-style "one sharp, receding neighbors"
        // composition). Rendered as SVG diagrams — the Top10 product photos
        // looked too similar side by side.
        $chordSlugs = [
            'm7b5-drop2-roota',                 // outer-left
            'maj6-shell-roota-9',                // inner-left
            'maj6-custom-roote-inv2-9-overAb',   // center hero — Top10 #1
            'dom7-drop3-roote-13',                // inner-right
            'o7-drop2-roota',                    // outer-right
        ];
        $chordRows = ChordDiagram::whereIn('slug', $chordSlugs)
            ->get(['slug', 'root_note', 'quality', 'extensions', 'diagram_data', 'interval_labels', 'start_fret'])
            ->keyBy('slug');

        $chords = [];
        foreach ($chordSlugs as $slug) {
            $row = $chordRows->get($slug);
            if (!$row) {
                continue;
            }
            $data  = json_decode($row->diagram_data ?? '{}', true) ?: [];
            $frets = $this->diagramDataToFretString($data);
            if ($frets === 'xxxxxx') {
                continue;
            }
            $chords[] = [
                'name'           => $slug === 'maj6-custom-roote-inv2-9-overAb'
                    ? 'Db6/9/Ab'
                    : $this->chordDisplayName($row->root_note, $row->quality, $row->extensions),
                'frets'          => $frets,
                'intervalLabels' => $row->interval_labels,
                'position'       => $row->start_fret ?? 1,
            ];
        }

        // A random sample of covers for the filmstrip — reshuffled on every
        // page load so the box reflects the size of the catalog rather than
        // always showing the same handful of "most popular" songs.
        $songs = Leadsheet::published()
            ->whereNotNull('cover_image_path')
            ->where('cover_image_path', '!=', '')
            ->inRandomOrder()
            ->limit(8)
            ->get(['id', 'title', 'cover_image_path']);

        // Same filmstrip treatment for courses — featured_image_path already
        // stores a full "/images/..." path (no accessor needed, unlike
        // leadsheet covers).
        $courses = Course::published()
            ->whereNotNull('featured_image_path')
            ->where('featured_image_path', '!=', '')
            ->inRandomOrder()
            ->limit(8)
            ->get(['id', 'title', 'featured_image_path']);

        // The Authentic Cadence (V7 → I) — real voicings looked up directly
        // (G7 shell transposed via ChordSerializer, Cmaj7 shell already
        // rooted at C) rather than the full ProgressionBuilder pipeline, so
        // the box gets real fretboard dots without pulling in the
        // harmonic-context/Viterbi voice-leading machinery for a
        // non-interactive preview.
        $progressionRow = ChordProgression::where('slug', 'perfect-authentic-cadence-2')
            ->first(['name']);

        $v7Chord   = ChordDiagram::where('slug', 'dom7-shell-roote')->first();
        $iChord    = ChordDiagram::where('slug', 'maj7-shell-roota')->first();

        $progression = null;
        if ($progressionRow && $v7Chord && $iChord) {
            $v7Data = $this->chordSerializer->serialize($v7Chord, 'G');
            $iData  = $this->chordSerializer->serialize($iChord);
            $progression = [
                'name'   => $progressionRow->name,
                'chords' => [
                    ['chordName' => $v7Data['name'], 'diagramData' => $v7Data, 'numeral' => 'V7'],
                    ['chordName' => $iData['name'],  'diagramData' => $iData,  'numeral' => 'I'],
                ],
            ];
        }

        return [
            'chordCount' => ChordDiagram::count(),
            'chords'     => $chords,
            'songCount'  => Leadsheet::published()->count(),
            'songs'      => $songs->map(fn ($s) => [
                'title' => $s->title,
                'cover' => $s->cover_image_url,
            ])->all(),
            'rhythmCount'      => RhythmPattern::count(),
            'rhythm'           => $rhythmPattern,
            'progressionCount' => ChordProgression::count(),
            'progression'      => $progression,
            'courseCount'      => Course::published()->count(),
            'courses'          => $courses->map(fn ($c) => [
                'title' => $c->title,
                'cover' => $c->featured_image_path,
            ])->all(),
        ];
    }

    /**
     * Build the hero bars array using the same logic as SyncedPlayerController::apiShow().
     * Slices Ipanema bars HERO_START..HERO_END so the tempo and rhythm exactly match top10.
     *
     * @return array{0: array|null, 1: array|null}  [bars, rhythmPattern]
     */
    private function buildHeroBars(): array
    {
        $leadsheet = Leadsheet::where('slug', HERO_SLUG)->first();
        if (! $leadsheet) {
            return [null, null];
        }

        // Read the default arrangement (versions split — falls back to the leadsheet's
        // legacy columns if no version row exists during the dual-read window).
        $version    = $leadsheet->defaultVersion ?? $leadsheet->versions()->first();
        $jsonData   = ($version?->parsed_data ?? $leadsheet->parsed_data) ?? [];
        $rhythmSlug = ($version?->rhythm ?: $leadsheet->rhythm) ?: null;

        // ── Rhythm pattern ────────────────────────────────────────────────────
        $rhythmPattern = null;
        if ($rhythmSlug) {
            $pattern = RhythmPattern::where('slug', $rhythmSlug)->first();
            if ($pattern) {
                $rhythmPattern = [
                    'slug'          => $pattern->slug,
                    'name'          => $pattern->name,
                    'timeSignature' => $pattern->time_signature,
                    'beats'         => $pattern->beats,
                    'gridType'      => $pattern->grid_type,
                    'bpm'           => $pattern->default_bpm,
                    'thumb'         => $pattern->thumb_pattern,
                    'fingers'       => $pattern->rhythm_pattern,
                    'percTop'       => $pattern->perc_top,
                    'percBass'      => $pattern->perc_bass,
                    'pickingMode'   => (bool) $pattern->picking_mode,
                    'fingerIndex'   => $pattern->finger_index,
                    'fingerMiddle'  => $pattern->finger_middle,
                    'fingerRing'    => $pattern->finger_ring,
                ];
            }
        }

        // ── Compute stepsPerBar (mirrors SyncedPlayerController) ─────────────
        $stepsPerBar = 16;
        if ($rhythmPattern) {
            $beats       = (int) ($rhythmPattern['beats'] ?? 16);
            $gridType    = $rhythmPattern['gridType'] ?? 'sixteenth';
            $stepBeats   = match ($gridType) {
                'eighth'  => 0.5,
                'triplet' => 1 / 3,
                default   => 0.25,
            };
            $patternBeats = $beats * $stepBeats;
            $timeSig      = $rhythmPattern['timeSignature'] ?? '4/4';
            $beatsPerBar  = max(1, (int) explode('/', $timeSig)[0]);
            $barsPerCycle = max(1, (int) round($patternBeats / $beatsPerBar));
            $stepsPerBar  = (int) round($beats / $barsPerCycle);
        }

        // ── Flatten sections → bar list ───────────────────────────────────────
        $sections = $jsonData['sections'] ?? [];
        $voicings = $jsonData['chordVoicings'] ?? [];
        $allBars  = [];
        $gi       = 0;

        foreach ($sections as $section) {
            foreach ($section['measures'] ?? [] as $measure) {
                if (!empty($measure['chordNames'])) {
                    $names = array_values($measure['chordNames']);
                } else {
                    $names = array_values(array_map(
                        fn ($c) => is_array($c) ? ($c['name'] ?? $c['symbol'] ?? '') : (string) $c,
                        $measure['chords'] ?? []
                    ));
                }
                $names = array_filter($names);

                if (empty($names)) {
                    $gi++;
                    continue;
                }

                $count         = count($names);
                $stepsPerChord = (int) round($stepsPerBar / $count);

                foreach ($names as $ci => $chordName) {
                    $card = $this->resolveHeroCard($chordName, (string) $gi, $ci, $voicings);

                    $allBars[] = [
                        'chordName'     => $chordName,
                        'chordCard'     => $card,
                        'durationBars'  => 1,
                        'stepsPerChord' => $stepsPerChord,
                        'gi'            => $gi,
                    ];
                }

                $gi++;
            }
        }

        // ── Slice HERO_START..HERO_END ────────────────────────────────────────
        $total  = count($allBars);
        $endIdx = min(HERO_END + 1, $total);
        $bars   = array_values(array_slice($allBars, HERO_START, $endIdx - HERO_START));

        return [$bars ?: null, $rhythmPattern];
    }

    /**
     * Resolve a chord name to a ChordDiagramData card — mirrors
     * SyncedPlayerController::resolveCard() exactly (curated leadsheet
     * voicing first, DB search only as fallback) so the homepage hero shows
     * the same shapes as the real Top10/leadsheet synced players. An earlier
     * version of this method inverted that priority (DB search first),
     * which is why the hero's chords/rhythm drifted from the Top10 page.
     */
    private function resolveHeroCard(string $chordName, string $gi, int $ci, array $voicings): ?array
    {
        $slotKey = "{$chordName}@{$gi}.{$ci}";
        $voicing = $voicings[$slotKey] ?? $voicings[$chordName] ?? null;

        if ($voicing) {
            $card = $this->viewerService->synthesizeMinimalCard($chordName, $voicing, $this->search);
            $dd   = $card['diagram_data'] ?? [];

            $fingers = $voicing['fingers'] ?? '000000';
            if (preg_match('/^0+$/', $fingers)) {
                $matches = $this->search->searchByName($chordName);
                $best = $this->viewerService->pickBestVoicing($matches, $voicing['frets'] ?? null);
                if ($best) {
                    $card['diagram_data']['positions'] = $best['diagram_data']['positions'] ?? $dd['positions'];
                    $dd = $card['diagram_data'];
                }
            }

            $card['interval_labels'] = $this->search->computeIntervalLabels(
                $dd['positions'] ?? [],
                $dd['open']     ?? [],
                $dd['muted']    ?? [],
                $card['root_note'],
                $card['quality'],
            );
            return $card;
        }

        $matches = $this->search->searchByName($chordName);
        return $matches[0] ?? null;
    }

    /**
     * Convert diagram_data positions/open/muted to a 6-char fret string
     * compatible with sbnRenderDiagramSVG (e.g. "x32010").
     */
    private function diagramDataToFretString(array $data): string
    {
        $frets = ['x', 'x', 'x', 'x', 'x', 'x'];

        foreach ($data['open'] ?? [] as $s) {
            if ($s >= 1 && $s <= 6) {
                $frets[$s - 1] = '0';
            }
        }
        foreach ($data['positions'] ?? [] as $pos) {
            $s = $pos['string'] ?? 0;
            $f = $pos['fret']   ?? 0;
            if ($s >= 1 && $s <= 6) {
                $frets[$s - 1] = dechex($f);
            }
        }
        foreach ($data['muted'] ?? [] as $s) {
            if ($s >= 1 && $s <= 6) {
                $frets[$s - 1] = 'x';
            }
        }

        return implode('', $frets);
    }

    /**
     * Build the chord symbol string that sbnFormatChordHtml() will parse:
     * root + quality symbol + extensions (e.g. "Cm7", "G7", "Fmaj7", "Bb7b9").
     */
    private function chordDisplayName(string $root, string $quality, ?string $extensions): string
    {
        $qualitySymbols = [
            'maj'   => '',
            'min'   => 'm',
            'aug'   => 'aug',
            'dim'   => 'dim',
            '5'     => '5',
            'sus4'  => 'sus4',
            'sus2'  => 'sus2',
            'add9'  => 'add9',
            'madd9' => 'madd9',
            'maj7'  => 'maj7',
            'm7'    => 'm7',
            'dom7'  => '7',
            'm7b5'  => 'm7b5',
            'o7'    => '°7',
            'maj6'  => 'maj6',
            'm6'    => 'm6',
            'mMaj7' => 'mMaj7',
            'aug7'  => 'aug7',
            '7sus4'   => '7sus4',
            'quartal' => 'quartal',
        ];

        $sym = $qualitySymbols[$quality] ?? $quality;
        $ext = $extensions ? trim($extensions) : '';

        return $root . $sym . ($ext ? $ext : '');
    }
}

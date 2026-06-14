<?php

namespace App\Http\Controllers;

use App\Models\ChordDiagram;
use App\Models\Leadsheet;
use App\Models\RhythmPattern;
use App\Services\ChordVoicingSearch;
use App\Services\LeadsheetViewerService;
use Inertia\Inertia;
use Inertia\Response;

// Hero song config (matches top10 bossa-nova-songs.php entry for Ipanema)
const HERO_SLUG  = 'the-girl-from-ipanema';
const HERO_START = 5;
const HERO_END   = 12;

class HomeController extends Controller
{
    public function __construct(
        private LeadsheetViewerService $viewerService,
        private ChordVoicingSearch $search,
    ) {}

    public function index(): Response
    {
        $pattern = RhythmPattern::where('category', 'bossa-nova')
            ->orderByDesc('default_bpm')
            ->first();

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

        return Inertia::render('Home', [
            'rhythmPattern' => $rhythmPattern,
            'heroBars'      => $heroBars,
            'heroRhythm'    => $heroRhythm,
            'rainChords'    => $this->buildRainChords(),
        ]);
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

        $jsonData   = $leadsheet->parsed_data ?? [];
        $rhythmSlug = $leadsheet->rhythm ?? null;

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
                    $slotKey = "{$chordName}@{$gi}.{$ci}";
                    $voicing = $voicings[$slotKey] ?? $voicings[$chordName] ?? null;
                    $matches = $this->search->searchByName($chordName);

                    $card = null;
                    if ($voicing && !empty($matches)) {
                        $card = $this->viewerService->pickBestVoicing($matches, $voicing['frets'] ?? null);
                    }
                    if (!$card && !empty($matches)) {
                        $card = $matches[0];
                    }
                    if (!$card && $voicing) {
                        $card = $this->viewerService->synthesizeMinimalCard($chordName, $voicing, $this->search);
                    }

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
     * Build a curated set of chord shapes for the ChordRain section.
     * Returns up to 25 diverse voicings with real fret/interval data.
     */
    private function buildRainChords(): array
    {
        $chords = ChordDiagram::whereNotNull('diagram_data')
            ->whereNotNull('interval_labels')
            ->where('interval_labels', '!=', '')
            ->whereNotNull('name')
            ->orderByDesc('popularity')
            ->limit(60)
            ->get(['id', 'name', 'root_note', 'quality', 'extensions', 'start_fret', 'diagram_data', 'interval_labels', 'voicing_category']);

        $result = [];
        foreach ($chords as $chord) {
            $data = json_decode($chord->diagram_data ?? '{}', true);
            if (empty($data)) {
                continue;
            }

            $frets = $this->diagramDataToFretString($data);
            if ($frets === 'xxxxxx') {
                continue;
            }

            $result[] = [
                'name'           => $this->chordDisplayName($chord->root_note, $chord->quality, $chord->extensions ?? null),
                'frets'          => $frets,
                'position'       => $chord->start_fret ?? 1,
                'intervalLabels' => $chord->interval_labels,
            ];

            if (count($result) >= 25) {
                break;
            }
        }

        return $result;
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
            '7sus4' => '7sus4',
        ];

        $sym = $qualitySymbols[$quality] ?? $quality;
        $ext = $extensions ? trim($extensions) : '';

        return $root . $sym . ($ext ? $ext : '');
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
}

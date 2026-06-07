<?php

namespace App\Http\Controllers;

use App\Models\ChordDiagram;
use App\Models\Leadsheet;
use App\Models\RhythmPattern;
use App\Services\ChordVoicingSearch;
use App\Services\LeadsheetViewerService;
use Inertia\Inertia;
use Inertia\Response;

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
        ] : null;

        $progression = $this->buildHeroProgression();

        return Inertia::render('Home', [
            'rhythmPattern' => $rhythmPattern,
            'progression'   => $progression,
            'barsPerChord'  => 2,
            'rainChords'    => $this->buildRainChords(),
        ]);
    }

    /**
     * Build an ordered chord sequence for the SyncedHero from Desafinado.
     * Walks the flat measures list, deduplicates consecutive repeats, looks up
     * each chord card via LeadsheetViewerService, and returns the first 8
     * distinct chords so the hero loops a representative passage.
     */
    private function buildHeroProgression(): ?array
    {
        $leadsheet = Leadsheet::find(113);
        if (! $leadsheet) {
            return null;
        }

        $enriched   = $this->viewerService->enrich($leadsheet, $this->search);
        $chordCards = $enriched['chordCards'];
        $data       = $leadsheet->parsed_data;
        $measures   = $data['measures'] ?? [];

        // Walk measures, collect unique consecutive chord names
        $sequence = [];
        $prev     = null;
        foreach ($measures as $measure) {
            foreach ($measure['chords'] ?? [] as $chord) {
                $name = trim($chord['name'] ?? '');
                if ($name === '' || $name === $prev) {
                    continue;
                }
                $sequence[] = $name;
                $prev       = $name;
                if (count($sequence) >= 8) {
                    break 2;
                }
            }
        }

        // Map each chord name to its ChordDiagramData card.
        // chordCards keys are either "ChordName" or "ChordName@position".
        $result = [];
        foreach ($sequence as $name) {
            if (isset($chordCards[$name])) {
                $result[] = $chordCards[$name];
                continue;
            }
            // Fall back to first @position key for this chord name
            foreach ($chordCards as $key => $card) {
                if (str_starts_with($key, $name . '@') || $key === $name) {
                    $result[] = $card;
                    break;
                }
            }
        }

        return $result ?: null;
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

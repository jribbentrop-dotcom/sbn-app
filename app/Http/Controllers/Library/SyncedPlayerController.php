<?php

namespace App\Http\Controllers\Library;

use App\Http\Controllers\Controller;
use App\Models\Exercise;
use App\Models\Leadsheet;
use App\Models\RhythmPattern;
use App\Services\ChordVoicingSearch;
use App\Services\LeadsheetViewerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API endpoint for <sbn-synced-player> course tags and future leadsheet embeds.
 *
 * GET /api/sbn/synced-player/{slug}?type=leadsheet|exercise&start=0&end=7
 *
 * Returns:
 *   bars[]          — flat ordered bar list { chordName, chordCard, durationBars }
 *   rhythmPattern   — RhythmPatternData shape (null if rhythm slug not found)
 *   bpm             — integer
 *   title           — string
 *   totalBars       — total bar count before slicing
 */
class SyncedPlayerController extends Controller
{
    public function __construct(
        private readonly ChordVoicingSearch $search,
        private readonly LeadsheetViewerService $viewer,
    ) {}

    public function apiShow(Request $request, string $slug): JsonResponse
    {
        $type  = $request->query('type', 'leadsheet');
        $start = max(0, (int) $request->query('start', 0));
        $end   = $request->query('end');   // null = no limit

        if ($type === 'exercise') {
            $source = Exercise::where('slug', $slug)->firstOrFail();
            $jsonData  = is_array($source->content_json) ? $source->content_json : [];
            $rhythmSlug = $source->rhythm ?? null;
            $bpm        = $source->bpm_default ?? 120;
            $title      = $source->title;
        } else {
            $source = Leadsheet::where('slug', $slug)->firstOrFail();
            $jsonData  = $source->parsed_data ?? [];
            $rhythmSlug = $source->rhythm ?? null;
            $bpm        = $source->tempo ?? 120;
            $title      = $source->title;
        }

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
                // Use rhythm's native BPM unless source overrides
                if ($bpm <= 0) {
                    $bpm = $pattern->default_bpm ?? 120;
                }
            }
        }

        // ── Flatten sections → bar list ───────────────────────────────────────
        $sections  = $jsonData['sections'] ?? [];
        $voicings  = $jsonData['chordVoicings'] ?? [];
        $allBars   = [];
        $gi        = 0;

        // Steps per notated bar — depends on grid type AND time signature.
        //   16 sixteenth steps in 4/4 → 4 beats → 1 bar  → stepsPerBar = 16
        //   16 sixteenth steps in 2/4 → 4 beats → 2 bars → stepsPerBar = 8
        //   16 eighth steps    in 4/4 → 8 beats → 2 bars → stepsPerBar = 8
        $gridSteps   = 16;
        $stepsPerBar = 16; // steps that correspond to one 4/4 measure
        if ($rhythmPattern) {
            $beats    = (int) ($rhythmPattern['beats'] ?? 16);
            $gridType = $rhythmPattern['gridType'] ?? 'sixteenth';
            $gridSteps = $beats;
            $stepBeats = match ($gridType) {
                'eighth'  => 0.5,
                'triplet' => 1 / 3,
                default   => 0.25,   // sixteenth
            };
            // How many quarter-note beats does the whole pattern span?
            $patternBeats = $beats * $stepBeats;
            // Beats per bar from the time signature (e.g. "2/4" → 2, "4/4" → 4).
            $timeSig = $rhythmPattern['timeSignature'] ?? '4/4';
            $beatsPerBar = (int) explode('/', $timeSig)[0];
            if ($beatsPerBar < 1) $beatsPerBar = 4;
            // How many bars does one pattern cycle span?
            // e.g. 16 sixteenth steps in 4/4 → 4 beats → 1 bar
            //      16 sixteenth steps in 2/4 → 4 beats → 2 bars
            //      16 eighth steps    in 4/4 → 8 beats → 2 bars
            // Sub-bar patterns clamp to 1 via round(0.5) = 1.
            $barsPerCycle = max(1, (int) round($patternBeats / $beatsPerBar));
            // Steps corresponding to exactly one notated bar.
            $stepsPerBar  = (int) round($gridSteps / $barsPerCycle);
        }

        foreach ($sections as $section) {
            foreach ($section['measures'] ?? [] as $measure) {
                // chordNames (preferred) or legacy chords[].name
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

                // Each chord in the measure gets an equal share of one bar's
                // steps. A 1-chord measure → stepsPerChord = stepsPerBar.
                // A 2-chord measure → stepsPerChord = stepsPerBar / 2 (beat 3
                // change). The frontend advances when stepsPlayed reaches this
                // value, independent of rhythm-cycle boundaries.
                $count         = count($names);
                $stepsPerChord = (int) round($stepsPerBar / $count);

                foreach ($names as $ci => $chordName) {
                    $card = $this->resolveCard($chordName, (string) $gi, (int) $ci, $voicings);
                    $allBars[] = [
                        'chordName'     => $chordName,
                        'chordCard'     => $card,
                        'durationBars'  => 1,          // kept for back-compat
                        'stepsPerChord' => $stepsPerChord,
                        'gi'            => $gi,
                    ];
                }

                $gi++;
            }
        }

        $totalBars = count($allBars);

        // ── Slice ─────────────────────────────────────────────────────────────
        $endIdx = $end !== null ? min((int) $end + 1, $totalBars) : $totalBars;
        $bars   = array_values(array_slice($allBars, $start, $endIdx - $start));

        return response()->json([
            'title'         => $title,
            'totalBars'     => $totalBars,
            'bars'          => $bars,
            'rhythmPattern' => $rhythmPattern,
            'bpm'           => (int) $bpm,
        ]);
    }

    // ── Resolve a chord name to a ChordDiagramData card ──────────────────────

    private function resolveCard(string $chordName, string $gi, int $ci, array $voicings): ?array
    {
        // Try per-slot key first, then bare name
        $slotKey  = "{$chordName}@{$gi}.{$ci}";
        $voicing  = $voicings[$slotKey] ?? $voicings[$chordName] ?? null;

        $matches = $this->search->searchByName($chordName);

        if ($voicing && !empty($matches)) {
            $best = $this->viewer->pickBestVoicing($matches, $voicing['frets'] ?? null);
            if ($best) return $best;
        }

        // Fall back to first DB match
        if (!empty($matches)) {
            return $matches[0];
        }

        // Synthesise a minimal card from the voicing data
        if ($voicing) {
            return $this->viewer->synthesizeMinimalCard($chordName, $voicing, $this->search);
        }

        return null;
    }
}

<?php

namespace App\Http\Controllers;

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
}

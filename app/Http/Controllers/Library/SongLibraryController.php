<?php

namespace App\Http\Controllers\Library;

use App\Http\Controllers\Controller;
use App\Models\ChordProgression;
use App\Models\Leadsheet;
use App\Services\ChordVoicingSearch;
use App\Services\EduContentService;
use Illuminate\Support\Str;
use Inertia\Inertia;

class SongLibraryController extends Controller
{
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
            'styleSlug'     => $this->rhythmToStyleSlug($song->rhythm),
            'description'   => $song->description ? Str::limit(strip_tags($song->description), 120) : null,
            'popularity'    => $song->popularity,
            'measureCount'  => $song->measure_count,
        ];
    }

    public function index()
    {
        $songs = Leadsheet::orderBy('title')->get();

        $serialized = $songs->map(fn ($s) => $this->serializeSong($s));

        $composers = Leadsheet::whereNotNull('composer')
            ->where('composer', '!=', '')
            ->selectRaw('composer, COUNT(*) as cnt')
            ->groupBy('composer')
            ->orderByDesc('cnt')
            ->limit(40)
            ->pluck('composer')
            ->toArray();

        $keys = Leadsheet::whereNotNull('song_key')
            ->where('song_key', '!=', '')
            ->distinct()
            ->orderBy('song_key')
            ->pluck('song_key')
            ->toArray();

        $rhythms = Leadsheet::whereNotNull('rhythm')
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

    public function show(Leadsheet $leadsheet)
    {
        // Chord names from the parsed leadsheet JSON
        $chordNames = $leadsheet->getChordNames();

        // Progressions detected in this song
        $progressions = ChordProgression::query()
            ->join('sbn_progression_occurrences as o', 'sbn_chord_progressions.id', '=', 'o.progression_id')
            ->where('o.leadsheet_id', $leadsheet->id)
            ->select('sbn_chord_progressions.id', 'sbn_chord_progressions.slug', 'sbn_chord_progressions.name', 'sbn_chord_progressions.category', 'sbn_chord_progressions.numerals')
            ->distinct()
            ->orderBy('sbn_chord_progressions.name')
            ->get()
            ->map(fn ($p) => [
                'id'             => $p->id,
                'slug'           => $p->slug,
                'name'           => $p->name,
                'category'       => $p->category,
                'numeralsDisplay'=> $p->numerals_display,
            ]);

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
                'styleSlug'     => $this->rhythmToStyleSlug($leadsheet->rhythm),
                'measureCount'  => $leadsheet->measure_count,
                'popularity'    => $leadsheet->popularity,
            ],
            'chordNames'   => $chordNames,
            'progressions' => $progressions,
        ]);
    }

    /**
     * Convert a fret string (e.g., "x32000") to diagram_data positions.
     * Leadsheet uses hex digits a-f for frets 10-15.
     *
     * String numbering: 1 = low E (leftmost in leadsheet format)
     */
    private function fretStringToDiagramData(string $frets): array
    {
        $positions = [];
        $open = [];
        $muted = [];

        // Leadsheet: index 0 = low E (string 1), index 5 = high E (string 6)
        for ($i = 0; $i < 6; $i++) {
            $char = $frets[$i] ?? 'x';
            $stringNum = $i + 1; // 1-indexed

            if ($char === 'x') {
                $muted[] = $stringNum;
            } elseif ($char === '0') {
                $open[] = $stringNum;
            } else {
                // Hex digit: 0-9 = 0-9, a-f = 10-15
                $fret = intval($char, 16);
                $positions[] = ['string' => $stringNum, 'fret' => $fret];
            }
        }

        return [
            'positions' => $positions,
            'barres' => [],
            'muted' => $muted,
            'open' => $open,
        ];
    }

    /**
     * Compare two diagram_data structures for fret-string equivalence.
     * Returns true if they represent the same played notes.
     */
    private function diagramDataMatches(array $a, array $b): bool
    {
        // Build string->fret maps (-1 = muted/not-played, 0 = open, >0 = fretted)
        $mapA = [];
        $mapB = [];

        foreach ($a['positions'] ?? [] as $pos) {
            $mapA[$pos['string']] = $pos['fret'];
        }
        foreach ($a['open'] ?? [] as $s) {
            $mapA[$s] = 0;
        }
        foreach ($a['muted'] ?? [] as $s) {
            $mapA[$s] = -1; // Explicitly muted
        }

        foreach ($b['positions'] ?? [] as $pos) {
            $mapB[$pos['string']] = $pos['fret'];
        }
        foreach ($b['open'] ?? [] as $s) {
            $mapB[$s] = 0;
        }
        foreach ($b['muted'] ?? [] as $s) {
            $mapB[$s] = -1; // Explicitly muted
        }

        // Ensure all 6 strings are represented (default to muted if not specified)
        for ($s = 1; $s <= 6; $s++) {
            if (!isset($mapA[$s])) $mapA[$s] = -1;
            if (!isset($mapB[$s])) $mapB[$s] = -1;
        }

        // Each string must have same fret
        for ($s = 1; $s <= 6; $s++) {
            if ($mapA[$s] !== $mapB[$s]) {
                return false;
            }
        }

        return true;
    }

    /**
     * Pick the best DB voicing match for a leadsheet voicing.
     * Returns exact fret-string match only; null if none found.
     */
    private function pickBestVoicing(array $matches, ?string $targetFrets): ?array
    {
        if (empty($matches) || !$targetFrets) {
            return null;
        }

        $targetDiagram = $this->fretStringToDiagramData($targetFrets);

        foreach ($matches as $match) {
            if ($this->diagramDataMatches($match['diagram_data'] ?? [], $targetDiagram)) {
                return $match;
            }
        }

        // No exact match - caller will synthesize from leadsheet data
        return null;
    }

    /**
     * Synthesize a minimal ChordDiagramData from leadsheet voicing.
     * Used when no DB match exists.
     */
    private function synthesizeMinimalCard(string $chordName, array $voicing, ChordVoicingSearch $search): array
    {
        $frets = $voicing['frets'] ?? 'xxxxxx';
        $fingers = $voicing['fingers'] ?? null;
        $position = $voicing['position'] ?? 0;

        // Parse chord name for metadata
        $parsed = $search->parseChordName($chordName);
        $quality = $parsed['quality'] ?? 'maj';

        // Map quality to label
        $qualityLabels = [
            'maj' => 'Major', 'min' => 'Minor', 'dom7' => 'Dominant 7',
            'maj7' => 'Major 7', 'm7' => 'Minor 7', 'm7b5' => 'Half-diminished',
            'dim' => 'Diminished', 'o7' => 'Diminished 7', 'aug' => 'Augmented',
            'aug7' => 'Augmented 7', 'mMaj7' => 'Minor-Major 7', 'sus4' => 'Suspended 4',
            'sus2' => 'Suspended 2', 'maj6' => 'Major 6', 'm6' => 'Minor 6',
            'add9' => 'Add 9', '7sus4' => '7 sus 4', '5' => 'Power chord',
        ];

        $diagramData = $this->fretStringToDiagramData($frets);

        // Add finger data to positions if available
        if ($fingers && strlen($fingers) === 6) {
            foreach ($diagramData['positions'] as &$pos) {
                $fingerChar = $fingers[$pos['string'] - 1] ?? '0';
                if ($fingerChar !== 'x' && $fingerChar !== '0') {
                    $pos['finger'] = intval($fingerChar);
                }
            }
        }

        // Determine start fret from position or min fret
        $startFret = $position;
        if (!$startFret) {
            $fretValues = array_column($diagramData['positions'], 'fret');
            if (!empty($fretValues)) {
                $minFret = min($fretValues);
                $startFret = $minFret > 0 ? $minFret : 1;
            } else {
                $startFret = 1;
            }
        }

        return [
            'id' => 0, // Synthetic ID
            'slug' => '',
            'name' => $chordName,
            'root_note' => $parsed['root'] ?? '',
            'quality' => $quality,
            'quality_label' => $qualityLabels[$quality] ?? $quality,
            'extensions' => $parsed['extension'] ?? null,
            'voicing_category' => 'standard',
            'category_label' => 'Standard',
            'root_string' => '',
            'root_string_label' => '',
            'inversion' => 'root',
            'inversion_label' => 'Root position',
            'bass_note' => $parsed['bass_note'] ?? null,
            'shape_family' => null,
            'start_fret' => $startFret,
            'diagram_data' => $diagramData,
            'interval_labels' => null,
            'notes' => null,
            'popularity' => null,
            'difficulty' => null,
            'description' => null,
        ];
    }

    public function viewer(Leadsheet $leadsheet, ChordVoicingSearch $search, EduContentService $edu)
    {
        $progressions = ChordProgression::query()
            ->join('sbn_progression_occurrences as o', 'sbn_chord_progressions.id', '=', 'o.progression_id')
            ->where('o.leadsheet_id', $leadsheet->id)
            ->select(
                'sbn_chord_progressions.id',
                'sbn_chord_progressions.slug',
                'sbn_chord_progressions.name',
                'sbn_chord_progressions.category',
                'sbn_chord_progressions.numerals',
            )
            ->distinct()
            ->orderBy('sbn_chord_progressions.name')
            ->get()
            ->map(fn ($p) => [
                'id'              => $p->id,
                'slug'            => $p->slug,
                'name'            => $p->name,
                'category'        => $p->category,
                'numeralsDisplay' => $p->numerals_display,
                'sectionId'       => null, // R3 fallback — section attribution unavailable
            ]);

        // Build enriched chordCards map keyed by "chordName@gi.ci"
        $voicings = $leadsheet->parsed_data['chordVoicings'] ?? [];
        $chordCards = [];
        $qualityByKey = []; // For Step 6: map chord key to quality slug

        // Cache search results by chord name to avoid redundant DB hits
        $searchCache = [];

        foreach ($voicings as $key => $voicing) {
            // Key shape: either "ChordName@gi.ci" (per-slot override) or
            // "ChordName" (song-wide fallback used by the grid for any slot
            // without a per-slot override). Build cards for both — the
            // viewer falls back to the bare-name lookup when no per-slot
            // entry exists, mirroring tab-editor/components/ChordCard.vue.
            if (preg_match('/^(.+)@\d+\.\d+$/', $key, $m)) {
                $chordName = $m[1];
            } else {
                $chordName = $key;
            }

            // Parse chord name for quality (used in Step 6)
            $parsed = $search->parseChordName($chordName);
            $qualityByKey[$key] = $parsed['quality'] ?? 'maj';

            // Cache search results by chord name
            $cacheKey = $chordName;
            if (!isset($searchCache[$cacheKey])) {
                $searchCache[$cacheKey] = $search->searchByName($chordName);
            }
            $matches = $searchCache[$cacheKey];

            $best = $this->pickBestVoicing($matches, $voicing['frets'] ?? null);

            if ($best) {
                $chordCards[$key] = $best;
            } else {
                $chordCards[$key] = $this->synthesizeMinimalCard($chordName, $voicing, $search);
            }
        }

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
                'jsonData'      => $leadsheet->parsed_data,  // accessor-decoded array
                'harmonyNotes'  => $leadsheet->harmony_notes,
                'formNotes'     => $leadsheet->form_notes,
                'voicingNotes'  => $leadsheet->voicing_notes,
            ],
            'progressions' => $progressions,
            'chordCards'   => $chordCards,         // NEW: enriched chord card data
            'qualityByKey' => $qualityByKey,      // NEW: quality slugs for Step 6
            'eduChordQualities' => $edu->allChordQualities(), // NEW: edu blurbs
        ]);
    }
}

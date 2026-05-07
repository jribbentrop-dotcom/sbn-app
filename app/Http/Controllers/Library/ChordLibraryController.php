<?php

namespace App\Http\Controllers\Library;

use App\Http\Controllers\Controller;
use App\Models\ChordDiagram;
use App\Models\ChordProgression;
use App\Models\Leadsheet;
use App\Services\ChordSerializer;
use App\Services\ChordShapeCalculator;
use App\Services\ChordVoicingSearch;
use App\Services\HarmonicContext;
use App\Services\ProgressionBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ChordLibraryController extends Controller
{
    public function __construct(
        protected ChordVoicingSearch $voicingSearch,
        protected ProgressionBuilder $progressionBuilder,
        protected HarmonicContext $harmonicContext,
        protected ChordShapeCalculator $shapeCalculator,
        protected ChordSerializer $chordSerializer,
    ) {
    }

    public function index()
    {
        $familyLabels = [
            'archetype-e'  => 'E Shape',
            'archetype-em' => 'Em Shape',
            'archetype-a'  => 'A Shape',
            'archetype-am' => 'Am Shape',
            'archetype-d'  => 'D Shape',
            'archetype-dm' => 'Dm Shape',
            'archetype-c'  => 'C Shape',
            'archetype-g'  => 'G Shape',
        ];

        // Archetypes: grouped by shape family, ordered within each family by sort_order
        $archetypeRows = ChordDiagram::query()
            ->where('voicing_category', 'archetype')
            ->orderBy('shape_family')
            ->orderBy('sort_order')
            ->orderByRaw('popularity DESC NULLS LAST')
            ->get()
            ->map(fn ($c) => $this->chordSerializer->serialize($c));

        $archetypeFamilies = [];
        foreach ($archetypeRows as $chord) {
            if (!empty($chord['shape_family'])) {
                $archetypeFamilies[$chord['shape_family']][] = $chord;
            }
        }

        // All other chords: purely sorted by popularity then quality
        $otherChords = ChordDiagram::query()
            ->where('voicing_category', '!=', 'archetype')
            ->orderByRaw('popularity DESC NULLS LAST')
            ->orderBy('quality')
            ->get()
            ->map(fn ($c) => $this->chordSerializer->serialize($c))
            ->values()
            ->all();

        // Build ordered family list with labels
        $families = [];
        foreach ($familyLabels as $key => $label) {
            if (isset($archetypeFamilies[$key])) {
                $families[] = [
                    'key'    => $key,
                    'label'  => $label,
                    'chords' => $archetypeFamilies[$key],
                ];
            }
        }

        return Inertia::render('Library/Chords/Index', [
            'archetypeFamilies' => $families,
            'otherChords'       => $otherChords,
            'voicingCategories' => ChordDiagram::VOICING_CATEGORIES,
            'chordQualities'    => ChordDiagram::CHORD_QUALITIES,
            'totalCount'        => $archetypeRows->count() + count($otherChords),
        ]);
    }

    public function show(Request $request, string $slug)
    {
        $chord = ChordDiagram::where('slug', $slug)->firstOrFail();

        // Optional ?root= param lets callers (search results, sibling links) carry
        // the specific root note through to the detail page without changing the slug.
        $displayRoot = $request->query('root', null);
        if ($displayRoot && !in_array($displayRoot, ChordDiagram::ROOT_NOTES)) {
            $displayRoot = null; // ignore invalid values
        }

        // Test switch — ?pass=2 runs the builder with extensions=true.
        $pass = (int) $request->query('pass', 1);
        $usePass2 = $pass === 2;

        $siblings = ChordDiagram::where('quality', $chord->quality)
            ->where('id', '!=', $chord->id)
            ->orderByDesc('popularity')
            ->limit(4)
            ->get()
            ->map(fn ($c) => $this->chordSerializer->serialize($c)); // siblings always at stored root (C)

        // Leadsheets that use this chord (via sbn_voicing_usage)
        $songs = Leadsheet::query()
            ->join('sbn_voicing_usage as vu', 'sbn_leadsheets.id', '=', 'vu.leadsheet_id')
            ->where('vu.chord_diagram_id', $chord->id)
            ->select('sbn_leadsheets.id', 'sbn_leadsheets.slug', 'sbn_leadsheets.title', 'sbn_leadsheets.composer', 'sbn_leadsheets.song_key', 'sbn_leadsheets.rhythm', 'sbn_leadsheets.popularity')
            ->distinct()
            ->orderByDesc('sbn_leadsheets.popularity')
            ->limit(8)
            ->get()
            ->map(fn ($s) => [
                'id'       => $s->id,
                'slug'     => $s->slug,
                'title'    => $s->title,
                'composer' => $s->composer,
                'songKey'  => $s->song_key,
                'rhythm'   => $s->rhythm,
            ]);

        // Find progressions that contain a chord with this quality.
        // We can't do this in SQL via LIKE because numerals like "I7" / "Im7" / "Imaj7"
        // share substrings — '7' would match every dominant + minor7 + major7. Instead,
        // parse each progression's numerals through HarmonicContext (cheap; the table
        // is small) and keep ones whose parsed chord qualities include the target.
        $chordQuality = $chord->quality;

        $progressions = ChordProgression::orderBy('sort_order')
            ->get()
            ->filter(function ($p) use ($chordQuality) {
                $context = $this->harmonicContext->buildFromNumerals('C', $p->numerals);
                $chords  = $context['sections'][0]['chords'] ?? [];
                foreach ($chords as $c) {
                    if (($c['quality'] ?? null) === $chordQuality) return true;
                }
                return false;
            })
            ->take(4)
            ->values();

        // Build a pinned voicing object from the current chord so the progression
        // builder voice-leads the surrounding chords around it. The DB stores
        // shapes at their canonical root (e.g. Cm7 for the m7 shape); when the
        // user is viewing a transposed version (Ebm7 via ?root=Eb), we must
        // transpose the diagram_data + start_fret to that root so the pinned
        // tile shows the correct frets.
        $pinnedRoot = $displayRoot ?? ($chord->root_note ?? 'C');

        // Always transpose — even when pinnedRoot matches the stored root_note.
        // Legacy rows store diagram_data at arbitrary low frets but label the row
        // root='C', so the raw start_fret is unreliable. The calculator is
        // root-agnostic and re-derives start_fret from the bass interval, so
        // round-tripping through it self-heals the label/data mismatch.
        $transposed = $this->shapeCalculator->calculateFrets($chord, $pinnedRoot);
        $diagData       = $transposed['diagram_data'] ?? null;
        $startFret      = $transposed['start_fret']   ?? ($chord->start_fret ?? 1);
        $intervalLabels = $transposed['interval_labels'] ?? ($chord->interval_labels ?? '');
        $notesField     = $transposed['notes'] ?? ($chord->notes ?? '');

        if (empty($diagData) || (empty($diagData['positions']) && empty($diagData['open']))) {
            $diagData = is_string($chord->diagram_data)
                ? json_decode($chord->diagram_data, true)
                : ($chord->diagram_data ?? []);
            $startFret      = $chord->start_fret ?? 1;
            $intervalLabels = $chord->interval_labels ?? '';
            $notesField     = $chord->notes ?? '';
        }

        $pinnedVoicing = [
            'id'               => $chord->id,
            'root_note'        => $pinnedRoot,
            'quality'          => $chordQuality,
            'extensions'       => $chord->extensions ?? '',
            'voicing_category' => $chord->voicing_category,
            'root_string'      => $chord->root_string,
            'inversion'        => $chord->inversion ?? 'root',
            'start_fret'       => $startFret,
            'diagram_data'     => $diagData,
            'interval_labels'  => $intervalLabels,
            'notes'            => $notesField,
            'popularity'       => $chord->popularity ?? 0,
            'frets'            => null,
        ];

        // The 12 keys we'll try when locating the right transposition for a progression.
        $candidateKeys = ['C','Db','D','Eb','E','F','F#','G','Ab','A','Bb','B'];

        $progressions = $progressions
            ->map(function ($p) use ($pinnedVoicing, $pinnedRoot, $chordQuality, $candidateKeys, $usePass2) {
                // Find the key in which this progression places a chord matching our
                // pinned root + quality. This makes the surrounding chords harmonically
                // related to the chord the user is looking at, instead of always C-based.
                // Example: viewing Ebm7 in "IIm7 - V7 - Imaj7" → key Db → Ebm7, Ab7, Dbmaj7.
                $chosenKey  = null;
                $pinnedSlot = null;
                foreach ($candidateKeys as $candidate) {
                    $context = $this->harmonicContext->buildFromNumerals($candidate, $p->numerals);
                    $chords  = $context['sections'][0]['chords'] ?? [];
                    foreach ($chords as $i => $c) {
                        if (($c['quality'] ?? null) === $chordQuality
                            && $this->rootsEqual($c['root'] ?? null, $pinnedRoot)) {
                            $chosenKey  = $candidate;
                            $pinnedSlot = $i;
                            break 2;
                        }
                    }
                }

                // Fallback: no key produces a slot matching this chord exactly. This
                // shouldn't happen for diatonic progressions but keeps us safe for
                // non-diatonic numerals — render in C without pinning.
                if ($chosenKey === null) {
                    $chosenKey = 'C';
                }

                $context = $this->harmonicContext->buildFromNumerals($chosenKey, $p->numerals);

                $built = $this->progressionBuilder->buildVoicings($context, [
                    'category'      => $p->category,
                    'extensions'    => $usePass2,
                    'pinnedSlot'    => $pinnedSlot,
                    'pinnedVoicing' => $pinnedSlot !== null ? $pinnedVoicing : null,
                ]);

                $tiles = array_map(function ($sel) {
                    $v = $sel['voicing'] ?? null;
                    return [
                        'chordName'   => $sel['chord_name'],
                        'diagramData' => $v,
                        'slug'        => null,
                    ];
                }, $built['selections']);

                return [
                    'id'             => $p->id,
                    'slug'           => $p->slug,
                    'name'           => $p->name,
                    'category'       => $p->category,
                    'numeralsDisplay'=> $p->numerals_display,
                    'keyLabel'       => $chosenKey,
                    'tiles'          => $tiles,
                ];
            });

        return Inertia::render('Library/Chords/Show', [
            'chord'        => $this->chordSerializer->serialize($chord, $displayRoot),
            'siblings'     => $siblings,
            'songs'        => $songs,
            'progressions' => $progressions,
            'builderPass'  => $usePass2 ? 2 : 1,
        ]);
    }

    /**
     * Compare two root notes by pitch class so enharmonic spellings match
     * (Eb == D#, Db == C#, etc.).
     */
    private function rootsEqual(?string $a, ?string $b): bool
    {
        if (!$a || !$b) return false;
        static $semi = [
            'C'=>0,'B#'=>0,'C#'=>1,'Db'=>1,'D'=>2,'D#'=>3,'Eb'=>3,
            'E'=>4,'Fb'=>4,'F'=>5,'E#'=>5,'F#'=>6,'Gb'=>6,'G'=>7,
            'G#'=>8,'Ab'=>8,'A'=>9,'A#'=>10,'Bb'=>10,'B'=>11,'Cb'=>11,
        ];
        $sa = $semi[$a] ?? null;
        $sb = $semi[$b] ?? null;
        return $sa !== null && $sa === $sb;
    }

    /**
     * Transposition search: "Dm7" → all m7 voicings transposed to D.
     * Returns results shaped like serializeChord() so the frontend ChordCard
     * consumes them identically.
     */
    public function search(Request $request)
    {
        $q = trim((string) $request->get('q', ''));
        if ($q === '') {
            return response()->json(['query' => $q, 'results' => []]);
        }

        $raw = $this->voicingSearch->searchByName($q);

        // Load parent diagrams to pull labels / popularity / slug.
        $ids = collect($raw)->pluck('id')->filter()->unique()->values()->all();
        $parents = ChordDiagram::whereIn('id', $ids)->get()->keyBy('id');

        $results = [];
        foreach ($raw as $r) {
            $parent = $parents->get($r['id']);
            if (!$parent) continue;

            $results[] = [
                'id'               => $r['id'],
                'slug'             => $parent->slug,
                'name'             => $r['name'],
                'root_note'        => $r['root_note'],
                'quality'          => $r['quality'],
                'quality_label'    => $parent->quality_label,
                'extensions'       => $r['extensions'] ?? '',
                'voicing_category' => $r['voicing_category'] ?? '',
                'category_label'   => $parent->category_label,
                'root_string'      => $r['root_string'] ?? '',
                'root_string_label'=> $parent->root_string_label,
                'inversion'        => $r['inversion'] ?? 'root',
                'inversion_label'  => $parent->inversion_label,
                'bass_note'        => $r['bass_note'] ?? '',
                'shape_family'     => $parent->shape_family,
                'start_fret'       => $r['start_fret'] ?? 1,
                'diagram_data'     => $r['diagram_data'],
                'interval_labels'  => $r['interval_labels'] ?? '',
                'notes'            => $r['notes'] ?? '',
                'popularity'       => $r['popularity'] ?? ($parent->popularity ?? 0),
                'difficulty'       => $r['difficulty'] ?? $parent->difficulty,
                'description'      => $parent->description,
                'transposed_from'  => $r['original_root'] ?? null,
            ];
        }

        return response()->json(['query' => $q, 'results' => $results]);
    }
}

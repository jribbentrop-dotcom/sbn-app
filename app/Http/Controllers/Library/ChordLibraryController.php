<?php

namespace App\Http\Controllers\Library;

use App\Http\Controllers\Controller;
use App\Models\ChordDiagram;
use App\Models\ChordDiagramAlias;
use App\Models\ChordProgression;
use App\Models\Leadsheet;
use App\Services\ChordSerializer;
use App\Services\ChordShapeCalculator;
use App\Services\ChordVoicingSearch;
use App\Services\EduContentService;
use App\Services\HarmonicContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ChordLibraryController extends Controller
{
    public function __construct(
        protected ChordVoicingSearch $voicingSearch,
        protected HarmonicContext $harmonicContext,
        protected ChordShapeCalculator $shapeCalculator,
        protected ChordSerializer $chordSerializer,
    ) {}

    public function index()
    {
        $familyLabels = [
            'archetype-e' => 'E Shape',
            'archetype-em' => 'Em Shape',
            'archetype-a' => 'A Shape',
            'archetype-am' => 'Am Shape',
            'archetype-d' => 'D Shape',
            'archetype-dm' => 'Dm Shape',
            'archetype-c' => 'C Shape',
            'archetype-g' => 'G Shape',
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
            if (! empty($chord['shape_family'])) {
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
                    'key' => $key,
                    'label' => $label,
                    'chords' => $archetypeFamilies[$key],
                ];
            }
        }

        return Inertia::render('Library/Chords/Index', [
            'archetypeFamilies' => $families,
            'otherChords' => $otherChords,
            'voicingCategories' => ChordDiagram::VOICING_CATEGORIES,
            'chordQualities' => ChordDiagram::CHORD_QUALITIES,
            'totalCount' => $archetypeRows->count() + count($otherChords),
        ]);
    }

    public function show(Request $request, string $slug, EduContentService $edu)
    {
        $chord = ChordDiagram::where('slug', $slug)->firstOrFail();

        // Optional ?root= param lets callers (search results, sibling links) carry
        // the specific root note through to the detail page without changing the slug.
        $displayRoot = $request->query('root', null);
        if ($displayRoot && ! in_array($displayRoot, ChordDiagram::ROOT_NOTES)) {
            $displayRoot = null; // ignore invalid values
        }

        $siblings = ChordDiagram::where('quality', $chord->quality)
            ->where('id', '!=', $chord->id)
            ->orderByDesc('popularity')
            ->limit(4)
            ->get()
            ->map(fn ($c) => $this->chordSerializer->serialize($c)); // siblings always at stored root (C)

        // Leadsheets that use this chord (via sbn_voicing_usage)
        $songs = Leadsheet::published()
            ->join('sbn_voicing_usage as vu', 'sbn_leadsheets.id', '=', 'vu.leadsheet_id')
            ->where('vu.chord_diagram_id', $chord->id)
            ->select('sbn_leadsheets.id', 'sbn_leadsheets.slug', 'sbn_leadsheets.title', 'sbn_leadsheets.rhythm', 'sbn_leadsheets.popularity', 'sbn_leadsheets.cover_image_path')
            ->distinct()
            ->orderByDesc('sbn_leadsheets.popularity')
            ->limit(8)
            ->get()
            ->map(fn ($s) => $s->toLinkArray());

        // Find progressions that contain a chord with this quality.
        // We can't do this in SQL via LIKE because numerals like "I7" / "Im7" / "Imaj7"
        // share substrings — '7' would match every dominant + minor7 + major7. Instead,
        // parse each progression's numerals through HarmonicContext (cheap; the table
        // is small) and keep ones whose parsed chord qualities include the target.
        $chordQuality = $chord->quality;

        // Find progressions containing this quality and record which slot (0-based,
        // C-key) matches — that index becomes the ?highlight= param on the link.
        $progressions = ChordProgression::orderBy('sort_order')
            ->get()
            ->map(function ($p) use ($chordQuality) {
                $context = $this->harmonicContext->buildFromNumerals('C', $p->numerals);
                $chords = $context['sections'][0]['chords'] ?? [];
                $pinnedSlot = null;
                foreach ($chords as $i => $c) {
                    if (($c['quality'] ?? null) === $chordQuality) {
                        $pinnedSlot = $i;
                        break;
                    }
                }
                if ($pinnedSlot === null) return null;

                return [
                    'id' => $p->id,
                    'slug' => $p->slug,
                    'name' => $p->name,
                    'category' => $p->category,
                    'numeralsDisplay' => $p->numerals_display,
                    'pinnedSlot' => $pinnedSlot,
                ];
            })
            ->filter()
            ->take(8)
            ->values();

        // Aliases: same fret shape, different musical reinterpretation.
        // Aliases are stored relative to the chord's stored root (C). If the page
        // is showing a transposed root, shift each alias root/bass by the same offset.
        $storedRoot  = $chord->root_note ?? 'C';
        $effectiveDisplayRoot = $displayRoot ?? $storedRoot;
        $semitones   = ChordShapeCalculator::NOTE_SEMITONES;
        $sharpNames  = ['C','C#','D','D#','E','F','F#','G','G#','A','A#','B'];
        $flatNames   = ['C','Db','D','Eb','E','F','Gb','G','Ab','A','Bb','B'];
        $delta       = (($semitones[$effectiveDisplayRoot] ?? 0) - ($semitones[$storedRoot] ?? 0) + 12) % 12;

        // Use the display root's enharmonic family to spell transposed alias notes.
        $useFlats = str_contains($effectiveDisplayRoot, 'b');
        $transposeNote = function (?string $note) use ($delta, $semitones, $sharpNames, $flatNames, $useFlats): ?string {
            if ($note === null || $note === '') return $note;
            $base = ($semitones[$note] ?? null);
            if ($base === null) return $note;
            $target = ($base + $delta) % 12;
            return $useFlats ? $flatNames[$target] : $sharpNames[$target];
        };

        $aliases = ChordDiagramAlias::where('diagram_id', $chord->id)
            ->whereNotNull('alt_root_note')
            ->whereNotNull('alt_quality')
            ->orderBy('id')
            ->get()
            ->map(function ($a) use ($transposeNote) {
                $root = $transposeNote($a->alt_root_note);
                $bass = $transposeNote($a->alt_bass_note);
                return [
                    'root_note'      => $root,
                    'quality'        => $a->alt_quality,
                    'extensions'     => $a->alt_extensions ?? '',
                    'bass_note'      => $bass,
                    'interval_labels'=> $a->interval_labels ?? null,
                    'notes'          => $a->notes ?? null,
                    'name'           => ChordDiagramAlias::buildAltName($root, $a->alt_quality, $a->alt_extensions, $bass),
                ];
            })
            ->values();

        // Edu content for this chord's quality — description/usage prose for
        // the identity section, plus body_html + has_widgets so the page can
        // light up an embedded <sbn-widget> if a quality body ever gains one.
        $qualityTopic = $edu->qualityTopic($chord->quality);

        return Inertia::render('Library/Chords/Show', [
            'chord' => $this->chordSerializer->serialize($chord, $displayRoot),
            'aliases' => $aliases,
            'siblings' => $siblings,
            'songs' => $songs,
            'progressions' => $progressions,
            'qualityTopic' => $qualityTopic?->toArray(),
        ]);
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
            if (! $parent) {
                continue;
            }

            $results[] = [
                'id' => $r['id'],
                'slug' => $parent->slug,
                'name' => $r['name'],
                'root_note' => $r['root_note'],
                'quality' => $r['quality'],
                'quality_label' => $parent->quality_label,
                'extensions' => $r['extensions'] ?? '',
                'voicing_category' => $r['voicing_category'] ?? '',
                'category_label' => $parent->category_label,
                'root_string' => $r['root_string'] ?? '',
                'root_string_label' => $parent->root_string_label,
                'inversion' => $r['inversion'] ?? 'root',
                'inversion_label' => $parent->inversion_label,
                'bass_note' => $r['bass_note'] ?? '',
                'shape_family' => $parent->shape_family,
                'start_fret' => $r['start_fret'] ?? 1,
                'diagram_data' => $r['diagram_data'],
                'interval_labels' => $r['interval_labels'] ?? '',
                'notes' => $r['notes'] ?? '',
                'popularity' => $r['popularity'] ?? ($parent->popularity ?? 0),
                'difficulty' => $r['difficulty'] ?? $parent->difficulty,
                'description' => $parent->description,
                'transposed_from' => $r['original_root'] ?? null,
                'alias_match' => $r['alias_match'] ?? false,
            ];
        }

        return response()->json(['query' => $q, 'results' => $results]);
    }

    // ── Phase 11b: JSON endpoint for mountSbnNodes.ts ──────────────────────

    public function apiShow(Request $request, string $slug): JsonResponse
    {
        $chord = ChordDiagram::where('slug', $slug)->firstOrFail();
        $payload = $this->chordSerializer->serialize($chord);

        $root = trim((string) $request->get('root', ''));
        if ($root !== '') {
            $transposed = $this->shapeCalculator->calculateFrets($chord, $root);
            $payload['root_note'] = $root;
            $payload['diagram_data'] = $transposed['diagram_data'] ?? $payload['diagram_data'] ?? null;
            $payload['start_fret'] = $transposed['start_fret'] ?? $payload['start_fret'] ?? 1;
            $payload['interval_labels'] = $transposed['interval_labels'] ?? $payload['interval_labels'] ?? '';
            $payload['notes'] = $transposed['notes'] ?? $payload['notes'] ?? '';
        }

        return response()->json($payload);
    }
}

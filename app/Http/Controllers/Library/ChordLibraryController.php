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
		// The 8 canonical tile chords — plain triads are the tile face.
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

		// Quality of the primary (tile-face) chord for each family.
		// The serializer builds names from root+quality, so we match on quality
		// rather than the raw DB name field which gets discarded.
		$primaryQualities = [
			'archetype-e'  => 'maj',
			'archetype-em' => 'min',
			'archetype-a'  => 'maj',
			'archetype-am' => 'min',
			'archetype-d'  => 'maj',
			'archetype-dm' => 'min',
			'archetype-c'  => 'maj',
			'archetype-g'  => 'maj',
		];

		// Only the 8 canonical families; order within each by sort_order.
		$archetypeRows = ChordDiagram::query()
			->where('voicing_category', 'archetype')
			->whereIn('shape_family', array_keys($familyLabels))
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

		// Build ordered family list; float the plain-triad chord to position 0.
		$families = [];
		foreach ($familyLabels as $key => $label) {
			if (! isset($archetypeFamilies[$key])) {
				continue;
			}
			$chords  = $archetypeFamilies[$key];
			$primary = $primaryQualities[$key] ?? null;
			if ($primary) {
				usort($chords, fn ($a, $b) =>
					($a['quality'] === $primary && empty($a['extensions']) ? 0 : 1)
					- ($b['quality'] === $primary && empty($b['extensions']) ? 0 : 1)
				);
			}
			$families[] = [
				'key'    => $key,
				'label'  => $label,
				'chords' => $chords,
			];
		}

		// Barré families: the 4 transferable shapes (E/Em/A/Am) transposed to
		// their canonical first-position barré root — F for E-shape, Bb for A-shape.
		$barreFamilyConfig = [
			'archetype-e'  => ['root' => 'F',  'label' => 'F'],
			'archetype-em' => ['root' => 'F',  'label' => 'Fm'],
			'archetype-a'  => ['root' => 'Bb', 'label' => 'B♭'],
			'archetype-am' => ['root' => 'Bb', 'label' => 'B♭m'],
		];

		$barreFamilies = [];
		foreach ($barreFamilyConfig as $familyKey => $config) {
			$root = $config['root'];

			// Fetch the family's diagrams and serialize at the barré root.
			$chords = ChordDiagram::query()
				->where('voicing_category', 'archetype')
				->where('shape_family', $familyKey)
				->orderBy('sort_order')
				->orderByRaw('popularity DESC NULLS LAST')
				->get()
				->map(fn ($c) => $this->chordSerializer->serialize($c, $root))
				->values()
				->all();

			// Float the plain triad to position 0.
			$primaryQ = $primaryQualities[$familyKey] ?? null;
			if ($primaryQ) {
				usort($chords, fn ($a, $b) =>
					($a['quality'] === $primaryQ && empty($a['extensions']) ? 0 : 1)
					- ($b['quality'] === $primaryQ && empty($b['extensions']) ? 0 : 1)
				);
			}

			$barreFamilies[] = [
				'key'    => $familyKey . '-barre',
				'label'  => $config['label'],
				'root'   => $root,
				'chords' => $chords,
			];
		}

		// Drop2/Drop3 target voicings for level-3 animation:
		// each barré family morphs to its corresponding jazz voicing.
		// F/Fm (E-shape) → drop3 root-on-e  (ids 33, 46)
		// Bb/Bbm (A-shape) → drop2 root-on-a (ids 44, 24)
		$dropTargetMap = [
			'archetype-e-barre'  => ['id' => 33, 'root' => 'F',  'label' => 'Fmaj7'],
			'archetype-em-barre' => ['id' => 46, 'root' => 'F',  'label' => 'Fm7'],
			'archetype-a-barre'  => ['id' => 44, 'root' => 'Bb', 'label' => 'B♭maj7'],
			'archetype-am-barre' => ['id' => 24, 'root' => 'Bb', 'label' => 'B♭m7'],
		];
		$dropIds = array_column($dropTargetMap, 'id');
		$dropDiagrams = ChordDiagram::whereIn('id', $dropIds)->get()->keyBy('id');

		$dropFamilies = [];
		foreach ($dropTargetMap as $familyKey => $config) {
			$diagram = $dropDiagrams->get($config['id']);
			if (! $diagram) continue;
			$dropFamilies[$familyKey] = [
				'label' => $config['label'],
				'chord' => $this->chordSerializer->serialize($diagram, $config['root']),
			];
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

		return Inertia::render('Library/Chords/Index', [
			'archetypeFamilies' => $families,
			'barreFamilies'     => $barreFamilies,
			'dropFamilies'      => $dropFamilies,
			'otherChords'       => $otherChords,
			'voicingCategories' => ChordDiagram::VOICING_CATEGORIES,
			'chordQualities'    => ChordDiagram::CHORD_QUALITIES,
			'totalCount'        => $archetypeRows->count() + count($otherChords),
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

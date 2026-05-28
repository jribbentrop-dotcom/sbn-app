<?php

namespace App\Http\Controllers\Library;

use App\Http\Controllers\Controller;
use App\Models\ChordDiagram;
use App\Models\ChordDiagramAlias;
use App\Models\ChordProgression;
use App\Models\Leadsheet;
use App\Repositories\CourseRepository;
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
		protected CourseRepository $courseRepo,
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

		// Barré families: the 4 transferable shapes (E/Em/A/Am).
		// Tile shown at G (E-shape) and B (A-shape) — clean 2nd-fret positions.
		// Drawer shows all 12 chromatic positions of the plain triad.
		$barreFamilyConfig = [
			'archetype-e'  => ['root' => 'G',  'label' => 'G'],
			'archetype-em' => ['root' => 'G',  'label' => 'Gm'],
			'archetype-a'  => ['root' => 'C',  'label' => 'C'],
			'archetype-am' => ['root' => 'C',  'label' => 'Cm'],
		];

		// Chromatic roots in order, using sharps for black keys.
		$chromaticRoots = ['E', 'F', 'F#', 'G', 'G#', 'A', 'A#', 'B', 'C', 'C#', 'D', 'D#'];

		$barreFamilies = [];
		foreach ($barreFamilyConfig as $familyKey => $config) {
			$root = $config['root'];

			// Fetch the family's diagrams and serialize at the tile root.
			$allDiagrams = ChordDiagram::query()
				->where('voicing_category', 'archetype')
				->where('shape_family', $familyKey)
				->orderBy('sort_order')
				->orderByRaw('popularity DESC NULLS LAST')
				->get();

			$chords = $allDiagrams
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

			// All 12 chromatic positions of the plain triad for the drawer.
			$triads = $allDiagrams->filter(
				fn ($c) => ($c->quality === $primaryQ && empty($c->extensions))
			);
			$triad = $triads->first();
			$chromaticChords = $triad
				? collect($chromaticRoots)
					->map(fn ($r) => $this->chordSerializer->serialize($triad, $r))
					->values()
					->all()
				: [];

			$barreFamilies[] = [
				'key'             => $familyKey . '-barre',
				'label'           => $config['label'],
				'root'            => $root,
				'chords'          => $chords,
				'chromaticChords' => $chromaticChords,
			];
		}

		// Drop2/Drop3 target voicings for level-3 animation:
		// each barré family morphs to its corresponding jazz voicing.
		// G/Gm (E-shape) → drop3 root-on-e  (ids 33, 46) at G
		// C/Cm (A-shape) → drop2 root-on-a (ids 44, 24) at C
		//
		// inversions: explicit list in order [root, inv1, inv2, inv3].
		// Some inversions are stored under an alias (different quality in DB);
		// those entries carry 'quality', 'inversion', 'inversion_label' overrides.
		$dropTargetMap = [
			'archetype-e-barre' => [
				'id' => 33, 'root' => 'G', 'label' => 'Gmaj7',
				'inversions' => [
					['id' => 33],
					['id' => 104],
					['id' => 304],
					['id' => 303, 'octave_up' => true],
				],
			],
			'archetype-em-barre' => [
				'id' => 46, 'root' => 'G', 'label' => 'Gm7',
				'inversions' => [
					['id' => 46],
					// ID 185 (Maj6 Drop3 Root E) is Gm7 1st inv as alias
					['id' => 185, 'quality' => 'm7', 'inversion' => 'inv1', 'inversion_label' => '1st Inversion'],
					// ID 87 (Maj6 Drop3 1st Inv Root E) is Gm7 2nd inv as alias
					['id' => 87,  'quality' => 'm7', 'inversion' => 'inv2', 'inversion_label' => '2nd Inversion'],
					['id' => 310],
				],
			],
			'archetype-a-barre' => [
				'id' => 44, 'root' => 'C', 'label' => 'Cmaj7',
				'inversions' => [
					['id' => 44],
					['id' => 305],
					['id' => 257],
					['id' => 307, 'octave_up' => true],
				],
			],
			'archetype-am-barre' => [
				'id' => 24, 'root' => 'C', 'label' => 'Cm7',
				'inversions' => [
					['id' => 24],
					['id' => 47],
					['id' => 309],
					['id' => 308, 'octave_up' => true],
				],
			],
		];

		// Collect all referenced IDs and load in one query
		$allDropInvIds = [];
		foreach ($dropTargetMap as $config) {
			$allDropInvIds[] = $config['id'];
			foreach ($config['inversions'] as $inv) {
				$allDropInvIds[] = $inv['id'];
			}
		}
		$dropDiagrams = ChordDiagram::whereIn('id', array_unique($allDropInvIds))->get()->keyBy('id');

		// Shift all fret positions up by one octave (used for 3rd inversions that
		// otherwise resolve to open/first-position, keeping the neck view ascending).
		// Any open strings that are already represented in positions at fret 0 get
		// shifted with everything else; the open[] array is cleared. start_fret is
		// recomputed from the actual minimum fret so the renderer window covers all dots.
		$shiftOctave = function (array $chord): array {
			$d = $chord['diagram_data'];

			// Promote any open[] strings not already in positions (fret 0 entry)
			$positionedStrings = array_map(fn ($p) => $p['string'], $d['positions']);
			foreach ($d['open'] ?? [] as $str) {
				if (! in_array($str, $positionedStrings)) {
					$d['positions'][] = ['string' => $str, 'fret' => 0, 'finger' => null];
				}
			}
			$d['open'] = [];

			$d['positions'] = array_map(
				fn ($p) => array_merge($p, ['fret' => $p['fret'] + 12]),
				$d['positions']
			);
			$d['barres'] = array_map(
				fn ($b) => array_merge($b, ['fret' => $b['fret'] + 12]),
				$d['barres']
			);

			$chord['diagram_data'] = $d;

			// Recompute start_fret from the actual minimum fret across all positions/barres
			$frets = array_map(fn ($p) => $p['fret'], $d['positions']);
			foreach ($d['barres'] as $b) { $frets[] = $b['fret']; }
			$chord['start_fret'] = $frets ? min($frets) : $chord['start_fret'] + 12;

			return $chord;
		};

		$dropFamilies = [];
		foreach ($dropTargetMap as $familyKey => $config) {
			$diagram = $dropDiagrams->get($config['id']);
			if (! $diagram) continue;

			$inversions = [];
			foreach ($config['inversions'] as $invConfig) {
				$invDiagram = $dropDiagrams->get($invConfig['id']);
				if (! $invDiagram) continue;

				if (isset($invConfig['quality'])) {
					// Alias inversion: stored under a different quality, override metadata
					$serialized = $this->chordSerializer->serializeAs(
						$invDiagram,
						$config['root'],
						$invConfig['quality'],
						$invConfig['inversion'],
						$invConfig['inversion_label'],
					);
				} else {
					$serialized = $this->chordSerializer->serialize($invDiagram, $config['root']);
				}

				if (!empty($invConfig['octave_up'])) {
					$serialized = $shiftOctave($serialized);
				}

				$inversions[] = $serialized;
			}

			$dropFamilies[$familyKey] = [
				'label'      => $config['label'],
				'chord'      => $this->chordSerializer->serialize($diagram, $config['root']),
				'inversions' => $inversions,
			];
		}

		// Shell voicings for level-4 animation:
		// The two centred drop voicings (Gmaj7 / Gm7) lose their highest dot → shell.
		// from = the drop voicing already shown; to = the shell version at the same root.
		// E-shape shells: maj7-shell-roote (id 37) and m7-shell-roote (id 36).
		//
		// related: drawer rows shown when the tile is tapped in shell mode.
		// Gmaj7: row1=[G7(73), Gmaj6(80)], row2=[Cmaj7(31), C6(311)]
		// Gm7:   row1=[Gm6(106)],           row2=[Cm6(312)]
		$shellTargetMap = [
			'archetype-e-barre'  => [
				'id' => 37, 'root' => 'G', 'label' => 'Gmaj7 shell',
				'related' => [
					['label' => 'Add a b7 or 6', 'chords' => [
						['id' => 73,  'root' => 'G'],   // G7  (dom7 shell Root E)
						['id' => 80,  'root' => 'G'],   // Gmaj6 (maj6 shell Root E)
					]],
					['label' => 'A-shape equivalent', 'chords' => [
						['id' => 31,  'root' => 'C'],   // Cmaj7 (maj7 shell Root A)
						['id' => 311, 'root' => 'C'],   // C6   (maj6 shell Root A)
					]],
				],
			],
			'archetype-em-barre' => [
				'id' => 36, 'root' => 'G', 'label' => 'Gm7 shell',
				'related' => [
					['label' => 'Add a 6', 'chords' => [
						['id' => 106, 'root' => 'G'],   // Gm6 (m6 shell Root E)
					]],
					['label' => 'A-shape equivalent', 'chords' => [
						['id' => 312, 'root' => 'C'],   // Cm6 (m6 shell Root A)
					]],
				],
			],
		];

		// Collect all shell-related IDs in one query
		$shellAllIds = [];
		foreach ($shellTargetMap as $config) {
			$shellAllIds[] = $config['id'];
			foreach ($config['related'] as $group) {
				foreach ($group['chords'] as $entry) {
					$shellAllIds[] = $entry['id'];
				}
			}
		}
		$shellDiagrams = ChordDiagram::whereIn('id', array_unique($shellAllIds))->get()->keyBy('id');

		$shellFamilies = [];
		foreach ($shellTargetMap as $familyKey => $config) {
			$diagram = $shellDiagrams->get($config['id']);
			if (! $diagram) continue;

			$relatedGroups = [];
			foreach ($config['related'] as $group) {
				$groupChords = [];
				foreach ($group['chords'] as $entry) {
					$d = $shellDiagrams->get($entry['id']);
					if ($d) $groupChords[] = $this->chordSerializer->serialize($d, $entry['root']);
				}
				$relatedGroups[] = ['label' => $group['label'], 'chords' => $groupChords];
			}

			$shellFamilies[$familyKey] = [
				'label'   => $config['label'],
				'chord'   => $this->chordSerializer->serialize($diagram, $config['root']),
				'related' => $relatedGroups,
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
			'shellFamilies'     => $shellFamilies,
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
			->get()
			->map(fn ($c) => $this->chordSerializer->serialize($c)); // siblings always at stored root (C)

		// Leadsheets that use this chord (via sbn_voicing_usage)
		$songs = Leadsheet::published()
			->join('sbn_voicing_usage as vu', 'sbn_leadsheets.id', '=', 'vu.leadsheet_id')
			->where('vu.chord_diagram_id', $chord->id)
			->select('sbn_leadsheets.id', 'sbn_leadsheets.slug', 'sbn_leadsheets.title', 'sbn_leadsheets.rhythm', 'sbn_leadsheets.popularity', 'sbn_leadsheets.cover_image_path')
			->distinct()
			->orderByDesc('sbn_leadsheets.popularity')
			->limit(4)
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

		// Related courses: chords are genre-agnostic, so derive a category from
		// the songs that use this chord (most common style_slug → course category).
		// Fall back to 'jazz' when no songs are present (jazz covers most chord theory).
		$chordCourseCategory = $songs->isNotEmpty()
			? ($songs->groupBy(fn ($s) => $s['styleSlug'] ?? 'jazz')
				->map->count()->sortDesc()->keys()->first() ?? 'jazz')
			: 'jazz';
		// Normalise bossa/samba → bossa-nova to match course category values
		$chordCourseCategory = match ($chordCourseCategory) {
			'bossa', 'samba' => 'bossa-nova',
			default          => $chordCourseCategory,
		};
		$courses = $this->courseRepo->relatedByCategory($chordCourseCategory, limit: 4);

		return Inertia::render('Library/Chords/Show', [
			'chord' => $this->chordSerializer->serialize($chord, $displayRoot),
			'aliases' => $aliases,
			'siblings' => $siblings,
			'songs' => $songs,
			'progressions' => $progressions,
			'qualityTopic' => $qualityTopic?->toArray(),
			'courses' => $courses,
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

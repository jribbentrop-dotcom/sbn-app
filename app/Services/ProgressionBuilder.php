<?php

namespace App\Services;

use App\Models\ChordDiagram;
use App\Services\ChordShapeCalculator;
use App\Services\HarmonicContext;
use App\Services\Builder\PhaseE\ExtensionTable;
use App\Services\Builder\PhaseE\Interval;
use App\Services\BuilderSettings;
use Illuminate\Support\Facades\DB;

/**
 * SBN Progression Builder
 *
 * Server-side voice leading engine. Port of WP progression-builder.js.
 *
 * Given a HarmonicContext and a target voicing style, generates one optimally
 * voice-led voicing per chord. Runs server-side against the voicing database
 * to avoid per-chord AJAX round-trips.
 *
 * Algorithm (3 layered constraints):
 *   1. VOICING GROUP COHERENCE — shell/closed don't mix; drop2+drop3 mix freely
 *   2. UPPER STRING SET CONTINUITY — inner voices stay on same strings
 *   3. VOICE LEADING SCORE — guide tone resolution, fret distance, common tones
 *
 * @package App\Services
 */
class ProgressionBuilder
{
    protected ChordShapeCalculator $calculator;
    protected BuilderSettings $settings;

    public function __construct(ChordShapeCalculator $calculator, BuilderSettings $settings)
    {
        $this->calculator = $calculator;
        $this->settings = $settings;
        ExtensionTable::initialize();
    }

    // =========================================================================
    // CONSTANTS
    // =========================================================================

    /** Standard tuning open-string MIDI pitches. String 1=low E ... 6=high E */
    private const OPEN_MIDI = [1 => 40, 2 => 45, 3 => 50, 4 => 55, 5 => 59, 6 => 64];

    private const NOTE_SEMI = [
        'C' => 0, 'C#' => 1, 'Db' => 1, 'D' => 2, 'D#' => 3, 'Eb' => 3,
        'E' => 4, 'F' => 5, 'F#' => 6, 'Gb' => 6, 'G' => 7, 'G#' => 8, 'Ab' => 8,
        'A' => 9, 'A#' => 10, 'Bb' => 10, 'B' => 11,
    ];

    // Category-mode voicing pools (priority order per §5)
    private const CATEGORY_VOICING_POOLS = [
        'jazz' => ['drop2', 'drop3', 'shell'],
        'blues' => ['shell'], // Basic blues; advanced selected by style/extension trigger
        'pop' => ['archetype'],
        'classical' => ['closed_triads', 'spread_triads'],
        'modal' => ['quartal', 'shell', 'drop3'],
        'latin' => ['drop2', 'drop3', 'shell', 'closed'],
    ];

    private const CATEGORY_DEFAULT = 'jazz';

    // Blues advanced pool (triggered by style or extensions)
    private const BLUES_ADVANCED_POOL = ['shell', 'drop3', 'drop2', 'closed'];

    // Interval label groups for voice leading scoring
    private const SEVENTH_LABELS  = ['b7' => true, '7' => true, 'maj7' => true];
    private const THIRD_LABELS    = ['3' => true, 'b3' => true];
    private const ROOT_LABELS     = ['R' => true];
    private const GUIDE_LABELS    = ['3' => true, 'b3' => true, '7' => true, 'b7' => true, 'maj7' => true];
    private const NINTH_LABELS    = ['9' => true, 'b9' => true, '#9' => true];
    private const MAJ7_LABELS     = ['maj7' => true];
    private const SIXTH_LABELS    = ['6' => true, '13' => true, 'b13' => true];
    private const FIFTH_LABELS    = ['5' => true];
    private const ELEVENTH_LABELS = ['11' => true, '#11' => true];

    // Quality classification
    private const DOM_QUALITIES      = ['dom7', '7', '7alt', '7#11', '7b9', '7#9', '7b13', '7b9b13', '9', '13'];
    private const TONIC_MAJ_QUALITIES = ['maj7', 'maj6', 'maj9', 'maj13', '6', '69', 'maj'];
    private const HALF_DIM_QUALITIES  = ['m7b5', 'half-dim'];
    private const MINOR_QUALITIES     = ['m7', 'm6', 'm7b5', 'mMaj7', 'min', 'mMin7'];

    private const SCORE_VL_NORMALIZER = 15.0;

    private const CATEGORY_SEED_BIAS = [
        'pop' => ['target_fret' => 0, 'range' => 3],
        'jazz' => ['target_fret' => 5, 'range' => 3],
        'classical' => ['target_fret' => 2, 'range' => 2],
        'blues' => ['target_fret' => 1, 'range' => 3],
        'modal' => ['target_fret' => 5, 'range' => 3],
        'latin' => ['target_fret' => 5, 'range' => 3],
    ];

    private const CATEGORY_REGISTER_TARGET = [
        'pop' => 0,
        'jazz' => 5,
        'classical' => 2,
        'blues' => 1,
        'modal' => 5,
        'latin' => 5,
    ];

    private const CATEGORY_REGISTER_WEIGHT = [
        'pop' => 0.10,
        'jazz' => 0.05,
        'classical' => 0.10,
        'blues' => 0.15,
        'modal' => 0.05,
        'latin' => 0.05,
    ];

    private const COST_WEIGHTS = [
        'simplicity' => 0.10,
        'position' => 0.20,
        'bass_motion' => 0.20,
        'common_tone' => 0.15,
        'voice_leading' => 0.25,
        'group_continuity' => 0.10,
        'register' => 0.10,
        'named_resolutions' => 1.0,
        'style' => 0.25,
    ];

    private const VOICING_STYLE_PRESETS = [
        'auto' => null,
        'drop2_high' => [
            'prefer_category' => 'drop2',
            'bass_string_min' => 2,
            'bass_string_max' => 4,
            'register_target' => 7,
            'prefer_root' => false,
        ],
        'drop2_mid' => [
            'prefer_category' => 'drop2',
            'bass_string_min' => 3,
            'bass_string_max' => 5,
            'register_target' => 5,
            'prefer_root' => false,
        ],
        'drop3_low' => [
            'prefer_category' => 'drop3',
            'bass_string_min' => 4,
            'bass_string_max' => 6,
            'register_target' => 3,
            'prefer_root' => false,
        ],
        'drop3_mid' => [
            'prefer_category' => 'drop3',
            'bass_string_min' => 3,
            'bass_string_max' => 5,
            'register_target' => 5,
            'prefer_root' => false,
        ],
        'roote' => [
            'prefer_category' => 'drop3',
            'bass_string_min' => 6,
            'bass_string_max' => 6,
            'register_target' => 7,
            'prefer_root' => true,
        ],
        'roota' => [
            'prefer_category' => null,
            'bass_string_min' => 4,
            'bass_string_max' => 5,
            'register_target' => 5,
            'prefer_root' => true,
        ],
        'shell_low' => [
            'prefer_category' => 'shell',
            'bass_string_min' => 4,
            'bass_string_max' => 6,
            'register_target' => 3,
            'prefer_root' => false,
        ],
        'mixed' => [
            'prefer_category' => null,
            'bass_string_min' => 2,
            'bass_string_max' => 6,
            'register_target' => 5,
            'prefer_root' => false,
        ],
    ];

    // =========================================================================
    // MAIN ENTRY POINT
    // =========================================================================

    /**
     * Generate voice-led voicings for a harmonic context.
     *
     * @param  array  $context  HarmonicContext output (from HarmonicContext::build*)
     * @param  array  $options  {
     *     mode: 'simple'|'',                    // simple lookup mode bypasses scoring/style/extensions
     *     category: 'jazz'|'blues'|'pop'|'classical'|'modal'|'latin', // category-driven pool filtering
     *     style: 'drop'|'shell'|'closed'|'',  // voicing group preference
     *     extensions: bool,                     // include extension voicings
     *     rootOnly: bool,                       // root position only
     * }
     * @return array{
     *     selections: array<int, array{
     *         chord_name: string,
     *         voicing: array|null,
     *         vl_score: float|null,
     *     }>,
     *     vl_scores: array<int, float|null>,
     *     diagnostics: array{
     *         category_pool_fallbacks: array<int, array{slot: int, requested_pool: string, fallback_pool: string, reason: string}>,
     *         style_ignored: array{reason: string, requested_style: string}|null,
     *         category_normalized: string,
     *         category_input: string|null,
     *     },
     * }
     */
    public function buildVoicings(array $context, array $options = []): array
    {
        $mode       = $options['mode'] ?? '';
        $category   = $options['category'] ?? self::CATEGORY_DEFAULT;
        $style      = $options['style'] ?? '';
        $extensions = $options['extensions'] ?? false;
        $rootOnly   = $options['rootOnly'] ?? $this->settings->getRootOnlyDefault($category);
        $voicingStyle = $options['voicing_style'] ?? $this->settings->getDefaultVoicingStyle($category);
        if ($voicingStyle === 'auto' || $voicingStyle === '') {
            $voicingStyle = $this->settings->getDefaultVoicingStyle($category);
        }
        $vlLevel    = $extensions ? 2 : 1;

        // Diagnostics container
        $diagnostics = [
            'category_pool_fallbacks' => [],
            'style_ignored' => null,
            'category_normalized' => $category,
            'category_input' => $category,
            'slot_constraints' => [], // Phase C.6: per-slot constraint tracking
            'cost_breakdown' => [],
            'path_cost' => null,
            'observed_raw_vl_max' => 0.0,
        ];

        // Normalize category (treat unrecognized as jazz)
        $pools = $this->settings->get('category_pools');
        if (!array_key_exists($category, $pools)) {
            $diagnostics['category_normalized'] = self::CATEGORY_DEFAULT;
            $category = self::CATEGORY_DEFAULT;
        }

        // Flatten all chords across sections into a single sequence
        $allChords = [];
        if (isset($context['sections'])) {
            foreach ($context['sections'] as $section) {
                foreach ($section['chords'] as $chord) {
                    $allChords[] = $chord;
                }
            }
        }

        if (empty($allChords)) {
            return [
                'selections' => [],
                'vl_scores' => [],
                'diagnostics' => $diagnostics,
            ];
        }

        // Auto-route pop without explicit style to simple-mode lookup
        if (($options['category'] ?? null) === 'pop'
            && empty($options['style'])
            && ($options['mode'] ?? '') !== 'simple') {
            $options['mode'] = 'simple';
        }

        // Re-read mode after potential pop routing
        $mode = $options['mode'] ?? '';

        if ($mode === 'simple') {
            return $this->buildSimpleModeVoicings($context, $allChords, $options, $diagnostics);
        }

        // Apply numeral upgrade (§6.1) for category-aware progressions
        $context = $this->applyCategoryNumeralUpgrade($context, $category);

        // Apply Phase E extension upgrade (§6.2)
        $applyExtensionUpgrade = $extensions && $this->settings->isPass2Eligible($category);
        $context = $this->applyPhaseEExtensionUpgrade($context, $applyExtensionUpgrade);

        // Ensure all chords have extension field set (fallback for non-PhaseE categories)
        foreach ($context['sections'] as $secIdx => $section) {
            foreach ($section['chords'] as $chordIdx => $chord) {
                if (!isset($chord['extension'])) {
                    $context['sections'][$secIdx]['chords'][$chordIdx]['extension'] = '';
                }
            }
        }

        // Re-flatten chords after upgrade (allChords was flattened before upgrade)
        $allChords = [];
        if (isset($context['sections'])) {
            foreach ($context['sections'] as $section) {
                foreach ($section['chords'] as $chord) {
                    $allChords[] = $chord;
                }
            }
        }

        $n = count($allChords);

        // Fetch voicings for each chord from the database
        $chordVoicings = [];
        foreach ($allChords as $i => $chord) {
            $functionalRole = $this->determineFunctionalRole($chord);
            $chordVoicings[$i] = $this->fetchVoicingsForChord(
                $chord['root'],
                $chord['quality'],
                $chord['extension'],
                $style,
                $rootOnly,
                $category,
                $extensions,
                $i,
                $diagnostics,
                $functionalRole
            );
        }
        $lattice = $this->buildAnchorFreeLattice($chordVoicings, $style, $rootOnly, $category);

        // Apply harmony filter to each slot
        for ($i = 0; $i < $n; $i++) {
            $lattice[$i] = $this->applyHarmonyFilter($lattice[$i], $allChords, $i);
        }

        // Handle pinned slot
        $pinnedSlot = $options['pinnedSlot'] ?? null;
        $pinnedVoicing = $options['pinnedVoicing'] ?? null;
        if ($pinnedSlot !== null && $pinnedVoicing !== null && $pinnedSlot >= 0 && $pinnedSlot < $n) {
            $lattice[$pinnedSlot] = [(object) $pinnedVoicing];
        }

        // Repeated-chord reuse is applied AFTER Viterbi finishes (see
        // applyRepeatedChordReuse below). Pinning a repeated slot to
        // pool[i-1][0] before search would force the preceding free slot to
        // harmonize with an arbitrary first-pool element rather than
        // letting Viterbi pick its true optimum.

        // Phase E: Run Pass 1 and Pass 2, then apply decision rule
        $pass1Selections = null;
        $pass1Cost = null;
        $pass2Selections = null;
        $pass2Cost = null;
        $pass2FiredResolutions = [];

        $runPass2 = $extensions && $this->settings->isPass2Eligible($category);

        if ($runPass2) {
            // Run Pass 1 (vlLevel = 1, no extensions)
            $pass1Context = [
                'category' => $category,
                'vlLevel' => 1,
                'voicing_style' => $voicingStyle,
                'weight_overrides' => $options['weight_overrides'] ?? [],
            ];
            $pass1Selections = $this->viterbiSearchWithRelaxation($lattice, $pass1Context, $style, $diagnostics, $allChords);
            $pass1Cost = $this->calculatePathCost($pass1Selections, $allChords, $pass1Context);

            // Run Pass 2 (vlLevel = 2, with extensions and named resolutions)
            $pass2Context = [
                'category' => $category,
                'vlLevel' => 2,
                'voicing_style' => $voicingStyle,
                'weight_overrides' => $options['weight_overrides'] ?? [],
            ];
            $pass2Selections = $this->viterbiSearchWithRelaxation($lattice, $pass2Context, $style, $diagnostics, $allChords);
            $pass2Result = $this->calculatePathCostWithResolutions($pass2Selections, $allChords, $pass2Context);
            $pass2Cost = $pass2Result['cost'];
            $pass2FiredResolutions = $pass2Result['fired_resolutions'];

            // Apply Pass 2 vs Pass 1 decision rule (E.1.5)
            $pass2Won = $this->applyPass2DecisionRule(
                $pass1Selections, $pass1Cost,
                $pass2Selections, $pass2Cost, $pass2FiredResolutions
            );
            
            $selections = $pass2Won ? $pass2Selections : $pass1Selections;
            if (empty($selections)) {
                $selections = array_fill(0, $n, null);
            }
            $selections = $this->applyRepeatedChordReuse($selections, $allChords, $options);

            $diagnostics['phase_e'] = [
                'pass1_cost' => $pass1Cost,
                'pass2_cost' => $pass2Cost,
                'pass2_fired_resolutions' => $pass2FiredResolutions,
                'pass2_won' => $pass2Won,
            ];
        } else {
            // Extensions disabled, run Pass 1 only
            $context = [
                'category' => $category,
                'vlLevel' => $vlLevel,
                'voicing_style' => $voicingStyle,
                'weight_overrides' => $options['weight_overrides'] ?? [],
            ];
            $selections = $this->viterbiSearchWithRelaxation($lattice, $context, $style, $diagnostics, $allChords);
            if (empty($selections)) {
                $selections = array_fill(0, $n, null);
            }
            $selections = $this->applyRepeatedChordReuse($selections, $allChords, $options);
        }

        // Compute VL scores between adjacent selections
        $vlScores = [];
        $pathCost = 0.0;
        $actualVlLevel = ($runPass2 && $selections === $pass2Selections) ? 2 : 1;
        for ($i = 0; $i < $n - 1; $i++) {
            $sel1 = $selections[$i] ?? null;
            $sel2 = $selections[$i + 1] ?? null;
            if ($sel1 && $sel2) {
                $rawScore = $this->scoreVL($sel1, $sel2, $actualVlLevel);
                $vlScores[] = $rawScore;
                $breakdown = $this->costBreakdown($sel1, $sel2, [
                    'vlLevel' => $actualVlLevel,
                    'category' => $category,
                    'weight_overrides' => $options['weight_overrides'] ?? [],
                    'source_chord' => $allChords[$i] ?? null,
                    'target_chord' => $allChords[$i + 1] ?? null,
                ]);
                $diagnostics['cost_breakdown'][] = array_merge([
                    'from' => $i,
                    'to' => $i + 1,
                ], $breakdown);
                $pathCost += $breakdown['total'];
                $diagnostics['observed_raw_vl_max'] = max($diagnostics['observed_raw_vl_max'], $breakdown['raw_voice_leading']);
            } else {
                $vlScores[] = null;
            }
        }
        $diagnostics['path_cost'] = round($pathCost, 4);

        // Format output — include the full voicing pool per chord for the picker UI
        $result = [];
        foreach ($allChords as $i => $chord) {
            $sel  = $selections[$i];
            $pool = $chordVoicings[$i] ?? [];
            $result[] = [
                'chord_name'    => $chord['chord_name'],
                'roman_numeral' => $chord['roman_numeral'],
                'measure_index' => $chord['measure_index'],
                'voicing'       => $sel ? $this->formatVoicing($sel, $chord['chord_name']) : null,
                'voicings'      => array_values(array_map(fn($v) => $this->formatVoicing($v, $chord['chord_name']), $pool)),
            ];
        }

        // Also include section structure so the UI can render section breaks
        $sections = [];
        if (isset($context['sections'])) {
            foreach ($context['sections'] as $sec) {
                $sections[] = [
                    'section_key' => $sec['section_key'] ?? '',
                    'length'      => count($sec['chords']),
                ];
            }
        }

        return [
            'selections' => $result,
            'vl_scores'  => $vlScores,
            'sections'   => $sections,
            'diagnostics' => $diagnostics,
        ];
    }

    // =========================================================================
    // VOICING FETCHING
    // =========================================================================

    /**
     * Fetch candidate voicings from the database for a chord.
     *
     * Shapes are stored as archetypes (often with root C or similar).
     * We query by quality, then transpose each shape to the target root
     * using ChordShapeCalculator — same approach as searchVoicingsAdvanced.
     *
     * Returns stdClass objects with transposed diagram_data, interval_labels,
     * start_fret, and all original metadata needed for VL scoring.
     */
    private function fetchVoicingsForChord(
        ?string $root,
        string $quality,
        string $extension,
        string $styleFilter,
        bool $rootOnly,
        string $category = self::CATEGORY_DEFAULT,
        bool $extensions = false,
        int $slotIndex = 0,
        array &$diagnostics = null,
        ?string $functionalRole = null
    ): array {
        if (!$root) return [];

        // Normalize quality for DB lookup
        $dbQuality = $quality;
        if ($dbQuality === '7') $dbQuality = 'dom7';

        $qualityAliases = $this->expandTonicFamilyAliases(
            $this->getQualityAliases($dbQuality),
            $dbQuality,
            $category,
            $functionalRole
        );

        // Determine pool based on category and blues sub-mode trigger
        $pool = $this->getCategoryPool($category, $styleFilter, $extensions);

        // Check if style is outside category pool
        if ($styleFilter && $styleFilter !== 'drop' && $styleFilter !== '') {
            if (!in_array($styleFilter, $pool, true)) {
                // Style is not in category pool — ignore it, log warning
                if ($diagnostics !== null && $diagnostics['style_ignored'] === null) {
                    $diagnostics['style_ignored'] = [
                        'reason' => "style '{$styleFilter}' not in category '{$category}' pool",
                        'requested_style' => $styleFilter,
                    ];
                }
                $styleFilter = ''; // Clear style to stay category-correct
            }
        }

        $query = DB::table('sbn_chord_diagrams')
            ->whereIn('quality', $qualityAliases)
            ->whereRaw("(bass_note = '' OR bass_note IS NULL)")
            ->orderByDesc('popularity');

        // Pass 1 (extensions=false): restrict to plain voicings (no option tones).
        // The DB seeds extension-carrying rows under the same quality (e.g. m7+11,
        // dom7+9), which leaked color tones into Pass 1 output. Pass 2 keeps the
        // full pool so Phase E can upgrade to tension voicings.
        if (!$extensions && !$this->settings->isPass1ExtensionsAllowed($category)) {
            $query->where(function ($q) {
                $q->whereNull('extensions')->orWhere('extensions', '');
            });
        }

        // When specific extensions are requested (Phase E), filter for voicings
        // that carry those extension tones. DB stores comma-separated values
        // (e.g. "9,13", "b9, #11"). Wrap in commas for exact tone matching so
        // "9" doesn't accidentally match "b9" or "#9".
        if ($extension !== '') {
            $requestedTones = array_map('trim', explode(',', $extension));
            $query->where(function ($q) use ($requestedTones) {
                foreach ($requestedTones as $tone) {
                    $q->orWhereRaw("(',' || extensions || ',') LIKE ?", ['%,' . $tone . ',%']);
                }
            });
        }

        // Apply category pool filter
        $shapes = $this->queryWithCategoryPool($query, $pool, $styleFilter, $rootOnly, $slotIndex, $diagnostics);

        // Alias voicings: a shape stored under one identity that re-spells under
        // another (e.g. Cmaj7 xx5557 = Em7/G). Fetched as shape objects with the
        // alias's identity (alt_root, alt_quality, alt_extensions, alt_bass) but
        // the parent's diagram_data. Same Pass-1/Pass-2 extension gating as the
        // parent table. Honors the category pool via the parent's voicing_category.
        $aliasShapes = $this->fetchAliasShapes($qualityAliases, $extensions, $rootOnly, $pool, $extension, $category);
        $shapes = $shapes->concat($aliasShapes);

        if ($shapes->isEmpty()) return [];

        // Transpose each shape to the target root
        $results = [];
        foreach ($shapes as $shape) {
            if (!empty($shape->is_fixed_position) && strtolower($shape->root_note ?? '') !== strtolower($root)) {
                continue;
            }
            // True-slash aliases (foreign bass) need transposition relative to the
            // bass note, not the root. Re-spell the alias's foreign bass into the
            // target key so the parent shape lands at the right fret.
            if (!empty($shape->_alias_true_slash) && !empty($shape->bass_note)) {
                $targetBass = $this->transposeBassNote($shape->root_note, $shape->bass_note, $root);
                if ($targetBass === null) continue;
                $calculated = $this->calculator->calculateFretsWithBass($shape, $root, $targetBass);
            } else {
                $calculated = $this->calculator->calculateFrets($shape, $root);
            }

            if (empty($calculated['diagram_data']) ||
                (empty($calculated['diagram_data']['positions']) && empty($calculated['diagram_data']['open']))) {
                continue;
            }

            // Build a voicing object with all fields needed for VL scoring
            $voicing = (object) [
                'id'               => $shape->id,
                'root_note'        => $root,
                'original_root'    => $shape->root_note,
                'quality'          => $shape->quality,
                'extensions'       => $shape->extensions ?? '',
                'voicing_category' => $shape->voicing_category,
                'root_string'      => $shape->root_string,
                'inversion'        => $shape->inversion ?? 'root',
                'start_fret'       => $calculated['start_fret'],
                'diagram_data'     => (object) $calculated['diagram_data'],
                'interval_labels'  => $calculated['interval_labels'] ?: ($shape->interval_labels ?? ''),
                'notes'            => $calculated['notes'] ?? '',
                'popularity'       => $shape->popularity ?? 0,
                'frets'            => null, // will be computed in formatVoicing
            ];

            // Convert positions to a fret string for display
            $fretStr = $this->buildFretString($calculated['diagram_data']);
            $voicing->frets = $fretStr;

            $results[] = $voicing;
        }

        return $results;
    }

    /**
     * Fetch alias-table shapes that re-spell a parent diagram under a different
     * identity (root/quality/bass). Returns shape objects matching the shape
     * produced by the parent-table query, so the existing transposition loop
     * accepts them unchanged. The alias's alt_root_note becomes shape->root_note;
     * the parent's diagram_data is preserved verbatim.
     */
    private function fetchAliasShapes(array $qualityAliases, bool $extensions, bool $rootOnly, array $pool, string $extension = '', string $category = self::CATEGORY_DEFAULT): \Illuminate\Support\Collection
    {
        $query = DB::table('sbn_chord_diagram_aliases as a')
            ->join('sbn_chord_diagrams as d', 'a.diagram_id', '=', 'd.id')
            ->whereIn('a.alt_quality', $qualityAliases)
            ->whereIn('d.voicing_category', $pool)
            ->select(
                'd.id',
                'd.voicing_category',
                'd.root_string',
                'd.diagram_data',
                'd.start_fret',
                'd.is_fixed_position',
                'd.popularity',
                'a.alt_root_note',
                'a.alt_quality',
                'a.alt_extensions',
                'a.alt_bass_note',
                'a.interval_labels as alt_interval_labels',
                'a.notes as alt_notes'
            );

        // Pass 1: only plain aliases. Mirrors the parent-table extension filter.
        if (!$extensions && !$this->settings->isPass1ExtensionsAllowed($category)) {
            $query->where(function ($q) {
                $q->whereNull('a.alt_extensions')->orWhere('a.alt_extensions', '');
            });
        }

        // When specific extensions are requested (Phase E), filter aliases
        // whose alt_extensions contain the requested tones.
        if ($extension !== '') {
            $requestedTones = array_map('trim', explode(',', $extension));
            $query->where(function ($q) use ($requestedTones) {
                foreach ($requestedTones as $tone) {
                    $q->orWhereRaw("(',' || a.alt_extensions || ',') LIKE ?", ['%,' . $tone . ',%']);
                }
            });
        }

        // rootOnly: alias is "root position" iff alt_bass_note is empty.
        if ($rootOnly) {
            $query->where(function ($q) {
                $q->whereNull('a.alt_bass_note')->orWhere('a.alt_bass_note', '');
            });
        }

        $rows = $query->orderByDesc('d.popularity')->get();

        return $rows->map(function ($r) {
            $shape = new \stdClass();
            $shape->id               = $r->id;
            $shape->root_note        = $r->alt_root_note;
            $shape->quality          = $r->alt_quality;
            $shape->extensions       = $r->alt_extensions ?? '';
            $shape->voicing_category = $r->voicing_category;
            $shape->root_string      = $r->root_string;
            $shape->bass_note        = $r->alt_bass_note ?? '';
            $shape->diagram_data     = $r->diagram_data;
            $shape->start_fret       = $r->start_fret;
            $shape->is_fixed_position = $r->is_fixed_position;
            $shape->popularity       = $r->popularity ?? 0;
            $shape->interval_labels  = $r->alt_interval_labels ?? '';
            $shape->notes            = $r->alt_notes ?? '';

            // Resolve inversion vs. true-slash. ChordShapeCalculator::calculateFrets
            // needs a known inversion label ('root', 'inv1', 'inv2', 'inv3'); a true
            // slash (foreign bass) takes the calculateFretsWithBass path instead.
            if (!empty($shape->bass_note)) {
                $info = $this->calculator->analyzeSlashChord(
                    $shape->root_note, $shape->quality, $shape->bass_note
                );
                if ($info['type'] === 'inversion' && !empty($info['inversion'])) {
                    $shape->inversion = $info['inversion'];
                } else {
                    $shape->inversion = 'root';
                    $shape->_alias_true_slash = true;
                }
            } else {
                $shape->inversion = 'root';
            }

            return $shape;
        });
    }

    /**
     * Transpose an alias's foreign bass note from its source root to a target root.
     * Returns null if either note is unrecognized.
     */
    private function transposeBassNote(string $sourceRoot, string $sourceBass, string $targetRoot): ?string
    {
        static $semi = [
            'C'=>0,'B#'=>0,'C#'=>1,'Db'=>1,'D'=>2,'D#'=>3,'Eb'=>3,
            'E'=>4,'Fb'=>4,'F'=>5,'E#'=>5,'F#'=>6,'Gb'=>6,'G'=>7,
            'G#'=>8,'Ab'=>8,'A'=>9,'A#'=>10,'Bb'=>10,'B'=>11,'Cb'=>11,
        ];
        static $names = ['C','C#','D','D#','E','F','F#','G','G#','A','A#','B'];
        if (!isset($semi[$sourceRoot], $semi[$sourceBass], $semi[$targetRoot])) {
            return null;
        }
        $interval = ($semi[$sourceBass] - $semi[$sourceRoot] + 12) % 12;
        return $names[($semi[$targetRoot] + $interval) % 12];
    }

    /**
     * Get the voicing category pool for a given category, style, and extensions flag.
     *
     * Blues sub-mode trigger: advanced blues when style is structured OR extensions=true.
     * Explicit 'archetype' style = basic blues (no trigger).
     */
    private function getCategoryPool(string $category, string $style, bool $extensions): array
    {
        $basePool = $this->settings->getCategoryPool($category);

        // Blues sub-mode trigger (per user answer Q1)
        if ($category === 'blues') {
            $advancedBlues = in_array($style, ['shell', 'drop2', 'drop3', 'closed'], true)
                || $extensions === true;
            // Explicit 'archetype' style = basic blues (no trigger)
            if ($style === 'archetype') {
                $advancedBlues = false;
            }
            if ($advancedBlues) {
                return $this->settings->getBluesAdvancedPool();
            }
        }

        return $basePool;
    }

    /**
     * Query with category pool filtering and fallback logging.
     *
     * Tries each category in priority order, falling back to the next if empty.
     * Logs fallback events to diagnostics.
     */
    private function queryWithCategoryPool($query, array $pool, string $styleFilter, bool $rootOnly, int $slotIndex, ?array &$diagnostics)
    {
        $originalPool = $pool;
        $allShapes = collect();

        foreach ($pool as $poolIdx => $category) {
            $queryClone = clone $query;
            $queryClone->whereIn('voicing_category', [$category, '', null]);

            if ($styleFilter && $styleFilter !== 'drop' && in_array($styleFilter, $pool, true)) {
                $queryClone->where('voicing_category', $styleFilter);
            }

            if ($rootOnly) {
                $queryClone->where(function ($q) {
                    $q->where('inversion', 'root')
                      ->orWhereNull('inversion')
                      ->orWhere('inversion', '');
                });
            }

            $queryClone->where(function ($q) {
                $q->where('voicing_category', '!=', 'open')
                  ->orWhereNull('voicing_category')
                  ->orWhere('voicing_category', '');
            });

            $shapes = $queryClone->limit(80)->get();

            if (!$shapes->isEmpty()) {
                if (in_array($category, ['archetype', '', null], true)) {
                    $shapes = $this->applyArchetypePriority($shapes);
                }
                $allShapes = $allShapes->concat($shapes);
            }

            if ($diagnostics !== null && $shapes->isEmpty() && $poolIdx < count($pool) - 1) {
                $nextCategory = $pool[$poolIdx + 1] ?? 'unrestricted';
                $diagnostics['category_pool_fallbacks'][] = [
                    'slot' => $slotIndex,
                    'requested_pool' => $category,
                    'fallback_pool' => $nextCategory,
                    'reason' => 'no candidates',
                ];
            }
        }

        if ($allShapes->isNotEmpty()) {
            return $allShapes;
        }

        // Final fallback: unrestricted query
        $query->where(function ($q) {
            $q->where('voicing_category', '!=', 'open')
              ->orWhereNull('voicing_category')
              ->orWhere('voicing_category', '');
        });

        if ($rootOnly) {
            $query->where(function ($q) {
                $q->where('inversion', 'root')
                  ->orWhereNull('inversion')
                  ->orWhere('inversion', '');
            });
        }

        if ($styleFilter && $styleFilter !== 'drop') {
            $query->where('voicing_category', $styleFilter);
        }

        if ($diagnostics !== null) {
            $diagnostics['category_pool_fallbacks'][] = [
                'slot' => $slotIndex,
                'requested_pool' => implode(',', $originalPool),
                'fallback_pool' => 'unrestricted',
                'reason' => 'all pools exhausted',
            ];
        }

        return $query->limit(80)->get();
    }

    /**
     * Apply non-barré priority ordering to archetype pool.
     * Non-barré → partial-barré → full-barré.
     */
    private function applyArchetypePriority($shapes)
    {
        $ranked = [];
        foreach ($shapes as $shape) {
            $rank = $this->archetypePriorityRank((object) $shape);
            $ranked[] = ['shape' => $shape, 'rank' => $rank];
        }

        usort($ranked, function ($a, $b) {
            if ($a['rank'] !== $b['rank']) {
                return $a['rank'] <=> $b['rank'];
            }
            return ($b['shape']->popularity ?? 0) <=> ($a['shape']->popularity ?? 0);
        });

        return collect($ranked)->pluck('shape');
    }

    /**
     * Determine whether to reuse the previous voicing for the current chord.
     * 
     * Reuse logic: adjacent identical chords reuse the same voicing unless pinned.
     * This provides consistency and avoids unnecessary position changes.
     * 
     * @param string $currentChord Current chord name
     * @param string|null $prevChord Previous chord name
     * @param int $slotIdx Current slot index
     * @param int|null $prevSelectionExists Whether previous selection exists
     * @param array $options Builder options (for pinnedSlot)
     * @return bool True if should reuse previous voicing
     */
    private function shouldReusePreviousVoicing(
        string $currentChord,
        ?string $prevChord,
        int $slotIdx,
        ?bool $prevSelectionExists,
        array $options
    ): bool {
        $pinnedSlot = $options['pinnedSlot'] ?? null;
        
        // Never reuse if current slot is pinned
        if ($pinnedSlot !== null && $pinnedSlot === $slotIdx) {
            return false;
        }
        
        // Reuse if adjacent chords are identical and previous selection exists
        return $slotIdx > 0
            && $prevChord === $currentChord
            && $prevSelectionExists;
    }

    /**
     * Post-Viterbi: copy each chosen voicing forward into immediately-following
     * slots whose chord_name is identical. Lets Viterbi optimize the "free"
     * slots first, then propagates the chosen voicing through the repeats.
     *
     * Why post-Viterbi: pinning the repeat slot to pool[i-1][0] before search
     * forces the preceding free slot to harmonize with an arbitrary first-pool
     * element. The cheap self-edge from that pin then drags the free slot's
     * choice toward the pinned voicing — the opposite of what we want.
     */
    private function applyRepeatedChordReuse(array $selections, array $allChords, array $options): array
    {
        if (!$this->settings->isRepeatedChordReuseEnabled()) {
            return $selections;
        }

        $n = count($selections);
        for ($i = 1; $i < $n; $i++) {
            $reuse = $this->shouldReusePreviousVoicing(
                $allChords[$i]['chord_name'] ?? '',
                $allChords[$i - 1]['chord_name'] ?? null,
                $i,
                $selections[$i - 1] !== null,
                $options
            );
            if ($reuse && $selections[$i - 1] !== null) {
                $selections[$i] = $selections[$i - 1];
            }
        }
        return $selections;
    }

    /**
     * Get the bass note (lowest sounding note) MIDI value for a voicing.
     * Returns null if unable to determine bass note.
     * 
     * @param object $voicing The voicing object
     * @return int|null MIDI value of bass note, or null
     */
    private function getBassNote(object $voicing): ?int
    {
        $pitchMap = $this->buildPitchMap($voicing);
        if (empty($pitchMap)) return null;
        
        // Find the lowest MIDI value (bass note)
        $bassMidi = null;
        foreach ($pitchMap as $pitch) {
            if ($bassMidi === null || $pitch['midi'] < $bassMidi) {
                $bassMidi = $pitch['midi'];
            }
        }
        
        return $bassMidi;
    }

    /**
     * Check if a transition is a tritone-substitution (dom7 → maj/maj7).
     * Used to relax bass-motion constraint for this specific jazz cadence.
     * 
     * @param object $anchorVoicing Previous voicing
     * @param object $candidateVoicing Candidate voicing
     * @return bool True if this is a tritone-sub transition
     */
    private function isTritoneSubTransition(object $anchorVoicing, object $candidateVoicing, int $bassInterval): bool
    {
        if ($bassInterval !== 6) {
            return false;
        }

        $anchorQuality = $anchorVoicing->quality ?? '';
        $anchorIsDom7 = $anchorQuality === '7' || $anchorQuality === 'dom7';
        
        $candidateQuality = $candidateVoicing->quality ?? '';
        $candidateIsMaj = in_array($candidateQuality, ['maj', 'maj7', 'maj6', 'maj9'], true);
        
        return $anchorIsDom7 && $candidateIsMaj;
    }

    /**
     * Phase A simple-mode voicing builder.
     * 
     * Bypasses scoring and style/extensions filtering, and selects archetype
     * voicings by direct lookup:
     * - strict root+quality first
     * - then root-only triad equivalent fallback
     * - repeated adjacent chords reuse prior voicing unless pinned
     */
    private function buildSimpleModeVoicings(array $context, array $allChords, array $options, array $diagnostics): array
    {
        $n = count($allChords);
        $chordVoicings = [];
        $selections = array_fill(0, $n, null);

        $pinnedSlot = $options['pinnedSlot'] ?? null;
        $pinnedVoicing = $options['pinnedVoicing'] ?? null;

        foreach ($allChords as $i => $chord) {
            $chordVoicings[$i] = $this->fetchSimpleModeVoicingsForChord(
                $chord['root'],
                $chord['quality'] ?? ''
            );

            $isPinnedSlot = $pinnedSlot !== null
                && $pinnedVoicing !== null
                && $pinnedSlot >= 0
                && $pinnedSlot < $n
                && $pinnedSlot === $i;

            if ($isPinnedSlot) {
                $selections[$i] = (object) $pinnedVoicing;
                continue;
            }

            // Reuse previous voicing for adjacent identical chords (skip first slot)
            if ($i > 0 && $this->shouldReusePreviousVoicing(
                $chord['chord_name'] ?? '',
                $allChords[$i - 1]['chord_name'] ?? null,
                $i,
                $selections[$i - 1] !== null,
                $options
            )) {
                $selections[$i] = $selections[$i - 1];
                continue;
            }

            $selections[$i] = $chordVoicings[$i][0] ?? null;
        }

        $vlScores = [];
        for ($i = 0; $i < $n - 1; $i++) {
            if ($selections[$i] && $selections[$i + 1]) {
                $vlScores[] = $this->scoreVL($selections[$i], $selections[$i + 1], 1);
            } else {
                $vlScores[] = null;
            }
        }

        $result = [];
        foreach ($allChords as $i => $chord) {
            $sel = $selections[$i];
            $pool = $chordVoicings[$i] ?? [];
            $result[] = [
                'chord_name' => $chord['chord_name'],
                'roman_numeral' => $chord['roman_numeral'],
                'measure_index' => $chord['measure_index'],
                'voicing' => $sel ? $this->formatVoicing($sel, $chord['chord_name']) : null,
                'voicings' => array_values(array_map(fn($v) => $this->formatVoicing($v, $chord['chord_name']), $pool)),
            ];
        }

        $sections = [];
        if (isset($context['sections'])) {
            foreach ($context['sections'] as $sec) {
                $sections[] = [
                    'section_key' => $sec['section_key'] ?? '',
                    'length' => count($sec['chords']),
                ];
            }
        }

        return [
            'selections' => $result,
            'vl_scores' => $vlScores,
            'sections' => $sections,
            'diagnostics' => $diagnostics,
        ];
    }

    /**
     * Fetch simple-mode archetype candidates for a root/quality pair.
     */
    private function fetchSimpleModeVoicingsForChord(?string $root, string $quality): array
    {
        if (!$root) {
            return [];
        }

        $baseQuery = DB::table('sbn_chord_diagrams')
            ->where(function ($q) {
                $q->where('voicing_category', 'archetype')
                    ->orWhereNull('voicing_category')
                    ->orWhere('voicing_category', '');
            })
            ->whereRaw("(bass_note = '' OR bass_note IS NULL)")
            ->orderByDesc('popularity');

        $strictQuality = $this->normalizeSimpleLookupQuality($quality);
        $strictAliases = $this->getQualityAliases($strictQuality);

        $shapes = (clone $baseQuery)
            ->whereIn('quality', $strictAliases)
            ->limit(80)
            ->get();

        if ($shapes->isEmpty()) {
            $fallbackQuality = $this->simpleFallbackQuality($quality);
            if ($fallbackQuality === null) {
                return [];
            }

            $fallbackAliases = $this->getQualityAliases($fallbackQuality);
            $shapes = (clone $baseQuery)
                ->whereIn('quality', $fallbackAliases)
                ->limit(80)
                ->get();
        }

        if ($shapes->isEmpty()) {
            return [];
        }

        $results = [];
        foreach ($shapes as $shape) {
            $calculated = $this->calculator->calculateFrets($shape, $root);

            if (empty($calculated['diagram_data']) ||
                (empty($calculated['diagram_data']['positions']) && empty($calculated['diagram_data']['open']))) {
                continue;
            }

            $voicingCategory = $shape->voicing_category ?: 'archetype';

            $voicing = (object) [
                'id' => $shape->id,
                'root_note' => $root,
                'original_root' => $shape->root_note,
                'quality' => $shape->quality,
                'extensions' => $shape->extensions ?? '',
                'voicing_category' => $voicingCategory,
                'root_string' => $shape->root_string,
                'inversion' => $shape->inversion ?? 'root',
                'start_fret' => $calculated['start_fret'],
                'diagram_data' => (object) $calculated['diagram_data'],
                'interval_labels' => $calculated['interval_labels'] ?: ($shape->interval_labels ?? ''),
                'notes' => $calculated['notes'] ?? '',
                'popularity' => $shape->popularity ?? 0,
                'frets' => null,
            ];

            $voicing->frets = $this->buildFretString($calculated['diagram_data']);
            $results[] = $voicing;
        }

        usort($results, function ($a, $b) {
            $aRank = $this->archetypePriorityRank($a);
            $bRank = $this->archetypePriorityRank($b);

            if ($aRank !== $bRank) {
                return $aRank <=> $bRank;
            }

            return ($b->popularity ?? 0) <=> ($a->popularity ?? 0);
        });

        return $results;
    }

    private function normalizeSimpleLookupQuality(string $quality): string
    {
        return $quality === '7' ? 'dom7' : $quality;
    }

    private function simpleFallbackQuality(string $quality): ?string
    {
        $q = $this->normalizeSimpleLookupQuality(trim($quality));

        if ($q === '' || in_array($q, ['maj', 'dom7', '7', 'maj7', 'maj6', '6', '69', '9', '13', 'sus2', 'sus4', 'aug', '+'], true)) {
            return 'maj';
        }

        if (in_array($q, ['m', 'min', 'm7', 'm6', 'mMaj7', 'mMin7', 'm7b5', 'half-dim', 'dim', 'dim7', 'o7'], true)) {
            return 'min';
        }

        return 'maj';
    }

    private function archetypePriorityRank(object $voicing): int
    {
        $dd = $voicing->diagram_data ?? null;
        if (is_object($dd)) {
            $dd = json_decode(json_encode($dd), true);
        }
        if (!is_array($dd)) {
            return 2;
        }

        $barres = $dd['barres'] ?? [];
        $hasBarre = !empty($barres);
        $hasFullBarre = false;
        foreach ($barres as $barre) {
            $from = (int) ($barre['fromString'] ?? 0);
            $to = (int) ($barre['toString'] ?? 0);
            if ($from > 0 && $to > 0 && abs($to - $from) >= 4) {
                $hasFullBarre = true;
                break;
            }
        }

        if ($hasFullBarre) {
            return 2;
        }
        if ($hasBarre) {
            return 1;
        }

        $open = $dd['open'] ?? [];
        if (!empty($open)) {
            return 0;
        }

        return 1;
    }

    /**
     * Apply category-aware numeral upgrade (§6.1) to the harmonic context.
     *
     * Upgrades plain numerals to category-appropriate qualities:
     * - jazz/latin: I→Imaj7, IV→IVmaj7, IIm→IIm7, V→V7, etc.
     * - blues: I→I7, IV→IV7, V→V7
     * - modal: Im→Im7, IV→IV7
     * - pop/classical: no upgrade
     *
     * Only upgrades when the roman_numeral is plain (no quality suffix beyond m/o/+/b)
     * and the chord_name has no explicit quality. Pinned slots are never upgraded.
     */
    private function applyCategoryNumeralUpgrade(array $context, string $category): array
    {
        $pinnedSlot = $context['pinnedSlot'] ?? null;

        if (!isset($context['sections'])) {
            return $context;
        }

        foreach ($context['sections'] as $secIdx => $section) {
            foreach ($section['chords'] as $chordIdx => $chord) {
                $globalIdx = $this->globalChordIndex($context, $secIdx, $chordIdx);
                if ($globalIdx === $pinnedSlot) {
                    continue; // Never upgrade pinned slots
                }

                $romanNumeral = $chord['roman_numeral'] ?? '';
                $chordName = $chord['chord_name'] ?? '';
                $quality = $chord['quality'] ?? '';

                // Skip if roman numeral has explicit quality (e.g. Imaj7, V7b9)
                if (!$this->isPlainNumeral($romanNumeral)) {
                    continue;
                }

                // Skip if chord_name already has explicit quality
                if ($this->hasExplicitQuality($chordName)) {
                    continue;
                }

                $newQuality = $this->upgradeQualityForCategory($romanNumeral, $category, $chord['tonality'] ?? 'major');

                // Force upgrade for plain numerals (HarmonicContext may pre-fill quality)
                $context['sections'][$secIdx]['chords'][$chordIdx]['quality'] = $newQuality;
                // Update chord_name to reflect new quality
                $root = $chord['root'] ?? '';
                $newChordName = $this->composeChordName($root, $newQuality, $chord['extension'] ?? '');
                $context['sections'][$secIdx]['chords'][$chordIdx]['chord_name'] = $newChordName;
            }
        }

        return $context;
    }

    /**
     * Check if a Roman numeral is plain (no quality suffix beyond basic m/o/+/b).
     */
    private function isPlainNumeral(string $numeral): bool
    {
        // Plain numerals: I, II, III, IV, V, VI, VII, Im, IIm, etc.
        // Also with accidentals: bII, #III, etc.
        // Not plain if contains: 7, maj7, m7, dim, aug, sus, add, etc.
        $plainPattern = '/^(b|#)?[IViv]+m?$/';
        return (bool) preg_match($plainPattern, $numeral);
    }

    /**
     * Check if a chord name has an explicit quality beyond basic triad.
     */
    private function hasExplicitQuality(string $chordName): bool
    {
        // Explicit if contains: 7, maj7, m7, m9, dim, aug, sus, add, etc.
        // Basic triads: C, Cm, C#, F#m, etc. are not explicit
        $explicitPattern = '/(7|maj|m9|m6|mMaj|dim|aug|sus|add|b9|#9|#11|b13)/i';
        return (bool) preg_match($explicitPattern, $chordName);
    }

    /**
     * Upgrade a plain numeral to category-appropriate quality (§6.1).
     * Returns the new quality string (e.g. 'maj7', 'dom7', 'm7').
     */
    private function upgradeQualityForCategory(string $numeral, string $category, string $tonality): string
    {
        // Parse numeral to get degree and whether it's minor
        $isMinor = str_ends_with($numeral, 'm');
        $baseNumeral = rtrim($numeral, 'm');

        // Map degree to number (I=1, II=2, etc.)
        $degree = $this->romanToDegree($baseNumeral);

        return match ($category) {
            'jazz', 'latin' => $this->upgradeJazzLatin($numeral, $degree, $isMinor, $tonality),
            'blues' => $this->upgradeBlues($degree, $isMinor),
            'modal' => $this->upgradeModal($degree, $isMinor, $tonality),
            'pop', 'classical' => $isMinor ? 'min' : 'maj', // No upgrade, just normalize
            default => $isMinor ? 'min' : 'maj',
        };
    }

    private function upgradeJazzLatin(string $fullNumeral, int $degree, bool $isMinor, string $tonality): string
    {
        // Tonic family (I, IV) → maj7
        // bVI (minor subdominant) → maj7
        // II, III, VI → m7 (diatonic minor chords in major)
        // V, VII, bII, bIII, bVII → dom7
        // Uppercase + m (IIm, IIIm, IVm, VIm, VIIm) → m7
        // VIIm → m7b5 in major
        if ($isMinor) {
            if ($degree === 7) { // VIIm
                return 'm7b5';
            }
            return 'm7';
        }

        // Check if this is a tonic numeral (I or IV) - case-sensitive
        $isTonic = ($fullNumeral === 'I' || $fullNumeral === 'IV');

        if ($isTonic) {
            return 'maj7';
        }

        // bVI → maj7 (minor subdominant, not dominant)
        if ($fullNumeral === 'bVI') {
            return 'maj7';
        }

        // II, III, VI → m7 (diatonic minor chords)
        if ($degree === 2 || $degree === 3 || $degree === 6) {
            return 'm7';
        }

        // V, VII, bII, bIII, bVII → dom7
        return 'dom7';
    }

    private function upgradeBlues(int $degree, bool $isMinor): string
    {
        // I, IV, V → dom7
        if ($degree === 1 || $degree === 4 || $degree === 5) {
            return 'dom7';
        }
        return $isMinor ? 'min' : 'maj';
    }

    private function upgradeModal(int $degree, bool $isMinor, string $tonality): string
    {
        // Im → m7 (dorian, aeolian)
        // IV → 7 (dorian bluesy color)
        // bVII → plain triad (mixolydian)
        if ($isMinor) {
            if ($degree === 1) {
                return 'm7';
            }
            return 'min';
        }

        if ($degree === 4) {
            return 'dom7';
        }

        return 'maj';
    }

    /**
     * Convert Roman numeral to degree number (I=1, II=2, ..., VII=7).
     * Handles accidentals: bII=2, #III=4, etc.
     */
    private function romanToDegree(string $numeral): int
    {
        $romanMap = ['I' => 1, 'II' => 2, 'III' => 3, 'IV' => 4, 'V' => 5, 'VI' => 6, 'VII' => 7];
        
        // Remove quality suffixes (m, m7, m7b5, maj7, 7, etc.) and accidentals
        $base = strtoupper(preg_replace('/^[b#]|[mM]7(b5)?$|maj7?|7$|dim$|aug$/', '', $numeral));
        $degree = $romanMap[$base] ?? 1;

        // Handle accidentals
        if (str_starts_with($numeral, 'b')) {
            $degree = ($degree - 1 + 7) % 7;
            if ($degree === 0) $degree = 7;
        } elseif (str_starts_with($numeral, '#')) {
            $degree = ($degree + 1) % 7;
            if ($degree === 0) $degree = 7;
        }

        return $degree;
    }

    /**
     * Compose a chord name from root, quality, and extension.
     * // TODO(harmonic-scorer): extract to shared module — duplicated from ProgressionDetector
     */
    private function composeChordName(string $root, string $quality, string $extension): string
    {
        $name = $root;

        // Normalize quality for display
        $displayQuality = $this->qualityToSuffix($quality);
        if ($displayQuality) {
            $name .= $displayQuality;
        }

        if ($extension) {
            $name .= '(' . $extension . ')';
        }

        return $name;
    }

    /**
     * Map quality to Roman numeral suffix.
     * // TODO(harmonic-scorer): extract to shared module — duplicated from ProgressionDetector::qualityToSuffix
     */
    private function qualityToSuffix(string $quality): string
    {
        $q = $this->normalizeQuality($quality);

        $suffixMap = [
            'maj' => '',
            'dom7' => '7',
            '7' => '7',
            'maj7' => 'maj7',
            'min' => 'm',
            'm' => 'm',
            'm7' => 'm7',
            'm7b5' => 'm7b5',
            'dim' => 'o',
            'dim7' => 'o7',
            'o7' => 'o7',
            'aug' => '+',
            'aug7' => '7+',
            'sus4' => 'sus4',
            'sus2' => 'sus2',
            'maj6' => '6',
            'm6' => 'm6',
            'add9' => 'add9',
            '9' => '9',
            '11' => '11',
            '13' => '13',
        ];

        return $suffixMap[$q] ?? $q;
    }

    /**
     * Normalize quality for suffix mapping.
     * // TODO(harmonic-scorer): extract to shared module — duplicated from ProgressionDetector
     */
    private function normalizeQuality(string $quality): string
    {
        $q = trim($quality);

        // Handle common aliases
        $aliases = [
            'dom7' => '7',
            'dominant' => '7',
            'major' => 'maj',
            'minor' => 'm',
            'half-dim' => 'm7b5',
            'half-diminished' => 'm7b5',
        ];

        return $aliases[$q] ?? $q;
    }

    /**
     * Calculate global chord index from section and chord indices.
     */
    private function globalChordIndex(array $context, int $secIdx, int $chordIdx): int
    {
        $index = 0;
        for ($i = 0; $i < $secIdx; $i++) {
            $index += count($context['sections'][$i]['chords'] ?? []);
        }
        return $index + $chordIdx;
    }

    /**
     * Apply Phase E extension-based numeral upgrade (§6.2 and §9.2).
     * 
     * This implements Pass 2 numeral upgrade using the ExtensionTable:
     * - For each chord, look up recommended extensions based on role, target_role, and key_mode
     * - Apply top-priority extension set as the upgraded numeral
     * - Handle secondary dominant routing
     * - Store extension data for Pass 2 candidate generation
     * 
     * @param array $context The harmonic context
     * @param bool $extensionsEnabled Whether extensions are enabled in options
     * @return array Modified context with Phase E upgrades applied
     */
    private function applyPhaseEExtensionUpgrade(array $context, bool $extensionsEnabled): array
    {
        if (!$extensionsEnabled) {
            return $context;
        }

        if (!isset($context['sections'])) {
            return $context;
        }

        $pinnedSlot = $context['pinnedSlot'] ?? null;

        // First pass: collect all chords and determine functional roles
        $chordRoles = [];
        foreach ($context['sections'] as $secIdx => $section) {
            foreach ($section['chords'] as $chordIdx => $chord) {
                $globalIdx = $this->globalChordIndex($context, $secIdx, $chordIdx);
                $chordRoles[$globalIdx] = $this->determineFunctionalRole($chord);
            }
        }

        // Second pass: apply extensions based on functional context
        foreach ($context['sections'] as $secIdx => $section) {
            foreach ($section['chords'] as $chordIdx => $chord) {
                $globalIdx = $this->globalChordIndex($context, $secIdx, $chordIdx);
                if ($globalIdx === $pinnedSlot) {
                    continue; // Never upgrade pinned slots
                }

                $currentRole = $chordRoles[$globalIdx];
                $nextRole = $chordRoles[$globalIdx + 1] ?? null;
                $keyMode = $chord['tonality'] ?? 'major';

                // Handle secondary dominant routing
                if ($this->isSecondaryDominant($currentRole, $chord)) {
                    $targetQuality = $this->getTargetChordQuality($context, $globalIdx + 1);
                    $routing = ExtensionTable::routeSecondaryDominant($targetQuality);
                    // Map routing to appropriate target role for extension lookup
                    $nextRole = $this->mapRoutingToTargetRole($routing);
                }

                // Get recommended extensions
                $extensions = ExtensionTable::getTopExtensions($currentRole, $nextRole, $keyMode);
                
                if ($extensions !== null) {
                    $topExtension = $extensions['extensions'][0] ?? null;
                    if ($topExtension !== null) {
                        // Apply the top-priority extension set, filtering out forbidden tones
                        $extensionTones = $topExtension['tones'] ?? [];
                        $forbid = $extensions['forbid'] ?? [];
                        if (!empty($forbid)) {
                            $extensionTones = array_values(array_diff($extensionTones, $forbid));
                        }
                        $context['sections'][$secIdx]['chords'][$chordIdx]['phase_e_extensions'] = $extensionTones;
                        
                        // Update chord name to include extensions
                        $root = $chord['root'] ?? '';
                        $quality = $chord['quality'] ?? '';
                        $extensionString = $this->formatExtensionString($extensionTones);
                        $newChordName = $this->composeChordName($root, $quality, $extensionString);
                        $context['sections'][$secIdx]['chords'][$chordIdx]['chord_name'] = $newChordName;
                        $context['sections'][$secIdx]['chords'][$chordIdx]['extension'] = $extensionString;
                    } else {
                        // No extensions available - set empty extension field
                        $context['sections'][$secIdx]['chords'][$chordIdx]['extension'] = '';
                    }
                } else {
                    // No extensions found - set empty extension field
                    $context['sections'][$secIdx]['chords'][$chordIdx]['extension'] = '';
                }
            }
        }

        return $context;
    }

    /**
     * Determine the functional role of a chord based on its quality and position
     */
    private function determineFunctionalRole(array $chord): string
    {
        $quality = $chord['quality'] ?? '';
        $romanNumeral = $chord['roman_numeral'] ?? '';
        
        // Map quality to functional role
        if (in_array($quality, ['dom7', '7', '7alt', '7#11', '7b9', '7#9', '7b13', '7b9b13', '9', '13'])) {
            // Check if it's a secondary dominant (not on scale degree V)
            $degree = $this->romanToDegree($romanNumeral);
            if ($degree === 5) {
                return 'V7';
            } elseif (str_contains($romanNumeral, 'bII')) {
                return 'bII7'; // tritone substitute
            } elseif (str_contains($romanNumeral, 'bVI')) {
                return 'bVI7'; // minor blues dominant
            } elseif (str_contains($romanNumeral, 'bVII')) {
                return 'bVII7'; // backdoor dominant
            } else {
                return 'V7'; // secondary dominant - will be routed later
            }
        }
        
        if (in_array($quality, ['maj7', 'maj6', 'maj9', 'maj13', '6', '69', 'maj'])) {
            $degree = $this->romanToDegree($romanNumeral);
            if ($degree === 1) {
                return $quality === '6' || $quality === '69' ? 'I6' : 'Imaj7';
            } elseif ($degree === 4) {
                return 'IVmaj7';
            }
            return 'Imaj7';
        }
        
        if (in_array($quality, ['m7', 'm6', 'm7b5', 'mMaj7', 'min', 'mMin7'])) {
            $degree = $this->romanToDegree($romanNumeral);
            if ($degree === 1) {
                return $quality === 'm6' ? 'Im6' : ($quality === 'mMaj7' ? 'ImMaj7' : 'Im7');
            } elseif ($degree === 2) {
                return $quality === 'm7b5' ? 'IIm7b5' : 'IIm7';
            } elseif ($degree === 3) {
                return $quality === 'm7b5' ? 'IIIm7b5' : 'IIIm7';
            } elseif ($degree === 6) {
                return $quality === 'm7b5' ? 'VIm7b5' : 'VIm7';
            }
            return 'Im7';
        }
        
        if (in_array($quality, ['dim7', 'dim', 'o7'])) {
            return 'dim7';
        }
        
        // Default fallback
        return 'Imaj7';
    }

    /**
     * Check if a chord is a secondary dominant
     */
    private function isSecondaryDominant(string $role, array $chord): bool
    {
        if ($role !== 'V7') {
            return false;
        }
        
        $romanNumeral = $chord['roman_numeral'] ?? '';
        $degree = $this->romanToDegree($romanNumeral);
        
        // V7 is primary, others are secondary
        return $degree !== 5;
    }

    /**
     * Get target chord quality for secondary dominant routing
     */
    private function getTargetChordQuality(array $context, int $targetIndex): string
    {
        // Find the target chord by global index
        $currentIndex = 0;
        foreach ($context['sections'] as $section) {
            foreach ($section['chords'] as $chord) {
                if ($currentIndex === $targetIndex) {
                    return $chord['quality'] ?? 'maj7';
                }
                $currentIndex++;
            }
        }
        
        return 'maj7'; // default fallback
    }

    /**
     * Map routing description to target role for extension lookup
     */
    private function mapRoutingToTargetRole(string $routing): string
    {
        if (str_contains($routing, 'Im (minor-target rules)')) {
            return 'Im';
        } elseif (str_contains($routing, 'Imaj7 (major-target rules)')) {
            return 'Imaj7';
        } elseif (str_contains($routing, 'V7 (dominant-chain rules)')) {
            return 'V7';
        }
        
        return 'Imaj7'; // default fallback
    }

    /**
     * Format extension tones as a string for chord names
     */
    private function formatExtensionString(array $tones): string
    {
        if (empty($tones)) {
            return '';
        }
        
        // Sort tones according to conventional order
        $order = ['b9', '9', '#9', '11', '#11', 'b13', '13'];
        $sorted = [];
        foreach ($order as $tone) {
            if (in_array($tone, $tones)) {
                $sorted[] = $tone;
            }
        }
        
        return implode(',', $sorted);
    }

    /**
     * Build a display fret string from diagram_data.
     * e.g. "x57565" — 6 chars, one per string, 'x' for muted.
     */
    private function buildFretString(array $diagramData): string
    {
        $frets = array_fill(0, 6, 'x');

        foreach ($diagramData['open'] ?? [] as $s) {
            if ($s >= 1 && $s <= 6) $frets[$s - 1] = '0';
        }
        foreach ($diagramData['positions'] ?? [] as $pos) {
            $s = (int) ($pos['string'] ?? 0);
            $f = $pos['fret'] ?? null;
            if ($s >= 1 && $s <= 6 && $f !== null && $f !== 'x' && $f !== -1) {
                $frets[$s - 1] = $f <= 9 ? (string) $f : dechex($f);
            }
        }
        foreach ($diagramData['barres'] ?? [] as $barre) {
            $from = min($barre['fromString'] ?? 1, $barre['toString'] ?? 6);
            $to   = max($barre['fromString'] ?? 1, $barre['toString'] ?? 6);
            for ($s = $from; $s <= $to; $s++) {
                if ($s >= 1 && $s <= 6 && $frets[$s - 1] === 'x') {
                    $f = $barre['fret'] ?? 0;
                    $frets[$s - 1] = $f <= 9 ? (string) $f : dechex($f);
                }
            }
        }

        return implode('', $frets);
    }

    /**
     * Get quality aliases for DB matching.
     */
    private function getQualityAliases(string $quality): array
    {
        $map = [
            'dom7' => ['dom7', '7'],
            '7'    => ['dom7', '7'],
            'min'  => ['min', 'm'],
            'm'    => ['min', 'm'],
            'maj'  => ['maj'],
            'maj7' => ['maj7'],
            'm7'   => ['m7'],
            'm7b5' => ['m7b5'],
            'o7'   => ['o7', 'dim7'],
            'dim7' => ['o7', 'dim7'],
            'dim'  => ['dim'],
            'aug'  => ['aug', '+'],
            'mMaj7' => ['mMaj7'],
            'aug7'  => ['aug7'],
            'maj6'  => ['maj6'],
            'm6'    => ['m6'],
            'sus4'  => ['sus4'],
            'sus2'  => ['sus2'],
        ];

        return $map[$quality] ?? [$quality];
    }

    /**
     * Widen the tonic-family quality aliases for jazz/latin tonic slots.
     *
     * In jazz/latin practice, a tonic-major chord can be voiced as maj7, 6,
     * 6/9 or maj6 interchangeably; a tonic-minor chord can be voiced as m7
     * or m6. Letting Viterbi see all of these in the candidate pool produces
     * idiomatic substitutions without requiring the upgrade table to enumerate
     * every quality. The widening is gated on functional role so that
     * predominant minor (IIm7, VIm7, etc.) does NOT pull m6 — m6 implies
     * tonic-minor and would change the function.
     */
    private function expandTonicFamilyAliases(
        array $aliases,
        string $dbQuality,
        string $category,
        ?string $functionalRole
    ): array {
        if (!$this->settings->isTonicWidenEnabled($category) || $functionalRole === null) {
            return $aliases;
        }

        $tonicMajorRoles = ['Imaj7', 'I6'];
        $tonicMinorRoles = ['Im7', 'Im6', 'ImMaj7'];

        if (in_array($functionalRole, $tonicMajorRoles, true)
            && in_array($dbQuality, ['maj7', 'maj6', '6'], true)) {
            return array_values(array_unique(array_merge($aliases, ['maj7', 'maj6', '6'])));
        }

        if (in_array($functionalRole, $tonicMinorRoles, true)
            && in_array($dbQuality, ['m7', 'm6'], true)) {
            return array_values(array_unique(array_merge($aliases, ['m7', 'm6'])));
        }

        return $aliases;
    }

    /**
     * Apply harmonic suitability filter for a chord slot.
     * Removes voicings with harmonically inappropriate extensions.
     */
    private function applyHarmonyFilter(array $pool, array $allChords, int $index): array
    {
        $chord = $allChords[$index];
        $quality = $this->resolveQuality($chord);
        if (!$quality) return $pool;

        $nextChord = $allChords[$index + 1] ?? null;
        $nextQuality = $nextChord ? $this->resolveQuality($nextChord) : '';
        $nextIsMinor = $this->isMinorQuality($nextQuality);

        $filtered = array_filter($pool, function ($v) use ($quality, $nextIsMinor, $chord, $nextChord, $index, $allChords) {
            $labels = array_map('trim', explode(',', $v->interval_labels ?? ''));
            $ext = trim($v->extensions ?? '');

            // m7b5: exclude natural 9
            if ($this->isHalfDimQuality($quality)) {
                if (in_array('9', $labels, true)) return false;
            }

            // dom7 → minor: exclude natural 13, natural 9, #9
            if ($this->isDomQuality($quality) && $nextIsMinor) {
                foreach ($labels as $l) {
                    if (in_array($l, ['13', '6', '9', '#9'], true)) return false;
                }
            }

            // Tonic maj7: exclude #11 (unless numeral explicitly requests it)
            if ($this->isTonicMajQuality($quality)) {
                if (in_array('#11', $labels, true)) return false;
                if (str_contains($ext, '#11')) return false;
            }

            // Phase E avoid tones filter (E.1.3)
            if ($this->hasPhaseEExtensions($chord)) {
                // Determine functional context for avoid tones checking
                $currentRole = $this->determineFunctionalRole($chord);
                $nextRole = $nextChord ? $this->determineFunctionalRole($nextChord) : null;
                $keyMode = $chord['tonality'] ?? 'major';
                
                // Create context string for avoid tones lookup
                $contextString = $this->createAvoidTonesContext($currentRole, $nextRole, $keyMode);
                
                // Check each extension tone against avoid tones index
                foreach ($labels as $tone) {
                    if (ExtensionTable::isToneForbidden($contextString, $tone)) {
                        return false;
                    }
                }
            }

            return true;
        });

        return !empty($filtered) ? array_values($filtered) : $pool;
    }

    /**
     * Check if a chord has Phase E extensions applied
     */
    private function hasPhaseEExtensions(array $chord): bool
    {
        return isset($chord['phase_e_extensions']) && !empty($chord['phase_e_extensions']);
    }

    /**
     * Create context string for avoid tones lookup
     */
    private function createAvoidTonesContext(string $currentRole, ?string $nextRole, string $keyMode): string
    {
        // Map to the context strings used in avoid_tones_index
        if ($currentRole === 'V7' && $nextRole) {
            if ($nextRole === 'Imaj7') {
                return 'V7 → Imaj7';
            } elseif ($nextRole === 'Im') {
                return 'V7 → Im';
            }
        } elseif ($currentRole === 'bII7' && $nextRole === 'Imaj7') {
            return 'bII7 → Imaj7';
        }
        
        // For other contexts, use the role directly
        return $currentRole;
    }

    // =========================================================================
    // ANCHOR-FREE LATTICE
    // =========================================================================

    private function buildAnchorFreeLattice(
        array $chordVoicings,
        ?string $group,
        bool $rootOnly,
        string $category
    ): array {
        $lattice = [];
        foreach ($chordVoicings as $slotVoicings) {
            $pool = array_filter($slotVoicings, function ($v) use ($rootOnly) {
                if ($this->isOpenVoicing($v)) return false;
                if ($rootOnly && ($v->inversion ?? 'root') !== 'root') return false;
                return true;
            });
            $lattice[] = array_values($pool);
        }
        return $lattice;
    }

    // =========================================================================
    // VOICE LEADING SCORE
    // =========================================================================

    /**
     * Score the voice leading quality between two voicings.
     * Lower = better. Port of JS scoreVL().
     *
     * Components:
     *   1. Guide tone resolution (b7→3, 3→R/b7, Level II extensions)
     *   1b. Wrong-alteration penalties (dom→minor clashes)
     *   2. General guide tone proximity (fallback)
     *   3. Common tones on same string (bonus)
     *   4. Fret distance (capped)
     *   5. String set continuity
     */

    /**
     * Phase 6f — Select one voicing per chord from a plain-symbol sequence.
     *
     * Naive placeholder: Shell category first, else first available.
     * Opus replaces the selection logic here with voice-leading optimisation.
     *
     * @param  array  $chordNames  ['Dm7', 'G7', 'Cmaj7', ...]
     * @param  string $key         Song key, e.g. 'C'
     * @param  array  $options     { category?: string }
     * @return array  [ ['chord_name'=>'Dm7','frets'=>'x57565','position'=>5,'diagram_id'=>42], ... ]
     */
    public function selectVoicingsForSequence(array $chordNames, string $key, array $options = []): array
    {
        $category = $options['category'] ?? '';
        if ($category === '') $category = 'pop';

        $hc = app(HarmonicContext::class)->buildFromChordSequence($key, $chordNames);
        $result = $this->buildVoicings($hc, ['category' => $category, 'extensions' => false]);

        $out = [];
        foreach ($result['selections'] ?? [] as $sel) {
            $voicing = $sel['voicing'] ?? null;
            $out[] = [
                'chord_name' => $sel['chord_name'] ?? '?',
                'frets'      => $voicing['frets'] ?? null,
                'position'   => $voicing['start_fret'] ?? 1,
                'diagram_id' => $voicing['id'] ?? null,
            ];
        }
        return $out;
    }

    public function scoreVL(object $a, object $b, int $level = 1): float
    {
        $pitchesA = $this->buildPitchMap($a);
        $pitchesB = $this->buildPitchMap($b);

        // Fallback if diagram data unavailable
        if (empty($pitchesA) || empty($pitchesB)) {
            $avgA = $this->averageFret($a);
            $avgB = $this->averageFret($b);
            return ($avgA && $avgB) ? round(abs($avgA - $avgB), 2) : 5.0;
        }

        $score = 0.0;

        // Derive qualities
        $qualityA = $this->resolveVoicingQuality($a);
        $qualityB = $this->resolveVoicingQuality($b);

        $sourceIsDom    = $this->isDomQuality($qualityA);
        $targetIsMinor  = $this->isMinorQuality($qualityB);
        $targetIsMaj    = $this->isTonicMajQuality($qualityB);
        $sourceIsHalfDim = $this->isHalfDimQuality($qualityA);

        // ── 1. GUIDE TONE RESOLUTION ────────────────────────────────

        $seventhsA = $this->filterByLabels($pitchesA, self::SEVENTH_LABELS);
        $thirdsA   = $this->filterByLabels($pitchesA, self::THIRD_LABELS);
        $thirdsB   = $this->filterByLabels($pitchesB, self::THIRD_LABELS);
        $rootsB    = $this->filterByLabels($pitchesB, self::ROOT_LABELS);
        $seventhsB = $this->filterByLabels($pitchesB, self::SEVENTH_LABELS);

        // Level II targets
        $maj7sB    = $level >= 2 ? $this->filterByLabels($pitchesB, self::MAJ7_LABELS) : [];
        $ninthsB   = $level >= 2 ? $this->filterByLabels($pitchesB, self::NINTH_LABELS) : [];
        $fifthsB   = $level >= 2 ? $this->filterByLabels($pitchesB, self::FIFTH_LABELS) : [];
        $fifthsA   = $level >= 2 ? $this->filterByLabels($pitchesA, self::FIFTH_LABELS) : [];
        $eleventhsA = $level >= 2 ? $this->filterByLabels($pitchesA, self::ELEVENTH_LABELS) : [];

        // Ninths (filtered for minor target)
        $ninthsA = [];
        if ($level >= 2) {
            foreach ($pitchesA as $p) {
                if (!isset(self::NINTH_LABELS[$p['label']])) continue;
                if ($targetIsMinor && $p['label'] !== 'b9') continue;
                $ninthsA[] = $p;
            }
        }

        // Sixths (filtered for minor target)
        $sixthsB = [];
        if ($level >= 2) {
            foreach ($pitchesB as $p) {
                if (!isset(self::SIXTH_LABELS[$p['label']])) continue;
                if ($targetIsMinor && $p['label'] !== 'b13') continue;
                $sixthsB[] = $p;
            }
        }

        $resPenalties = [];

        // b7 → 3/b3
        foreach ($seventhsA as $seventh) {
            $bestDist = 99;
            foreach ($thirdsB as $third) {
                $d = abs($seventh['midi'] - $third['midi']);
                if ($d < $bestDist) $bestDist = $d;
            }
            $resPenalties[] = $this->distToPenalty($bestDist);
        }

        // 3 → R, b7, maj7, 9 of next
        foreach ($thirdsA as $third) {
            $bestDist = 99;
            foreach (array_merge($rootsB, $seventhsB, $maj7sB, $ninthsB) as $t) {
                $d = abs($third['midi'] - $t['midi']);
                if ($d < $bestDist) $bestDist = $d;
            }
            $resPenalties[] = $this->distToPenalty($bestDist);
        }

        // Level II: 9/b9 → 13/b13, 9, R, 5
        if ($level >= 2) {
            foreach ($ninthsA as $ninth) {
                $bestDist = 99;
                foreach (array_merge($sixthsB, $ninthsB, $rootsB, $fifthsB) as $t) {
                    $d = abs($ninth['midi'] - $t['midi']);
                    if ($d < $bestDist) $bestDist = $d;
                }
                if ($bestDist < 99) $resPenalties[] = $this->distToPenalty($bestDist);
            }

            // #11 → 5, 3, 9
            foreach ($eleventhsA as $eleventh) {
                $bestDist = 99;
                foreach (array_merge($fifthsB, $thirdsB, $ninthsB) as $t) {
                    $d = abs($eleventh['midi'] - $t['midi']);
                    if ($d < $bestDist) $bestDist = $d;
                }
                if ($bestDist < 99) $resPenalties[] = $this->distToPenalty($bestDist);
            }

            // 5 of m7 → R, 5 of next
            foreach ($fifthsA as $fifth) {
                $bestDist = 99;
                foreach (array_merge($rootsB, $fifthsB) as $t) {
                    $d = abs($fifth['midi'] - $t['midi']);
                    if ($d < $bestDist) $bestDist = $d;
                }
                if ($bestDist < 99) $resPenalties[] = $this->distToPenalty($bestDist);
            }
        }

        if (!empty($resPenalties)) {
            $score += array_sum($resPenalties);
        }

        // ── 2. GENERAL GUIDE TONE PROXIMITY (fallback) ──────────────

        if (empty($resPenalties)) {
            $guidesA = $this->filterByLabels($pitchesA, self::GUIDE_LABELS);
            $guidesB = $this->filterByLabels($pitchesB, self::GUIDE_LABELS);

            if (!empty($guidesA) && !empty($guidesB)) {
                $guideScore = 0;
                foreach ($guidesA as $ga) {
                    $minDist = 99;
                    foreach ($guidesB as $gb) {
                        $d = abs($ga['midi'] - $gb['midi']);
                        if ($d < $minDist) $minDist = $d;
                    }
                    $guideScore += $minDist;
                }
                $score += ($guideScore / count($guidesA)) * 1.5;
            }
        }

        // ── 3. COMMON TONES ─────────────────────────────────────────

        $commonSameString = 0;
        foreach ($pitchesA as $pa) {
            foreach ($pitchesB as $pb) {
                if ($pa['string'] === $pb['string'] && $pa['midi'] === $pb['midi']) {
                    $commonSameString++;
                }
            }
        }

        $pcSetA = array_unique(array_map(fn($p) => $p['midi'] % 12, $pitchesA));
        $pcSetB = array_unique(array_map(fn($p) => $p['midi'] % 12, $pitchesB));
        $commonAny = count(array_intersect($pcSetA, $pcSetB));

        $score = max(0, $score - $commonSameString * 1.5 - $commonAny * 0.3);

        // ── 4. FRET DISTANCE (capped) ───────────────────────────────

        $avgA = $this->averageFret($a);
        $avgB = $this->averageFret($b);
        $fretDist = abs($avgA - $avgB);
        $score += min($fretDist * 0.4, 3.0);

        // ── 5. STRING SET CONTINUITY ────────────────────────────────

        $setA = $this->upperStringSet($a);
        $setB = $this->upperStringSet($b);
        if ($setA && $setB) {
            if ($setA === $setB) {
                $score = max(0, $score - 1.5);
            } else {
                $score += 3;
            }
        } elseif (($a->root_string ?? '') && ($b->root_string ?? '')
                  && $a->root_string === $b->root_string) {
            $score = max(0, $score - 1);
        }

        return round($score, 2);
    }

    public function costBetween(object $v1, object $v2, array $context = []): float
    {
        $breakdown = $this->costBreakdown($v1, $v2, $context);
        return $breakdown['total'];
    }

    private function costBreakdown(object $v1, object $v2, array $context = []): array
    {
        $weights = $this->costWeights($context['weight_overrides'] ?? [], $context['category'] ?? self::CATEGORY_DEFAULT);
        $rawVoiceLeading = $this->scoreVL($v1, $v2, $context['vlLevel'] ?? 1);

        $terms = [
            'simplicity' => $this->costSimplicity($v2, $context),
            'position' => $this->costPosition($v1, $v2),
            'bass_motion' => $this->costBassMotion($v1, $v2),
            'common_tone' => $this->costCommonTone($v1, $v2),
            'voice_leading' => $this->boundedCost($rawVoiceLeading / self::SCORE_VL_NORMALIZER),
            'group_continuity' => $this->costGroupContinuity($v1, $v2),
            'register' => $this->costRegister($v2, $context),
            'style' => $this->costStyle($v2, $context),
        ];

        // Phase E named resolutions (E.1.4)
        $namedResolutionBonus = 0.0;
        $firedResolutions = [];
        if (($context['vlLevel'] ?? 1) >= 2) {
            $resolutionResult = $this->evaluateNamedResolutions($v1, $v2, $context);
            $namedResolutionBonus = $resolutionResult['total_bonus'];
            $firedResolutions = $resolutionResult['fired_ids'];
        }

        $terms['named_resolutions'] = $namedResolutionBonus;

        $weighted = [];
        $total = 0.0;
        foreach ($terms as $term => $value) {
            $weighted[$term] = round($value * ($weights[$term] ?? 0.0), 4);
            $total += $weighted[$term];
        }

        return array_merge($terms, [
            'raw_voice_leading' => $rawVoiceLeading,
            'weighted' => $weighted,
            'weighted_total' => round($total, 4),
            'total' => round($total, 4),
            'fired_named_resolutions' => $firedResolutions,
        ]);
    }

    private function costWeights(array $overrides, string $category = self::CATEGORY_DEFAULT): array
    {
        $weights = $this->settings->getCostWeights();
        $weights['register'] = $this->settings->getCategoryRegisterWeight($category);
        return array_merge($weights, array_intersect_key($overrides, $weights));
    }

    /**
     * Evaluate Phase E named resolutions for a voicing transition
     * 
     * @param object $v1 Source voicing
     * @param object $v2 Target voicing
     * @param array $context Context including chord information
     * @return array ['total_bonus' => float, 'fired_ids' => array]
     */
    private function evaluateNamedResolutions(object $v1, object $v2, array $context): array
    {
        $totalBonus = 0.0;
        $firedIds = [];

        // Get chord information from context
        $sourceChord = $context['source_chord'] ?? null;
        $targetChord = $context['target_chord'] ?? null;
        
        if (!$sourceChord || !$targetChord) {
            return ['total_bonus' => 0.0, 'fired_ids' => []];
        }

        // Get MIDI notes from both voicings
        $sourceNotes = $this->getVoicingMidiNotes($v1);
        $targetNotes = $this->getVoicingMidiNotes($v2);

        // Get pitch classes (0-11) for root notes
        $sourceRootPc = $this->noteNameToPitchClass($sourceChord['root'] ?? 'C');
        $targetRootPc = $this->noteNameToPitchClass($targetChord['root'] ?? 'C');

        // Determine functional roles
        $sourceRole = $this->determineFunctionalRole($sourceChord);
        $targetRole = $this->determineFunctionalRole($targetChord);

        // Get all named resolutions and test each one
        $resolutions = ExtensionTable::getNamedResolutions();
        foreach ($resolutions as $resolution) {
            if ($this->testNamedResolution($resolution, $sourceNotes, $targetNotes, 
                                        $sourceRootPc, $targetRootPc, $sourceRole, $targetRole)) {
                $totalBonus += $resolution['bonus'];
                $firedIds[] = $resolution['id'];
            }
        }

        return [
            'total_bonus' => $totalBonus,
            'fired_ids' => $firedIds
        ];
    }

    /**
     * Test if a specific named resolution fires on this transition
     */
    private function testNamedResolution(array $resolution, array $sourceNotes, array $targetNotes,
                                       int $sourceRootPc, int $targetRootPc, 
                                       string $sourceRole, string $targetRole): bool
    {
        $result = $this->testNamedResolutionWithDebug($resolution, $sourceNotes, $targetNotes,
                                                     $sourceRootPc, $targetRootPc, $sourceRole, $targetRole);
        return $result['fired'];
    }

    private function testNamedResolutionWithDebug(array $resolution, array $sourceNotes, array $targetNotes,
                                                 int $sourceRootPc, int $targetRootPc, 
                                                 string $sourceRole, string $targetRole): array
    {
        // Check role matching (support 'any_tonic' wildcard)
        $sourceMatch = $resolution['source']['role'] === $sourceRole;
        $targetMatch = $resolution['target']['role'] === $targetRole || 
                      $resolution['target']['role'] === 'any_tonic';

        if (!$sourceMatch || !$targetMatch) {
            $reason = "role_mismatch";
            if (!$sourceMatch) $reason .= " (source: {$resolution['source']['role']} != $sourceRole)";
            if (!$targetMatch) $reason .= " (target: {$resolution['target']['role']} != $targetRole)";
            return ['fired' => false, 'reason' => $reason];
        }

        // Check if source tone is present in source voicing
        $sourceTone = $resolution['source']['tone'];
        if (!ExtensionTable::isToneInVoicing($sourceNotes, $sourceRootPc, $sourceTone)) {
            return ['fired' => false, 'reason' => "tone_not_present (source: $sourceTone)"];
        }

        // Check if target tone is present in target voicing
        $targetTone = $resolution['target']['tone'];
        if (!ExtensionTable::isToneInVoicing($targetNotes, $targetRootPc, $targetTone)) {
            return ['fired' => false, 'reason' => "tone_not_present (target: $targetTone)"];
        }

        // Check motion constraint
        $expectedSemitones = $resolution['motion']['semitones'];
        $sameVoice = $resolution['motion']['same_voice'];

        if ($sameVoice) {
            // Same voice constraint: same pitch rank in both voicings
            $motionResult = $this->testSameVoiceMotion($sourceNotes, $targetNotes, 
                                                      $sourceRootPc, $targetRootPc,
                                                      $sourceTone, $targetTone, $expectedSemitones);
            return ['fired' => $motionResult, 'reason' => $motionResult ? "fired" : "same_voice_mismatch"];
        } else {
            // Any voice pair constraint (not currently used in YAML)
            $motionResult = $this->testAnyVoiceMotion($sourceNotes, $targetNotes,
                                                     $sourceRootPc, $targetRootPc,
                                                     $sourceTone, $targetTone, $expectedSemitones);
            return ['fired' => $motionResult, 'reason' => $motionResult ? "fired" : "motion_mismatch"];
        }
    }

    /**
     * Test same_voice motion constraint
     */
    private function testSameVoiceMotion(array $sourceNotes, array $targetNotes,
                                       int $sourceRootPc, int $targetRootPc,
                                       string $sourceTone, string $targetTone, int $expectedSemitones): bool
    {
        // Get sounded notes (non-muted) and sort by pitch
        $soundedSource = array_filter($sourceNotes, fn($note) => $note !== -1);
        $soundedTarget = array_filter($targetNotes, fn($note) => $note !== -1);
        
        sort($soundedSource);
        sort($soundedTarget);

        // Get target pitch classes for the tones
        $sourceOffset = Interval::offset($sourceTone);
        $targetOffset = Interval::offset($targetTone);
        $sourceTargetPc = ($sourceRootPc + $sourceOffset) % 12;
        $targetTargetPc = ($targetRootPc + $targetOffset) % 12;

        // Check each voice position
        $maxIndex = min(count($soundedSource), count($soundedTarget));
        for ($i = 0; $i < $maxIndex; $i++) {
            $sourceNote = $soundedSource[$i];
            $targetNote = $soundedTarget[$i];

            // Check if this voice position contains both required tones
            if (($sourceNote % 12) === $sourceTargetPc && ($targetNote % 12) === $targetTargetPc) {
                // Check semitone motion
                $actualSemitones = $targetNote - $sourceNote;
                if ($actualSemitones === $expectedSemitones) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Test any voice motion constraint (not currently used)
     */
    private function testAnyVoiceMotion(array $sourceNotes, array $targetNotes,
                                      int $sourceRootPc, int $targetRootPc,
                                      string $sourceTone, string $targetTone, int $expectedSemitones): bool
    {
        // Get target pitch classes for the tones
        $sourceOffset = Interval::offset($sourceTone);
        $targetOffset = Interval::offset($targetTone);
        $sourceTargetPc = ($sourceRootPc + $sourceOffset) % 12;
        $targetTargetPc = ($targetRootPc + $targetOffset) % 12;

        // Find all instances of each tone in each voicing
        $sourceInstances = [];
        $targetInstances = [];

        foreach ($sourceNotes as $note) {
            if ($note !== -1 && ($note % 12) === $sourceTargetPc) {
                $sourceInstances[] = $note;
            }
        }

        foreach ($targetNotes as $note) {
            if ($note !== -1 && ($note % 12) === $targetTargetPc) {
                $targetInstances[] = $note;
            }
        }

        // Check any pair for correct motion
        foreach ($sourceInstances as $sourceNote) {
            foreach ($targetInstances as $targetNote) {
                if (($targetNote - $sourceNote) === $expectedSemitones) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get MIDI notes from a voicing object
     */
    private function getVoicingMidiNotes(object $voicing): array
    {
        $notes = [];
        
        // Handle different voicing data formats
        if (isset($voicing->midi_notes)) {
            $notes = $voicing->midi_notes;
        } elseif (isset($voicing->diagram_data)) {
            // Extract from diagram data
            $diagram = $voicing->diagram_data;
            $frets = $diagram->frets ?? [];
            
            // If diagram_data frets is empty, try the direct frets property
            if (empty($frets) && isset($voicing->frets)) {
                $frets = $voicing->frets;
            }
            
            for ($string = 0; $string < 6; $string++) {
                $fret = $frets[$string] ?? 'x';
                if ($fret === 'x' || $fret === -1) {
                    $notes[] = -1; // muted
                } else {
                    $openMidi = self::OPEN_MIDI[$string + 1];
                    $notes[] = $openMidi + (int)$fret;
                }
            }
        }
        
        return $notes;
    }

    /**
     * Convert note name to pitch class (0-11)
     */
    private function noteNameToPitchClass(string $noteName): int
    {
        $noteMap = [
            'C' => 0, 'C#' => 1, 'Db' => 1, 'D' => 2, 'D#' => 3, 'Eb' => 3,
            'E' => 4, 'F' => 5, 'F#' => 6, 'Gb' => 6, 'G' => 7, 'G#' => 8, 'Ab' => 8,
            'A' => 9, 'A#' => 10, 'Bb' => 10, 'B' => 11,
        ];
        
        return $noteMap[$noteName] ?? 0;
    }

    /**
     * Calculate total path cost for a selection path
     */
    private function calculatePathCost(array $selections, array $allChords, array $context): float
    {
        $totalCost = 0.0;
        $n = count($selections);
        
        for ($i = 0; $i < $n - 1; $i++) {
            if ($selections[$i] && $selections[$i + 1]) {
                $edgeContext = array_merge($context, [
                    'source_chord' => $allChords[$i] ?? null,
                    'target_chord' => $allChords[$i + 1] ?? null,
                ]);
                $totalCost += $this->costBetween($selections[$i], $selections[$i + 1], $edgeContext);
            }
        }
        
        return $totalCost;
    }

    /**
     * Calculate path cost and collect fired named resolutions
     */
    private function calculatePathCostWithResolutions(array $selections, array $allChords, array $context): array
    {
        $totalCost = 0.0;
        $allFiredResolutions = [];
        $n = count($selections);
        
        for ($i = 0; $i < $n - 1; $i++) {
            if ($selections[$i] && $selections[$i + 1]) {
                $edgeContext = array_merge($context, [
                    'source_chord' => $allChords[$i] ?? null,
                    'target_chord' => $allChords[$i + 1] ?? null,
                ]);
                $breakdown = $this->costBreakdown($selections[$i], $selections[$i + 1], $edgeContext);
                $totalCost += $breakdown['total'];
                
                // Collect fired named resolutions
                if (isset($breakdown['fired_named_resolutions'])) {
                    $allFiredResolutions = array_merge($allFiredResolutions, $breakdown['fired_named_resolutions']);
                }
            }
        }
        
        return [
            'cost' => $totalCost,
            'fired_resolutions' => array_unique($allFiredResolutions),
        ];
    }

    /**
     * Apply Pass 2 vs Pass 1 decision rule (E.1.5)
     * 
     * @param array $pass1Selections Pass 1 voicing selections
     * @param ?float $pass1Cost Pass 1 total path cost
     * @param array $pass2Selections Pass 2 voicing selections  
     * @param ?float $pass2Cost Pass 2 total path cost
     * @param array $pass2FiredResolutions Named resolutions fired in Pass 2
     * @return bool True if Pass 2 wins, false if Pass 1 wins
     */
    private function applyPass2DecisionRule(
        array $pass1Selections, ?float $pass1Cost,
        array $pass2Selections, ?float $pass2Cost, array $pass2FiredResolutions
    ): bool {
        // Guard against failed searches
        if (empty($pass2Selections)) {
            return false; // Pass 1 wins
        }
        if (empty($pass1Selections)) {
            return true; // Pass 2 wins
        }

        // Condition (a): at least one named resolution must fire
        $hasNamedResolutions = !empty($pass2FiredResolutions);
        
        // Condition (b): Pass 2 cost must be ≤ Pass 1 cost
        $costCondition = ($pass2Cost !== null && $pass1Cost !== null) && ($pass2Cost <= $pass1Cost);
        
        // Pass 2 wins only if BOTH conditions are met
        if ($hasNamedResolutions && $costCondition) {
            return true;
        }
        
        // Fall back to Pass 1
        return false;
    }

    private function costRegister(object $voicing, array $context): float
    {
        $category = $context['category'] ?? self::CATEGORY_DEFAULT;
        $target = $this->settings->getCategoryRegisterTarget($category);
        $pos = $voicing->start_fret ?? $voicing->position ?? null;
        if ($pos === null) return 0.0;
        return $this->boundedCost(abs($pos - $target) / 12.0);
    }

    /**
     * Phase G — soft style preference cost.
     * Penalizes voicings that don't match the requested voicing_style preset.
     * Returns 0.0 for 'auto' (no preference).
     */
    private function costStyle(object $voicing, array $context): float
    {
        $styleKey = $context['voicing_style'] ?? 'auto';
        if ($styleKey === 'auto' || $styleKey === '') return 0.0;

        $preset = self::VOICING_STYLE_PRESETS[$styleKey] ?? null;
        if ($preset === null) return 0.0;

        $cost = 0.0;

        // Penalize wrong voicing category
        if ($preset['prefer_category'] !== null) {
            $vc = $voicing->voicing_category ?? '';
            if ($vc !== $preset['prefer_category']) {
                $cost += 0.6;
            }
        }

        // Penalize bass string outside preferred range
        $bassString = $voicing->bass_string ?? $this->inferBassString($voicing);
        if ($bassString !== null) {
            if ($bassString < ($preset['bass_string_min'] ?? 0)) {
                $cost += 0.3;
            } elseif ($bassString > ($preset['bass_string_max'] ?? 6)) {
                $cost += 0.3;
            }
        }

        // Penalize non-root position when root is preferred
        if ($preset['prefer_root'] ?? false) {
            $inv = $voicing->inversion ?? '';
            if ($inv !== '' && $inv !== 'root') {
                $cost += 0.5;
            }
        }

        return $this->boundedCost($cost);
    }

    private function inferBassString(object $voicing): ?int
    {
        $frets = (string) ($voicing->frets ?? '');
        if ($frets === '') return null;
        // Fret string is low-E to high-e (left to right). Find the first
        // (lowest) sounding string.
        for ($s = 0; $s < min(6, strlen($frets)); $s++) {
            $c = $frets[$s];
            if ($c !== 'x' && $c !== 'X' && $c !== '-') {
                return 6 - $s; // string 6 (low E) = index 0, string 1 (high e) = index 5
            }
        }
        return null;
    }

    private function costSimplicity(object $voicing, array $context): float
    {
        $category = $context['category'] ?? self::CATEGORY_DEFAULT;
        $quality = $this->resolveVoicingQuality($voicing);
        $baseline = in_array($category, ['pop', 'classical'], true) ? 3 : 4;
        if ($category === 'blues' && !$this->isDomQuality($quality)) {
            $baseline = 3;
        }

        $noteCount = $this->soundingNoteCount($voicing);
        $notePenalty = max(0, $noteCount - $baseline);
        $extensionPenalty = $this->extensionCount($voicing);

        return $this->boundedCost(($notePenalty * 0.5 + $extensionPenalty * 0.5) / 2.0);
    }

    private function costPosition(object $v1, object $v2): float
    {
        $pos1 = $v1->start_fret ?? $v1->position ?? null;
        $pos2 = $v2->start_fret ?? $v2->position ?? null;
        if ($pos1 === null || $pos2 === null) return 0.0;
        return $this->boundedCost(min(abs($pos2 - $pos1), 5) / 5);
    }

    private function costBassMotion(object $v1, object $v2): float
    {
        $bass1 = $this->getBassNote($v1);
        $bass2 = $this->getBassNote($v2);
        if ($bass1 === null || $bass2 === null) return 0.0;

        $interval = ($bass2 - $bass1 + 12) % 12;
        if (in_array($interval, [0, 5, 7], true)) return 0.0;
        if (in_array($interval, [3, 4, 9, 10], true)) return 0.1;
        if (in_array($interval, [1, 2, 11], true)) return 0.2;
        if ($interval === 6 && $this->isTritoneSubTransition($v1, $v2, $interval)) return 0.2;
        return 1.0;
    }

    private function costCommonTone(object $v1, object $v2): float
    {
        $pitches1 = $this->buildPitchMap($v1);
        $pitches2 = $this->buildPitchMap($v2);
        if (empty($pitches1) || empty($pitches2)) return 1.0;

        $sameString = 0;
        foreach ($pitches1 as $p1) {
            foreach ($pitches2 as $p2) {
                if ($p1['string'] === $p2['string'] && $p1['midi'] === $p2['midi']) {
                    $sameString++;
                }
            }
        }

        $pcSet1 = array_unique(array_map(fn($p) => $p['midi'] % 12, $pitches1));
        $pcSet2 = array_unique(array_map(fn($p) => $p['midi'] % 12, $pitches2));
        $anyString = count(array_intersect($pcSet1, $pcSet2));
        $maxPossible = max(1, min(count($pitches1), count($pitches2)));

        return $this->boundedCost(1 - (($sameString * 0.7 + $anyString * 0.3) / $maxPossible));
    }

    private function costGroupContinuity(object $v1, object $v2): float
    {
        $group1 = $this->voicingGroup($v1->voicing_category ?? '');
        $group2 = $this->voicingGroup($v2->voicing_category ?? '');
        if ($group1 === $group2) return 0.0;

        $pos1 = $v1->start_fret ?? $v1->position ?? null;
        $pos2 = $v2->start_fret ?? $v2->position ?? null;
        $positionOk = $pos1 !== null && $pos2 !== null && abs($pos2 - $pos1) <= 2;
        if ($positionOk && $this->soundingNoteCount($v1) === $this->soundingNoteCount($v2)) {
            return 0.1;
        }

        return 0.5;
    }

    private function soundingNoteCount(object $voicing): int
    {
        $pitches = $this->buildPitchMap($voicing);
        if (!empty($pitches)) return count($pitches);

        $frets = (string) ($voicing->frets ?? '');
        if ($frets !== '') {
            return strlen(preg_replace('/[xX-]/', '', $frets));
        }

        return 0;
    }

    private function extensionCount(object $voicing): int
    {
        $extensions = trim((string) ($voicing->extensions ?? ''));
        if ($extensions === '') return 0;
        return count(array_filter(array_map('trim', preg_split('/[, ]+/', $extensions))));
    }

    private function boundedCost(float $value): float
    {
        return round(max(0.0, min(1.0, $value)), 4);
    }

    private function seedCost(object $candidate, string $category, array $context = []): float
    {
        $bias = self::CATEGORY_SEED_BIAS[$category] ?? self::CATEGORY_SEED_BIAS['jazz'];
        $target = $bias['target_fret'];
        $range = $bias['range'];

        // Phase G: override seed target from voicing style preset
        $styleKey = $context['voicing_style'] ?? 'auto';
        if ($styleKey !== 'auto' && $styleKey !== '') {
            $preset = self::VOICING_STYLE_PRESETS[$styleKey] ?? null;
            if ($preset && isset($preset['register_target'])) {
                $target = $preset['register_target'];
            }
        }

        $pos = $candidate->start_fret ?? $candidate->position ?? 5;
        $cost = $this->boundedCost(abs($pos - $target) / $range);

        // Phase G: add style penalty to seed cost
        $cost += $this->costStyle($candidate, $context);

        return $this->boundedCost($cost);
    }

    private function isEdgeAdmissible(object $from, object $to, ?int $positionLimit = 3): bool
    {
        $pos1 = $from->start_fret ?? $from->position ?? null;
        $pos2 = $to->start_fret ?? $to->position ?? null;
        if ($positionLimit !== null && $pos1 !== null && $pos2 !== null && abs($pos2 - $pos1) > $positionLimit) {
            return false;
        }

        $bass1 = $this->getBassNote($from);
        $bass2 = $this->getBassNote($to);
        if ($bass1 !== null && $bass2 !== null) {
            $interval = ($bass2 - $bass1 + 12) % 12;
            if (in_array($interval, [6, 8], true) && !$this->isTritoneSubTransition($from, $to, $interval)) {
                return false;
            }
        }

        return true;
    }

    private function viterbiSearch(array $pools, array $context, ?int $positionLimit = 3, array $allChords = []): array
    {
        $n = count($pools);
        if ($n === 0) return [];
        if ($n === 1) {
            if (empty($pools[0])) return [];
            $category = $context['category'] ?? self::CATEGORY_DEFAULT;
            $best = $pools[0][0];
            $bestCost = $this->seedCost($best, $category, $context);
            foreach ($pools[0] as $c) {
                $cost = $this->seedCost($c, $category, $context);
                if ($cost < $bestCost) {
                    $bestCost = $cost;
                    $best = $c;
                }
            }
            return [$best];
        }

        $category = $context['category'] ?? self::CATEGORY_DEFAULT;
        $INF = 999999.0;

        $cost = [];
        $prev = [];

        $cost[0] = array_map(fn($c) => $this->seedCost($c, $category, $context), $pools[0]);
        $prev[0] = array_fill(0, count($pools[0]), null);

        for ($i = 1; $i < $n; $i++) {
            $cost[$i] = [];
            $prev[$i] = [];
            foreach ($pools[$i] as $k => $cCurr) {
                $best = $INF;
                $bestPrev = null;
                foreach ($pools[$i - 1] as $j => $cPrev) {
                    if (!$this->isEdgeAdmissible($cPrev, $cCurr, $positionLimit)) {
                        continue;
                    }
                    
                    // Add chord context for Phase E named resolutions
                    $edgeContext = array_merge($context, [
                        'source_chord' => $allChords[$i - 1] ?? null,
                        'target_chord' => $allChords[$i] ?? null,
                    ]);
                    
                    $edgeCost = $this->costBetween($cPrev, $cCurr, $edgeContext);
                    $total = $cost[$i - 1][$j] + $edgeCost;
                    if ($total < $best) {
                        $best = $total;
                        $bestPrev = $j;
                    }
                }
                $cost[$i][$k] = $best;
                $prev[$i][$k] = $bestPrev;
            }
        }

        $path = [];
        $idx = null;
        $minLast = $INF;
        foreach ($cost[$n - 1] as $k => $c) {
            if ($c < $minLast) {
                $minLast = $c;
                $idx = $k;
            }
        }

        if ($idx === null || $minLast >= $INF) {
            return [];
        }

        for ($i = $n - 1; $i >= 0; $i--) {
            if ($idx === null || !isset($pools[$i][$idx])) {
                return [];
            }
            $path[$i] = $pools[$i][$idx];
            $idx = $prev[$i][$idx];
        }

        return array_reverse($path);
    }

    private function viterbiSearchWithRelaxation(array $lattice, array $context, ?string $group, ?array &$diagnostics = null, array $allChords = []): array
    {
        $relaxations = [];
        $diagnostics['slot_constraints'] = [];

        $cascade = [
            ['position' => 3, 'useCategory' => true],
            ['position' => 4, 'useCategory' => true],
            ['position' => 6, 'useCategory' => true],
            ['position' => null, 'useCategory' => true],
            ['position' => 6, 'useCategory' => false],
            ['position' => null, 'useCategory' => false],
        ];

        foreach ($cascade as $step) {
            $positionLimit = $step['position'];
            $useCategory = $step['useCategory'];

            $filteredLattice = [];
            foreach ($lattice as $slotIdx => $pool) {
                $slotPool = $pool;
                if ($useCategory && $group) {
                    $slotPool = array_filter($slotPool, fn($v) => $this->voicingGroup($v->voicing_category ?? '') === $group);
                } elseif ($useCategory && !$group) {
                    $slotPool = array_filter($slotPool, fn($v) => $this->isStructuredGroup($this->voicingGroup($v->voicing_category ?? '')));
                }
                $filteredLattice[$slotIdx] = array_values($slotPool);
            }

            $path = $this->viterbiSearch($filteredLattice, $context, $positionLimit, $allChords);

            if (!empty($path)) {
                $relaxations[] = [
                    'rule' => 'position',
                    'from' => 3,
                    'to' => $positionLimit,
                    'category_relaxed' => !$useCategory,
                ];
                $diagnostics['slot_constraints'] = $relaxations;
                return $path;
            }
        }

        $relaxations[] = ['rule' => 'bass_motion_unsatisfiable', 'from' => 'legal', 'to' => 'unrestricted'];
        $diagnostics['slot_constraints'] = $relaxations;

        return [];
    }

    // =========================================================================
    // PITCH MAP
    // =========================================================================

    /**
     * Build array of {midi, label, string} for every sounding note.
     * Port of JS buildPitchMap().
     * Handles diagram_data as object (from DB) or array (from calculateFrets).
     */
    private function buildPitchMap(object $voicing): array
    {
        $dd = $voicing->diagram_data;
        if (is_string($dd)) {
            $dd = json_decode($dd);
        }

        // Normalize to work with both objects and arrays
        $positions = null;
        if (is_object($dd) && isset($dd->positions)) {
            $positions = $dd->positions;
        } elseif (is_array($dd) && isset($dd['positions'])) {
            $positions = $dd['positions'];
        }

        if (!$positions || !is_array($positions)) {
            return [];
        }

        $rawLabels = array_map('trim', explode(',', $voicing->interval_labels ?? ''));
        // Strip 'x' entries (muted string placeholders) — the calculator includes
        // them for all 6 strings but we only want labels for sounding strings
        $intvArr = array_values(array_filter($rawLabels, fn($l) => $l !== '' && strtolower($l) !== 'x'));

        // Count sounding strings
        $soundingCount = 0;
        foreach ($positions as $pos) {
            $fret = is_object($pos) ? ($pos->fret ?? null) : ($pos['fret'] ?? null);
            if ($fret !== null && $fret !== 'x' && $fret !== -1) {
                $soundingCount++;
            }
        }

        // Misaligned labels → return empty (triggers fret-distance fallback)
        if (!empty($intvArr) && count($intvArr) !== $soundingCount) {
            return [];
        }

        $result = [];
        $noteIdx = 0;

        foreach ($positions as $pos) {
            $fret = is_object($pos) ? ($pos->fret ?? null) : ($pos['fret'] ?? null);
            $string = is_object($pos) ? ($pos->string ?? ($noteIdx + 1)) : ($pos['string'] ?? ($noteIdx + 1));

            if ($fret === null || $fret === 'x' || $fret === -1) continue;

            $midi = (self::OPEN_MIDI[$string] ?? 40) + intval($fret);
            $label = $intvArr[$noteIdx] ?? '';

            $result[] = [
                'midi'   => $midi,
                'label'  => $label,
                'string' => $string,
            ];
            $noteIdx++;
        }

        return $result;
    }

    /**
     * Filter pitch map entries by label group.
     */
    private function filterByLabels(array $pitches, array $labelMap): array
    {
        return array_values(array_filter($pitches, function ($p) use ($labelMap) {
            return isset($labelMap[$p['label']]);
        }));
    }

    // =========================================================================
    // VOICING TYPE HELPERS
    // =========================================================================

    private function voicingGroup(string $category): string
    {
        if ($category === 'drop2' || $category === 'drop3') return 'drop';
        if ($category === 'shell') return 'shell';
        if ($category === 'closed') return 'closed';
        return $category ?: 'other';
    }

    private function isStructuredGroup(string $group): bool
    {
        return in_array($group, ['drop', 'shell', 'closed'], true);
    }

    private function isOpenVoicing(object $v): bool
    {
        return ($v->voicing_category ?? '') === 'open';
    }

    /**
     * Derive the upper string set (all sounding strings except bass).
     * Returns comma-joined string for comparison.
     */
    private function upperStringSet(object $voicing): string
    {
        $dd = $voicing->diagram_data;
        if (is_string($dd)) $dd = json_decode($dd);

        $positions = null;
        if (is_object($dd) && isset($dd->positions)) $positions = $dd->positions;
        elseif (is_array($dd) && isset($dd['positions'])) $positions = $dd['positions'];
        if (!$positions) return '';

        $sounding = [];
        foreach ($positions as $pos) {
            $fret = is_object($pos) ? ($pos->fret ?? null) : ($pos['fret'] ?? null);
            $string = is_object($pos) ? ($pos->string ?? 0) : ($pos['string'] ?? 0);
            if ($fret !== null && $fret !== 'x' && $fret !== -1) {
                $sounding[] = $string;
            }
        }

        if (count($sounding) < 2) return implode(',', $sounding);

        sort($sounding);
        $upper = array_slice($sounding, 1);
        return implode(',', $upper);
    }

    private function averageFret(object $voicing): float
    {
        $dd = $voicing->diagram_data;
        if (is_string($dd)) $dd = json_decode($dd);

        $positions = null;
        if (is_object($dd) && isset($dd->positions)) $positions = $dd->positions;
        elseif (is_array($dd) && isset($dd['positions'])) $positions = $dd['positions'];
        if (!$positions) return 0;

        $sum = 0;
        $count = 0;
        foreach ($positions as $pos) {
            $fret = is_object($pos) ? ($pos->fret ?? null) : ($pos['fret'] ?? null);
            if ($fret !== null && is_numeric($fret) && $fret > 0) {
                $sum += $fret;
                $count++;
            }
        }
        return $count > 0 ? $sum / $count : 0;
    }

    // =========================================================================
    // QUALITY HELPERS
    // =========================================================================

    private function resolveQuality(array $chord): string
    {
        $q = $chord['quality'] ?? '';
        if ($q === 'dom7') return 'dom7';
        if ($q === '7') return 'dom7';
        return $q;
    }

    private function resolveVoicingQuality(object $voicing): string
    {
        $q = $voicing->quality ?? '';
        if ($q === '7') return 'dom7';
        return $q;
    }

    private function isDomQuality(string $q): bool
    {
        return in_array($q, self::DOM_QUALITIES, true);
    }

    private function isTonicMajQuality(string $q): bool
    {
        return in_array($q, self::TONIC_MAJ_QUALITIES, true);
    }

    private function isMinorQuality(string $q): bool
    {
        return in_array($q, self::MINOR_QUALITIES, true);
    }

    private function isHalfDimQuality(string $q): bool
    {
        return in_array($q, self::HALF_DIM_QUALITIES, true);
    }

    private function distToPenalty(int $dist): float
    {
        if ($dist <= 2) return 0;
        if ($dist <= 4) return 2;
        if ($dist <= 7) return 4;
        return 5 + floor(($dist - 7) / 3);
    }

    // =========================================================================
    // OUTPUT FORMATTING
    // =========================================================================

    /**
     * Format a voicing object for API output.
     * 
     * @param object $v The voicing object from the DB
     * @param string|null $contextChordName The chord_name from the context (after numeral upgrade)
     */
    private function formatVoicing(object $v, ?string $contextChordName = null): array
    {
        $dd = $v->diagram_data;
        if (is_string($dd)) {
            $dd = json_decode($dd, true);
        } elseif (is_object($dd)) {
            $dd = json_decode(json_encode($dd), true);
        }

        $root    = $v->root_note ?? '';
        $quality = $v->quality ?? '';
        $ext     = $v->extensions ?? '';

        // Use context chord_name if provided (preserves numeral upgrade), otherwise build from voicing quality
        if ($contextChordName !== null) {
            $chordName = $contextChordName;
        } else {
            // Build a human-readable chord name for the UI
            $chordName = $root;
            if ($quality && !in_array($quality, ['maj', ''], true)) {
                $chordName .= $quality === 'dom7' ? '7' : $quality;
            }
            if ($ext) {
                $chordName .= '(' . $ext . ')';
            }
        }

        return [
            'diagram_id'       => $v->id ?? null,
            'chord_name'       => $chordName,
            'root_note'        => $root,
            'quality'          => $quality,
            'extensions'       => $ext,
            'voicing_category' => $v->voicing_category ?? '',
            'root_string'      => $v->root_string ?? '',
            'inversion'        => $v->inversion ?? 'root',
            'frets'            => $v->frets ?? $this->buildFretString($dd ?? []),
            'position'         => $v->start_fret ?? 1,
            'start_fret'       => $v->start_fret ?? 1,
            'diagram_data'     => $dd,
            'interval_labels'  => $v->interval_labels ?? '',
            'popularity'       => $v->popularity ?? 0,
        ];
    }
}

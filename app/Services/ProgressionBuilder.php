<?php

namespace App\Services;

use App\Models\ChordDiagram;
use App\Services\ChordShapeCalculator;
use App\Services\HarmonicContext;
use App\Services\Builder\PhaseE\ExtensionTable;
use App\Services\Builder\PhaseE\Interval;
use App\Services\BuilderSettings;
use App\Services\Harmony\ChordQualityMapper;
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
    protected ChordQualityMapper $qualityMapper;

    public function __construct(
        ChordShapeCalculator $calculator,
        BuilderSettings $settings,
        ChordQualityMapper $qualityMapper = new ChordQualityMapper()
    ) {
        $this->calculator = $calculator;
        $this->settings = $settings;
        $this->qualityMapper = $qualityMapper;
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

    /**
     * Extra simplicity cost charged per option (extension) tone carried by a
     * half-diminished voicing. The m7b5(11) shape is idiomatic but reads as
     * late-intermediate; on a standard minor ii–V–i the plain m7b5 should win
     * unless an option-tone voicing has an overwhelming voice-leading edge.
     *
     * Machine-room lever: this is the seed for the planned basic↔advanced
     * progression slider — at "advanced" the multiplier would fall toward 0,
     * at "basic" it would rise. Tune here.
     */
    private const HALF_DIM_OPTION_TONE_PENALTY = 1.5;

    /**
     * Strength of the melody-position pull (audio transcription only — inert
     * unless the caller supplies $context['position_hints']). Sized to be a
     * strong influence: comparable to the heaviest standing cost term so a
     * voicing that tracks the melody's hand position wins decisively over a
     * far-away shape, while extreme voice-leading/simplicity disagreement can
     * still override. See positionHintCost().
     */
    private const POSITION_HINT_WEIGHT = 0.45;

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
        $tonality   = $options['tonality'] ?? 'major';
        // Default extensions to the Machine Room's per-category pass2_eligible
        // setting. Callers may still pass an explicit bool to override.
        $extensions = $options['extensions'] ?? $this->settings->isPass2Eligible($category);
        // When set, basic-only voicings are enforced regardless of category
        // pass1_extensions_allowed setting. Caller signals strict-basic mode.
        $strictBasic = (bool) ($options['strict_basic'] ?? false);
        $rootOnly   = $options['rootOnly'] ?? $this->settings->getRootOnlyDefault($category);
        $voicingStyle = $options['voicing_style'] ?? $this->settings->getDefaultVoicingStyle($category);
        if ($voicingStyle === 'auto' || $voicingStyle === '') {
            $voicingStyle = $this->settings->getDefaultVoicingStyle($category);
        }

        // Resolve bass-string hard constraints from the voicing_style preset.
        // The range is enforced as a hard per-slot filter after transposition
        // (see fetchVoicingsForChord), replacing the old soft 0.075 penalty that
        // got out-voted by voice-leading and register costs on every progression.
        $stylePreset   = (isset($voicingStyle) && $voicingStyle !== 'auto' && $voicingStyle !== '')
            ? (self::VOICING_STYLE_PRESETS[$voicingStyle] ?? null)
            : null;
        $presetBassMin = $stylePreset['bass_string_min'] ?? null;
        $presetBassMax = $stylePreset['bass_string_max'] ?? null;

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

        // Apply numeral upgrade (§6.1) for category-aware progressions.
        // Skipped when caller sets skip_numeral_upgrade (e.g. fill-voicings on an existing chart).
        if (empty($options['skip_numeral_upgrade'])) {
            $context = $this->applyCategoryNumeralUpgrade($context, $category);
        }

        // Pre-scan cadence-requirement edges (roles/intervals only, no lattice
        // yet) so Phase E knows which slots it must NOT decorate with option
        // tones — decorating first would restrict fetchVoicingsForChord's pool
        // to tension-carrying shapes that the §6.6 discipline check then
        // rejects for lack of a justifying resolution, dropping the edge.
        $requirementSlots = [];
        if (!empty($options['pedagogical_vl'])) {
            foreach ($this->computeEdgeRequirementDefs($allChords, $category) as $i => $req) {
                $requirementSlots[$i] = true;
                $requirementSlots[$i + 1] = true;
            }
        }

        // Apply Phase E extension upgrade (§6.2)
        $applyExtensionUpgrade = $extensions && $this->settings->isPass2Eligible($category);
        $context = $this->applyPhaseEExtensionUpgrade($context, $applyExtensionUpgrade, $requirementSlots);

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

        // Fetch voicings for each chord from the database.
        // Note: in strict-basic mode we DO keep the chord's own extension —
        // hardcoded tensions on the input (e.g. Emaj7(9)) must be honored so
        // the picked voicing actually contains those tones. The strict flag
        // only blocks the *category-wide* extension allowance that would let
        // option-tone shapes leak in for plain chord tokens.
        $chordVoicings = [];
        foreach ($allChords as $i => $chord) {
            $functionalRole = $this->determineFunctionalRole($chord);
            // Hardcoded quality (e.g. Cm6, EMaj7) must not be widened to its
            // tonic-family siblings — only plain triads written without a
            // quality token get the widen treatment.
            $explicitQuality = $this->hasExplicitQuality($chord['chord_name'] ?? '');
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
                $functionalRole,
                $strictBasic,
                $explicitQuality,
                $tonality,
                $presetBassMin,
                $presetBassMax,
                !empty($requirementSlots[$i])
            );
        }
        $lattice = $this->buildAnchorFreeLattice($chordVoicings, $style, $rootOnly, $category);

        // Apply harmony filter to each slot
        for ($i = 0; $i < $n; $i++) {
            $lattice[$i] = $this->applyHarmonyFilter($lattice[$i], $allChords, $i);
        }

        // Handle pinned slots (lattice restriction)
        $pinnedSlot = $options['pinnedSlot'] ?? null;
        $pinnedVoicing = $options['pinnedVoicing'] ?? null;
        $pinnedVoicings = $options['pinnedVoicings'] ?? []; // array of [index => voicing]

        if ($pinnedSlot !== null && $pinnedVoicing !== null && $pinnedSlot >= 0 && $pinnedSlot < $n) {
            $lattice[$pinnedSlot] = [(object) $pinnedVoicing];
        }

        foreach ($pinnedVoicings as $idx => $voicing) {
            if ($idx >= 0 && $idx < $n && $voicing) {
                $lattice[$idx] = [(object) $voicing];
            }
        }

        // Repeated-chord reuse is applied AFTER Viterbi finishes (see
        // applyRepeatedChordReuse below). Pinning a repeated slot to
        // pool[i-1][0] before search would force the preceding free slot to
        // harmonize with an arbitrary first-pool element rather than
        // letting Viterbi pick its true optimum.

        // Pedagogical cadence enforcement: computed after pins so feasibility
        // respects pinned single-element pools.
        $this->activeEdgeRequirements = null;
        $this->edgeRequirementMemo = [];
        if (!empty($options['pedagogical_vl'])) {
            $edgeReqs = $this->computeEdgeRequirements($allChords, $lattice, $category);
            if (!empty($edgeReqs)) {
                $this->activeEdgeRequirements = $edgeReqs;
            }
            $diagnostics['pedagogical_vl'] = array_map(fn($idx, $r) => [
                'edge'        => $idx,
                'requirement' => $r['requirement_id'],
                'required'    => array_keys($r['resolutions']),
                'one_of'      => array_keys($r['one_of'] ?? []),
                'level'       => $r['level'],
            ], array_keys($edgeReqs), $edgeReqs);
        }

        // Phase E: Run Pass 1 and Pass 2, then apply decision rule
        $pass1Selections = null;
        $pass1Cost = null;
        $pass2Selections = null;
        $pass2Cost = null;
        $pass2FiredResolutions = [];

        $runPass2 = $extensions && $this->settings->isPass2Eligible($category);

        // Melody position hints (audio transcription) — measure_index => target
        // fret. Threaded into every Viterbi context so positionHintCost() can
        // pull each chord toward the melody's hand position. Inert when absent.
        $positionHints = $options['position_hints'] ?? [];

        if ($runPass2) {
            // Run Pass 1 (vlLevel = 1, no extensions)
            $pass1Context = [
                'category' => $category,
                'vlLevel' => 1,
                'voicing_style' => $voicingStyle,
                'weight_overrides' => $options['weight_overrides'] ?? [],
                'position_hints' => $positionHints,
            ];
            $pass1Selections = $this->searchWithPedagogicalFallback($lattice, $pass1Context, $style, $diagnostics, $allChords);
            $pass1Cost = $this->calculatePathCost($pass1Selections, $allChords, $pass1Context);

            // Run Pass 2 (vlLevel = 2, with extensions and named resolutions)
            $pass2Context = [
                'category' => $category,
                'vlLevel' => 2,
                'voicing_style' => $voicingStyle,
                'weight_overrides' => $options['weight_overrides'] ?? [],
                'position_hints' => $positionHints,
            ];
            $pass2Selections = $this->searchWithPedagogicalFallback($lattice, $pass2Context, $style, $diagnostics, $allChords);
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
                'position_hints' => $positionHints,
            ];
            $selections = $this->searchWithPedagogicalFallback($lattice, $context, $style, $diagnostics, $allChords);
            if (empty($selections)) {
                $selections = array_fill(0, $n, null);
            }
            $selections = $this->applyRepeatedChordReuse($selections, $allChords, $options);
        }

        // Requirement state must not leak into a later build on this instance.
        $this->activeEdgeRequirements = null;
        $this->edgeRequirementMemo = [];

        // Compute VL scores between adjacent selections
        $vlScores = [];
        $pathCost = 0.0;
        $actualVlLevel = ($runPass2 && $selections === $pass2Selections) ? 2 : 1;
        // Per-edge fired named resolutions — indexed by source slot i.
        // Used by the frontend to draw accurate guide-tone arrows rather than
        // the proximity heuristic. The detail variant carries the exact
        // string/fret pair per fired resolution and is evaluated for EVERY
        // category and pass (display concern — the cost bonus stays gated).
        $edgeFiredResolutions = [];
        $edgeFiredDetails = [];

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

                $details = $this->describeFiredResolutions($sel1, $sel2, [
                    'source_chord' => $allChords[$i] ?? null,
                    'target_chord' => $allChords[$i + 1] ?? null,
                ]);
                $edgeFiredDetails[$i] = $details;
                $edgeFiredResolutions[$i] = array_column($details, 'id');
            } else {
                $vlScores[] = null;
                $edgeFiredResolutions[$i] = [];
                $edgeFiredDetails[$i] = [];
            }
        }
        $diagnostics['path_cost'] = round($pathCost, 4);

        // Format output — include the full voicing pool per chord for the picker UI
        $result = [];
        foreach ($allChords as $i => $chord) {
            $sel  = $selections[$i];
            $pool = $chordVoicings[$i] ?? [];
            $result[] = [
                'chord_name'       => $chord['chord_name'],
                'roman_numeral'    => $chord['roman_numeral'],
                'measure_index'    => $chord['measure_index'],
                'voicing'          => $sel ? $this->formatVoicing($sel, $chord['chord_name'], $chord) : null,
                'voicings'         => array_values(array_map(fn($v) => $this->formatVoicing($v, $chord['chord_name'], $chord), $pool)),
                // Named resolutions fired on the edge FROM this slot to the next.
                // Empty array for the last slot (no outgoing edge).
                'fired_resolutions' => $edgeFiredResolutions[$i] ?? [],
                // Detail variant: id/core/same_string/semitones + exact
                // from/to {string, fret, midi, tone} per fired resolution.
                'fired_resolution_details' => $edgeFiredDetails[$i] ?? [],
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
        ?string $functionalRole = null,
        bool $strictBasic = false,
        bool $explicitQuality = false,
        string $tonality = 'major',
        ?int $bassStringMin = null,
        ?int $bassStringMax = null,
        bool $widenArchetype = false
    ): array {
        if (!$root) return [];

        // Normalize quality for DB lookup
        $dbQuality = $quality;
        if ($dbQuality === '7') $dbQuality = 'dom7';

        $qualityAliases = $this->expandTonicFamilyAliases(
            $this->getQualityAliases($dbQuality),
            $dbQuality,
            $category,
            $functionalRole,
            $explicitQuality,
            $tonality
        );

        // Determine pool based on category and blues sub-mode trigger
        $pool = $this->getCategoryPool($category, $styleFilter, $extensions);

        // Chromatic passing chords with a hardcoded extension (b6, #5, etc.) are
        // line-cliché shapes that won't exist as drop2/shell voicings. Widen the
        // pool to include 'archetype' and 'closed' so the DB fallback can find them
        // regardless of category. The extension LIKE filter below still narrows
        // the result to only the shapes that actually carry the requested tone.
        if ($extension !== '' && $explicitQuality) {
            $pool = array_values(array_unique(array_merge($pool, ['archetype', 'closed', 'closed_triads', 'triad'])));
        }

        // Cadence-requirement edges (§6.6): some category pools (e.g. classical
        // dominant 7ths) carry only one shape, starving the same-string
        // guide-tone search of a textbook pair (open G7 320001 → C x32010).
        // Widen with the archetype pool so the requirement search has real
        // candidates; the requirement/discipline checks still gate which
        // pair actually gets picked.
        if ($widenArchetype) {
            $pool = array_values(array_unique(array_merge($pool, ['archetype'])));
        }

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
        // Strict-basic forces the filter even when the category is normally
        // pass1_extensions_allowed (e.g. jazz). However, when the input chord
        // carries an explicit extension (e.g. Emaj7(9)), skip the filter so
        // hardcoded tensions can still match extension-carrying shapes — the
        // narrower per-tone filter below handles that case.
        $applyPlainFilter = $extension === '' && ($strictBasic || (!$extensions && !$this->settings->isPass1ExtensionsAllowed($category)));
        if ($applyPlainFilter) {
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
        $aliasShapes = $this->fetchAliasShapes($qualityAliases, $extensions, $rootOnly, $pool, $extension, $category, $strictBasic);
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

        // Hard bass-string filter from voicing_style preset.
        // Only applied when the preset specifies a range (non-null min/max) and
        // the filtered pool is non-empty — if the filter would leave the slot
        // empty (e.g. no A-string shapes for a rare quality), we fall back to
        // the full pool and log a diagnostic so the soft costStyle penalty still
        // applies but the slot doesn't go dark.
        if ($results !== [] && ($bassStringMin !== null || $bassStringMax !== null)) {
            $filtered = array_values(array_filter($results, function ($v) use ($bassStringMin, $bassStringMax) {
                $bs = $v->bass_string ?? $this->inferBassString($v);
                if ($bs === null) return true; // can't determine — keep
                if ($bassStringMin !== null && $bs < $bassStringMin) return false;
                if ($bassStringMax !== null && $bs > $bassStringMax) return false;
                return true;
            }));

            if ($filtered !== []) {
                $results = $filtered;
            } elseif ($diagnostics !== null) {
                $diagnostics['category_pool_fallbacks'][] = [
                    'slot' => $slotIndex,
                    'requested_pool' => "bass_string {$bassStringMin}–{$bassStringMax}",
                    'fallback_pool' => 'unrestricted bass_string',
                    'reason' => 'no candidates in bass-string range',
                ];
            }
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
    private function fetchAliasShapes(array $qualityAliases, bool $extensions, bool $rootOnly, array $pool, string $extension = '', string $category = self::CATEGORY_DEFAULT, bool $strictBasic = false): \Illuminate\Support\Collection
    {
        $query = DB::table('sbn_chord_diagram_aliases as a')
            ->join('sbn_chord_diagrams as d', 'a.diagram_id', '=', 'd.id')
            ->whereIn('a.alt_quality', $qualityAliases)
            // Match queryWithCategoryPool: include blank/null categories so
            // aliases on uncategorized parent shapes aren't silently dropped.
            ->whereIn('d.voicing_category', array_merge($pool, ['', null]))
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
        // Strict-basic overrides the category opt-out — but skip when an
        // explicit extension was requested so hardcoded tensions still match
        // their tone-carrying aliases.
        $applyPlainFilter = $extension === '' && ($strictBasic || (!$extensions && !$this->settings->isPass1ExtensionsAllowed($category)));
        if ($applyPlainFilter) {
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
        static $sharp = ['C','C#','D','D#','E','F','F#','G','G#','A','A#','B'];
        static $flat  = ['C','Db','D','Eb','E','F','Gb','G','Ab','A','Bb','B'];
        if (!isset($semi[$sourceRoot], $semi[$sourceBass], $semi[$targetRoot])) {
            return null;
        }
        $interval  = ($semi[$sourceBass] - $semi[$sourceRoot] + 12) % 12;
        $targetSemi = ($semi[$targetRoot] + $interval) % 12;
        $useFlats  = \App\Services\HarmonicContext::spellingUsesFlats($targetRoot);
        return $useFlats ? $flat[$targetSemi] : $sharp[$targetSemi];
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
        $pinnedVoicings = $options['pinnedVoicings'] ?? [];

        foreach ($allChords as $i => $chord) {
            $chordVoicings[$i] = $this->fetchSimpleModeVoicingsForChord(
                $chord['root'],
                $chord['quality'] ?? ''
            );

            $isPinnedSlot = ($pinnedSlot !== null && $pinnedSlot === $i) || isset($pinnedVoicings[$i]);

            if ($isPinnedSlot) {
                $selections[$i] = (object) ($pinnedVoicings[$i] ?? $pinnedVoicing);
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
                'voicing' => $sel ? $this->formatVoicing($sel, $chord['chord_name'], $chord) : null,
                'voicings' => array_values(array_map(fn($v) => $this->formatVoicing($v, $chord['chord_name'], $chord), $pool)),
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
        // Explicit if contains: 6, 7, maj6, maj7, m6, m7, m9, dim, aug, sus, add, etc.
        // Basic triads: C, Cm, C#, F#m, etc. are not explicit.
        // Strip a trailing slash-bass so "C/G" doesn't trip false positives on
        // anything after the slash, but the chord body itself decides.
        $body = explode('/', $chordName, 2)[0];
        // b6 must precede bare 6 so it isn't consumed by the shorter match.
        $explicitPattern = '/(b6|6|7|maj|mMaj|dim|aug|sus|add|b9|#9|#11|b13)/i';
        return (bool) preg_match($explicitPattern, $body);
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
            // Diatonic VIIm is the half-diminished leading-tone chord.
            // A *chromatic* flat-7 minor (bVIIm — backdoor / borrowed) is a
            // plain m7, so the m7b5 upgrade must skip altered numerals.
            $isChromaticMinor = str_starts_with($fullNumeral, 'b')
                || str_starts_with($fullNumeral, '#');
            if ($degree === 7 && ! $isChromaticMinor) { // VIIm
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

        // Chromatic (flatted/sharped) numerals are chromatic dominants —
        // bII (tritone sub), bIII, bVII (backdoor), etc. → dom7. bVI is the
        // sole exception, handled above. The diatonic-degree test below must
        // only run for *unaltered* numerals: romanToDegree returns the plain
        // letter degree, so e.g. bII would otherwise read as degree 2 (m7).
        $isChromatic = str_starts_with($fullNumeral, 'b') || str_starts_with($fullNumeral, '#');
        if ($isChromatic) {
            return 'dom7';
        }

        // Diatonic II, III, VI → m7 (diatonic minor chords)
        if ($degree === 2 || $degree === 3 || $degree === 6) {
            return 'm7';
        }

        // Diatonic V, VII → dom7
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
     * Convert a Roman numeral to its plain scale-degree number (I=1 … VII=7).
     *
     * Returns the degree of the Roman *letters only* — the leading accidental
     * is ignored, so `bVII` → 7 and `#IV` → 4. Every caller inspects the
     * accidental itself (via str_contains on the raw numeral), so degree must
     * stay the plain letter value, not an accidental-shifted one.
     *
     * The numeral is matched by extracting the longest leading run of Roman
     * characters, which is robust to any trailing quality suffix
     * (m7, maj9, sus4, add9, o, °, dim, aug, …) without enumerating them.
     */
    private function romanToDegree(string $numeral): int
    {
        $romanMap = ['VII' => 7, 'VI' => 6, 'IV' => 4, 'V' => 5, 'III' => 3, 'II' => 2, 'I' => 1];

        // Drop a leading accidental, then take the leading Roman-numeral run.
        $stripped = ltrim($numeral, 'b#');
        if (preg_match('/^([IViv]+)/', $stripped, $m)) {
            $base = strtoupper($m[1]);
            // Match longest-first so "VII" wins over "VI"/"V".
            foreach ($romanMap as $roman => $degree) {
                if ($base === $roman) {
                    return $degree;
                }
            }
        }

        return 1; // fallback for unparseable input
    }

    /**
     * Compose a chord name from root, quality, and extension.
     */
    private function composeChordName(string $root, string $quality, string $extension): string
    {
        $name = $root;

        // Normalize quality for chord-name display (e.g. "dim", not the
        // Roman-numeral "o" — see ChordQualityMapper::toChordNameSuffix).
        $displayQuality = $this->qualityMapper->toChordNameSuffix($quality);
        if ($displayQuality) {
            $name .= $displayQuality;
        }

        if ($extension) {
            $name .= '(' . $extension . ')';
        }

        return $name;
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
    private function applyPhaseEExtensionUpgrade(array $context, bool $extensionsEnabled, array $requirementSlots = []): array
    {
        if (!isset($context['sections'])) {
            return $context;
        }

        $pinnedSlot = $context['pinnedSlot'] ?? null;

        // Always flag hardcoded-extension chords so the harmony filter can
        // reject voicings that introduce alterations not in the source token —
        // this needs to happen even when Phase E option-tone upgrade is
        // disabled (e.g. pass2_eligible doesn't include this category).
        foreach ($context['sections'] as $secIdx => $section) {
            foreach ($section['chords'] as $chordIdx => $chord) {
                $globalIdx = $this->globalChordIndex($context, $secIdx, $chordIdx);
                if ($globalIdx === $pinnedSlot) continue;
                $existingExt = trim($chord['extension'] ?? '');
                if ($existingExt === '') continue;
                $existingTones = array_values(array_filter(
                    array_map('trim', explode(',', $existingExt)),
                    fn ($t) => $t !== ''
                ));
                $context['sections'][$secIdx]['chords'][$chordIdx]['phase_e_extensions'] = $existingTones;
                $context['sections'][$secIdx]['chords'][$chordIdx]['phase_e_hardcoded'] = true;
            }
        }

        if (!$extensionsEnabled) {
            return $context;
        }

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

                // Hardcoded extensions are already flagged above and we never
                // overwrite them with Phase E option-tone selection.
                if (!empty($chord['phase_e_hardcoded'])) {
                    continue;
                }

                // Cadence-requirement edges (§6.6) fetch their voicing pools
                // under the plain quality — decorating first would restrict
                // fetchVoicingsForChord to tension-carrying shapes that the
                // discipline check then rejects for lack of a justifying
                // resolution, silently dropping the edge to same_voice/dropped.
                if (!empty($requirementSlots[$globalIdx])) {
                    continue;
                }

                // Skip sus / add chords. Phase E's option-tone table is keyed
                // by functional role and assumes plain triads / 7ths; a sus4
                // or add9 already carries its own tone structure, so layering
                // a "(9)" on top yields nonsense names (Dsus4(9), Dadd9(9)).
                $chordQuality = $this->qualityMapper->normalizeAlias($chord['quality'] ?? '');
                if (in_array($chordQuality, ['sus2', 'sus4', 'add9', 'madd9'], true)) {
                    $context['sections'][$secIdx]['chords'][$chordIdx]['extension'] = '';
                    continue;
                }

                $currentRole = $chordRoles[$globalIdx];
                $nextRole = $chordRoles[$globalIdx + 1] ?? null;
                $keyMode = $chord['tonality'] ?? 'major';

                // Handle secondary dominant routing
                $nextChordForRouting = $this->chordAtGlobalIndex($context, $globalIdx + 1);
                if ($this->isSecondaryDominant($currentRole, $chord)
                    && $this->resolvesToNextChord($chord, $nextChordForRouting)) {
                    $targetQuality = $this->getTargetChordQuality($context, $globalIdx + 1);
                    $routing = ExtensionTable::routeSecondaryDominant($targetQuality);
                    // Map routing to appropriate target role for extension lookup
                    $nextRole = $this->mapRoutingToTargetRole($routing);

                    // A secondary dominant *locally* tonicizes its target, so
                    // the extension lookup's key_mode must follow the target's
                    // quality, not the progression's global key. Without this,
                    // a V7/ii inside a major-key tune (VI7 → IIm7) finds no
                    // V7→Im row — that row is gated key_mode:minor — and gets
                    // no altered tones at all.
                    //
                    // The resolvesToNextChord() guard above is essential: it
                    // gates this on a *genuine* dominant resolution (next root
                    // a 4th up). Without it, a non-resolving dominant like
                    // II7 → IIm7 (same root, D7 → Dm7 — V7/V followed by ii,
                    // not a V7→i cadence) reads the next chord's m7 quality
                    // and gets routed "into minor", handing it b9/b13. A II7
                    // that doesn't resolve falls through to the generic V7
                    // handling below and keeps the major-key Lydian-dom
                    // palette (naturals + #11).
                    $keyMode = $nextRole === 'Im' ? 'minor' : 'major';
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
            // Borrowed flat-submediant (minor blues cadence: bVImaj7 → V7).
            // Without its own role it fell into the Imaj7 fallback, so no
            // voice-leading rule could ever target the bVI → V planing move.
            // bVI is a substring of bVII — exclude the latter explicitly.
            if (str_contains($romanNumeral, 'bVI') && !str_contains($romanNumeral, 'bVII')) {
                return 'bVImaj7';
            }
            return 'Imaj7';
        }
        
        if (in_array($quality, ['m7', 'm6', 'm7b5', 'mMaj7', 'min', 'mMin7', 'm'])) {
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

        // aug / + on the tonic (line-cliché chromatic passing chord)
        if (in_array($quality, ['aug', '+'])) {
            $degree = $this->romanToDegree($romanNumeral);
            return $degree === 1 ? 'Iaug' : 'Imaj7';
        }

        if (in_array($quality, ['dim7', 'dim', 'o7'])) {
            return 'dim7';
        }

        // Default fallback
        return 'Imaj7';
    }

    /**
     * Check if a chord is a secondary dominant.
     *
     * Only chords that resolved to the `V7` role qualify — substitute
     * dominants (`bII7`, `bVI7`, `bVII7`) carry their own roles. Among
     * `V7`-role chords, the one on scale degree 5 is the *primary* dominant;
     * any other degree (V/ii, V/vi, …) is a secondary dominant.
     */
    private function isSecondaryDominant(string $role, array $chord): bool
    {
        if ($role !== 'V7') {
            return false;
        }

        $romanNumeral = $chord['roman_numeral'] ?? '';

        // Plain V (degree 5) is primary; any other degree is secondary.
        return $this->romanToDegree($romanNumeral) !== 5;
    }

    /**
     * Does this dominant genuinely resolve to the next chord?
     *
     * A secondary dominant tonicizes — and so routes its extensions by — the
     * next chord only when that chord is a plausible *target*, i.e. the roots
     * differ. The one motion that is never a resolution is a *static* root:
     * II7 → IIm7 (D7 → Dm7) is V7/V followed by the supertonic, sharing root
     * D — a parallel major→minor, not a cadence. Treating it as a resolution
     * reads Dm7's m7 quality and wrongly hands D7 the into-minor b9/b13
     * palette.
     *
     * Any non-zero interval (4th-up V→I, half-step deceptive, etc.) still
     * counts: those routings were correct before and must be preserved.
     *
     * Returns false when there is no next chord (terminal dominant).
     */
    private function resolvesToNextChord(array $chord, ?array $nextChord): bool
    {
        if ($nextChord === null) {
            return false;
        }

        $domRoot  = $chord['root'] ?? null;
        $nextRoot = $nextChord['root'] ?? null;
        if ($domRoot === null || $nextRoot === null) {
            return false;
        }

        $interval = ($this->noteNameToPitchClass($nextRoot)
            - $this->noteNameToPitchClass($domRoot) + 12) % 12;

        // Static root (interval 0) is the sole non-resolution. Everything else
        // is a plausible tonicization target.
        return $interval !== 0;
    }

    /**
     * Get target chord quality for secondary dominant routing
     */
    private function getTargetChordQuality(array $context, int $targetIndex): string
    {
        return $this->chordAtGlobalIndex($context, $targetIndex)['quality'] ?? 'maj7';
    }

    /**
     * Fetch the chord array at a global (section-flattened) index, or null
     * when the index is out of range. Used where a method has $context but
     * not a pre-flattened $allChords (e.g. applyPhaseEExtensionUpgrade).
     */
    private function chordAtGlobalIndex(array $context, int $targetIndex): ?array
    {
        $currentIndex = 0;
        foreach ($context['sections'] ?? [] as $section) {
            foreach ($section['chords'] as $chord) {
                if ($currentIndex === $targetIndex) {
                    return $chord;
                }
                $currentIndex++;
            }
        }

        return null;
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
            'dim'  => ['dim', 'o7', 'dim7'],
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
        ?string $functionalRole,
        bool $explicitQuality = false,
        string $tonality = 'major'
    ): array {
        // Hardcoded quality token (Cm6, EMaj7, etc.) is a hard constraint —
        // never widen across the tonic family. Plain triads upgraded by the
        // category numeral pass still get widened.
        if ($explicitQuality) {
            return $aliases;
        }
        if (!$this->settings->isTonicWidenEnabled($category) || $functionalRole === null) {
            return $aliases;
        }

        $tonicMajorRoles = ['Imaj7', 'I6'];
        $tonicMinorRoles = ['Im7', 'Im6', 'ImMaj7'];
        // Iaug is a chromatic passing chord in a line cliché — never widened.

        $isMajorTonic = in_array($functionalRole, $tonicMajorRoles, true);
        $isMinorTonic = in_array($functionalRole, $tonicMinorRoles, true);

        // When tonality='both', a plain tonic numeral (I, I6) should match both
        // major and minor shapes — the progression is key-agnostic and the caller
        // wants any idiomatic tonic voicing (e.g. samba line cliché).
        $bothTonalities = ($tonality === 'both');

        if ($isMajorTonic && in_array($dbQuality, ['maj7', 'maj6', '6', 'maj'], true)) {
            $wide = ['maj7', 'maj6', '6'];
            if ($bothTonalities) {
                $wide = array_merge($wide, ['m', 'm7', 'm6', 'mMaj7', 'min']);
            }
            return array_values(array_unique(array_merge($aliases, $wide)));
        }

        if ($isMinorTonic && in_array($dbQuality, ['m7', 'm6', 'mMaj7', 'm', 'min'], true)) {
            $wide = ['m7', 'm6', 'mMaj7'];
            if ($bothTonalities) {
                $wide = array_merge($wide, ['maj7', 'maj6', '6']);
            }
            return array_values(array_unique(array_merge($aliases, $wide)));
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

        $currentRoleForFilter = $this->determineFunctionalRole($chord);
        $filtered = array_filter($pool, function ($v) use ($quality, $nextIsMinor, $chord, $nextChord, $index, $allChords, $currentRoleForFilter) {
            $labels = array_map('trim', explode(',', $v->interval_labels ?? ''));
            $ext = trim($v->extensions ?? '');

            // m7b5: exclude natural 9
            if ($this->isHalfDimQuality($quality)) {
                if (in_array('9', $labels, true)) return false;
            }

            // dom7 → minor: exclude natural 13, natural 9, #9.
            // Skip for tritone subs (bII7): a Lydian-dominant tritone sub uses
            // naturals + #11; altered tones come from the Phase E avoid filter
            // below. The "naturals imply major resolution" heuristic is for V7
            // → Im only.
            if ($this->isDomQuality($quality) && $nextIsMinor && $currentRoleForFilter !== 'bII7') {
                foreach ($labels as $l) {
                    if (in_array($l, ['13', '6', '9', '#9'], true)) return false;
                }
            }

            // Major-key II7 (V7/V) is a bright Lydian-dominant colour — only
            // the naturals and #11 are idiomatic. The Phase-E avoid_tones rule
            // for "II7" enforces this, but ONLY when the chord carries a
            // Phase-E extension upgrade; a plain D7 that wasn't upgraded skips
            // that block, and Pass-2 voice-leading would then be free to pick
            // a b9/#9/b13 voicing to chase a guide-tone line (e.g. the second
            // of two II7s feeding a IIm7). Filter the pool unconditionally so
            // an altered voicing is never in scope for a major-key II7.
            if ($this->isDomQuality($quality)
                && $this->createAvoidTonesContext($currentRoleForFilter, null, $chord['tonality'] ?? 'major', $chord) === 'II7') {
                foreach ($labels as $l) {
                    if (in_array($l, ['b9', '#9', 'b13'], true)) return false;
                }
            }

            // Tonic maj7: exclude #11 (unless numeral explicitly requests it)
            if ($this->isTonicMajQuality($quality)) {
                if (in_array('#11', $labels, true)) return false;
                if (str_contains($ext, '#11')) return false;
            }

            // Hardcoded extension: the chord name (e.g. Eb7#11) is the source
            // of truth. Reject voicings carrying any extension tone that the
            // chord name doesn't list — both alterations (b9, #9, b13, #11)
            // and naturals (9, 11, 13). Chord tones (R, 3, b3, 5, 7, b7, etc.)
            // are always present and don't count as extensions.
            if (!empty($chord['phase_e_hardcoded'])) {
                $requested = $chord['phase_e_extensions'] ?? [];
                $extensionTones = ['9', 'b9', '#9', '11', '#11', '13', 'b13'];
                foreach ($labels as $l) {
                    if (in_array($l, $extensionTones, true) && !in_array($l, $requested, true)) {
                        return false;
                    }
                }
            }

            // Phase E avoid tones filter (E.1.3)
            if ($this->hasPhaseEExtensions($chord)) {
                // Determine functional context for avoid tones checking
                $currentRole = $this->determineFunctionalRole($chord);
                $nextRole = $nextChord ? $this->determineFunctionalRole($nextChord) : null;
                $keyMode = $chord['tonality'] ?? 'major';
                
                // Create context string for avoid tones lookup
                $contextString = $this->createAvoidTonesContext($currentRole, $nextRole, $keyMode, $chord);
                
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
    private function createAvoidTonesContext(string $currentRole, ?string $nextRole, string $keyMode, array $chord = []): string
    {
        // A degree-2 secondary dominant (II7 = V7/V) collapses to the generic
        // V7 role in determineFunctionalRole(). Restore its own avoid-tones
        // context so the YAML "II7" rule applies. In a *major* key II7 is a
        // bright Lydian-dominant colour — only the natural extensions and #11
        // are idiomatic, so b9/#9/b13 are forbidden (the YAML "II7" row). In a
        // *minor* key II7 borrows the altered palette and keeps the generic
        // V7-family handling, so fall through.
        if ($currentRole === 'V7' && $keyMode === 'major') {
            $romanNumeral = $chord['roman_numeral'] ?? '';
            if ($this->romanToDegree(rtrim($romanNumeral, 'm')) === 2
                && ! str_starts_with($romanNumeral, 'b')
                && ! str_starts_with($romanNumeral, '#')) {
                return 'II7';
            }
        }

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
     * Describe every named resolution that fires on a chosen edge, with the
     * exact note pair involved — the display-layer companion of
     * evaluateNamedResolutions. Runs for EVERY category and pass (the cost
     * bonus stays Pass-2/jazz-latin-only; this is diagnostics for the
     * frontend, which draws the guide-tone motion from these details).
     *
     * Each entry:
     *   id           — named resolution ID
     *   core         — bool, bedrock guide-tone motion (YAML `core` flag)
     *   same_string  — bool, motion happens on one string
     *   semitones    — signed motion
     *   from / to    — ['string' (1-based, low E = 1), 'fret', 'midi', 'tone']
     */
    private function describeFiredResolutions(object $v1, object $v2, array $context): array
    {
        $sourceChord = $context['source_chord'] ?? null;
        $targetChord = $context['target_chord'] ?? null;
        if (!$sourceChord || !$targetChord) {
            return [];
        }

        $sourceNotes = $this->getVoicingMidiNotes($v1);
        $targetNotes = $this->getVoicingMidiNotes($v2);
        if (empty($sourceNotes) || empty($targetNotes)) {
            return [];
        }

        $sourceRootPc = $this->noteNameToPitchClass($sourceChord['root'] ?? 'C');
        $targetRootPc = $this->noteNameToPitchClass($targetChord['root'] ?? 'C');
        // Localized roles: a tonicizing dominant's target reads as a local
        // tonic so the minor remap (3 → b3) applies on e.g. III7 → VIm.
        [$sourceRole, $targetRole] = $this->localizedEdgeRoles(
            $sourceChord, $targetChord, ($targetRootPc - $sourceRootPc + 12) % 12
        );

        $details = [];
        foreach (ExtensionTable::getNamedResolutions() as $resolution) {
            // Prefer the same-string reading; fall back to nearest-voice.
            $result = $this->testNamedResolutionWithDebug($resolution, $sourceNotes, $targetNotes,
                                                          $sourceRootPc, $targetRootPc,
                                                          $sourceRole, $targetRole, 'same_string');
            if (!$result['fired']) {
                $result = $this->testNamedResolutionWithDebug($resolution, $sourceNotes, $targetNotes,
                                                              $sourceRootPc, $targetRootPc,
                                                              $sourceRole, $targetRole);
            }
            if (!$result['fired'] || empty($result['pair'])) {
                continue;
            }

            $pair = $result['pair'];
            // Mirror the minor-tonic display remap (3 → b3) the tester applies.
            $targetTone = (string) $resolution['target']['tone'];
            if ($targetTone === '3' && $this->isMinorTonicRole($targetRole)) {
                $targetTone = 'b3';
            }
            $details[] = [
                'id'          => $resolution['id'],
                'core'        => (bool) ($resolution['core'] ?? false),
                'same_string' => (bool) $pair['same_string'],
                'semitones'   => $pair['target_midi'] - $pair['source_midi'],
                'from' => [
                    'string' => $pair['source_string'] + 1,
                    'fret'   => $pair['source_midi'] - self::OPEN_MIDI[$pair['source_string'] + 1],
                    'midi'   => $pair['source_midi'],
                    'tone'   => $resolution['source']['tone'],
                ],
                'to' => [
                    'string' => $pair['target_string'] + 1,
                    'fret'   => $pair['target_midi'] - self::OPEN_MIDI[$pair['target_string'] + 1],
                    'midi'   => $pair['target_midi'],
                    'tone'   => $targetTone,
                ],
            ];
        }

        return $details;
    }

    // =========================================================================
    // PEDAGOGICAL CADENCE ENFORCEMENT
    // =========================================================================
    //
    // When buildVoicings runs with `pedagogical_vl: true` (the progression
    // library display surface), edges whose role pair matches a YAML
    // `cadence_requirements` entry get their required named resolutions
    // enforced as HARD edge admissibility in Viterbi — the picked voicing
    // pair must demonstrate the guide-tone motion (7→3, ti→do, …) the edu
    // prose describes, ideally on a single string.
    //
    // Each edge relaxes independently through: same_string → same_voice
    // (nearest voice, may cross strings) → dropped. The level is decided by a
    // feasibility pre-scan over the candidate pools, so one unsatisfiable
    // edge never drags down enforcement on the others.

    /** Active per-edge cadence requirements for the current search (keyed by source-slot index), or null. */
    private ?array $activeEdgeRequirements = null;

    /** Memo for per-pair requirement tests, keyed "edge:objIdFrom:objIdTo". */
    private array $edgeRequirementMemo = [];

    /**
     * Match cadence-requirement edges against the flattened chord list and
     * pre-scan each matched edge's candidate pools for the strictest
     * satisfiable enforcement level.
     */
    private function computeEdgeRequirements(array $allChords, array $lattice, string $category = self::CATEGORY_DEFAULT): array
    {
        $edgeReqs = $this->computeEdgeRequirementDefs($allChords, $category);

        // Per-edge feasibility: relax each edge independently to the
        // strictest level at least one candidate pair can satisfy.
        foreach ($edgeReqs as $i => &$req) {
            $poolA = $lattice[$i] ?? [];
            $poolB = $lattice[$i + 1] ?? [];
            foreach (['same_string', 'same_voice'] as $level) {
                $req['level'] = $level;
                if ($this->edgeHasSatisfiablePair($req, $poolA, $poolB)) {
                    continue 2;
                }
            }
            $req['level'] = 'dropped';
        }
        unset($req);

        return $edgeReqs;
    }

    /**
     * Role/interval matching half of requirement computation — no lattice
     * access, so it can run before voicing pools exist. Used both by
     * computeEdgeRequirements() (post-lattice, adds feasibility levels) and by
     * the pre-Phase-E scan that tells applyPhaseEExtensionUpgrade() which
     * slots sit on a requirement edge and must not be decorated with option
     * tones before the requirement pools are fetched (§6.6 discipline would
     * otherwise reject the decorated voicing and drop the edge).
     */
    private function computeEdgeRequirementDefs(array $allChords, string $category = self::CATEGORY_DEFAULT): array
    {
        $requirements = ExtensionTable::getCadenceRequirements();
        if (empty($requirements)) {
            return [];
        }

        $edgeReqs = [];
        $n = count($allChords);
        for ($i = 0; $i < $n - 1; $i++) {
            $source = $allChords[$i];
            $target = $allChords[$i + 1];

            $srcPc = $this->noteNameToPitchClass($source['root'] ?? 'C');
            $tgtPc = $this->noteNameToPitchClass($target['root'] ?? 'C');
            // A static root is never a resolution (II7 → IIm7) — don't bind.
            if ($srcPc === $tgtPc) {
                continue;
            }
            $rootInterval = ($tgtPc - $srcPc + 12) % 12;

            [$sourceRole, $targetRole] = $this->localizedEdgeRoles($source, $target, $rootInterval);

            $requiredIds = [];
            $oneOfIds = [];
            $rootPosition = [];
            $bassSemitones = null;
            $matchedReq = null;
            foreach ($requirements as $req) {
                // Optional root-interval guard: (target - source) mod 12.
                // Keeps e.g. cad.deceptive from binding a tonicizing III7→VIm
                // (that edge is an authentic cadence into the local minor).
                if (isset($req['edge']['interval']) && (int) $req['edge']['interval'] !== $rootInterval) continue;
                $srcRule = $req['edge']['source_role'] ?? '';
                $tgtRule = $req['edge']['target_role'] ?? '';
                if ($srcRule !== 'any' && !$this->resolutionRoleMatches($srcRule, $sourceRole)) continue;
                if (!$this->requirementTargetMatches($tgtRule, $targetRole)) continue;
                $matchedReq = $req['id'];
                foreach ($req['require'] ?? [] as $id) {
                    $requiredIds[] = $id;
                }
                foreach ($req['require_one_of'] ?? [] as $id) {
                    $oneOfIds[] = $id;
                }
                foreach ($req['root_position'] ?? [] as $side) {
                    $rootPosition[] = $side;
                }
                if (isset($req['bass_semitones'])) {
                    $bassSemitones = (int) $req['bass_semitones'];
                }
            }
            if (empty($requiredIds) && empty($oneOfIds) && empty($rootPosition) && $bassSemitones === null) {
                continue;
            }

            $lookup = function (array $ids): array {
                $out = [];
                foreach (array_unique($ids) as $id) {
                    $res = ExtensionTable::getNamedResolution($id);
                    if ($res) {
                        $out[$id] = $res;
                    }
                }
                return $out;
            };
            $resolutions = $lookup($requiredIds);
            $oneOf = $lookup($oneOfIds);

            // Every named resolution whose roles match this edge — used to
            // justify extension tones (§6.6 discipline): a tension is only
            // admissible when it carries a voice-leading story on this edge.
            $candidates = [];
            foreach (ExtensionTable::getNamedResolutions() as $res) {
                $srcMatch = $this->resolutionRoleMatches($res['source']['role'], $sourceRole);
                $tgtMatch = $res['target']['role'] === 'any_tonic'
                    || $this->resolutionRoleMatches($res['target']['role'], $targetRole);
                if ($srcMatch && $tgtMatch) {
                    $candidates[$res['id']] = $res;
                }
            }

            $edgeReqs[$i] = [
                'requirement_id' => $matchedReq,
                'resolutions'    => $resolutions,
                'one_of'         => $oneOf,
                'candidates'     => $candidates,
                'root_position'  => array_values(array_unique($rootPosition)),
                'bass_semitones' => $bassSemitones,
                // Plain-only categories never take tensions on cadence edges.
                'plain_only'     => in_array($category, ['classical', 'pop'], true),
                'source_root_pc' => $srcPc,
                'target_root_pc' => $tgtPc,
                'source_role'    => $sourceRole,
                'target_role'    => $targetRole,
                'level'          => 'same_string',
            ];
        }

        return $edgeReqs;
    }

    /**
     * Localize an edge's functional roles for guide-tone testing.
     *
     * A dominant that resolves down a fifth (root interval +5) tonicizes the
     * next chord — the ear hears that chord as a LOCAL tonic no matter what
     * global degree it sits on. III7 → VIm is an authentic cadence into the
     * relative minor, not a deceptive cadence; the minor-tonic remap
     * (3 → b3) and the tonic-family requirement matching must both apply.
     */
    private function localizedEdgeRoles(array $source, array $target, int $rootInterval): array
    {
        $sourceRole = $this->determineFunctionalRole($source);
        $targetRole = $this->determineFunctionalRole($target);

        if ($sourceRole === 'V7' && $rootInterval === 5) {
            $localTonic = match ($target['quality'] ?? '') {
                'maj7', 'maj9', 'maj13', 'maj' => 'Imaj7',
                '6', '69', 'maj6'              => 'I6',
                'm', 'min', 'm7', 'min7', 'mMin7' => 'Im7',
                'm6', 'min6'                   => 'Im6',
                'mMaj7'                        => 'ImMaj7',
                default                        => null,
            };
            if ($localTonic !== null) {
                $targetRole = $localTonic;
            }
        }

        return [$sourceRole, $targetRole];
    }

    /**
     * Requirement target-role match. `any_tonic` here means the strict tonic
     * families only — NOT the loose match-anything wildcard the
     * named_resolutions tester uses (a blues I7→IV7 edge must not bind).
     */
    private function requirementTargetMatches(string $ruleRole, string $actualRole): bool
    {
        if ($ruleRole === 'any') {
            return true;
        }
        if ($ruleRole === 'any_tonic') {
            return in_array($actualRole, ['Imaj7', 'I6', 'Im', 'Im7', 'Im6', 'ImMaj7'], true);
        }
        return $this->resolutionRoleMatches($ruleRole, $actualRole);
    }

    private function edgeHasSatisfiablePair(array $req, array $poolA, array $poolB): bool
    {
        foreach ($poolA as $vA) {
            foreach ($poolB as $vB) {
                if ($this->requirementPairSatisfied($req, $vA, $vB)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Full per-pair admissibility for a requirement edge at its enforcement
     * level:
     *
     *   1. bass_semitones — literal signed bass motion (passing-dim7 climb)
     *   2. root_position  — required sides must have the chord root in the
     *                       bass (no 6/4 tonic landings, no random inversions)
     *   3. require / require_one_of resolutions fire (same-string at level 1)
     *   4. extension discipline — every non-chord tone must be justified by
     *      a fired resolution on this edge; plain-only categories (classical,
     *      pop) take no tensions at all on cadence edges
     */
    private function requirementPairSatisfied(array $req, object $vA, object $vB): bool
    {
        if ($req['level'] === 'dropped') {
            return true;
        }
        $mode = $req['level'] === 'same_string' ? 'same_string' : null;

        $sourceNotes = $this->getVoicingMidiNotes($vA);
        $targetNotes = $this->getVoicingMidiNotes($vB);
        if (empty($sourceNotes) || empty($targetNotes)) {
            return false;
        }

        // 1. Literal bass motion (chromatic passing lines)
        if (($req['bass_semitones'] ?? null) !== null) {
            $b1 = $this->getBassNote($vA);
            $b2 = $this->getBassNote($vB);
            if ($b1 === null || $b2 === null || ($b2 - $b1) !== $req['bass_semitones']) {
                return false;
            }
        }

        // 2. Root-position anchors
        $rootPos = $req['root_position'] ?? [];
        if (in_array('source', $rootPos, true) && !$this->bassIsRoot($vA, $req['source_root_pc'])) {
            return false;
        }
        if (in_array('target', $rootPos, true) && !$this->bassIsRoot($vB, $req['target_root_pc'])) {
            return false;
        }

        // 3. Resolution firing — evaluate the edge's full candidate set once;
        //    fired tones double as the justification list for step 4.
        $fired = [];
        foreach ($req['candidates'] ?? [] as $id => $resolution) {
            $result = $this->testNamedResolutionWithDebug(
                $resolution, $sourceNotes, $targetNotes,
                $req['source_root_pc'], $req['target_root_pc'],
                $req['source_role'], $req['target_role'], $mode
            );
            if ($result['fired']) {
                $fired[$id] = $resolution;
            }
        }

        foreach (array_keys($req['resolutions']) as $id) {
            if (!isset($fired[$id])) {
                return false;
            }
        }
        if (!empty($req['one_of'])) {
            $anyFired = false;
            foreach (array_keys($req['one_of']) as $id) {
                if (isset($fired[$id])) {
                    $anyFired = true;
                    break;
                }
            }
            if (!$anyFired) {
                return false;
            }
        }

        // 4. Extension discipline
        return $this->extensionDisciplineSatisfied($req, $vA, $vB, $fired);
    }

    /**
     * Is the voicing's lowest sounded note the chord root?
     */
    private function bassIsRoot(object $v, int $rootPc): bool
    {
        $bass = $this->getBassNote($v);
        return $bass !== null && ($bass % 12) === $rootPc;
    }

    /**
     * §6.6 extension discipline.
     *
     * Non-chord tones are detected from interval_labels — NOT the
     * `extensions` column, which is empty on some tension-carrying shapes
     * (e.g. a G7#5 stored as plain dom7). In plain-only categories any
     * tension disqualifies the pair. Otherwise each tension must be
     * justified by a fired resolution on this edge: a source tension must be
     * some fired resolution's source tone (it leaves via voice leading), a
     * target tension must be some fired resolution's target tone (it arrived
     * via voice leading). Tensions with no voice-leading story — the
     * dom7(b13,#9) on a classical cadence — are inadmissible.
     */
    private function extensionDisciplineSatisfied(array $req, object $vA, object $vB, array $fired): bool
    {
        $srcTensions = $this->voicingTensionOffsets($vA);
        $tgtTensions = $this->voicingTensionOffsets($vB);

        if (empty($srcTensions) && empty($tgtTensions)) {
            return true;
        }
        if (!empty($req['plain_only'])) {
            return false;
        }

        $firedSrcOffsets = [];
        $firedTgtOffsets = [];
        foreach ($fired as $resolution) {
            $firedSrcOffsets[] = Interval::offset((string) $resolution['source']['tone']) % 12;
            $tgtTone = (string) $resolution['target']['tone'];
            if ($tgtTone === '3' && $this->isMinorTonicRole($req['target_role'])) {
                $tgtTone = 'b3';
            }
            $firedTgtOffsets[] = Interval::offset($tgtTone) % 12;
        }

        foreach ($srcTensions as $offset) {
            if (!in_array($offset, $firedSrcOffsets, true)) {
                return false;
            }
        }
        foreach ($tgtTensions as $offset) {
            if (!in_array($offset, $firedTgtOffsets, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Semitone offsets (0–11 from the chord root) of every tension tone in
     * the voicing, read from interval_labels. Chord tones (R/3/b3/5/b5/7ths)
     * are never tensions; 6 counts as a chord tone on 6-family qualities,
     * #5 on augmented, 2/4 on sus chords. Everything else (9/b9/#9/11/#11/
     * 13/b13, or a 6/#5 on a non-matching quality) is a tension.
     */
    private function voicingTensionOffsets(object $v): array
    {
        $labels = array_map('trim', explode(',', (string) ($v->interval_labels ?? '')));
        $quality = strtolower((string) ($v->quality ?? ''));

        $allowed = ['', 'x', 'r', '1', '3', 'b3', '5', 'b5', '7', 'b7', 'maj7', 'bb7'];
        if (str_contains($quality, '6')) {
            $allowed[] = '6';
        }
        if (str_contains($quality, 'aug') || str_contains($quality, '+')) {
            $allowed[] = '#5';
        }
        if (str_contains($quality, 'sus')) {
            $allowed[] = '2';
            $allowed[] = '4';
            $allowed[] = 'sus2';
            $allowed[] = 'sus4';
        }

        $tensions = [];
        foreach ($labels as $label) {
            if (in_array(strtolower($label), $allowed, true)) {
                continue;
            }
            // Interval::offset throws on non-tone labels — skip what we
            // can't classify rather than reject the voicing.
            if (!Interval::isValid($label)) {
                continue;
            }
            $tensions[] = Interval::offset($label) % 12;
        }

        return array_values(array_unique($tensions));
    }

    /**
     * Memoized per-edge admissibility check used inside Viterbi.
     */
    private function edgeRequirementSatisfied(int $edgeIdx, object $from, object $to): bool
    {
        $req = $this->activeEdgeRequirements[$edgeIdx] ?? null;
        if ($req === null || $req['level'] === 'dropped') {
            return true;
        }

        $key = $edgeIdx . ':' . spl_object_id($from) . ':' . spl_object_id($to);
        if (!array_key_exists($key, $this->edgeRequirementMemo)) {
            $this->edgeRequirementMemo[$key] = $this->requirementPairSatisfied($req, $from, $to);
        }
        return $this->edgeRequirementMemo[$key];
    }

    /**
     * viterbiSearchWithRelaxation plus a last-resort pedagogical fallback:
     * if no global path satisfies the (already per-edge-relaxed) cadence
     * requirements, abandon enforcement rather than return nothing.
     */
    private function searchWithPedagogicalFallback(array $lattice, array $context, ?string $group, ?array &$diagnostics, array $allChords): array
    {
        $path = $this->viterbiSearchWithRelaxation($lattice, $context, $group, $diagnostics, $allChords);
        if (!empty($path) || $this->activeEdgeRequirements === null) {
            return $path;
        }

        // Not restored afterwards: a later pass must search the same space
        // as the one it is cost-compared against.
        $this->activeEdgeRequirements = null;
        $diagnostics['pedagogical_vl_abandoned'] = true;

        return $this->viterbiSearchWithRelaxation($lattice, $context, $group, $diagnostics, $allChords);
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

    /**
     * @param string|null $motionMode Override for the motion test:
     *   - null           — YAML semantics (same_voice ⇒ nearest-voice test)
     *   - 'same_string'  — the motion must happen on ONE string (pedagogical
     *                      enforcement: the dot visibly slides on the diagram)
     *
     * When the resolution fires, the returned array carries a 'pair' with the
     * exact note pair that satisfied the motion (0-based string indices,
     * low E = 0) so callers can point at the two dots involved.
     */
    private function testNamedResolutionWithDebug(array $resolution, array $sourceNotes, array $targetNotes,
                                                 int $sourceRootPc, int $targetRootPc,
                                                 string $sourceRole, string $targetRole,
                                                 ?string $motionMode = null): array
    {
        // Check role matching (support 'any_tonic' wildcard)
        $sourceMatch = $this->resolutionRoleMatches($resolution['source']['role'], $sourceRole);
        $targetMatch = $resolution['target']['role'] === 'any_tonic'
            || $this->resolutionRoleMatches($resolution['target']['role'], $targetRole);

        if (!$sourceMatch || !$targetMatch) {
            $reason = "role_mismatch";
            if (!$sourceMatch) $reason .= " (source: {$resolution['source']['role']} != $sourceRole)";
            if (!$targetMatch) $reason .= " (target: {$resolution['target']['role']} != $targetRole)";
            return ['fired' => false, 'reason' => $reason];
        }

        // The minor tonic is a *family* of roles (Im, Im7, Im6, ImMaj7) — the
        // YAML resolution table refers to it as bare "Im". A minor target's 3rd
        // is a b3, a whole step below the dominant's b7 rather than a half step.
        // Remap "3" → "b3" and shift the expected motion by one extra semitone
        // down so vl.dom.b7_to_3 (and friends) fire into minor as the YAML
        // comment promises ("3 of major or b3 of minor").
        // NB: YAML parses a bare `tone: 3` as an integer — compare as string,
        // or the remap silently never fires.
        if ($this->isMinorTonicRole($targetRole) && (string) ($resolution['target']['tone'] ?? '') === '3') {
            $resolution['target']['tone'] = 'b3';
            $resolution['motion']['semitones'] = ($resolution['motion']['semitones'] ?? 0) - 1;
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

        if ($motionMode === 'same_string') {
            // Pedagogical enforcement: the motion must land on the same string.
            $pair = $this->findSameStringMotionPair($sourceNotes, $targetNotes,
                                                    $sourceRootPc, $targetRootPc,
                                                    $sourceTone, $targetTone, $expectedSemitones);
            return ['fired' => $pair !== null, 'reason' => $pair ? 'fired' : 'same_string_mismatch', 'pair' => $pair];
        }

        if ($sameVoice) {
            // Same voice constraint: nearest-voice motion (see findSameVoiceMotionPair)
            $pair = $this->findSameVoiceMotionPair($sourceNotes, $targetNotes,
                                                   $sourceRootPc, $targetRootPc,
                                                   $sourceTone, $targetTone, $expectedSemitones);
            return ['fired' => $pair !== null, 'reason' => $pair ? 'fired' : 'same_voice_mismatch', 'pair' => $pair];
        }

        // Any voice pair constraint (not currently used in YAML)
        $motionResult = $this->testAnyVoiceMotion($sourceNotes, $targetNotes,
                                                 $sourceRootPc, $targetRootPc,
                                                 $sourceTone, $targetTone, $expectedSemitones);
        return ['fired' => $motionResult, 'reason' => $motionResult ? "fired" : "motion_mismatch", 'pair' => null];
    }

    /**
     * Match a named-resolution role against an actual functional role.
     *
     * The YAML refers to tonic chords by their bare quality-free role (`Im`,
     * `Imaj7`), but determineFunctionalRole() returns quality-specific roles
     * (`Im7`, `Im6`, `ImMaj7`, `I6`). Without family matching the minor-tonic
     * resolutions (vl.dom.b9_to_5, vl.dom.b13_to_5, …) never fire because
     * `Im` !== `Im7`.
     */
    private function resolutionRoleMatches(string $ruleRole, string $actualRole): bool
    {
        if ($ruleRole === $actualRole) {
            return true;
        }

        $families = [
            'Im'    => ['Im', 'Im7', 'Im6', 'ImMaj7'],
            'Imaj7' => ['Imaj7', 'I6'],
            // The half-diminished ii is the minor-key member of the ii family —
            // its b7→3 descent into V7 is identical to IIm7's, so the ii–V
            // guide-tone entries (and cad.ii_v enforcement) apply to both.
            'IIm7'  => ['IIm7', 'IIm7b5'],
        ];

        return isset($families[$ruleRole]) && in_array($actualRole, $families[$ruleRole], true);
    }

    /**
     * True for any role in the minor-tonic family.
     */
    private function isMinorTonicRole(string $role): bool
    {
        return in_array($role, ['Im', 'Im7', 'Im6', 'ImMaj7'], true);
    }

    /**
     * Find a nearest-voice motion pair satisfying the resolution, or null.
     *
     * NEAREST-VOICE motion (supersedes the original pitch-rank model).
     *
     * The pitch-rank definition (source tone and target tone must occupy the
     * SAME index in each voicing's bass-up sort) was chosen in the original
     * spec but proved too strict for guitar: in a real V7->I drop voicing the
     * target's 3rd routinely sits BELOW where the dominant's b7 was, so the
     * ranks cross and the bedrock b7->3 resolution never fires. Guitarists
     * (and the ear) hear it as one voice regardless of where it lands on the
     * staff. We therefore fire when the source tone moves to the target tone
     * by the expected signed interval via the CLOSEST actual note pair —
     * voicing-shape-agnostic, but still pinned to the exact interval (so it
     * is not the looser any-voice / pitch-class-only test).
     *
     * Among equally valid pairs, a same-string pair wins so the returned dots
     * describe the clearest visual motion available.
     *
     * @return array|null ['source_string','target_string' (0-based, low E = 0),
     *                     'source_midi','target_midi','same_string']
     */
    private function findSameVoiceMotionPair(array $sourceNotes, array $targetNotes,
                                             int $sourceRootPc, int $targetRootPc,
                                             string $sourceTone, string $targetTone, int $expectedSemitones): ?array
    {
        $sourceTargetPc = ($sourceRootPc + Interval::offset($sourceTone)) % 12;
        $targetTargetPc = ($targetRootPc + Interval::offset($targetTone)) % 12;

        $best = null;
        $bestRank = PHP_INT_MAX;
        foreach ($sourceNotes as $si => $s) {
            if ($s === -1 || ($s % 12) !== $sourceTargetPc) continue;
            foreach ($targetNotes as $ti => $t) {
                if ($t === -1 || ($t % 12) !== $targetTargetPc) continue;
                if (($t - $s) !== $expectedSemitones) continue;
                // Same-string pairs outrank cross-string pairs; then closer wins.
                $rank = abs($t - $s) + ($si === $ti ? 0 : 100);
                if ($rank < $bestRank) {
                    $bestRank = $rank;
                    $best = [
                        'source_string' => $si,
                        'target_string' => $ti,
                        'source_midi'   => $s,
                        'target_midi'   => $t,
                        'same_string'   => $si === $ti,
                    ];
                }
            }
        }

        return $best;
    }

    /**
     * Find a SAME-STRING motion pair satisfying the resolution, or null.
     *
     * The pedagogical variant of findSameVoiceMotionPair: source and target
     * tone must sound on the same string, so the diagram shows one dot
     * sliding by the exact interval — the clearest possible picture of the
     * guide-tone motion for a student.
     */
    private function findSameStringMotionPair(array $sourceNotes, array $targetNotes,
                                              int $sourceRootPc, int $targetRootPc,
                                              string $sourceTone, string $targetTone, int $expectedSemitones): ?array
    {
        $sourceTargetPc = ($sourceRootPc + Interval::offset($sourceTone)) % 12;
        $targetTargetPc = ($targetRootPc + Interval::offset($targetTone)) % 12;

        for ($i = 0; $i < 6; $i++) {
            $s = $sourceNotes[$i] ?? -1;
            $t = $targetNotes[$i] ?? -1;
            if ($s === -1 || $t === -1) continue;
            if (($s % 12) !== $sourceTargetPc) continue;
            if (($t % 12) !== $targetTargetPc) continue;
            if (($t - $s) !== $expectedSemitones) continue;

            return [
                'source_string' => $i,
                'target_string' => $i,
                'source_midi'   => $s,
                'target_midi'   => $t,
                'same_string'   => true,
            ];
        }

        return null;
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
        // Pre-computed MIDI notes win when present.
        if (isset($voicing->midi_notes) && is_array($voicing->midi_notes)) {
            return $voicing->midi_notes;
        }

        // The canonical representation is the 6-char `frets` string, low-E first
        // (e.g. "xa9aax"). Frets 10+ are encoded as hex letters (a=10, b=11, …),
        // so they MUST be decoded with hexdec — a naive (int) cast silently turns
        // every fret >= 10 into an open string, corrupting the pitch set and
        // breaking guide-tone resolution firing for any voicing above fret 9.
        // Mirrors the decode at positionHintCost() / VoicingMaterializer.
        $fretStr = (string) ($voicing->frets ?? '');
        if ($fretStr === '') {
            return [];
        }

        $notes = [];
        for ($string = 0; $string < 6; $string++) {
            $ch = $fretStr[$string] ?? 'x';
            if ($ch === 'x' || $ch === 'X' || $ch === '-') {
                $notes[] = -1; // muted
                continue;
            }
            $fret = ctype_digit($ch) ? (int) $ch : hexdec($ch); // 10+ frets are hex
            $notes[] = self::OPEN_MIDI[$string + 1] + $fret;
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
        $extensionPenalty = $this->extensionCount($voicing)
            + $this->halfDimOptionTonePenalty($voicing, $quality);

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

    /**
     * Surcharge for option (extension) tones on a half-diminished voicing.
     * Returns the *extra* extension-equivalent units to add to a simplicity
     * calculation — zero for any non-m7b5 quality, or a plain m7b5.
     *
     * See HALF_DIM_OPTION_TONE_PENALTY: the m7b5(11) is idiomatic but reads
     * as late-intermediate, so a standard minor ii–V–i should land the plain
     * m7b5 unless an option-tone shape voice-leads far better.
     */
    private function halfDimOptionTonePenalty(object $voicing, ?string $quality = null): float
    {
        $quality ??= $this->resolveVoicingQuality($voicing);
        if (!$this->isHalfDimQuality($quality)) {
            return 0.0;
        }

        return $this->extensionCount($voicing) * self::HALF_DIM_OPTION_TONE_PENALTY;
    }

    /**
     * Melody-position pull (audio transcription).
     *
     * When the caller supplies per-measure melody fret hints, a chord voicing
     * is penalised for sitting far from where the melody's fretting hand is in
     * that measure — both are played by one hand. Without this, the Viterbi
     * anchors the whole progression on the first chord's seed and then only
     * minimises *relative* fret motion, so an open shape (x0221x) can win even
     * when the melody lives at the 5th–8th fret (5775xx).
     *
     * Hints are a measure_index => target_fret map under $context['position_hints'].
     * No hint for a measure → 0.0 (term inert). Strength is deliberately high:
     * the user opted for a strong pull for chord-melody material.
     *
     * @param int|null $measureIndex Global measure index of the candidate's slot.
     */
    private function positionHintCost(object $candidate, array $context, ?int $measureIndex): float
    {
        if ($measureIndex === null) return 0.0;
        $hints = $context['position_hints'] ?? null;
        if (empty($hints) || !isset($hints[$measureIndex])) return 0.0;

        $target = (float) $hints[$measureIndex];

        // Where is the fretting hand for this voicing? Mean of the *fretted*
        // (non-open, non-muted) positions — that's the hand's centre. Open and
        // muted strings don't move the hand, so they're excluded.
        $fretted = [];
        foreach (str_split((string) ($candidate->frets ?? '')) as $ch) {
            if ($ch === 'x' || $ch === 'X' || $ch === '0') continue;
            $f = is_numeric($ch) ? (int) $ch : hexdec($ch); // 10+ frets are hex
            if ($f > 0) $fretted[] = $f;
        }

        if (empty($fretted)) {
            // Fully open voicing — playable from anywhere, so only a soft pull
            // proportional to how high the melody sits.
            return $this->boundedCost(abs($target) / 12.0) * self::POSITION_HINT_WEIGHT * 0.4;
        }

        $hand = array_sum($fretted) / count($fretted);
        // Distance in frets, normalised over the neck, scaled by the strong weight.
        return $this->boundedCost(abs($hand - $target) / 7.0) * self::POSITION_HINT_WEIGHT;
    }

    private function seedCost(object $candidate, string $category, array $context = [], ?int $measureIndex = null): float
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

        // Melody position hint dominates the fixed category seed bias when
        // present — the first chord anchors the whole progression, so it must
        // sit where the melody is rather than at a generic category register.
        $hintCost = $this->positionHintCost($candidate, $context, $measureIndex);
        $hasHint  = isset(($context['position_hints'] ?? [])[$measureIndex]);

        $pos = $candidate->start_fret ?? $candidate->position ?? 5;
        $cost = $hasHint
            ? $hintCost
            : $this->boundedCost(abs($pos - $target) / $range);

        // Phase G: add style penalty to seed cost
        $cost += $this->costStyle($candidate, $context);

        // seedCost has no simplicity term, so a first-slot m7b5 would pick an
        // option-tone voicing for free. Carry the half-dim surcharge here too
        // so a leading IIm7b5 prefers the plain shape.
        $cost += $this->halfDimOptionTonePenalty($candidate);

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
        // Global measure index per slot — used to look up melody position hints.
        $measureOf = fn(int $slot): ?int => isset($allChords[$slot]['measure_index'])
            ? (int) $allChords[$slot]['measure_index']
            : null;

        // Slots with empty pools are treated as null pass-throughs: the path
        // skips them with zero edge cost so other slots still get voicings.
        $emptySlots = [];
        foreach ($pools as $i => $pool) {
            if (empty($pool)) {
                $emptySlots[$i] = true;
            }
        }

        if ($n === 1) {
            if (empty($pools[0])) return [null];
            $category = $context['category'] ?? self::CATEGORY_DEFAULT;
            $m0 = $measureOf(0);
            $best = $pools[0][0];
            $bestCost = $this->seedCost($best, $category, $context, $m0);
            foreach ($pools[0] as $c) {
                $cost = $this->seedCost($c, $category, $context, $m0);
                if ($cost < $bestCost) {
                    $bestCost = $cost;
                    $best = $c;
                }
            }
            return [$best];
        }

        $category = $context['category'] ?? self::CATEGORY_DEFAULT;
        $INF = 999999.0;

        // For empty slots, inject a single null sentinel so the DP can traverse them.
        $effectivePools = [];
        foreach ($pools as $i => $pool) {
            $effectivePools[$i] = empty($pool) ? [null] : $pool;
        }

        $cost = [];
        $prev = [];

        if (isset($emptySlots[0])) {
            $cost[0] = [0.0];
        } else {
            $cost[0] = array_map(
                fn($c) => $this->seedCost($c, $category, $context, $measureOf(0)),
                $effectivePools[0]
            );
        }
        $prev[0] = array_fill(0, count($effectivePools[0]), null);

        for ($i = 1; $i < $n; $i++) {
            $cost[$i] = [];
            $prev[$i] = [];
            $measureIdx = $measureOf($i);
            $currEmpty = isset($emptySlots[$i]);
            $prevEmpty = isset($emptySlots[$i - 1]);

            foreach ($effectivePools[$i] as $k => $cCurr) {
                $best = $INF;
                $bestPrev = null;
                foreach ($effectivePools[$i - 1] as $j => $cPrev) {
                    // Empty-slot edges: carry cost through with zero transition cost.
                    if ($currEmpty || $prevEmpty) {
                        $total = $cost[$i - 1][$j];
                        if ($total < $best) {
                            $best = $total;
                            $bestPrev = $j;
                        }
                        continue;
                    }

                    if (!$this->isEdgeAdmissible($cPrev, $cCurr, $positionLimit)) {
                        continue;
                    }

                    // Pedagogical cadence enforcement: the required guide-tone
                    // motion is a hard edge filter, not a bonus.
                    if ($this->activeEdgeRequirements !== null
                        && !$this->edgeRequirementSatisfied($i - 1, $cPrev, $cCurr)) {
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
                // Per-slot node cost: pull this chord toward the melody's hand
                // position in its measure. Same value for every path into node
                // k, so it doesn't disturb the edge argmin above.
                $nodeCost = $currEmpty ? 0.0 : $this->positionHintCost($cCurr, $context, $measureIdx);
                $cost[$i][$k] = $best + $nodeCost;
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
            if ($idx === null || !isset($effectivePools[$i][$idx])) {
                return [];
            }
            // null sentinel → no voicing for this slot
            $path[$i] = $effectivePools[$i][$idx];
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
     * @param object      $v                The voicing object from the DB
     * @param string|null $contextChordName The chord_name from the context (after numeral upgrade)
     * @param array|null  $chordContext     The full chord context array (provides roman_numeral for role)
     */
    private function formatVoicing(object $v, ?string $contextChordName = null, ?array $chordContext = null): array
    {
        $dd = $v->diagram_data ?? null;
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

        $functionalRole = null;
        if ($chordContext !== null) {
            $functionalRole = $this->determineFunctionalRole([
                'quality'       => $quality,
                'roman_numeral' => $chordContext['roman_numeral'] ?? '',
            ]);
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
            'functional_role'  => $functionalRole,
        ];
    }
}

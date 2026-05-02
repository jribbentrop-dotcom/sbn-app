<?php

namespace App\Services;

use App\Models\ChordDiagram;
use App\Services\ChordShapeCalculator;
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

    public function __construct(ChordShapeCalculator $calculator)
    {
        $this->calculator = $calculator;
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
        'jazz' => ['drop2', 'drop3', 'shell', 'closed'],
        'blues' => ['archetype'], // Basic blues; advanced selected by style/extension trigger
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
        $rootOnly   = $options['rootOnly'] ?? false;
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
        if (!array_key_exists($category, self::CATEGORY_VOICING_POOLS)) {
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
            $chordVoicings[$i] = $this->fetchVoicingsForChord(
                $chord['root'],
                $chord['quality'],
                $chord['extension'],
                $style,
                $rootOnly,
                $category,
                $extensions,
                $i,
                $diagnostics
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

        // Handle repeated-chord reuse
        for ($i = 1; $i < $n; $i++) {
            if ($this->shouldReusePreviousVoicing(
                $allChords[$i]['chord_name'] ?? '',
                $allChords[$i - 1]['chord_name'] ?? null,
                $i,
                true,
                $options
            )) {
                if (!empty($lattice[$i - 1])) {
                    $lattice[$i] = [$lattice[$i - 1][0]];
                }
            }
        }

        $context = [
            'category' => $category,
            'vlLevel' => $vlLevel,
            'weight_overrides' => $options['weight_overrides'] ?? [],
        ];

        $selections = $this->viterbiSearchWithRelaxation($lattice, $context, $style, $diagnostics);

        // Compute VL scores between adjacent selections
        $vlScores = [];
        $pathCost = 0.0;
        for ($i = 0; $i < $n - 1; $i++) {
            if ($selections[$i] && $selections[$i + 1]) {
                $rawScore = $this->scoreVL($selections[$i], $selections[$i + 1], $vlLevel);
                $vlScores[] = $rawScore;
                $breakdown = $this->costBreakdown($selections[$i], $selections[$i + 1], [
                    'vlLevel' => $vlLevel,
                    'category' => $category,
                    'weight_overrides' => $options['weight_overrides'] ?? [],
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
        array &$diagnostics = null
    ): array {
        if (!$root) return [];

        // Normalize quality for DB lookup
        $dbQuality = $quality;
        if ($dbQuality === '7') $dbQuality = 'dom7';

        $qualityAliases = $this->getQualityAliases($dbQuality);

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

        // Apply category pool filter
        $shapes = $this->queryWithCategoryPool($query, $pool, $styleFilter, $rootOnly, $slotIndex, $diagnostics);

        if ($shapes->isEmpty()) return [];

        // Transpose each shape to the target root
        $results = [];
        foreach ($shapes as $shape) {
            if (!empty($shape->is_fixed_position) && strtolower($shape->root_note ?? '') !== strtolower($root)) {
                continue;
            }
            $calculated = $this->calculator->calculateFrets($shape, $root);

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
     * Get the voicing category pool for a given category, style, and extensions flag.
     *
     * Blues sub-mode trigger: advanced blues when style is structured OR extensions=true.
     * Explicit 'archetype' style = basic blues (no trigger).
     */
    private function getCategoryPool(string $category, string $style, bool $extensions): array
    {
        $basePool = self::CATEGORY_VOICING_POOLS[$category] ?? self::CATEGORY_VOICING_POOLS[self::CATEGORY_DEFAULT];

        // Blues sub-mode trigger (per user answer Q1)
        if ($category === 'blues') {
            $advancedBlues = in_array($style, ['shell', 'drop2', 'drop3', 'closed'], true)
                || $extensions === true;
            // Explicit 'archetype' style = basic blues (no trigger)
            if ($style === 'archetype') {
                $advancedBlues = false;
            }
            if ($advancedBlues) {
                return self::BLUES_ADVANCED_POOL;
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

        foreach ($pool as $poolIdx => $category) {
            $queryClone = clone $query;
            $queryClone->whereIn('voicing_category', [$category, '', null]); // Include empty/null as archetype

            // Apply style filter if within pool
            if ($styleFilter && $styleFilter !== 'drop' && in_array($styleFilter, $pool, true)) {
                $queryClone->where('voicing_category', $styleFilter);
            }

            // Root only
            if ($rootOnly) {
                $queryClone->where(function ($q) {
                    $q->where('inversion', 'root')
                      ->orWhereNull('inversion')
                      ->orWhere('inversion', '');
                });
            }

            // Exclude open voicings
            $queryClone->where(function ($q) {
                $q->where('voicing_category', '!=', 'open')
                  ->orWhereNull('voicing_category')
                  ->orWhere('voicing_category', '');
            });

            $shapes = $queryClone->limit(80)->get();

            if (!$shapes->isEmpty()) {
                // Apply non-barré priority for archetype pool
                if (in_array($category, ['archetype', '', null], true)) {
                    $shapes = $this->applyArchetypePriority($shapes);
                }
                return $shapes;
            }

            // Log fallback
            if ($diagnostics !== null && $poolIdx < count($pool) - 1) {
                $nextCategory = $pool[$poolIdx + 1] ?? 'unrestricted';
                $diagnostics['category_pool_fallbacks'][] = [
                    'slot' => $slotIndex,
                    'requested_pool' => $category,
                    'fallback_pool' => $nextCategory,
                    'reason' => 'no candidates',
                ];
            }
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
        // Tonic family (I, IV) uppercase plain → maj7
        // Non-tonic uppercase plain (II, III, VI, VII, bII, bIII, bVI, bVII) → dom7
        // Uppercase + m (IIm, IIIm, IVm, VIm, VIIm) → m7
        // Lowercase (ii, iii, vi) → m7
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

        // All other uppercase plain → dom7 (V, II, III, VI, VII, bII, bIII, bVI, bVII)
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
        $base = strtoupper(preg_replace('/^[b#]/', '', $numeral));
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

        $filtered = array_filter($pool, function ($v) use ($quality, $nextIsMinor) {
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

            return true;
        });

        return !empty($filtered) ? array_values($filtered) : $pool;
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
        $preferCategory = $options['category'] ?? '';
        $results        = [];

        foreach ($chordNames as $name) {
            $parsed  = $this->parseChordNameSimple($name);
            $root    = $parsed['root']      ?? null;
            $quality = $parsed['quality']   ?? 'maj';
            $ext     = $parsed['extension'] ?? '';

            $pool = $this->fetchVoicingsForChord($root, $quality, $ext, '', false);

            if (empty($pool)) {
                $results[] = ['chord_name' => $name, 'frets' => null, 'position' => 1, 'diagram_id' => null];
                continue;
            }

            if ($preferCategory !== '') {
                $preferred = array_filter($pool, fn($v) =>
                    strcasecmp($v->voicing_category ?? '', $preferCategory) === 0
                );
                $pick = !empty($preferred) ? array_values($preferred)[0] : $pool[0];
            } else {
                $pick = $pool[0];
            }


            $results[] = [
                'chord_name' => $name,
                'frets'      => $pick->frets,
                'position'   => $pick->start_fret ?? 1,
                'diagram_id' => $pick->id ?? null,
            ];
        }

        return $results;
    }

    /**
     * Minimal chord name parser for selectVoicingsForSequence.
     * Splits 'Dm7', 'G7', 'Cmaj7', 'F#m7b5' into root/quality/extension.
     */
    private function parseChordNameSimple(string $name): array
    {
        if (!preg_match('/^([A-G][#b]?)(.*)$/', trim($name), $m)) {
            return ['root' => null, 'quality' => 'maj', 'extension' => ''];
        }
        $root   = $m[1];
        $suffix = $m[2];

        $ext = '';
        if (preg_match('/\(([^)]+)\)/', $suffix, $em)) {
            $ext    = $em[1];
            $suffix = str_replace($em[0], '', $suffix);
        }

        $qualityMap = [
            'maj7' => 'maj7', 'Maj7' => 'maj7', 'M7'    => 'maj7',
            'maj9' => 'maj9', 'maj'  => 'maj',
            'm7b5' => 'm7b5', 'mMaj7'=> 'mMaj7',
            'm7'   => 'm7',   'min7' => 'm7',
            'm9'   => 'm9',   'm6'   => 'm6',
            'm'    => 'min',  'min'  => 'min',
            'dim7' => 'dim7', 'dim'  => 'dim',
            'aug7' => 'aug7', 'aug'  => 'aug',
            '13'   => 'dom7', '11'   => 'dom7',
            '9'    => 'dom7', '7'    => 'dom7',
            'sus4' => 'sus4', 'sus2' => 'sus2',
            '6'    => 'maj6',
        ];

        foreach ($qualityMap as $token => $q) {
            if ($token !== '' && str_starts_with($suffix, $token)) {
                return ['root' => $root, 'quality' => $q, 'extension' => $ext];
            }
        }

        return ['root' => $root, 'quality' => 'maj', 'extension' => $ext];
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
        ];

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
        ]);
    }

    private function costWeights(array $overrides, string $category = self::CATEGORY_DEFAULT): array
    {
        $weights = self::COST_WEIGHTS;
        if (isset(self::CATEGORY_REGISTER_WEIGHT[$category])) {
            $weights['register'] = self::CATEGORY_REGISTER_WEIGHT[$category];
        }
        return array_merge($weights, array_intersect_key($overrides, $weights));
    }

    private function costRegister(object $voicing, array $context): float
    {
        $category = $context['category'] ?? self::CATEGORY_DEFAULT;
        $target = self::CATEGORY_REGISTER_TARGET[$category] ?? 5;
        $pos = $voicing->start_fret ?? $voicing->position ?? null;
        if ($pos === null) return 0.0;
        return $this->boundedCost(abs($pos - $target) / 12.0);
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

    private function seedCost(object $candidate, string $category): float
    {
        $bias = self::CATEGORY_SEED_BIAS[$category] ?? self::CATEGORY_SEED_BIAS['jazz'];
        $target = $bias['target_fret'];
        $range = $bias['range'];
        $pos = $candidate->start_fret ?? $candidate->position ?? 5;
        return $this->boundedCost(abs($pos - $target) / $range);
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

    private function viterbiSearch(array $pools, array $context, ?int $positionLimit = 3): array
    {
        $n = count($pools);
        if ($n === 0) return [];
        if ($n === 1) {
            if (empty($pools[0])) return [];
            $category = $context['category'] ?? self::CATEGORY_DEFAULT;
            $best = $pools[0][0];
            $bestCost = $this->seedCost($best, $category);
            foreach ($pools[0] as $c) {
                $cost = $this->seedCost($c, $category);
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

        $cost[0] = array_map(fn($c) => $this->seedCost($c, $category), $pools[0]);
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
                    $edgeCost = $this->costBetween($cPrev, $cCurr, $context);
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
            $path[$i] = $pools[$i][$idx];
            $idx = $prev[$i][$idx];
        }

        return array_reverse($path);
    }

    private function viterbiSearchWithRelaxation(array $lattice, array $context, ?string $group, ?array &$diagnostics = null): array
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

            $path = $this->viterbiSearch($filteredLattice, $context, $positionLimit);

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

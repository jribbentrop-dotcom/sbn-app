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

    // =========================================================================
    // MAIN ENTRY POINT
    // =========================================================================

    /**
     * Generate voice-led voicings for a harmonic context.
     *
     * @param  array  $context  HarmonicContext output (from HarmonicContext::build*)
     * @param  array  $options  {
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
     * }
     */
    public function buildVoicings(array $context, array $options = []): array
    {
        $style      = $options['style'] ?? '';
        $extensions = $options['extensions'] ?? false;
        $rootOnly   = $options['rootOnly'] ?? false;
        $vlLevel    = $extensions ? 2 : 1;

        // Flatten all chords across sections into a single sequence
        $allChords = [];
        foreach ($context['sections'] as $section) {
            foreach ($section['chords'] as $chord) {
                $allChords[] = $chord;
            }
        }

        if (empty($allChords)) {
            return ['selections' => [], 'vl_scores' => []];
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
                $rootOnly
            );
        }

        // Run auto-suggest algorithm
        $selections = array_fill(0, $n, null);

        // Determine locked group
        $lockedGroup = $style ?: null;

        // Pick first voicing
        $candidates = $this->filterCandidates($chordVoicings[0], $lockedGroup, $rootOnly);
        if (!empty($candidates)) {
            // Prefer voicings near fret 5 (middle of neck) as starting position
            $best = $candidates[0];
            $bestDist = 999;
            foreach ($candidates as $v) {
                $avg = $this->averageFret($v);
                $dist = abs($avg - 5);
                if ($dist < $bestDist) {
                    $bestDist = $dist;
                    $best = $v;
                }
            }
            $selections[0] = $best;
            if (!$lockedGroup) {
                $lockedGroup = $this->voicingGroup($best->voicing_category ?? '');
            }
        }

        // Forward pass
        for ($i = 0; $i < $n; $i++) {
            if (!$selections[$i]) continue;
            if (!$lockedGroup) {
                $lockedGroup = $this->voicingGroup($selections[$i]->voicing_category ?? '');
            }
            for ($j = $i + 1; $j < $n; $j++) {
                if ($selections[$j]) break;
                $prev = $selections[$j - 1];
                if (!$prev) break;
                $pool = $chordVoicings[$j];
                if (empty($pool)) {
                    $selections[$j] = null;
                    continue;
                }
                // Apply harmonic suitability filter
                $pool = $this->applyHarmonyFilter($pool, $allChords, $j);
                $selections[$j] = $this->pickBestVL(
                    $prev, $pool, $vlLevel, $lockedGroup, $rootOnly, $extensions
                );
            }
        }

        // Backward pass
        for ($i = $n - 1; $i >= 0; $i--) {
            if (!$selections[$i]) continue;
            if (!$lockedGroup) {
                $lockedGroup = $this->voicingGroup($selections[$i]->voicing_category ?? '');
            }
            for ($j = $i - 1; $j >= 0; $j--) {
                if ($selections[$j]) break;
                $next = $selections[$j + 1];
                if (!$next) break;
                $pool = $chordVoicings[$j];
                if (empty($pool)) {
                    $selections[$j] = null;
                    continue;
                }
                $pool = $this->applyHarmonyFilter($pool, $allChords, $j);
                $selections[$j] = $this->pickBestVL(
                    $next, $pool, $vlLevel, $lockedGroup, $rootOnly, $extensions
                );
            }
        }

        // Fill remaining gaps
        for ($i = 0; $i < $n; $i++) {
            if ($selections[$i]) continue;
            $pool = $chordVoicings[$i];
            if (empty($pool)) continue;
            $pool = $this->applyHarmonyFilter($pool, $allChords, $i);
            $anchor = $i > 0 ? $selections[$i - 1] : null;
            if ($anchor) {
                $selections[$i] = $this->pickBestVL(
                    $anchor, $pool, $vlLevel, $lockedGroup, $rootOnly, $extensions
                );
            } else {
                $filtered = $this->filterCandidates($pool, $lockedGroup, $rootOnly);
                $selections[$i] = !empty($filtered) ? $filtered[0] : ($pool[0] ?? null);
            }
        }

        // Compute VL scores between adjacent selections
        $vlScores = [];
        for ($i = 0; $i < $n - 1; $i++) {
            if ($selections[$i] && $selections[$i + 1]) {
                $vlScores[] = $this->scoreVL($selections[$i], $selections[$i + 1], $vlLevel);
            } else {
                $vlScores[] = null;
            }
        }

        // Format output — include the full voicing pool per chord for the picker UI
        $result = [];
        foreach ($allChords as $i => $chord) {
            $sel  = $selections[$i];
            $pool = $chordVoicings[$i] ?? [];
            $result[] = [
                'chord_name'    => $chord['chord_name'],
                'roman_numeral' => $chord['roman_numeral'],
                'measure_index' => $chord['measure_index'],
                'voicing'       => $sel ? $this->formatVoicing($sel) : null,
                'voicings'      => array_values(array_map([$this, 'formatVoicing'], $pool)),
            ];
        }

        // Also include section structure so the UI can render section breaks
        $sections = [];
        foreach ($context['sections'] as $sec) {
            $sections[] = [
                'section_key' => $sec['section_key'] ?? '',
                'length'      => count($sec['chords']),
            ];
        }

        return [
            'selections' => $result,
            'vl_scores'  => $vlScores,
            'sections'   => $sections,
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
        bool $rootOnly
    ): array {
        if (!$root) return [];

        // Normalize quality for DB lookup
        $dbQuality = $quality;
        if ($dbQuality === '7') $dbQuality = 'dom7';

        $qualityAliases = $this->getQualityAliases($dbQuality);

        $query = DB::table('sbn_chord_diagrams')
            ->whereIn('quality', $qualityAliases)
            ->whereRaw("(bass_note = '' OR bass_note IS NULL)")
            ->orderByDesc('popularity');

        // Style filter
        if ($styleFilter && $styleFilter !== 'drop') {
            $query->where('voicing_category', $styleFilter);
        }

        // Root only
        if ($rootOnly) {
            $query->where(function ($q) {
                $q->where('inversion', 'root')
                  ->orWhereNull('inversion')
                  ->orWhere('inversion', '');
            });
        }

        // Exclude open voicings (but allow null/empty category — these are triads/archetypes)
        $query->where(function ($q) {
            $q->where('voicing_category', '!=', 'open')
              ->orWhereNull('voicing_category')
              ->orWhere('voicing_category', '');
        });

        $shapes = $query->limit(60)->get();

        if ($shapes->isEmpty()) return [];

        // Transpose each shape to the target root
        $results = [];
        foreach ($shapes as $shape) {
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

    // =========================================================================
    // CANDIDATE FILTERING
    // =========================================================================

    /**
     * Filter candidates by group and root-only constraints.
     */
    private function filterCandidates(array $voicings, ?string $lockedGroup, bool $rootOnly): array
    {
        // Try structured group first
        $filtered = array_filter($voicings, function ($v) use ($lockedGroup, $rootOnly) {
            if ($this->isOpenVoicing($v)) return false;
            if ($rootOnly && ($v->inversion ?? 'root') !== 'root') return false;
            if ($lockedGroup && $this->voicingGroup($v->voicing_category ?? '') !== $lockedGroup) return false;
            return $this->isStructuredGroup($this->voicingGroup($v->voicing_category ?? ''));
        });

        if (!empty($filtered)) return array_values($filtered);

        // Fallback: non-open, any group
        $filtered = array_filter($voicings, function ($v) use ($rootOnly) {
            if ($this->isOpenVoicing($v)) return false;
            if ($rootOnly && ($v->inversion ?? 'root') !== 'root') return false;
            return true;
        });

        return array_values(!empty($filtered) ? $filtered : $voicings);
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
    // PICK BEST VOICE-LED VOICING (4-level funnel)
    // =========================================================================

    /**
     * Pick the voicing from candidates with the best VL score to anchor.
     *
     * Candidate pools (each falls back to next if empty):
     *   A: same group AND same upper string set
     *   B: same group, any string set
     *   C: any structured non-open voicing
     *   D: anything
     *
     * Within the winning pool, if extensions mode is on, prefer voicings
     * with extensions — but fall back to full pool if none qualify.
     */
    private function pickBestVL(
        object $anchor,
        array $candidates,
        int $level,
        ?string $group,
        bool $rootOnly,
        bool $preferExtensions
    ): ?object {
        if (empty($candidates)) return null;

        $anchorSet = $this->upperStringSet($anchor);

        $rootFilter = function ($v) use ($rootOnly) {
            if (!$rootOnly) return true;
            return ($v->inversion ?? 'root') === 'root';
        };

        // Pool A: same group + same string set
        $poolA = array_filter($candidates, function ($v) use ($group, $anchorSet, $rootFilter) {
            if ($this->isOpenVoicing($v)) return false;
            if (!$rootFilter($v)) return false;
            if ($group && $this->voicingGroup($v->voicing_category ?? '') !== $group) return false;
            return $this->upperStringSet($v) === $anchorSet;
        });

        // Pool B: same group, any string set
        $poolB = array_filter($candidates, function ($v) use ($group, $rootFilter) {
            if ($this->isOpenVoicing($v)) return false;
            if (!$rootFilter($v)) return false;
            if ($group && $this->voicingGroup($v->voicing_category ?? '') !== $group) return false;
            return true;
        });

        // Pool C: any structured non-open voicing
        $poolC = array_filter($candidates, function ($v) use ($rootFilter) {
            return !$this->isOpenVoicing($v)
                && $rootFilter($v)
                && $this->isStructuredGroup($this->voicingGroup($v->voicing_category ?? ''));
        });

        // Pool C': any non-open
        $poolCp = array_filter($candidates, function ($v) use ($rootFilter) {
            return !$this->isOpenVoicing($v) && $rootFilter($v);
        });

        // Pick first non-empty pool
        $pool = !empty($poolA) ? array_values($poolA)
              : (!empty($poolB) ? array_values($poolB)
              : (!empty($poolC) ? array_values($poolC)
              : (!empty($poolCp) ? array_values($poolCp)
              : $candidates)));

        // Extension preference (soft)
        if ($preferExtensions) {
            $extPool = array_filter($pool, function ($v) {
                return !empty(trim($v->extensions ?? ''));
            });
            if (!empty($extPool)) $pool = array_values($extPool);
        }

        // Score within the chosen pool
        $best = $pool[0];
        $bestScore = 999;
        foreach ($pool as $v) {
            $s = $this->scoreVL($anchor, $v, $level);
            if ($s < $bestScore) {
                $bestScore = $s;
                $best = $v;
            }
        }

        // Cross-pool rescue: if a voicing from a lower-priority pool scores
        // significantly better, break the group lock
        $rescueThreshold = 4.0;
        if ($bestScore > $rescueThreshold) {
            $rescuePool = array_filter($candidates, function ($v) use ($pool) {
                return !$this->isOpenVoicing($v) && !in_array($v, $pool, true);
            });
            foreach ($rescuePool as $v) {
                $s = $this->scoreVL($anchor, $v, $level);
                if ($s < $bestScore - $rescueThreshold) {
                    $bestScore = $s;
                    $best = $v;
                }
            }
        }

        return $best;
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
        $preferCategory = $options['category'] ?? 'Shell';
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

            // Prefer the requested category; fall back to first available
            $preferred = array_filter($pool, fn($v) =>
                strcasecmp($v->voicing_category ?? '', $preferCategory) === 0
            );
            $pick = !empty($preferred) ? array_values($preferred)[0] : $pool[0];

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

        // ── 1b. WRONG-ALTERATION PENALTIES ──────────────────────────

        // Dom→minor: natural 13/9/#9 on source clash
        if ($targetIsMinor) {
            foreach ($pitchesA as $p) {
                if (in_array($p['label'], ['13', '6'], true)) $score += 10;
                if (in_array($p['label'], ['9', '#9'], true)) $score += 10;
            }
        }

        // #11 on major tonic target
        if ($targetIsMaj) {
            foreach ($pitchesB as $p) {
                if ($p['label'] === '#11') $score += 10;
            }
        }

        // Natural 9 on half-dim (source or target)
        if ($sourceIsHalfDim) {
            foreach ($pitchesA as $p) {
                if ($p['label'] === '9') $score += 8;
            }
        }
        if ($this->isHalfDimQuality($qualityB)) {
            foreach ($pitchesB as $p) {
                if ($p['label'] === '9') $score += 8;
            }
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
     */
    private function formatVoicing(object $v): array
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

        // Build a human-readable chord name for the UI
        $chordName = $root;
        if ($quality && !in_array($quality, ['maj', ''], true)) {
            $chordName .= $quality === 'dom7' ? '7' : $quality;
        }
        if ($ext) {
            $chordName .= '(' . $ext . ')';
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
            'diagram_data'     => $dd,
            'interval_labels'  => $v->interval_labels ?? '',
            'popularity'       => $v->popularity ?? 0,
        ];
    }
}

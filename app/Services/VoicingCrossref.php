<?php

namespace App\Services;

use App\Models\ChordDiagram;
use App\Models\ChordDiagramAlias;
use App\Models\Leadsheet;
use App\Models\VoicingDraft;
use App\Models\VoicingUsage;
use App\Services\ChordShapeCalculator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SBN Voicing Cross-Reference Engine
 *
 * Links leadsheet voicings to chord library archetypes.
 * When a leadsheet is processed, this service:
 *   1. Extracts voicings from the [sbn_voicings] block
 *   2. Matches each voicing against transposed chord diagram shapes
 *   3. Stores matches in sbn_voicing_usage
 *   4. Stores unmatched voicings as reviewable drafts in sbn_voicing_drafts
 *   5. Recalculates popularity counts on chord diagrams
 *
 * Also provides reverse lookup: identify a chord name from a fret string.
 *
 * Ported from WP class-voicing-crossref.php (3,426 lines → ~900 lines).
 */
class VoicingCrossref
{
    private ChordShapeCalculator $calculator;

    /** @var array<string, \Illuminate\Support\Collection> Cached shapes by quality key */
    private array $shapeCache = [];

    /** @var array<string, \Illuminate\Support\Collection> Cached alias shapes by quality key */
    private array $aliasCache = [];

    public function __construct(ChordShapeCalculator $calculator)
    {
        $this->calculator = $calculator;
    }

    /**
     * Clear caches (call between leadsheets if memory is a concern).
     */
    public function clearCache(): void
    {
        $this->shapeCache = [];
        $this->aliasCache = [];
    }

    // =========================================================================
    // CONSTANTS
    // =========================================================================

    /**
     * Quality aliases: maps parser names to DB-stored names and vice versa.
     * DB canonical form is 'dom7' (not '7').
     */
    private const QUALITY_ALIASES = [
        'dom7' => ['dom7', '7'], '7' => ['dom7', '7'],
        'min' => ['min', 'm'], 'm' => ['m', 'min'],
        'maj' => ['maj'], 'maj7' => ['maj7'], 'm7' => ['m7'], 'm7b5' => ['m7b5'],
        'o7' => ['o7', 'dim7'], 'dim7' => ['dim7', 'o7'], 'dim' => ['dim'],
        'aug' => ['aug', '+'], '+' => ['+', 'aug'],
        'mMaj7' => ['mMaj7'], 'aug7' => ['aug7'], 'maj6' => ['maj6'], 'm6' => ['m6'],
        'sus4' => ['sus4'], 'sus2' => ['sus2'], 'add9' => ['add9'], '5' => ['5'],
    ];

    /**
     * Known base qualities — sorted longest-first for greedy splitting.
     */
    private const KNOWN_BASE_QUALITIES = [
        'mMaj7','mmaj7','maj13','maj11',
        'maj9','maj7','maj6','m7b5','aug7','dim7','min7','min6','dom7','add9','sus4','sus2',
        'mM7','m13','m11','maj','min','dim','aug','sus',
        'M7','M6','o7','m9','m7','-7','m6','13','11',
        '9','7','5','M','m','-','o','+',
    ];

    /** Standard tuning: 0-based index → open semitone. Index 0 = low E. */
    private const TUNING = [4, 9, 2, 7, 11, 4];

    /** Note → semitone. */
    private const NOTE_SEMI = [
        'C'=>0,'C#'=>1,'Db'=>1,'D'=>2,'D#'=>3,'Eb'=>3,'E'=>4,
        'F'=>5,'F#'=>6,'Gb'=>6,'G'=>7,'G#'=>8,'Ab'=>8,'A'=>9,'A#'=>10,'Bb'=>10,'B'=>11,
    ];

    /** Chord tone intervals by quality (for fragment matching). */
    private const CHORD_TONES = [
        'maj'=>[0,4,7],'min'=>[0,3,7],'m'=>[0,3,7],
        'maj7'=>[0,4,7,11],'maj6'=>[0,4,7,9],'m7'=>[0,3,7,10],'m6'=>[0,3,7,9],
        'dom7'=>[0,4,7,10],'7'=>[0,4,7,10],'m7b5'=>[0,3,6,10],
        'o7'=>[0,3,6,9],'dim7'=>[0,3,6,9],'dim'=>[0,3,6],
        'mMaj7'=>[0,3,7,11],'aug7'=>[0,4,8,10],'aug'=>[0,4,8],'+'=>[0,4,8],
        'sus4'=>[0,5,7],'sus2'=>[0,2,7],'5'=>[0,7],
        '9'=>[0,2,4,7,10],'maj9'=>[0,2,4,7,11],'m9'=>[0,2,3,7,10],'add9'=>[0,2,4,7],
        '13'=>[0,2,4,7,9,10],'maj13'=>[0,2,4,7,9,11],'m13'=>[0,2,3,7,9,10],
        '11'=>[0,2,4,5,7,10],'maj11'=>[0,2,4,5,7,11],'m11'=>[0,2,3,5,7,10],
    ];

    /**
     * Open-string equivalence pairs for normalisation.
     * [lo_idx, fret_on_lo, hi_idx] — upward (lower string fretted = higher string open).
     */
    private const UPWARD_PAIRS = [[0,5,1],[1,5,2],[2,5,3],[3,4,4],[4,5,5]];
    /** [lo_idx, hi_idx, fret_on_hi] — downward. */
    private const DOWNWARD_PAIRS = [[0,1,7],[1,2,7],[2,3,7],[3,4,8],[4,5,7]];

    // =========================================================================
    // PUBLIC: PROCESS LEADSHEET
    // =========================================================================

    /**
     * Process a single leadsheet: extract voicings, match, store results.
     * Note: does NOT recalculate popularity — caller should do that after
     * processing (once for batch, or explicitly for single-leadsheet).
     */
    public function processLeadsheet(Leadsheet $leadsheet): array
    {
        $voicings = $this->extractVoicings($leadsheet->shortcode_content ?? '');

        // Clear previous references
        $this->clearLeadsheetReferences($leadsheet->id);

        if (empty($voicings)) {
            return ['matched' => 0, 'unmatched' => 0];
        }

        $matched = 0;
        $unmatched = 0;

        foreach ($voicings as $voicing) {
            $result = $this->matchVoicing($voicing);

            if ($result !== false) {
                $this->storeMatch($leadsheet->id, $voicing, $result);
                $matched++;
            } else {
                $this->storeDraft($leadsheet->id, $leadsheet->title, $voicing);
                $unmatched++;
            }
        }

        Log::info("VoicingCrossref: Processed '{$leadsheet->title}' — {$matched} matched, {$unmatched} unmatched");

        return ['matched' => $matched, 'unmatched' => $unmatched];
    }

    /**
     * Process ALL leadsheets (batch reprocess).
     */
    public function processAllLeadsheets(): array
    {
        $leadsheets = Leadsheet::whereNotNull('shortcode_content')
            ->where('shortcode_content', '!=', '')
            ->get();

        $results = ['processed' => 0, 'skipped' => 0];

        // Wrap all DB writes in a single transaction for SQLite performance
        DB::transaction(function () use ($leadsheets, &$results) {
            foreach ($leadsheets as $ls) {
                $voicings = $this->extractVoicings($ls->shortcode_content);
                if (empty($voicings)) {
                    $results['skipped']++;
                    continue;
                }
                $this->processLeadsheet($ls);
                $results['processed']++;
            }
        });

        // Recalculate popularity once after all leadsheets are processed
        $this->recalculatePopularity();

        $results['matched']   = VoicingUsage::count();
        $results['unmatched'] = VoicingDraft::pending()->count();

        return $results;
    }

    /**
     * Recalculate popularity for all chord diagrams based on usage count.
     */
    public function recalculatePopularity(): void
    {
        // Reset all to 0
        DB::table('sbn_chord_diagrams')->update(['popularity' => 0]);

        // SQLite-compatible: subquery approach
        $usageCounts = DB::table('sbn_voicing_usage')
            ->select('chord_diagram_id', DB::raw('COUNT(DISTINCT leadsheet_id) as usage_count'))
            ->groupBy('chord_diagram_id')
            ->get();

        foreach ($usageCounts as $row) {
            DB::table('sbn_chord_diagrams')
                ->where('id', $row->chord_diagram_id)
                ->update(['popularity' => $row->usage_count]);
        }
    }

    // =========================================================================
    // VOICING EXTRACTION
    // =========================================================================

    /**
     * Extract voicings from [sbn_voicings]...[/sbn_voicings] block.
     *
     * @return array[] Each with keys: chord_name, fret_string, position, fingers, root, quality, extension, bass_note
     */
    public function extractVoicings(string $content): array
    {
        if (!preg_match('/\[sbn_voicings\]([\s\S]*?)\[\/sbn_voicings\]/', $content, $match)) {
            return [];
        }

        // WP import artifact: shortcode_content may contain literal '\n' (two chars)
        // instead of actual newlines. Normalize before splitting.
        $voicingsText = str_replace('\\n', "\n", $match[1]);

        $voicings = [];
        $lines = array_filter(explode("\n", $voicingsText), 'trim');

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            // Format: ChordName: frets @position (fingers)
            $sepPos = strpos($line, ': ');
            if ($sepPos === false) continue;

            $chordName = trim(substr($line, 0, $sepPos));
            $rest      = trim(substr($line, $sepPos + 2));

            if (!preg_match('/^([x0-9a-fA-F]+)(?:\s*@(\d+))?(?:\s*\(([0-9]+)\))?/', $rest, $m)) continue;

            $fretString = trim($m[1]);
            $position   = isset($m[2]) ? intval($m[2]) : 1;
            $fingers    = $m[3] ?? '';

            // Skip override keys
            if (preg_match('/@\d+(\.\d+)?$/', $chordName)) continue;
            if (preg_match('/@\d+:\d+$/', $chordName)) continue;
            if (preg_match('/^#\d+\.\d+$/', $chordName)) continue;

            if (strlen($fretString) !== 6) continue;

            // Parse chord name — reuse the controller's parser logic
            $parsed = $this->parseChordName($chordName);

            $voicings[] = [
                'chord_name'  => $chordName,
                'fret_string' => $fretString,
                'position'    => $position,
                'fingers'     => $fingers,
                'root'        => $parsed['root'] ?? '',
                'quality'     => $parsed['quality'] ?? '',
                'extension'   => $parsed['extension'] ?? '',
                'bass_note'   => $parsed['bass_note'] ?? '',
            ];
        }

        return $voicings;
    }

    // =========================================================================
    // MATCHING ENGINE
    // =========================================================================

    /**
     * Match a single voicing against the chord library.
     *
     * Full pipeline: quality resolution → shape query → transposition →
     * fret comparison (exact → subset → superset → fragment) → alias lookup →
     * slash chord routing → inversion fallback.
     *
     * @return array|false Match data or false
     */
    public function matchVoicing(array $voicing): array|false
    {
        if (empty($voicing['root']) || empty($voicing['quality'])) {
            return false;
        }

        // Split quality into base + extensions
        $qualityParts = $this->splitQualityExtensions($voicing['quality']);
        $baseQuality  = $qualityParts['base'];
        $extensions   = $voicing['extension'] ?: $qualityParts['extensions'];

        // Get all alias variants for the base quality
        $qualityVariants = $this->getQualityVariants($baseQuality);

        // Query shapes from DB (cached per quality+extension key)
        $cacheKey = implode('|', $qualityVariants) . ':' . $extensions;

        if (!isset($this->shapeCache[$cacheKey])) {
            $shapesQuery = ChordDiagram::whereIn('quality', $qualityVariants);

            if (!empty($extensions)) {
                $shapes = (clone $shapesQuery)->where('extensions', $extensions)->get();
                if ($shapes->isEmpty()) {
                    $shapes = $shapesQuery->get();
                }
            } else {
                $shapes = $shapesQuery->get();
            }
            $this->shapeCache[$cacheKey] = $shapes;
        }
        $shapes = $this->shapeCache[$cacheKey];

        // Alias-based lookup (cached per quality key)
        $aliasCacheKey = implode('|', $qualityVariants) . ':' . $extensions;
        if (!isset($this->aliasCache[$aliasCacheKey])) {
            $this->aliasCache[$aliasCacheKey] = $this->findAliasShapes($qualityVariants, $extensions, $voicing);
        }
        $aliasShapes = $this->aliasCache[$aliasCacheKey];
        if ($aliasShapes->isNotEmpty()) {
            // Convert to base Collection to allow merging stdClass alias rows with Eloquent models
            $shapes = collect($shapes->all())->merge($aliasShapes);
        }

        if ($shapes->isEmpty()) {
            return false;
        }

        // Parse target fret array
        $targetFretArray = $this->normalizeOpenEquivalents(
            $this->parseFretString($voicing['fret_string'], $voicing['position'] ?? 1)
        );

        // Slash chord analysis
        $bassNote        = $voicing['bass_note'] ?? '';
        $slashType       = '';
        $targetInversion = '';

        if (!empty($bassNote)) {
            $slashInfo       = $this->calculator->analyzeSlashChord($voicing['root'], $baseQuality, $bassNote);
            $slashType       = $slashInfo['type'];
            $targetInversion = $slashInfo['inversion'];
        }

        // Main matching loop
        $bestMatch = null;
        $bestScore = 999;

        foreach ($shapes as $shape) {
            // Skip fixed-position shapes for different roots
            if ($shape->is_fixed_position && strtolower($shape->root_note ?? '') !== strtolower($voicing['root'])) {
                continue;
            }

            // For inversion slash chords, only try matching inversion shapes first
            if ($slashType === 'inversion' && !empty($targetInversion)) {
                if ($shape->inversion !== $targetInversion) continue;
            }

            // For true slash, only shapes with bass_note
            if ($slashType === 'slash') {
                if (!empty($shape->bass_note)) {
                    $calculated = $this->calculator->calculateFretsWithBass($shape, $voicing['root'], $bassNote);
                } else {
                    continue;
                }
            } else {
                $calculated = $this->calculator->calculateFrets($shape, $voicing['root']);
            }

            if (!$calculated || empty($calculated['diagram_data'])) continue;

            $calcFretArray = $this->normalizeOpenEquivalents(
                $this->diagramToFretArray($calculated['diagram_data'])
            );

            if (!$this->hasValidFrets($calcFretArray)) continue;

            // Exact match
            if ($this->fretArraysMatch($calcFretArray, $targetFretArray)) {
                return $this->buildMatchResult($shape, 'exact', $calcFretArray);
            }

            // Subset match (leadsheet omits strings from library shape)
            $subsetResult = $this->isSubsetMatch($targetFretArray, $calcFretArray);
            if ($subsetResult !== false && $subsetResult < $bestScore) {
                $bestScore = $subsetResult;
                $bestMatch = $this->buildMatchResult($shape, 'subset', $calcFretArray);
                continue; // subset found for this shape, move to next
            }

            // Superset match (leadsheet adds strings)
            $superResult = $this->isSupersetMatch($targetFretArray, $calcFretArray);
            if ($superResult !== false) {
                $penalized = $superResult + 100;
                if ($penalized < $bestScore) {
                    $bestScore = $penalized;
                    $bestMatch = $this->buildMatchResult($shape, 'superset', $calcFretArray);
                }
                continue; // superset found for this shape, move to next
            }

            // Fragment match (bass note relocated to open string)
            $fragResult = $this->isRootRelocatedFragmentMatch(
                $targetFretArray, $calcFretArray, $voicing['root'], $baseQuality
            );
            if ($fragResult !== false) {
                $penalized = $fragResult + 200;
                if ($penalized < $bestScore) {
                    $bestScore = $penalized;
                    $bestMatch = $this->buildMatchResult($shape, 'fragment', $calcFretArray);
                }
            }
        }

        // Inversion fallback: try root-position shapes
        if ($slashType === 'inversion' && !empty($targetInversion) && $targetInversion !== 'root' && !$bestMatch) {
            foreach ($shapes as $shape) {
                if ($shape->inversion === $targetInversion) continue;
                if ($shape->is_fixed_position && strtolower($shape->root_note ?? '') !== strtolower($voicing['root'])) continue;

                $calculated = $this->calculator->calculateFrets($shape, $voicing['root']);
                if (!$calculated || empty($calculated['diagram_data'])) continue;

                $calcFretArray = $this->normalizeOpenEquivalents(
                    $this->diagramToFretArray($calculated['diagram_data'])
                );
                if (!$this->hasValidFrets($calcFretArray)) continue;

                $superResult = $this->isSupersetMatch($targetFretArray, $calcFretArray);
                if ($superResult !== false) {
                    $penalized = $superResult + 150;
                    if ($penalized < $bestScore) {
                        $bestScore = $penalized;
                        $bestMatch = $this->buildMatchResult($shape, 'superset', $calcFretArray);
                    }
                }

                $fragResult = $this->isRootRelocatedFragmentMatch(
                    $targetFretArray, $calcFretArray, $voicing['root'], $baseQuality
                );
                if ($fragResult !== false) {
                    $penalized = $fragResult + 250;
                    if ($penalized < $bestScore) {
                        $bestScore = $penalized;
                        $bestMatch = $this->buildMatchResult($shape, 'fragment', $calcFretArray);
                    }
                }
            }
        }

        // True slash fallback: try all shapes
        if ($slashType === 'slash' && !$bestMatch) {
            foreach ($shapes as $shape) {
                if (!empty($shape->bass_note)) continue;

                $calculated = $this->calculator->calculateFrets($shape, $voicing['root']);
                if (!$calculated || empty($calculated['diagram_data'])) continue;

                $calcFretArray = $this->normalizeOpenEquivalents(
                    $this->diagramToFretArray($calculated['diagram_data'])
                );
                if (!$this->hasValidFrets($calcFretArray)) continue;

                if ($this->fretArraysMatch($calcFretArray, $targetFretArray)) {
                    return $this->buildMatchResult($shape, 'exact', $calcFretArray);
                }

                $subsetResult = $this->isSubsetMatch($targetFretArray, $calcFretArray);
                if ($subsetResult !== false && $subsetResult < $bestScore) {
                    $bestScore = $subsetResult;
                    $bestMatch = $this->buildMatchResult($shape, 'subset', $calcFretArray);
                }
            }
        }

        return $bestMatch ?: false;
    }

    // =========================================================================
    // FRET ARRAY COMPARISON
    // =========================================================================

    private function fretArraysMatch(array $a, array $b): bool
    {
        if (count($a) !== count($b)) return false;
        for ($i = 0; $i < count($a); $i++) {
            if ($a[$i] !== $b[$i]) return false;
        }
        return true;
    }

    /**
     * Subset: every sounding string in leadsheet matches library.
     * Library may have extra sounding strings (leadsheet omits them).
     * @return int|false Extra string count or false
     */
    private function isSubsetMatch(array $leadFrets, array $libFrets): int|false
    {
        if (count($leadFrets) !== count($libFrets)) return false;
        $extra = 0;
        for ($i = 0; $i < count($leadFrets); $i++) {
            if ($leadFrets[$i] === 'x') {
                if ($libFrets[$i] !== 'x') $extra++;
            } else {
                if ($libFrets[$i] === 'x' || intval($leadFrets[$i]) !== intval($libFrets[$i])) return false;
            }
        }
        return $extra === 0 ? false : $extra; // 0 extra = exact match, handled above
    }

    /**
     * Superset: every sounding string in library matches leadsheet.
     * Leadsheet may have extra notes (adds to the core shape).
     * @return int|false Extra string count or false
     */
    private function isSupersetMatch(array $leadFrets, array $libFrets): int|false
    {
        if (count($leadFrets) !== count($libFrets)) return false;
        $extra = 0;
        $libSounding = 0;
        for ($i = 0; $i < count($libFrets); $i++) {
            if ($libFrets[$i] === 'x') {
                if ($leadFrets[$i] !== 'x') $extra++;
            } else {
                $libSounding++;
                if ($leadFrets[$i] === 'x' || intval($leadFrets[$i]) !== intval($libFrets[$i])) return false;
            }
        }
        return ($extra === 0 || $libSounding < 2) ? false : $extra;
    }

    /**
     * Fragment: bass note relocated to a different open string.
     * Strips the bass, verifies it's a chord tone, runs subset on remainder.
     */
    private function isRootRelocatedFragmentMatch(array $leadFrets, array $libFrets, string $rootNote, string $baseQuality): int|false
    {
        if (count($leadFrets) !== 6 || count($libFrets) !== 6) return false;

        $rootSemi = self::NOTE_SEMI[$rootNote] ?? null;
        if ($rootSemi === null) return false;

        $intervals = self::CHORD_TONES[$baseQuality] ?? [0, 7];
        $validPitches = array_map(fn($iv) => ($rootSemi + $iv) % 12, $intervals);

        // Find lowest sounding string in leadsheet
        $bassIdx = null;
        for ($i = 0; $i < 6; $i++) {
            if ($leadFrets[$i] !== 'x') { $bassIdx = $i; break; }
        }
        if ($bassIdx === null) return false;

        // If bass already matches library, normal matching handles it
        if ($libFrets[$bassIdx] !== 'x' && intval($leadFrets[$bassIdx]) === intval($libFrets[$bassIdx])) return false;

        // Verify bass is a chord tone
        $bassPitch = (self::TUNING[$bassIdx] + intval($leadFrets[$bassIdx])) % 12;
        if (!in_array($bassPitch, $validPitches, true)) return false;

        // Strip bass and check remaining fragment
        $fragment = $leadFrets;
        $fragment[$bassIdx] = 'x';

        $remaining = 0;
        for ($i = 0; $i < 6; $i++) {
            if ($fragment[$i] !== 'x') $remaining++;
        }
        if ($remaining < 3) return false;

        $subsetResult = $this->isSubsetMatch($fragment, $libFrets);
        if ($subsetResult !== false) return $subsetResult;

        // Also check exact match ignoring muted strings
        if ($this->fretArraysMatchIgnoringMuted($fragment, $libFrets)) return 0;

        return false;
    }

    private function fretArraysMatchIgnoringMuted(array $a, array $b): bool
    {
        if (count($a) !== count($b)) return false;
        $matched = 0;
        for ($i = 0; $i < count($a); $i++) {
            if ($a[$i] === 'x') continue;
            if ($b[$i] === 'x' || intval($a[$i]) !== intval($b[$i])) return false;
            $matched++;
        }
        return $matched >= 3;
    }

    private function hasValidFrets(array $frets): bool
    {
        foreach ($frets as $v) {
            if ($v !== 'x' && $v < 0) return false;
        }
        return true;
    }

    // =========================================================================
    // FRET STRING PARSING & NORMALISATION
    // =========================================================================

    /**
     * Parse a fret string into a 6-element array.
     * Handles standard 6-char ("x02010") and multi-digit ("x81089x").
     */
    private function parseFretString(string $fretString, int $position = 1): array
    {
        if (strlen($fretString) <= 6) {
            $result = [];
            for ($i = 0; $i < strlen($fretString); $i++) {
                $c = strtolower($fretString[$i]);
                $result[] = ($c === 'x') ? 'x' : (ctype_xdigit($c) ? hexdec($c) : intval($c));
            }
            while (count($result) < 6) $result[] = 'x';
            return $result;
        }

        // Multi-digit: brute-force partition search
        $best = null;
        $bestScore = PHP_INT_MAX;
        $this->fretParseSearch($fretString, 0, [], $position, $best, $bestScore);

        return $best ?? $this->parseFretString(substr($fretString, 0, 6), $position);
    }

    private function fretParseSearch(string $s, int $depth, array $current, int $position, ?array &$best, int &$bestScore): void
    {
        if ($depth === 6) {
            if (strlen($s) === 0) {
                $score = $this->fretParseScore($current, $position);
                if ($score < $bestScore) { $bestScore = $score; $best = $current; }
            }
            return;
        }
        if (strlen($s) === 0) {
            $padded = array_merge($current, array_fill(0, 6 - $depth, 'x'));
            $score = $this->fretParseScore($padded, $position);
            if ($score < $bestScore) { $bestScore = $score; $best = $padded; }
            return;
        }

        $c = $s[0];
        if ($c === 'x' || $c === 'X') {
            $this->fretParseSearch(substr($s, 1), $depth + 1, [...$current, 'x'], $position, $best, $bestScore);
        } elseif (ctype_digit($c)) {
            $this->fretParseSearch(substr($s, 1), $depth + 1, [...$current, intval($c)], $position, $best, $bestScore);
            if (strlen($s) >= 2 && ctype_digit($s[1])) {
                $this->fretParseSearch(substr($s, 2), $depth + 1, [...$current, intval(substr($s, 0, 2))], $position, $best, $bestScore);
            }
        } else {
            $this->fretParseSearch(substr($s, 1), $depth, $current, $position, $best, $bestScore);
        }
    }

    private function fretParseScore(array $parse, int $position): int
    {
        $sounding = array_filter($parse, fn($v) => $v !== 'x');
        if (empty($sounding)) return 0;

        $score = 0;
        if ($position > 1) {
            foreach ($sounding as $v) { $score += ($v - $position) ** 2; }
        } else {
            $nonzero = array_filter($sounding, fn($f) => $f > 0);
            if (!empty($nonzero)) {
                $span = max($sounding) - min($nonzero);
                if ($span > 5) $score += ($span - 5) * 100;
            }
        }
        foreach ($sounding as $v) {
            if ($v > 24 || $v < 0) $score += 10000;
        }
        return $score;
    }

    /**
     * Convert diagram_data to a 6-element fret array.
     */
    private function diagramToFretArray(array $diagramData): array
    {
        $frets = array_fill(0, 6, 'x');

        foreach ($diagramData['open'] ?? [] as $s) {
            if ($s >= 1 && $s <= 6) $frets[$s - 1] = 0;
        }
        foreach ($diagramData['positions'] ?? [] as $pos) {
            $s = (int)$pos['string'];
            if ($s >= 1 && $s <= 6) $frets[$s - 1] = intval($pos['fret']);
        }
        foreach ($diagramData['barres'] ?? [] as $barre) {
            $from = min($barre['fromString'], $barre['toString']);
            $to   = max($barre['fromString'], $barre['toString']);
            for ($s = $from; $s <= $to; $s++) {
                if ($s >= 1 && $s <= 6 && $frets[$s - 1] === 'x') {
                    $frets[$s - 1] = intval($barre['fret']);
                }
            }
        }

        return $frets;
    }

    /**
     * Normalise open-string / equivalent-fret substitutions.
     * Canonical form: prefer the open-string version.
     */
    private function normalizeOpenEquivalents(array $frets): array
    {
        $out = $frets;

        // Upward: lo fretted → hi open
        foreach (self::UPWARD_PAIRS as [$lo, $fretOnLo, $hi]) {
            if ($out[$lo] !== 'x' && intval($out[$lo]) === $fretOnLo && $out[$hi] === 'x') {
                $out[$lo] = 'x';
                $out[$hi] = 0;
            }
        }

        // Downward: hi fretted → lo open
        foreach (self::DOWNWARD_PAIRS as [$lo, $hi, $fretOnHi]) {
            if ($out[$hi] !== 'x' && intval($out[$hi]) === $fretOnHi && $out[$lo] === 'x') {
                $out[$hi] = 'x';
                $out[$lo] = 0;
            }
        }

        return $out;
    }

    // =========================================================================
    // QUALITY RESOLUTION
    // =========================================================================

    private function getQualityVariants(string $quality): array
    {
        return self::QUALITY_ALIASES[$quality] ?? [$quality];
    }

    private function splitQualityExtensions(string $quality): array
    {
        if ($quality === '') return ['base' => 'maj', 'extensions' => ''];

        if (preg_match('/^(.+?)\(([^)]+)\)$/', $quality, $m)) {
            return ['base' => trim($m[1]), 'extensions' => trim($m[2])];
        }

        foreach (self::KNOWN_BASE_QUALITIES as $base) {
            if ($quality === $base) return ['base' => $base, 'extensions' => ''];
            if (str_starts_with($quality, $base) && strlen($quality) > strlen($base)) {
                $ext = substr($quality, strlen($base));
                if (preg_match('/^[b#\d]/', $ext)) return ['base' => $base, 'extensions' => $ext];
            }
        }

        return ['base' => $quality, 'extensions' => ''];
    }

    /**
     * Quality patterns: input notation → DB canonical form.
     * Ordered most-specific-first. Ported from WP sbn_parse_chord_name().
     */
    private const QUALITY_PATTERNS = [
        'm7b5'=>'m7b5','m7♭5'=>'m7b5','ø7'=>'m7b5','ø'=>'m7b5',
        'half-dim7'=>'m7b5','halfdim7'=>'m7b5',
        'mMaj7'=>'mMaj7','mmaj7'=>'mMaj7','mM7'=>'mMaj7',
        'm(maj7)'=>'mMaj7','min(maj7)'=>'mMaj7',
        'maj7'=>'maj7','M7'=>'maj7','Δ7'=>'maj7','△7'=>'maj7',
        'Maj7'=>'maj7', // case variant common in leadsheets
        'maj6'=>'maj6','M6'=>'maj6','6'=>'maj6',
        'min7'=>'m7','m7'=>'m7','-7'=>'m7',
        'min6'=>'m6','m6'=>'m6','-6'=>'m6',
        'dom7'=>'dom7','7'=>'dom7',
        '7sus4'=>'7sus4',
        'aug7'=>'aug7','+7'=>'aug7','aug'=>'aug','+'=>'aug',
        'dim7'=>'o7','o7'=>'o7','°7'=>'o7',
        'dim'=>'dim','o'=>'dim','°'=>'dim',
        'sus4'=>'sus4','sus2'=>'sus2','sus'=>'sus4',
        'add9'=>'add9','(add9)'=>'add9','add2'=>'add9',
        '5'=>'5',
        'maj'=>'maj','M'=>'maj','Maj'=>'maj',
        'minor'=>'min','min'=>'min','m'=>'min','-'=>'min',
    ];

    /**
     * Shorthand extension map: notation → [base_quality, extension].
     */
    private const SHORTHAND_MAP = [
        '9'=>['dom7','9'],'11'=>['dom7','11'],'13'=>['dom7','13'],
        'dom9'=>['dom7','9'],'dom11'=>['dom7','11'],'dom13'=>['dom7','13'],
        '7b9'=>['dom7','b9'],'7#9'=>['dom7','#9'],
        '7b13'=>['dom7','b13'],'7#11'=>['dom7','#11'],
        '7b9b13'=>['dom7','b9,b13'],'7b9#11'=>['dom7','b9,#11'],
        'maj9'=>['maj7','9'],'maj11'=>['maj7','11'],'maj13'=>['maj7','13'],
        'M9'=>['maj7','9'],'M11'=>['maj7','11'],'M13'=>['maj7','13'],
        'Δ9'=>['maj7','9'],'△9'=>['maj7','9'],
        'maj7#11'=>['maj7','#11'],
        'm9'=>['m7','9'],'m11'=>['m7','11'],'m13'=>['m7','13'],
        'min9'=>['m7','9'],'min11'=>['m7','11'],'min13'=>['m7','13'],
        '-9'=>['m7','9'],'-11'=>['m7','11'],'-13'=>['m7','13'],
        'm7b9'=>['m7','b9'],
        'ø9'=>['m7b5','9'],
    ];

    private const SKIP_PATTERNS = ['maj13','maj11','maj9','m13','m11','m9','13','11','9'];

    /**
     * Parse a chord name into root, quality, extension, bass_note.
     * Full port of WP sbn_parse_chord_name() with shorthand map,
     * extension extraction, and progressive decomposition.
     */
    private function parseChordName(string $input): ?array
    {
        $input = trim($input);
        if (empty($input)) return null;

        // Handle slash chords
        $bassNote = '';
        if (str_contains($input, '/')) {
            [$input, $bassPart] = explode('/', $input, 2);
            $input    = trim($input);
            $bassPart = trim($bassPart);

            $validRoots = ['C','C#','Db','D','D#','Eb','E','F','F#','Gb','G','G#','Ab','A','A#','Bb','B'];
            if (strlen($bassPart) >= 2 && in_array(substr($bassPart, 0, 2), $validRoots)) {
                $bassNote = substr($bassPart, 0, 2);
            } elseif (strlen($bassPart) >= 1 && in_array(strtoupper(substr($bassPart, 0, 1)), $validRoots)) {
                $bassNote = strtoupper(substr($bassPart, 0, 1));
            }
        }

        // Extract root note
        $validRoots = ['C','C#','Db','D','D#','Eb','E','F','F#','Gb','G','G#','Ab','A','A#','Bb','B'];
        $root = null;
        $qualityPart = '';

        if (strlen($input) >= 2 && in_array(substr($input, 0, 2), $validRoots)) {
            $root = substr($input, 0, 2);
            $qualityPart = substr($input, 2);
        } elseif (strlen($input) >= 1 && in_array(strtoupper(substr($input, 0, 1)), $validRoots)) {
            $root = strtoupper(substr($input, 0, 1));
            $qualityPart = substr($input, 1);
        }

        if (!$root) return null;
        $qualityPart = trim($qualityPart);

        // Extension extraction
        $extension = '';

        // 1. Parenthesised: "m7(9)", "7(#11)", "6(9)"
        if (preg_match('/^(.+?)\(([^)]+)\)$/', $qualityPart, $m)) {
            $qualityPart = $m[1];
            $extension   = $m[2];
        }
        // 2. Space-separated: "m7 9"
        elseif (preg_match('/^(\S+)\s+(.+)$/', $qualityPart, $m)) {
            $qualityPart = $m[1];
            $extension   = trim($m[2]);
        }

        // Empty quality → major triad
        if ($qualityPart === '') {
            return ['root' => $root, 'quality' => 'maj', 'extension' => $extension, 'bass_note' => $bassNote];
        }

        // Shorthand check (only if no extension already extracted)
        if (empty($extension)) {
            if (isset(self::SHORTHAND_MAP[$qualityPart])) {
                $sh = self::SHORTHAND_MAP[$qualityPart];
                return ['root' => $root, 'quality' => $sh[0], 'extension' => $sh[1], 'bass_note' => $bassNote];
            }
            $qpLower = strtolower($qualityPart);
            foreach (self::SHORTHAND_MAP as $pat => $sh) {
                if ($qpLower === strtolower($pat) && !in_array($pat, ['M9','M11','M13'])) {
                    return ['root' => $root, 'quality' => $sh[0], 'extension' => $sh[1], 'bass_note' => $bassNote];
                }
            }
        }

        // Standard quality matching — case-sensitive
        foreach (self::QUALITY_PATTERNS as $pattern => $canonical) {
            if (in_array($pattern, self::SKIP_PATTERNS)) continue;
            if ($qualityPart === $pattern) {
                return ['root' => $root, 'quality' => $canonical, 'extension' => $extension, 'bass_note' => $bassNote];
            }
        }
        // Case-insensitive
        $qualityLower = strtolower($qualityPart);
        foreach (self::QUALITY_PATTERNS as $pattern => $canonical) {
            if (in_array($pattern, self::SKIP_PATTERNS)) continue;
            if ($qualityLower === strtolower($pattern) && !in_array($pattern, ['M','M7','M6'])) {
                return ['root' => $root, 'quality' => $canonical, 'extension' => $extension, 'bass_note' => $bassNote];
            }
        }

        // Progressive decomposition: "dim7b13" → "dim7" + "b13"
        if (empty($extension) && preg_match('/^(.+?)((?:[b#]?\d+,?)+)$/', $qualityPart, $prog)) {
            $baseTry = $prog[1];
            $extTry  = rtrim($prog[2], ',');
            foreach (self::QUALITY_PATTERNS as $pattern => $canonical) {
                if (in_array($pattern, self::SKIP_PATTERNS)) continue;
                if ($baseTry === $pattern || (strtolower($baseTry) === strtolower($pattern) && !in_array($pattern, ['M','M7','M6']))) {
                    return ['root' => $root, 'quality' => $canonical, 'extension' => $extTry, 'bass_note' => $bassNote];
                }
            }
        }

        // Fallback: return raw
        return ['root' => $root, 'quality' => $qualityPart, 'extension' => $extension, 'bass_note' => $bassNote];
    }

    // =========================================================================
    // ALIAS LOOKUP
    // =========================================================================

    /**
     * Find chord diagrams that have aliases matching the target quality.
     * Builds fake shape objects with the alias's identity (root, quality,
     * inversion, bass_note) but the parent diagram's fret data, so the
     * calculator transposes using the alias context.
     */
    private function findAliasShapes(array $qualityVariants, string $extensions, array $voicing): \Illuminate\Support\Collection
    {
        $baseQuery = DB::table('sbn_chord_diagram_aliases as a')
            ->join('sbn_chord_diagrams as d', 'a.diagram_id', '=', 'd.id')
            ->whereIn('a.alt_quality', $qualityVariants)
            ->select(
                'd.id', 'd.slug', 'd.voicing_category', 'd.root_string',
                'd.diagram_data', 'd.start_fret', 'd.is_fixed_position',
                'a.alt_root_note', 'a.alt_quality', 'a.alt_extensions',
                'a.alt_bass_note', 'a.alt_name'
            );

        if (!empty($extensions)) {
            $withExt = (clone $baseQuery)->where('a.alt_extensions', $extensions)->get();
            $aliasRows = $withExt->isNotEmpty() ? $withExt : $baseQuery->get();
        } else {
            $aliasRows = $baseQuery->get();
        }

        if ($aliasRows->isEmpty()) {
            return collect();
        }

        // Build fake shape objects with alias context for transposition
        $aliasShapes = [];
        foreach ($aliasRows as $ar) {
            // Compute the inversion from the alias context
            $aliasInversion = 'root';
            if (!empty($ar->alt_bass_note)) {
                $slashInfo = $this->calculator->analyzeSlashChord(
                    $ar->alt_root_note, $ar->alt_quality, $ar->alt_bass_note
                );
                if ($slashInfo['type'] === 'inversion' && !empty($slashInfo['inversion'])) {
                    $aliasInversion = $slashInfo['inversion'];
                }
            }

            // Override identity with alias context, keep parent's fret data
            $aliasShape = new \stdClass();
            $aliasShape->id               = $ar->id;
            $aliasShape->slug             = $ar->slug . '/alias:' . $ar->alt_name;
            $aliasShape->root_note        = $ar->alt_root_note;     // alias root for transposition
            $aliasShape->quality          = $ar->alt_quality;       // alias quality for interval calc
            $aliasShape->voicing_category = $ar->voicing_category;
            $aliasShape->root_string      = $ar->root_string;       // parent's root string (fret data)
            $aliasShape->inversion        = $aliasInversion;        // computed from alias context
            $aliasShape->diagram_data     = $ar->diagram_data;      // parent's fret data
            $aliasShape->start_fret       = $ar->start_fret;
            $aliasShape->bass_note        = $ar->alt_bass_note ?? '';
            $aliasShape->is_fixed_position = $ar->is_fixed_position;
            $aliasShape->extensions       = $ar->alt_extensions ?? '';
            $aliasShape->interval_labels  = '';
            $aliasShape->_is_alias        = true;

            $aliasShapes[] = $aliasShape;
        }

        return collect($aliasShapes);
    }

    // =========================================================================
    // STORAGE
    // =========================================================================

    private function storeMatch(int $leadsheetId, array $voicing, array $match): void
    {
        $qualityParts = $this->splitQualityExtensions($voicing['quality']);

        // Classify extra notes
        $addedNotes = '';
        if (!empty($match['calculated_frets']) && $match['match_type'] !== 'exact' && $match['match_type'] !== 'subset') {
            $targetFretArray = $this->normalizeOpenEquivalents(
                $this->parseFretString($voicing['fret_string'], $voicing['position'] ?? 1)
            );
            $addedNotes = $this->classifyExtraNotes(
                $targetFretArray, $match['calculated_frets'],
                $voicing['root'], $qualityParts['base'], $match['match_type']
            );
        }

        DB::table('sbn_voicing_usage')->updateOrInsert(
            [
                'leadsheet_id' => $leadsheetId,
                'chord_name'   => $voicing['chord_name'],
                'fret_string'  => $voicing['fret_string'],
            ],
            [
                'chord_diagram_id' => $match['diagram_id'],
                'position'         => $voicing['position'],
                'root_note'        => $voicing['root'],
                'quality'          => $voicing['quality'],
                'base_quality'     => $qualityParts['base'],
                'extensions'       => $qualityParts['extensions'],
                'voicing_category' => $match['voicing_category'],
                'root_string'      => $match['root_string'],
                'inversion'        => $match['inversion'],
                'bass_note'        => $voicing['bass_note'] ?? '',
                'added_notes'      => $addedNotes,
            ]
        );
    }

    private function storeDraft(int $leadsheetId, string $title, array $voicing): void
    {
        DB::table('sbn_voicing_drafts')->updateOrInsert(
            [
                'leadsheet_id' => $leadsheetId,
                'chord_name'   => $voicing['chord_name'],
                'fret_string'  => $voicing['fret_string'],
            ],
            [
                'leadsheet_title' => $title,
                'position'        => $voicing['position'],
                'fingers'         => $voicing['fingers'],
                'root_note'       => $voicing['root'],
                'quality'         => $voicing['quality'],
                'bass_note'       => $voicing['bass_note'] ?? '',
                'status'          => 'pending',
                'updated_at'      => now(),
            ]
        );
    }

    private function clearLeadsheetReferences(int $leadsheetId): void
    {
        DB::table('sbn_voicing_usage')->where('leadsheet_id', $leadsheetId)->delete();
        DB::table('sbn_voicing_drafts')->where('leadsheet_id', $leadsheetId)->delete();
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function buildMatchResult(object $shape, string $matchType, array $calculatedFrets): array
    {
        return [
            'diagram_id'       => $shape->id,
            'voicing_category' => $shape->voicing_category,
            'root_string'      => $shape->root_string,
            'inversion'        => $shape->inversion,
            'match_type'       => $matchType,
            'calculated_frets' => $calculatedFrets,
        ];
    }

    /**
     * Classify extra notes between leadsheet and matched library shape.
     */
    private function classifyExtraNotes(array $leadFrets, array $libFrets, string $rootNote, string $baseQuality, string $matchType): string
    {
        if ($matchType === 'exact' || $matchType === 'subset') return '';
        if (count($leadFrets) !== 6 || count($libFrets) !== 6) return '';

        $rootSemi = self::NOTE_SEMI[$rootNote] ?? null;
        if ($rootSemi === null) return '';

        $intervalLabels = [0=>'R',1=>'b2',2=>'2',3=>'b3',4=>'3',5=>'4',6=>'b5',7=>'5',8=>'b6',9=>'6',10=>'b7',11=>'7'];

        $libPitches = [];
        $libLowest = null;
        for ($i = 0; $i < 6; $i++) {
            if ($libFrets[$i] !== 'x') {
                $libPitches[] = (self::TUNING[$i] + intval($libFrets[$i])) % 12;
                if ($libLowest === null) $libLowest = $i;
            }
        }

        $tags = [];
        for ($i = 0; $i < 6; $i++) {
            if ($leadFrets[$i] === 'x') continue;
            if ($libFrets[$i] !== 'x' && intval($leadFrets[$i]) === intval($libFrets[$i])) continue;

            $extraPitch = (self::TUNING[$i] + intval($leadFrets[$i])) % 12;
            $interval = ($extraPitch - $rootSemi + 12) % 12;
            $label = $intervalLabels[$interval] ?? '?';

            if (in_array($extraPitch, $libPitches, true)) {
                $tags[] = 'doubled:' . $label;
            } elseif ($libLowest !== null && $i < $libLowest) {
                $tags[] = 'bass:' . $label;
            } else {
                $tags[] = 'added:' . $label;
            }
        }

        return implode('+', $tags);
    }

    // =========================================================================
    // REVERSE VOICING LOOKUP — identify_from_frets
    // Ported from WP class-voicing-crossref.php
    // =========================================================================

    // ── Static data tables ────────────────────────────────────────────────────

    /** Quality → interval set (semitones from root). Longer/more-specific first. */
    private const IDENTIFY_QUALITY_INTERVALS = [
        'maj7'  => [0, 4, 7, 11],
        'maj6'  => [0, 4, 7, 9],
        'm7'    => [0, 3, 7, 10],
        'm6'    => [0, 3, 7, 9],
        'dom7'  => [0, 4, 7, 10],
        '7'     => [0, 4, 7, 10],
        'm7b5'  => [0, 3, 6, 10],
        'o7'    => [0, 3, 6, 9],
        'dim7'  => [0, 3, 6, 9],
        'mMaj7' => [0, 3, 7, 11],
        'add9'  => [0, 2, 4, 7],
        'aug'   => [0, 4, 8],
        'dim'   => [0, 3, 6],
        'sus4'  => [0, 5, 7],
        'sus2'  => [0, 2, 7],
        '5'     => [0, 7],
        'maj'   => [0, 4, 7],
        'min'   => [0, 3, 7],
        'm'     => [0, 3, 7],
    ];

    /** Interval (semitones above root) → extension label. */
    private const IDENTIFY_EXTENSION_INTERVALS = [
        1 => 'b9',
        2 => '9',
        3 => '#9',
        5 => '11',
        6 => '#11',
        8 => 'b13',
        9 => '13',
    ];

    private const IDENTIFY_NOTE_SHARP = [0=>'C',1=>'C#',2=>'D',3=>'D#',4=>'E',5=>'F',6=>'F#',7=>'G',8=>'G#',9=>'A',10=>'A#',11=>'B'];
    private const IDENTIFY_NOTE_FLAT  = [0=>'C',1=>'Db',2=>'D',3=>'Eb',4=>'E',5=>'F',6=>'Gb',7=>'G',8=>'Ab',9=>'A',10=>'Bb',11=>'B'];
    private const IDENTIFY_FLAT_ROOTS = ['F','Bb','Eb','Ab','Db','Gb'];

    /**
     * Rootless voicing templates: interval sets from an ABSENT root.
     * Priority order: more specific/common jazz qualities first.
     */
    private const IDENTIFY_ROOTLESS_TEMPLATES = [
        'm7'     => [[3,7,10],[3,10,5]],
        '7(#9)'  => [[3,4,10]],
        '7'      => [[4,7,10],[4,10,2]],
        'maj7'   => [[4,7,11]],
        'm7b5'   => [[3,6,10]],
        '7(b9)'  => [[1,4,10]],
        '7(#11)' => [[4,6,10]],
        'dim7'   => [[3,6,9]],
        'm6'     => [[3,7,9]],
        '6'      => [[4,7,9]],
        'mMaj7'  => [[3,7,11]],
    ];

    // ── Context-aware identification constants ──────────────────────────────

    /**
     * Common root motions (interval in semitones → bonus).
     * Ascending P4 (5) and ascending semitone (1) are the strongest resolutions.
     */
    private const ROOT_MOTION_BONUS = [
        5  => 3,   // ascending P4 / descending P5 (V→I, ii→V)
        7  => 2,   // ascending P5 / descending P4 (I→V)
        1  => 2,   // ascending semitone (vii→I, tritone sub resolution)
        2  => 1,   // ascending whole step (IV→V, I→II)
        10 => 1,   // descending whole step (= ascending m7, V→IV)
        3  => 1,   // ascending m3 (I→bIII, relative key motion)
        9  => 1,   // ascending M6 / descending m3 (vi→IV)
    ];

    /**
     * Penalized root motions — unlikely jumps that suggest mis-identification.
     */
    private const ROOT_MOTION_PENALTY = [
        6  => -2,  // tritone leap (unless dominant function — handled separately)
        8  => -1,  // augmented 5th leap
    ];

    /**
     * Common two-chord functional fragments: [interval_from_first_to_second, quality_pair].
     * Interval is (root2 - root1 + 12) % 12.
     * Quality pairs use normalized families: 'dom'→dominant, 'min'→minor, 'maj'→major, 'hdim'→half-dim.
     */
    private const FUNCTIONAL_FRAGMENTS = [
        // ii-V patterns
        ['interval' => 5, 'from' => 'minor', 'to' => 'dom',   'bonus' => 5],
        // V-I patterns
        ['interval' => 5, 'from' => 'dom',   'to' => 'major', 'bonus' => 5],
        ['interval' => 5, 'from' => 'dom',   'to' => 'minor', 'bonus' => 4],
        // ii-V (half-dim to dom in minor)
        ['interval' => 5, 'from' => 'hdim',  'to' => 'dom',   'bonus' => 5],
        // iv-V
        ['interval' => 2, 'from' => 'minor', 'to' => 'dom',   'bonus' => 3],
        // I-IV
        ['interval' => 5, 'from' => 'major', 'to' => 'major', 'bonus' => 2],
        // IV-V
        ['interval' => 2, 'from' => 'major', 'to' => 'dom',   'bonus' => 3],
        // V-vi deceptive
        ['interval' => 9, 'from' => 'dom',   'to' => 'minor', 'bonus' => 3],
        // bVII-I (backdoor)
        ['interval' => 2, 'from' => 'dom',   'to' => 'major', 'bonus' => 3],
        // tritone sub: bII7 → I (descending semitone = interval 11)
        ['interval' => 11, 'from' => 'dom',  'to' => 'major', 'bonus' => 4],
        ['interval' => 11, 'from' => 'dom',  'to' => 'minor', 'bonus' => 3],
        // vi-ii
        ['interval' => 5, 'from' => 'minor', 'to' => 'minor', 'bonus' => 2],
        // iii-vi
        ['interval' => 5, 'from' => 'minor', 'to' => 'minor', 'bonus' => 2],
    ];

    /** Major scale intervals for diatonicity check. */
    private const CTX_MAJOR_SCALE = [0, 2, 4, 5, 7, 9, 11];

    /** Common chromatic alterations that are acceptable in key context. */
    private const CTX_CHROMATIC_OK = [1, 3, 6, 8, 10]; // bII, bIII, #IV/bV, bVI, bVII

    // ── Public batch entry point ───────────────────────────────────────────────

    /**
     * Identify chord names for a batch of tab voicings.
     * Called from LeadsheetController::identifyVoicings().
     *
     * When $harmonicContext is provided (from HarmonicContext::buildFromLeadsheet()),
     * a context-aware second pass disambiguates close-scoring candidates using
     * key fit, functional continuity, and root motion analysis.
     *
     * @param  array      $voicings        Assoc array: placeholderName → ['frets'=>'x02210','position'=>1]
     * @param  array|null $harmonicContext  Output of HarmonicContext::buildFromLeadsheet() or null
     * @return array                        Assoc array: placeholderName → result
     */
    public function identifyVoicingsBatch(array $voicings, ?array $harmonicContext = null): array
    {
        // Pass 1: identify all voicings without context
        $results = [];
        foreach ($voicings as $placeholderName => $voicing) {
            $frets    = $voicing['frets']    ?? '';
            $position = (int)($voicing['position'] ?? 1);
            if (strlen($frets) !== 6) continue;

            $identified = $this->identifyFromFrets($frets, $position);
            $results[$placeholderName] = $identified;
        }

        // Pass 2: context-aware re-scoring (if context is available)
        if ($harmonicContext !== null && !empty($harmonicContext['song_key'])) {
            $songKey = $harmonicContext['song_key'];

            // Build ordered list of placeholder names for neighbor lookup
            $orderedNames = array_keys($results);

            foreach ($orderedNames as $idx => $name) {
                $voicing = $voicings[$name] ?? null;
                if (!$voicing) continue;

                $frets    = $voicing['frets'] ?? '';
                $position = (int)($voicing['position'] ?? 1);

                // Gather neighbor chord names (from already-identified results)
                $prevChords = [];
                $nextChords = [];

                for ($back = 1; $back <= 2; $back++) {
                    $ni = $idx - $back;
                    if ($ni >= 0 && isset($results[$orderedNames[$ni]]['name'])) {
                        array_unshift($prevChords, $results[$orderedNames[$ni]]['name']);
                    }
                }
                for ($fwd = 1; $fwd <= 2; $fwd++) {
                    $ni = $idx + $fwd;
                    if ($ni < count($orderedNames) && isset($results[$orderedNames[$ni]]['name'])) {
                        $nextChords[] = $results[$orderedNames[$ni]]['name'];
                    }
                }

                // Also check harmonicContext sections for chord names at this position
                // (these are from the chord grid — more authoritative than pitch-class guesses)
                $gridContext = $this->extractGridContext($harmonicContext, $idx);
                if (!empty($gridContext['prev'])) {
                    $prevChords = $gridContext['prev']; // prefer grid data
                }
                if (!empty($gridContext['next'])) {
                    $nextChords = $gridContext['next'];
                }

                $context = [
                    'song_key'    => $songKey,
                    'prev_chords' => $prevChords,
                    'next_chords' => $nextChords,
                ];

                $contextResult = $this->identifyFromFretsWithContext($frets, $position, $context);
                if ($contextResult !== null) {
                    $results[$name] = $contextResult;
                }
            }
        }

        return $results;
    }

    /**
     * Identify a single voicing with optional context.
     * Convenience wrapper for the identifySingle controller endpoint.
     *
     * @param  string      $frets        6-char fret string
     * @param  int         $position     Position marker
     * @param  string|null $songKey      Song key for context (e.g. 'C', 'Dm')
     * @param  array       $prevChords   Up to 2 preceding chord names
     * @param  array       $nextChords   Up to 2 following chord names
     * @return array                     Identification result
     */
    public function identifySingleWithContext(
        string $frets,
        int $position = 1,
        ?string $songKey = null,
        array $prevChords = [],
        array $nextChords = []
    ): array {
        // Always run base identification first
        $result = $this->identifyFromFrets($frets, $position);

        // If context is provided, attempt disambiguation
        if ($songKey !== null) {
            $context = [
                'song_key'    => $songKey,
                'prev_chords' => $prevChords,
                'next_chords' => $nextChords,
            ];
            $contextResult = $this->identifyFromFretsWithContext($frets, $position, $context);
            if ($contextResult !== null) {
                $result = $contextResult;
            }
        }

        return $result;
    }

    // ── Core algorithm ────────────────────────────────────────────────────────

    /**
     * Identify the chord name from a fret string.
     *
     * Pass 1 — Score every (root, quality) pair using pitch-class analysis.
     *   Bass note gets a strong priority bonus (×4).
     *   Exact 7th-chord matches get an extra bonus (×3) to beat partial matches.
     *
     * Pass 2 — Extension detection: leftover pitch classes → b9/9/#9/11/#11/b13/13.
     *
     * Pass 3 — Rootless upgrade: plain triads from 3 PCs are checked against
     *   rootless templates to prefer jazz interpretations (e.g. Cm7 over Eb).
     *
     * @return array { name, root, quality, extensions, bass_note, inversion, diagram_id, confidence }
     */
    public function identifyFromFrets(string $frets, int $position = 1): array
    {
        // ── Step 1: Compute pitch classes ──
        $pitchClasses = [];
        $bassPc       = null;

        for ($i = 0; $i < 6; $i++) {
            $ch = strtolower($frets[$i]);
            if ($ch === 'x') continue;
            $fret = ctype_xdigit($ch) ? hexdec($ch) : (int)$ch;
            $pc   = (self::TUNING[$i] + $fret) % 12;
            $pitchClasses[] = $pc;
            if ($bassPc === null) $bassPc = $pc;
        }

        if (empty($pitchClasses)) {
            return $this->noResult();
        }

        $pcSet = array_values(array_unique($pitchClasses));

        // ── Step 2: Score (root, quality) pairs — Pass 1 ──
        $bassBoost  = 4;
        $exactBonus = 3;
        $slashBonus7th = 3.5;
        $slashBonusTriad = 2.5;

        $bestScore   = -1;
        $bestRootPc  = null;
        $bestQuality = null;

        // Sort qualities longest-first (more specific wins ties)
        $qualities = self::IDENTIFY_QUALITY_INTERVALS;
        uasort($qualities, fn($a, $b) => count($b) - count($a));

        // Candidate roots: bass first, then voicing PCs, then chromatic fill
        $candidateRoots = array_values(array_unique(array_merge(
            $bassPc !== null ? [$bassPc] : [],
            $pcSet,
            range(0, 11)
        )));

        foreach ($candidateRoots as $rootPc) {
            $isBass = ($rootPc === $bassPc);

            foreach ($qualities as $quality => $intervals) {
                $expectedPcs = array_map(fn($iv) => ($rootPc + $iv) % 12, $intervals);
                $matched     = count(array_intersect($expectedPcs, $pcSet));
                $total       = count($expectedPcs);

                // Count unexplained leftovers (not a chord tone or known extension)
                $leftover    = array_diff($pcSet, $expectedPcs);
                $unexplained = 0;
                foreach ($leftover as $lpc) {
                    $ivLeft = ($lpc - $rootPc + 12) % 12;
                    if (!isset(self::IDENTIFY_EXTENSION_INTERVALS[$ivLeft])) {
                        $unexplained++;
                    }
                }

                if ($matched === $total) {
                    $raw = $total * 100 - $unexplained * 50;
                    // Exact bonus only for 7th chords (4+ tones); triads excluded
                    if ($total >= 4) $raw *= $exactBonus;
                } elseif ($matched >= 2 && $matched >= $total - 1) {
                    $raw = $matched * 100 - 50 - $unexplained * 50;
                } else {
                    continue;
                }

                // Slash-chord candidate check: bass is chord tone (3rd, 5th, 7th, maj7th)
                $bassIv = ($bassPc - $rootPc + 12) % 12;
                $isSlashCandidate = !$isBass
                    && in_array($bassIv, [3, 4, 7, 10, 11], true)
                    && ($matched === $total || $unexplained === 0);

                $score = $raw;
                if ($isBass) {
                    $score *= $bassBoost;
                } elseif ($isSlashCandidate) {
                    // Two-tier slash bonus: higher for 7th chords, lower for triads
                    $slashBonus = ($total >= 4) ? $slashBonus7th : $slashBonusTriad;
                    $score *= $slashBonus;
                }

                if ($score > $bestScore) {
                    $bestScore   = $score;
                    $bestRootPc  = $rootPc;
                    $bestQuality = $quality;
                }
            }
        }

        // ── Pass 3a: Rootless detection — main algo found nothing ──
        if ($bestRootPc === null) {
            $rootless = $this->identifyRootless($pcSet, $bassPc);
            if ($rootless) {
                return $this->buildRootlessResult($rootless, $frets, $position, $bassPc);
            }
            return $this->noResult();
        }

        // ── Pass 3b: Rootless upgrade — plain triad from 3 PCs ──
        $isPlainTriad  = in_array($bestQuality, ['maj','min','m','aug'], true);
        $frettedCount  = strlen(str_replace('x', '', strtolower($frets)));
        if ($isPlainTriad && count($pcSet) <= 3 && $frettedCount <= 3) {
            $rootless = $this->identifyRootless($pcSet, $bassPc);
            if ($rootless) {
                return $this->buildRootlessResult($rootless, $frets, $position, $bassPc);
            }
        }

        // ── Pass 3c: Tritone-dominant upgrade ───────────────────────────────
        //
        // If the winning quality is non-dominant AND the PC set contains a tritone
        // (two notes interval 6 apart), those two notes are almost certainly the
        // 3rd + b7 of a dominant chord. Re-score dominant readings for the two
        // implied roots with a tritone bonus (×2), overriding the current best
        // if a dominant reading scores higher.
        //
        // Example: xx5676 = {G,C#,F#,Bb}. Tritone C#/G implies A7 (C#=3rd, G=b7)
        // or Eb7. Without this pass the algo picks GmMaj7(#11) — correct by the
        // numbers but musically wrong in a jazz/bossa context.
        //
        // Guard: skip when the current best is already a dominant quality.
        $dominantQualities = ['dom7', '7'];
        $isDominantWin = in_array($bestQuality, $dominantQualities, true);

        if (!$isDominantWin) {
            // Find all tritone pairs in the PC set
            $tritonePairs = [];
            $pcList = array_values($pcSet);
            for ($a = 0; $a < count($pcList); $a++) {
                for ($b = $a + 1; $b < count($pcList); $b++) {
                    if (($pcList[$b] - $pcList[$a] + 12) % 12 === 6) {
                        $tritonePairs[] = [$pcList[$a], $pcList[$b]];
                    }
                }
            }

            if (!empty($tritonePairs)) {
                $tritoneBonus = 5;

                foreach ($tritonePairs as [$pcA, $pcB]) {
                    // Each tritone note can be the 3rd (interval 4) of a dom7 root.
                    // root = tritone_pc - 4 (mod 12) → the note whose major 3rd = pcA/pcB
                    $tritoneRoots = [
                        ($pcA - 4 + 12) % 12,  // pcA is the 3rd
                        ($pcB - 4 + 12) % 12,  // pcB is the 3rd
                    ];

                    foreach ($tritoneRoots as $rootPc) {
                        $isBass = ($rootPc === $bassPc);

                        foreach ($dominantQualities as $quality) {
                            $intervals   = self::IDENTIFY_QUALITY_INTERVALS[$quality];
                            $expectedPcs = array_map(fn($iv) => ($rootPc + $iv) % 12, $intervals);
                            $matched     = count(array_intersect($expectedPcs, $pcSet));

                            // Require at least the tritone pair (3rd + b7) to be present
                            $has3rd = in_array(($rootPc + 4) % 12, $pcSet, true);
                            $hasb7  = in_array(($rootPc + 10) % 12, $pcSet, true);
                            if (!$has3rd || !$hasb7) continue;

                            // Count unexplained leftovers
                            $leftover    = array_diff($pcSet, $expectedPcs);
                            $unexplained = 0;
                            foreach ($leftover as $lpc) {
                                $ivLeft = ($lpc - $rootPc + 12) % 12;
                                if (!isset(self::IDENTIFY_EXTENSION_INTERVALS[$ivLeft])) {
                                    $unexplained++;
                                }
                            }

                            $total = count($expectedPcs);
                            if ($matched === $total) {
                                $raw = $total * 100 - $unexplained * 50;
                                if ($total >= 4) $raw *= $exactBonus;
                            } else {
                                // Partial — allow even with 2/4 when tritone is confirmed
                                $raw = $matched * 100 - 50 - $unexplained * 50;
                            }

                            $score = ($isBass ? $raw * $bassBoost : $raw) * $tritoneBonus;

                            if ($score > $bestScore) {
                                $bestScore   = $score;
                                $bestRootPc  = $rootPc;
                                $bestQuality = $quality;
                            }
                        }
                    }
                }
            }
        }

        // ── Step 3: Extension detection — Pass 2 ──
        $basePcs = array_map(
            fn($iv) => ($bestRootPc + $iv) % 12,
            self::IDENTIFY_QUALITY_INTERVALS[$bestQuality]
        );
        $leftoverPcs      = array_diff($pcSet, $basePcs);
        $extensionLabels  = [];

        foreach ($leftoverPcs as $lpc) {
            $iv = ($lpc - $bestRootPc + 12) % 12;
            if (isset(self::IDENTIFY_EXTENSION_INTERVALS[$iv])) {
                $extensionLabels[$iv] = self::IDENTIFY_EXTENSION_INTERVALS[$iv];
            }
        }
        ksort($extensionLabels);
        $extensionsStr = !empty($extensionLabels)
            ? '(' . implode(',', $extensionLabels) . ')'
            : '';

        // ── Step 4: Inversion / slash detection ──
        $slashSuffix  = '';
        $bassNoteName = null;
        $inversion    = '';

        if ($bassPc !== null && $bassPc !== $bestRootPc) {
            $bassNoteName  = $this->pcToNoteName($bassPc, $bestQuality);
            $slashSuffix   = '/' . $bassNoteName;
            $bassInterval  = ($bassPc - $bestRootPc + 12) % 12;
            $qualityIvs    = self::IDENTIFY_QUALITY_INTERVALS[$bestQuality];
            $isChordTone   = in_array($bassInterval, $qualityIvs, true);

            if ($isChordTone) {
                if ($bassInterval === 3 || $bassInterval === 4)          $inversion = 'inv1';
                elseif ($bassInterval === 6 || $bassInterval === 7)      $inversion = 'inv2';
                elseif ($bassInterval >= 9 && $bassInterval <= 11)       $inversion = 'inv3';
            }
        }

        // ── Step 5: Assemble name ──
        $rootName    = $this->pcToNoteName($bestRootPc, $bestQuality);
        // Normalize quality for display: 'dom7' → '7', 'min'/'m' → 'm', 'maj' → '' (leadsheet convention)
        $displayQuality = match($bestQuality) {
            'dom7' => '7',
            'min', 'm' => 'm',
            'maj' => '',
            default => $bestQuality
        };
        $chordName   = $rootName . $displayQuality . $extensionsStr . $slashSuffix;

        $baseMatched = count(array_intersect($basePcs, $pcSet));
        $confidence  = ($baseMatched === count($basePcs)) ? 'exact' : 'partial';

        // ── Step 6: DB diagram lookup ──
        $diagramId = $this->findDiagramByFrets($frets, $position, $rootName, $bestQuality, $inversion, $bassNoteName ?? '');

        return [
            'name'       => $chordName,
            'root'       => $rootName,
            'quality'    => $bestQuality,
            'extensions' => $extensionsStr ? trim($extensionsStr, '()') : '',
            'bass_note'  => $bassNoteName,
            'inversion'  => $inversion,
            'diagram_id' => $diagramId,
            'confidence' => $confidence,
            'rootless'   => false,
        ];
    }

    // ── Context-aware identification ────────────────────────────────────────

    /**
     * Identify a chord from frets with harmonic context for disambiguation.
     *
     * Collects all candidates scoring within 70% of the best from Pass 1,
     * then re-ranks using key fit, functional continuity, and root motion.
     *
     * Returns null if context doesn't change the result (single strong candidate).
     *
     * @param  string $frets     6-char fret string
     * @param  int    $position  Position marker
     * @param  array  $context   { song_key: string, prev_chords: string[], next_chords: string[] }
     * @return array|null        Identification result or null if no change
     */
    public function identifyFromFretsWithContext(string $frets, int $position, array $context): ?array
    {
        $songKey    = $context['song_key'] ?? 'C';
        $prevChords = $context['prev_chords'] ?? [];
        $nextChords = $context['next_chords'] ?? [];

        // ── Step 1: Compute pitch classes (same as identifyFromFrets) ──
        $pitchClasses = [];
        $bassPc       = null;
        for ($i = 0; $i < 6; $i++) {
            $ch = strtolower($frets[$i]);
            if ($ch === 'x') continue;
            $fret = ctype_xdigit($ch) ? hexdec($ch) : (int)$ch;
            $pc   = (self::TUNING[$i] + $fret) % 12;
            $pitchClasses[] = $pc;
            if ($bassPc === null) $bassPc = $pc;
        }
        if (empty($pitchClasses)) return null;

        $pcSet = array_values(array_unique($pitchClasses));

        // ── Step 2: Collect ALL viable candidates (not just best) ──
        $candidates = $this->collectCandidates($pcSet, $bassPc);

        if (count($candidates) <= 1) {
            return null; // single candidate or none — context won't help
        }

        // Check if the top two are close enough to warrant context
        $topScore    = $candidates[0]['raw_score'];
        $secondScore = $candidates[1]['raw_score'];
        $threshold   = $topScore * 0.70;

        // Filter to candidates within threshold
        $viable = array_filter($candidates, fn($c) => $c['raw_score'] >= $threshold);
        if (count($viable) <= 1) {
            return null; // clear winner — no disambiguation needed
        }

        // ── Step 3: Context scoring ──
        $keyNoteName = preg_replace('/[mM].*$/', '', trim($songKey));
        $keySemi     = self::NOTE_SEMI[$keyNoteName] ?? 0;

        // Parse neighbor chords
        $prevParsed = array_map(fn($n) => $this->parseChordNameForContext($n), $prevChords);
        $nextParsed = array_map(fn($n) => $this->parseChordNameForContext($n), $nextChords);

        $bestContextScore = -PHP_INT_MAX;
        $bestCandidate    = null;

        foreach ($viable as $candidate) {
            $rootPc  = $candidate['root_pc'];
            $quality = $candidate['quality'];
            $ctxScore = $candidate['raw_score']; // start from pitch-class score

            // ── Key fit ──
            $interval = ($rootPc - $keySemi + 12) % 12;
            if (in_array($interval, self::CTX_MAJOR_SCALE, true)) {
                $ctxScore += 3; // diatonic root
            } elseif (in_array($interval, self::CTX_CHROMATIC_OK, true)) {
                $ctxScore += 1; // common chromatic alteration
            }

            // ── Functional continuity (check against neighbors) ──
            $family = $this->qualityToFamily($quality);

            // Check with previous chord (this chord is the "to")
            if (!empty($prevParsed)) {
                $prevInfo = end($prevParsed); // immediate predecessor
                if ($prevInfo) {
                    $motionInterval = ($rootPc - $prevInfo['root_pc'] + 12) % 12;
                    $prevFamily     = $this->qualityToFamily($prevInfo['quality']);
                    $fragBonus      = $this->scoreFunctionalFragment($motionInterval, $prevFamily, $family);
                    $ctxScore      += $fragBonus;
                }
            }

            // Check with next chord (this chord is the "from")
            if (!empty($nextParsed)) {
                $nextInfo = $nextParsed[0]; // immediate successor
                if ($nextInfo) {
                    $motionInterval = ($nextInfo['root_pc'] - $rootPc + 12) % 12;
                    $nextFamily     = $this->qualityToFamily($nextInfo['quality']);
                    $fragBonus      = $this->scoreFunctionalFragment($motionInterval, $family, $nextFamily);
                    $ctxScore      += $fragBonus;
                }
            }

            // ── Root motion bonuses/penalties (raw interval analysis) ──
            if (!empty($prevParsed)) {
                $prevInfo = end($prevParsed);
                if ($prevInfo) {
                    $motion = ($rootPc - $prevInfo['root_pc'] + 12) % 12;
                    $ctxScore += self::ROOT_MOTION_BONUS[$motion] ?? 0;
                    // Tritone penalty — but NOT if this candidate is dominant (tritone sub is valid)
                    if (isset(self::ROOT_MOTION_PENALTY[$motion])) {
                        $isDominant = in_array($quality, ['dom7', '7'], true);
                        if (!$isDominant || $motion !== 6) {
                            $ctxScore += self::ROOT_MOTION_PENALTY[$motion];
                        }
                    }
                }
            }
            if (!empty($nextParsed)) {
                $nextInfo = $nextParsed[0];
                if ($nextInfo) {
                    $motion = ($nextInfo['root_pc'] - $rootPc + 12) % 12;
                    $ctxScore += self::ROOT_MOTION_BONUS[$motion] ?? 0;
                    if (isset(self::ROOT_MOTION_PENALTY[$motion])) {
                        $isDominant = in_array($quality, ['dom7', '7'], true);
                        if (!$isDominant || $motion !== 6) {
                            $ctxScore += self::ROOT_MOTION_PENALTY[$motion];
                        }
                    }
                }
            }

            if ($ctxScore > $bestContextScore) {
                $bestContextScore = $ctxScore;
                $bestCandidate    = $candidate;
            }
        }

        // If context didn't change the winner, return null (no change needed)
        if ($bestCandidate === null || $bestCandidate === $viable[0]) {
            // Check: the winner might be the same as Pass 1
            // Only return a new result if the context-ranked winner differs from raw-ranked winner
            if ($bestCandidate !== null &&
                $bestCandidate['root_pc'] === $candidates[0]['root_pc'] &&
                $bestCandidate['quality'] === $candidates[0]['quality']) {
                return null;
            }
        }

        if ($bestCandidate === null) return null;

        // ── Build result from the context-selected candidate ──
        return $this->buildContextResult($bestCandidate, $frets, $position, $pcSet, $bassPc);
    }

    /**
     * Collect all viable (root, quality) candidates from pitch-class scoring.
     * Returns sorted by raw_score descending.
     */
    private function collectCandidates(array $pcSet, ?int $bassPc): array
    {
        $bassBoost  = 4;
        $exactBonus = 3;

        $candidates = [];

        $qualities = self::IDENTIFY_QUALITY_INTERVALS;
        uasort($qualities, fn($a, $b) => count($b) - count($a));

        $candidateRoots = array_values(array_unique(array_merge(
            $bassPc !== null ? [$bassPc] : [],
            $pcSet,
            range(0, 11)
        )));

        foreach ($candidateRoots as $rootPc) {
            $isBass = ($rootPc === $bassPc);

            foreach ($qualities as $quality => $intervals) {
                $expectedPcs = array_map(fn($iv) => ($rootPc + $iv) % 12, $intervals);
                $matched     = count(array_intersect($expectedPcs, $pcSet));
                $total       = count($expectedPcs);

                $leftover    = array_diff($pcSet, $expectedPcs);
                $unexplained = 0;
                foreach ($leftover as $lpc) {
                    $ivLeft = ($lpc - $rootPc + 12) % 12;
                    if (!isset(self::IDENTIFY_EXTENSION_INTERVALS[$ivLeft])) {
                        $unexplained++;
                    }
                }

                if ($matched === $total) {
                    $raw = $total * 100 - $unexplained * 50;
                    if ($total >= 4) $raw *= $exactBonus;
                } elseif ($matched >= 2 && $matched >= $total - 1) {
                    $raw = $matched * 100 - 50 - $unexplained * 50;
                } else {
                    continue;
                }

                $score = $isBass ? $raw * $bassBoost : $raw;

                if ($score > 0) {
                    $candidates[] = [
                        'root_pc'   => $rootPc,
                        'quality'   => $quality,
                        'raw_score' => $score,
                        'is_bass'   => $isBass,
                    ];
                }
            }
        }

        // De-duplicate: keep highest score per (root_pc, quality)
        $seen = [];
        $deduped = [];
        foreach ($candidates as $c) {
            $key = $c['root_pc'] . ':' . $c['quality'];
            if (!isset($seen[$key]) || $c['raw_score'] > $seen[$key]) {
                $seen[$key] = $c['raw_score'];
                $deduped[$key] = $c;
            }
        }
        $candidates = array_values($deduped);

        usort($candidates, fn($a, $b) => $b['raw_score'] <=> $a['raw_score']);

        // Cap at top 8 to avoid excessive work
        return array_slice($candidates, 0, 8);
    }

    /**
     * Parse a chord name into root_pc + quality for context comparison.
     * Lightweight — just needs root semitone and normalized quality.
     */
    private function parseChordNameForContext(string $name): ?array
    {
        $parsed = $this->parseChordName($name);
        if (!$parsed || empty($parsed['root'])) return null;

        $rootPc = self::NOTE_SEMI[$parsed['root']] ?? null;
        if ($rootPc === null) return null;

        return [
            'root_pc' => $rootPc,
            'quality' => $parsed['quality'],
            'name'    => $name,
        ];
    }

    /**
     * Map a quality string to a harmonic family for functional analysis.
     */
    private function qualityToFamily(string $quality): string
    {
        return match ($quality) {
            'maj', 'maj7', 'maj6', 'add9' => 'major',
            'min', 'm', 'm7', 'm6', 'mMaj7' => 'minor',
            'dom7', '7'                   => 'dom',
            'm7b5'                        => 'hdim',
            'o7', 'dim7', 'dim'           => 'dim',
            'aug', 'aug7'                 => 'aug',
            'sus4', 'sus2'                => 'sus',
            default                       => 'other',
        };
    }

    /**
     * Score a two-chord functional fragment against known patterns.
     */
    private function scoreFunctionalFragment(int $interval, string $fromFamily, string $toFamily): int
    {
        $bestBonus = 0;
        foreach (self::FUNCTIONAL_FRAGMENTS as $frag) {
            if ($frag['interval'] === $interval &&
                $frag['from'] === $fromFamily &&
                $frag['to'] === $toFamily) {
                $bestBonus = max($bestBonus, $frag['bonus']);
            }
        }
        return $bestBonus;
    }

    /**
     * Build a full identification result from a context-selected candidate.
     * Mirrors the extension/inversion/name assembly from identifyFromFrets.
     */
    private function buildContextResult(array $candidate, string $frets, int $position, array $pcSet, ?int $bassPc): array
    {
        $bestRootPc  = $candidate['root_pc'];
        $bestQuality = $candidate['quality'];

        // Extension detection (same as Pass 2 in identifyFromFrets)
        $basePcs = array_map(
            fn($iv) => ($bestRootPc + $iv) % 12,
            self::IDENTIFY_QUALITY_INTERVALS[$bestQuality]
        );
        $leftoverPcs     = array_diff($pcSet, $basePcs);
        $extensionLabels = [];

        foreach ($leftoverPcs as $lpc) {
            $iv = ($lpc - $bestRootPc + 12) % 12;
            if (isset(self::IDENTIFY_EXTENSION_INTERVALS[$iv])) {
                $extensionLabels[$iv] = self::IDENTIFY_EXTENSION_INTERVALS[$iv];
            }
        }
        ksort($extensionLabels);
        $extensionsStr = !empty($extensionLabels)
            ? '(' . implode(',', $extensionLabels) . ')'
            : '';

        // Inversion / slash detection
        $slashSuffix  = '';
        $bassNoteName = null;
        $inversion    = '';

        if ($bassPc !== null && $bassPc !== $bestRootPc) {
            $bassNoteName  = $this->pcToNoteName($bassPc, $bestQuality);
            $slashSuffix   = '/' . $bassNoteName;
            $bassInterval  = ($bassPc - $bestRootPc + 12) % 12;
            $qualityIvs    = self::IDENTIFY_QUALITY_INTERVALS[$bestQuality];
            $isChordTone   = in_array($bassInterval, $qualityIvs, true);

            if ($isChordTone) {
                if ($bassInterval === 3 || $bassInterval === 4)          $inversion = 'inv1';
                elseif ($bassInterval === 6 || $bassInterval === 7)      $inversion = 'inv2';
                elseif ($bassInterval >= 9 && $bassInterval <= 11)       $inversion = 'inv3';
            }
        }

        // Assemble name
        $rootName       = $this->pcToNoteName($bestRootPc, $bestQuality);
        $displayQuality = $bestQuality === 'dom7' ? '7' : $bestQuality;
        $chordName      = $rootName . $displayQuality . $extensionsStr . $slashSuffix;

        $baseMatched = count(array_intersect($basePcs, $pcSet));
        $confidence  = ($baseMatched === count($basePcs)) ? 'exact' : 'partial';

        // DB diagram lookup
        $diagramId = $this->findDiagramByFrets($frets, $position, $rootName, $bestQuality, $inversion, $bassNoteName ?? '');

        return [
            'name'       => $chordName,
            'root'       => $rootName,
            'quality'    => $bestQuality,
            'extensions' => $extensionsStr ? trim($extensionsStr, '()') : '',
            'bass_note'  => $bassNoteName,
            'inversion'  => $inversion,
            'diagram_id' => $diagramId,
            'confidence' => $confidence,
            'rootless'   => false,
            'context_resolved' => true,
        ];
    }

    /**
     * Extract grid-based neighbor chords from HarmonicContext for a given position.
     * Falls back gracefully if the position doesn't map cleanly.
     */
    private function extractGridContext(array $harmonicContext, int $voicingIndex): array
    {
        $allChords = [];
        foreach ($harmonicContext['sections'] ?? [] as $section) {
            foreach ($section['chords'] ?? [] as $chord) {
                $allChords[] = $chord['chord_name'] ?? null;
            }
        }

        $prev = [];
        $next = [];

        // Look back up to 2
        for ($b = 1; $b <= 2; $b++) {
            $ni = $voicingIndex - $b;
            if ($ni >= 0 && isset($allChords[$ni]) && $allChords[$ni]) {
                array_unshift($prev, $allChords[$ni]);
            }
        }
        // Look forward up to 2
        for ($f = 1; $f <= 2; $f++) {
            $ni = $voicingIndex + $f;
            if ($ni < count($allChords) && isset($allChords[$ni]) && $allChords[$ni]) {
                $next[] = $allChords[$ni];
            }
        }

        return ['prev' => $prev, 'next' => $next];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function noResult(): array
    {
        return [
            'name' => null, 'root' => null, 'quality' => null,
            'extensions' => '', 'bass_note' => null,
            'inversion' => '', 'diagram_id' => null, 'confidence' => 'none',
            'rootless' => false,
        ];
    }

    private function buildRootlessResult(array $rootless, string $frets, int $position, ?int $bassPc): array
    {
        $diagramId = $this->findDiagramByFrets($frets, $position, $rootless['root_name'], $rootless['quality'], '', '');
        return [
            'name'       => $rootless['name'],
            'root'       => $rootless['root_name'],
            'quality'    => $rootless['quality'],
            'extensions' => '',
            'bass_note'  => $bassPc !== null ? $this->pcToNoteName($bassPc, $rootless['quality']) : null,
            'inversion'  => '',
            'diagram_id' => $diagramId,
            'confidence' => 'rootless',
            'rootless'   => true,
        ];
    }

    /**
     * Try to identify a 3-note rootless voicing from a pitch class set.
     * Returns best candidate or null.
     */
    private function identifyRootless(array $pcSet, ?int $bassPc): ?array
    {
        $pcFrozen = array_values(array_unique($pcSet));
        if (count($pcFrozen) !== 3) return null;

        $candidates = [];

        foreach (self::IDENTIFY_ROOTLESS_TEMPLATES as $quality => $voicingTypes) {
            foreach ($voicingTypes as $template) {
                for ($rootPc = 0; $rootPc < 12; $rootPc++) {
                    $expected = array_map(fn($iv) => ($rootPc + $iv) % 12, $template);
                    sort($expected);
                    $actual = $pcFrozen;
                    sort($actual);

                    if ($expected === $actual) {
                        $rootName     = $this->pcToNoteName($rootPc, $quality);
                        $candidates[] = ['root_pc' => $rootPc, 'root_name' => $rootName, 'quality' => $quality, 'name' => $rootName . $quality];
                    }
                }
            }
        }

        if (empty($candidates)) return null;
        if (count($candidates) === 1) return $candidates[0];

        // Prefer by template priority order
        $priority = array_keys(self::IDENTIFY_ROOTLESS_TEMPLATES);
        usort($candidates, function ($a, $b) use ($priority) {
            $ai = array_search($a['quality'], $priority);
            $bi = array_search($b['quality'], $priority);
            return ($ai === false ? 999 : $ai) - ($bi === false ? 999 : $bi);
        });

        return $candidates[0];
    }

    /**
     * Convert pitch class to note name, preferring flat spelling for flat-key qualities.
     */
    private function pcToNoteName(int $pc, string $quality = ''): string
    {
        $sharp = self::IDENTIFY_NOTE_SHARP[$pc];
        $flat  = self::IDENTIFY_NOTE_FLAT[$pc];
        if ($sharp === $flat) return $sharp;
        if (in_array($flat, self::IDENTIFY_FLAT_ROOTS, true)) return $flat;
        return $sharp;
    }

    /**
     * Scan chord diagrams DB for a shape that transposes to match the fret string.
     * Mirrors forward matchVoicing() logic but driven by pitch-class results.
     */
    private function findDiagramByFrets(string $frets, int $position, string $root, string $quality, string $inversion, string $bassNote): ?int
    {
        $qualityVariants = $this->getQualityVariants($quality);
        $shapes = ChordDiagram::whereIn('quality', $qualityVariants)->get();

        if ($shapes->isEmpty()) return null;

        $targetFretArray = $this->normalizeOpenEquivalents(
            $this->parseFretString($frets, $position)
        );

        // Pass 1: inversion-tagged shapes first; Pass 2: all shapes (fallback)
        $candidateSets = [];
        if (!empty($inversion)) {
            $filtered = $shapes->filter(fn($s) => $s->inversion === $inversion)->values();
            if ($filtered->isNotEmpty()) $candidateSets[] = $filtered;
        }
        $candidateSets[] = $shapes;

        foreach ($candidateSets as $candidates) {
            foreach ($candidates as $shape) {
                if (!empty($bassNote) && empty($inversion) && !empty($shape->bass_note)) {
                    $calculated = $this->calculator->calculateFretsWithBass($shape, $root, $bassNote);
                } else {
                    $calculated = $this->calculator->calculateFrets($shape, $root);
                }

                if (!$calculated || empty($calculated['diagram_data'])) continue;

                $calcFretArray = $this->normalizeOpenEquivalents(
                    $this->diagramToFretArray($calculated['diagram_data'])
                );

                if ($this->fretArraysMatch($calcFretArray, $targetFretArray)) {
                    return $shape->id;
                }

                // Accept subset match too (leadsheet omits strings from library shape)
                if ($this->isSubsetMatch($targetFretArray, $calcFretArray) !== false) {
                    return $shape->id;
                }
            }
        }

        return null;
    }

    /**
     * Identify a chord from a set of MIDI pitch numbers.
     * Uses the lowest note as the bass note.
     */
    public function identifyFromMidi(array $midiPitches): array
    {
        if (empty($midiPitches)) {
            return $this->noResult();
        }

        sort($midiPitches);
        $pitchClasses = array_map(fn($p) => $p % 12, $midiPitches);
        $bassPc = $pitchClasses[0];
        $pcSet = array_values(array_unique($pitchClasses));

        return $this->identifyFromPcSet($pcSet, $bassPc);
    }

    /**
     * Core identification logic from a unique set of pitch classes.
     */
    private function identifyFromPcSet(array $pcSet, ?int $bassPc = null): array
    {
        // ── Step 2: Score (root, quality) pairs — Pass 1 ──
        $bassBoost  = 4;
        $exactBonus = 3;
        
        $bestScore   = -1;
        $bestRootPc  = null;
        $bestQuality = null;

        $qualities = self::IDENTIFY_QUALITY_INTERVALS;
        uasort($qualities, fn($a, $b) => count($b) - count($a));

        $candidateRoots = array_values(array_unique(array_merge(
            $bassPc !== null ? [$bassPc] : [],
            $pcSet,
            range(0, 11)
        )));

        foreach ($candidateRoots as $rootPc) {
            $isBass = ($rootPc === $bassPc);

            foreach ($qualities as $quality => $intervals) {
                $expectedPcs = array_map(fn($iv) => ($rootPc + $iv) % 12, $intervals);
                $matched     = count(array_intersect($expectedPcs, $pcSet));
                $total       = count($expectedPcs);

                $leftover    = array_diff($pcSet, $expectedPcs);
                $unexplained = 0;
                foreach ($leftover as $lpc) {
                    $ivLeft = ($lpc - $rootPc + 12) % 12;
                    if (!isset(self::IDENTIFY_EXTENSION_INTERVALS[$ivLeft])) {
                        $unexplained++;
                    }
                }

                if ($matched === $total) {
                    $raw = $total * 100 - $unexplained * 50;
                    if ($total >= 4) $raw *= $exactBonus;
                } elseif ($matched >= 2 && $matched >= $total - 1) {
                    $raw = $matched * 100 - 50 - $unexplained * 50;
                } else {
                    continue;
                }

                $score = $isBass ? $raw * $bassBoost : $raw;
                if ($score > $bestScore) {
                    $bestScore   = $score;
                    $bestRootPc  = $rootPc;
                    $bestQuality = $quality;
                }
            }
        }

        if ($bestRootPc === null) return $this->noResult();

        // ── Extension detection ──
        $basePcs = array_map(
            fn($iv) => ($bestRootPc + $iv) % 12,
            self::IDENTIFY_QUALITY_INTERVALS[$bestQuality]
        );
        $leftoverPcs = array_diff($pcSet, $basePcs);
        $extensionLabels = [];
        foreach ($leftoverPcs as $lpc) {
            $iv = ($lpc - $bestRootPc + 12) % 12;
            if (isset(self::IDENTIFY_EXTENSION_INTERVALS[$iv])) {
                $extensionLabels[$iv] = self::IDENTIFY_EXTENSION_INTERVALS[$iv];
            }
        }
        ksort($extensionLabels);
        $extensionsStr = !empty($extensionLabels) ? '(' . implode(',', $extensionLabels) . ')' : '';

        // ── Inversion / slash detection ──
        $slashSuffix = '';
        if ($bassPc !== null && $bassPc !== $bestRootPc) {
            $bassNoteName = $this->pcToNoteName($bassPc, $bestQuality);
            $slashSuffix = '/' . $bassNoteName;
        }

        $rootName = $this->pcToNoteName($bestRootPc, $bestQuality);
        $qualityDisplay = $this->getQualityDisplayName($bestQuality);

        return [
            'name'       => $rootName . $qualityDisplay . $extensionsStr . $slashSuffix,
            'confidence' => $bestScore > 500 ? 'high' : ($bestScore > 200 ? 'medium' : 'low'),
            'root'       => $rootName,
            'quality'    => $bestQuality,
            'bass'       => $bassPc !== null ? $this->pcToNoteName($bassPc) : null,
        ];
    }
}

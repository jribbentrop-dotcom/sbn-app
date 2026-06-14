<?php

namespace App\Services;

use App\Services\HarmonicContext\DiminishedSymmetry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Chord voicing search + transposition.
 *
 * Extracted from LeadsheetController so the public chord library and the
 * admin voicing picker share one code path. Parses a chord name like "Dm7"
 * or "G7(b9)/B", queries matching shapes from the chord diagram DB, and
 * transposes each one to the requested root.
 */
class ChordVoicingSearch
{
    public function __construct(
        protected ChordShapeCalculator $calculator,
        protected DiminishedSymmetry $dimSymmetry = new DiminishedSymmetry(),
    ) {
    }

    // =========================================================================
    // CHORD NAME PARSING (ported from WP sbn_parse_chord_name)
    // =========================================================================

    private const QUALITY_PATTERNS = [
        'm7b5' => 'm7b5', 'm7♭5' => 'm7b5', 'ø7' => 'm7b5', 'ø' => 'm7b5',
        'half-dim7' => 'm7b5', 'halfdim7' => 'm7b5',
        'mMaj7' => 'mMaj7', 'mmaj7' => 'mMaj7', 'mM7' => 'mMaj7',
        'm(maj7)' => 'mMaj7', 'min(maj7)' => 'mMaj7',
        'maj7' => 'maj7', 'M7' => 'maj7', 'Δ7' => 'maj7', '△7' => 'maj7',
        'maj6' => 'maj6', 'M6' => 'maj6', '6' => 'maj6',
        'min7' => 'm7', 'm7' => 'm7', '-7' => 'm7',
        'min6' => 'm6', 'm6' => 'm6', '-6' => 'm6',
        'dom7' => 'dom7', '7' => 'dom7',
        '7sus4' => '7sus4',
        'aug7' => 'aug7', '+7' => 'aug7', 'aug' => 'aug', '+' => 'aug',
        'dim7' => 'o7', 'o7' => 'o7', '°7' => 'o7',
        'dim' => 'dim', 'o' => 'dim', '°' => 'dim',
        'sus4' => 'sus4', 'sus2' => 'sus2', 'sus' => 'sus4',
        'add9' => 'add9', '(add9)' => 'add9', 'add2' => 'add9',
        '5' => '5',
        'maj' => 'maj', 'M' => 'maj',
        'minor' => 'min', 'min' => 'min', 'm' => 'min', '-' => 'min',
    ];

    private const SHORTHAND_MAP = [
        '9'      => ['dom7', '9'],    '11'     => ['dom7', '11'],   '13'     => ['dom7', '13'],
        'dom9'   => ['dom7', '9'],    'dom11'  => ['dom7', '11'],   'dom13'  => ['dom7', '13'],
        '7b9'    => ['dom7', 'b9'],   '7#9'    => ['dom7', '#9'],
        '7b13'   => ['dom7', 'b13'],  '7#11'   => ['dom7', '#11'],
        '7b9b13' => ['dom7', 'b9,b13'], '7b9#11' => ['dom7', 'b9,#11'],
        'maj9'   => ['maj7', '9'],    'maj11'  => ['maj7', '11'],   'maj13'  => ['maj7', '13'],
        'M9'     => ['maj7', '9'],    'M11'    => ['maj7', '11'],   'M13'    => ['maj7', '13'],
        'Δ9'     => ['maj7', '9'],    '△9'     => ['maj7', '9'],
        'maj7#11' => ['maj7', '#11'],
        'm9'     => ['m7', '9'],      'm11'    => ['m7', '11'],     'm13'    => ['m7', '13'],
        'min9'   => ['m7', '9'],      'min11'  => ['m7', '11'],     'min13'  => ['m7', '13'],
        '-9'     => ['m7', '9'],      '-11'    => ['m7', '11'],     '-13'    => ['m7', '13'],
        'm7b9'   => ['m7', 'b9'],
        'ø9'     => ['m7b5', '9'],
        // Minor add9 compact notations (parenthesised form "m(add9)" is handled
        // by the paren-split regex and resolves via QUALITY_PATTERNS 'm' → 'min')
        'madd9'    => ['min', 'add9'], 'madd2'   => ['min', 'add9'],
        'minadd9'  => ['min', 'add9'], 'minadd2' => ['min', 'add9'],
        'min(add9)' => ['min', 'add9'], 'min(add2)' => ['min', 'add9'],
    ];

    private const SKIP_PATTERNS = ['maj13','maj11','maj9','m13','m11','m9','13','11','9'];

    /**
     * Parse a chord name into root / quality / extension / bass_note.
     * Returns null if no valid root can be found.
     */
    public function parseChordName(string $input): ?array
    {
        $input = trim($input);
        if (empty($input)) return null;

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
        $extension = '';

        if (preg_match('/^(.+?)\(([^)]+)\)$/', $qualityPart, $m)) {
            $qualityPart = $m[1];
            $extension   = $m[2];
        } elseif (preg_match('/^(\S+)\s+(.+)$/', $qualityPart, $m)) {
            $qualityPart = $m[1];
            $extension   = trim($m[2]);
        }

        if ($qualityPart === '') {
            return ['root' => $root, 'quality' => 'maj', 'extension' => $extension, 'bass_note' => $bassNote];
        }

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

        foreach (self::QUALITY_PATTERNS as $pattern => $canonical) {
            if (in_array($pattern, self::SKIP_PATTERNS)) continue;
            if ($qualityPart === $pattern) {
                return ['root' => $root, 'quality' => $canonical, 'extension' => $extension, 'bass_note' => $bassNote];
            }
        }
        $qualityLower = strtolower($qualityPart);
        foreach (self::QUALITY_PATTERNS as $pattern => $canonical) {
            if (in_array($pattern, self::SKIP_PATTERNS)) continue;
            if ($qualityLower === strtolower($pattern) && !in_array($pattern, ['M','M7','M6'])) {
                return ['root' => $root, 'quality' => $canonical, 'extension' => $extension, 'bass_note' => $bassNote];
            }
        }

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

        return ['root' => $root, 'quality' => $qualityPart, 'extension' => $extension, 'bass_note' => $bassNote];
    }

    public function computeIntervalLabels(
        array $positions, array $openStrings, array $muted, string $root, string $quality
    ): string {
        return $this->calculator->computeIntervalLabelsPublic($positions, $openStrings, $muted, $root, $quality);
    }

    // =========================================================================
    // SEARCH BY NAME
    // =========================================================================

    /**
     * Full pipeline: parse a chord name, query shapes, transpose, return results.
     */
    public function searchByName(string $query): array
    {
        $parsed = $this->parseChordName($query);
        if (!$parsed || empty($parsed['root']) || empty($parsed['quality'])) {
            return [];
        }

        $root      = $parsed['root'];
        $quality   = $parsed['quality'];
        $extension = $parsed['extension'] ?? '';
        $bassNote  = $parsed['bass_note'] ?? '';

        $slashType      = '';
        $slashInversion = '';
        $slashInterval  = -1;

        if (!empty($bassNote)) {
            $slashInfo      = $this->calculator->analyzeSlashChord($root, $quality, $bassNote);
            $slashType      = $slashInfo['type'];
            $slashInversion = $slashInfo['inversion'];
            $slashInterval  = $slashInfo['interval'];
        }

        if ($slashType === 'slash') {
            $shapes = $this->querySlashShapes($quality, $extension, $slashInterval);
        } elseif ($slashType === 'inversion' && !empty($slashInversion) && $slashInversion !== 'root') {
            $shapes = $this->queryInversionShapes($quality, $extension, $slashInversion);
        } else {
            if (!empty($extension)) {
                $shapes = DB::table('sbn_chord_diagrams')
                    ->where('quality', $quality)
                    ->where('extensions', $extension)
                    ->whereRaw("(bass_note = '' OR bass_note IS NULL)")
                    ->orderByDesc('popularity')
                    ->orderBy('voicing_category')
                    ->orderBy('root_string')
                    ->orderBy('inversion')
                    ->get();

                if ($shapes->isEmpty()) {
                    $shapes = DB::table('sbn_chord_diagrams')
                        ->where('quality', $quality)
                        ->whereRaw("(extensions = '' OR extensions IS NULL)")
                        ->whereRaw("(bass_note = '' OR bass_note IS NULL)")
                        ->orderByDesc('popularity')
                        ->orderBy('voicing_category')
                        ->orderBy('root_string')
                        ->orderBy('inversion')
                        ->get();
                }
            } else {
                $shapes = DB::table('sbn_chord_diagrams')
                    ->where('quality', $quality)
                    ->whereRaw("(extensions = '' OR extensions IS NULL)")
                    ->whereRaw("(bass_note = '' OR bass_note IS NULL)")
                    ->orderByDesc('popularity')
                    ->orderBy('voicing_category')
                    ->orderBy('root_string')
                    ->orderBy('inversion')
                    ->get();
            }
        }

        $results = $shapes->isEmpty()
            ? []
            : $this->transposeShapes($shapes, $root, $quality, $bassNote, $slashType);

        // Include alias matches (e.g. a Bdim shape aliased as G7(b9))
        $seenIds = array_values(array_filter(array_map(fn ($r) => $r['id'] ?? null, $results)));
        $aliasResults = $this->findAliasMatches($root, $quality, $extension, $bassNote, '', '', $seenIds);

        // Generated dim7 ↔ dom7(b9) symmetry matches: every dim7 shape is a
        // rootless voicing of this dominant when the searched chord is a 7(b9).
        // Generated (not hand-stored) so all dim7 shapes surface, consistently.
        $dimDomResults = $this->findDiminishedDominantMatches($root, $quality, $extension);

        // When searching a dim7 with a known extension (e.g. G°7(b13)), also
        // surface the four dom7 alias readings as search results so the user
        // can navigate directly to each dominant interpretation.
        $dimAliasResults = $this->findDiminishedAliasReadings($root, $quality, $extension);

        return array_merge($results, $aliasResults, $dimDomResults, $dimAliasResults);
    }

    // =========================================================================
    // DB QUERY HELPERS
    // =========================================================================

    public function querySlashShapes(string $quality, string $extension, int $targetInterval): Collection
    {
        $noteSemitones = ChordShapeCalculator::NOTE_SEMITONES;

        $baseQuery = DB::table('sbn_chord_diagrams')
            ->where('quality', $quality)
            ->where(function ($q) {
                $q->where('bass_note', '!=', '')->whereNotNull('bass_note');
            });

        if (!empty($extension)) {
            $withExt = (clone $baseQuery)->where('extensions', $extension)->get();
            $shapes = $withExt->isNotEmpty() ? $withExt : $baseQuery->get();
        } else {
            $shapes = $baseQuery->get();
        }

        return $shapes->filter(function ($shape) use ($noteSemitones, $targetInterval) {
            $rootSemi = $noteSemitones[$shape->root_note] ?? null;
            $bassSemi = $noteSemitones[$shape->bass_note] ?? null;
            if ($rootSemi === null || $bassSemi === null) return false;
            return (($bassSemi - $rootSemi + 12) % 12) === $targetInterval;
        });
    }

    /**
     * Alias matching: find chord diagrams whose stored aliases (alt_root/alt_quality)
     * match the requested chord, then transpose by the interval from the alias root
     * to the requested root. Used by the advanced picker so e.g. a Bdim shape with
     * an alias of G7(b9) surfaces when searching for G7(b9).
     *
     * Returns the same transposed-result shape as transposeShapes(), with
     * `alias_match => true` set on each entry.
     */
    public function findAliasMatches(
        string $root,
        string $quality,
        string $extension,
        string $bassNote,
        string $voicingCategory,
        string $rootString,
        array $excludeIds = []
    ): array {
        $aliasQuery = DB::table('sbn_chord_diagram_aliases')
            ->where('alt_quality', $quality);

        if (!empty($extension)) {
            $aliasQuery->where('alt_extensions', $extension);
        } else {
            $aliasQuery->whereRaw("(alt_extensions = '' OR alt_extensions IS NULL)");
        }

        $aliasMatches = $aliasQuery->get();
        if ($aliasMatches->isEmpty()) return [];

        $aliasDiagramIds = $aliasMatches->pluck('diagram_id')->unique()
            ->diff(collect($excludeIds)->filter())->values()->all();

        if (empty($aliasDiagramIds)) return [];

        $aliasShapesQuery = DB::table('sbn_chord_diagrams')->whereIn('id', $aliasDiagramIds);

        if (!empty($voicingCategory) && $voicingCategory !== 'all') {
            $aliasShapesQuery->where('voicing_category', $voicingCategory);
        }
        if (!empty($rootString) && $rootString !== 'all') {
            $aliasShapesQuery->where('root_string', $rootString);
        }

        $aliasShapes = $aliasShapesQuery->orderByDesc('popularity')->get();
        if ($aliasShapes->isEmpty()) return [];

        $noteSemitones = ChordShapeCalculator::NOTE_SEMITONES;
        $semitoneToNote = [
            0 => 'C', 1 => 'Db', 2 => 'D', 3 => 'Eb', 4 => 'E', 5 => 'F',
            6 => 'Gb', 7 => 'G', 8 => 'Ab', 9 => 'A', 10 => 'Bb', 11 => 'B',
        ];

        // Group aliases by diagram_id — multiple alt_roots can alias one shape,
        // and each yields a distinct fret position when transposed to the
        // requested root.
        $aliasesByDiagram = [];
        foreach ($aliasMatches as $alias) {
            $aliasesByDiagram[$alias->diagram_id][] = $alias;
        }

        $shapeById = [];
        foreach ($aliasShapes as $shape) {
            $shapeById[$shape->id] = $shape;
        }

        $results = [];
        $seenFretPos = []; // dedupe by (shape_id, start_fret) so two aliases that land on the same position don't double up
        foreach ($aliasShapes as $shape) {
            $aliases = $aliasesByDiagram[$shape->id] ?? [];
            foreach ($aliases as $alias) {
                $altRootSemi   = $noteSemitones[$alias->alt_root_note] ?? null;
                $reqRootSemi   = $noteSemitones[$root] ?? null;
                $shapeRootSemi = $noteSemitones[$shape->root_note] ?? null;

                if ($altRootSemi === null || $reqRootSemi === null || $shapeRootSemi === null) continue;

                $offset = (($reqRootSemi - $altRootSemi) + 12) % 12;
                $targetSemi = ($shapeRootSemi + $offset) % 12;
                $targetNote = $semitoneToNote[$targetSemi] ?? null;
                if (!$targetNote) continue;

                $calculated = $this->calculator->calculateFrets($shape, $targetNote);

                if (empty($calculated['diagram_data']) ||
                    (empty($calculated['diagram_data']['positions']) && empty($calculated['diagram_data']['open']))) {
                    continue;
                }

                $dedupeKey = $shape->id . ':' . ($calculated['start_fret'] ?? '?');
                if (isset($seenFretPos[$dedupeKey])) continue;
                $seenFretPos[$dedupeKey] = true;

                $chordName = $root . $quality . ($extension ? "($extension)" : '');
                if (!empty($bassNote)) {
                    $chordName .= '/' . $bassNote;
                }

                // Recompute interval labels relative to the alias root+quality,
                // not the parent shape's root. calculateFrets() uses the parent
                // quality/root and would produce wrong colours (e.g. Bbm6 labels
                // on an Eb7(9)/Bb alias voicing).
                $aliasIntervalLabels = $this->calculator->computeIntervalLabelsPublic(
                    $calculated['diagram_data']['positions'] ?? [],
                    $calculated['diagram_data']['open'] ?? [],
                    $calculated['diagram_data']['muted'] ?? [],
                    $root,
                    $quality
                );

                $results[] = [
                    'id'               => $shape->id,
                    'slug'             => $shape->slug ?? '',
                    'name'             => $chordName,
                    'root_note'        => $root,
                    'original_root'    => $shape->root_note,
                    'quality'          => $quality,
                    'extensions'       => $extension,
                    'voicing_category' => $shape->voicing_category,
                    'root_string'      => $shape->root_string,
                    'inversion'        => $shape->inversion ?? 'root',
                    'start_fret'       => $calculated['start_fret'],
                    'diagram_data'     => $calculated['diagram_data'],
                    'interval_labels'  => $aliasIntervalLabels ?: ($calculated['interval_labels'] ?? ''),
                    'notes'            => $calculated['notes'] ?? '',
                    'bass_note'        => $bassNote,
                    'popularity'       => $shape->popularity ?? 0,
                    'difficulty'       => $shape->difficulty ?? null,
                    'alias_match'      => true,
                    'alias_alt_root'   => $alias->alt_root_note,
                    // Deep-link context for the detail page: open at the PARENT
                    // shape's transposed root ($targetNote) so the primary diagram
                    // renders correctly, then pre-select the alias by its own
                    // transposed identity (root = $root, the searched chord).
                    'display_root'     => $targetNote,
                    'alias_root'       => $root,
                    'alias_quality'    => $quality,
                    'alias_extensions' => $extension,
                    'alias_bass'       => $bassNote,
                ];
            }
        }

        return $results;
    }

    /**
     * Generated dim7 → dom7(b9) matches.
     *
     * A diminished 7th is a rootless voicing of four dominant-7(b9) chords. So
     * when the search is a dom7 carrying a b9 (e.g. "Ab7(b9)", "G7b9"), every
     * When searching a dim7 with a known extension (e.g. G°7(b13)), surface the
     * four dom7(b9) alias readings as additional search results. Each result is an
     * alias_match pointing back to the dim7 detail page with the correct alias
     * pre-selected, mirroring what buildDiminishedReadings() shows on the detail page.
     *
     * @return array<int, array>
     */
    public function findDiminishedAliasReadings(string $root, string $quality, string $extension): array
    {
        if (! in_array($quality, DiminishedSymmetry::DIM_QUALITIES, true)) {
            return [];
        }
        if ($extension === '' || ! isset(DiminishedSymmetry::DIM_EXTENSION_SEMITONES[$extension])) {
            return [];
        }

        $reqRootSemi = ChordShapeCalculator::NOTE_SEMITONES[$root] ?? null;
        if ($reqRootSemi === null) {
            return [];
        }

        // Query shapes matching this dim quality + extension.
        $shapes = DB::table('sbn_chord_diagrams')
            ->where('quality', 'o7')
            ->where('extensions', $extension)
            ->whereRaw("(bass_note = '' OR bass_note IS NULL)")
            ->orderByDesc('popularity')
            ->orderBy('voicing_category')
            ->orderBy('root_string')
            ->get();

        if ($shapes->isEmpty()) {
            return [];
        }

        // The four symmetric dim roots for the searched root.
        $symRootPcs = $this->dimSymmetry->symmetricRoots($reqRootSemi);

        $results = [];
        $seenKey = [];

        foreach ($shapes as $shape) {
            // The shape can be displayed at any of the four symmetric roots; use
            // the searched root as the display root (the page transposes to it).
            $calculated = $this->calculator->calculateFrets($shape, $root);
            if (empty($calculated['diagram_data']) ||
                (empty($calculated['diagram_data']['positions']) && empty($calculated['diagram_data']['open']))) {
                continue;
            }

            // Emit one search result per dom7 alias reading (four total).
            foreach ($this->dimSymmetry->dominantReadings($reqRootSemi) as $slotIdx => $reading) {
                $domRoot       = $this->dimSymmetry->spellRoot($reading['domRootPc']);
                $domExtLabel   = $this->dimSymmetry->extensionLabelForReading($extension, $slotIdx, true);
                $domExtensions = 'b9' . ($domExtLabel ? ',' . $domExtLabel : '');
                $chordName     = $domRoot . '7(' . $domExtensions . ')';
                $isRootless    = $domExtLabel !== null;

                $dedupeKey = $shape->id . ':' . $slotIdx;
                if (isset($seenKey[$dedupeKey])) continue;
                $seenKey[$dedupeKey] = true;

                $tones = $this->dimSymmetry->spellDom7b9($domRoot);
                $notes = implode(',', [$tones['3'], $tones['5'], $tones['b7'], $tones['b9']]);

                $results[] = [
                    'id'               => $shape->id,
                    'slug'             => $shape->slug ?? '',
                    'name'             => $chordName,
                    'root_note'        => $domRoot,
                    'original_root'    => $shape->root_note,
                    'quality'          => 'dom7',
                    'extensions'       => $domExtensions,
                    'voicing_category' => $shape->voicing_category,
                    'root_string'      => $shape->root_string,
                    'inversion'        => 'root',
                    'start_fret'       => $calculated['start_fret'],
                    'diagram_data'     => $calculated['diagram_data'],
                    'interval_labels'  => $calculated['interval_labels'] ?? ($shape->interval_labels ?? ''),
                    'notes'            => $notes,
                    'bass_note'        => '',
                    'popularity'       => $shape->popularity ?? 0,
                    'difficulty'       => $shape->difficulty ?? null,
                    'alias_match'      => true,
                    'rootless'         => $isRootless,
                    'display_root'     => $root,
                    'alias_root'       => $domRoot,
                    'alias_quality'    => 'dom7',
                    'alias_extensions' => $domExtensions,
                    'alias_bass'       => '',
                ];
            }
        }

        return $results;
    }

    /**
     * stored dim7 (`o7`) shape is a valid voicing: transpose it so the searched
     * dominant's b9 (a semitone above the dom root) is a chord tone, and present
     * it as a rootless reading. Generated from DiminishedSymmetry so ALL dim7
     * shapes surface — replaces the old hand-authored dim7 alias rows.
     *
     * Returns transposed-result entries with the same deep-link context as
     * findAliasMatches (display_root = the dim7 root we transpose to; alias_*
     * identify the dom7(b9) reading the detail page should pre-select).
     *
     * @return array<int, array>
     */
    public function findDiminishedDominantMatches(string $root, string $quality, string $extension): array
    {
        // Only the dom7(b9) family. Accept any extension that includes a b9
        // (b9, b9b13, b9#11, …) — the dim7 supplies the b9; extra tensions may
        // be voiced by an extended dim7 shape (e.g. °7b13 → dom7(b9,13)).
        if ($quality !== 'dom7' || ! str_contains($extension, 'b9')) {
            return [];
        }

        $reqRootSemi = ChordShapeCalculator::NOTE_SEMITONES[$root] ?? null;
        if ($reqRootSemi === null) {
            return [];
        }

        // The dim7 lives on the dominant's b9 (one semitone above the root).
        $dimRootPc  = ($reqRootSemi + 1) % 12;
        $targetNote = $this->dimSymmetry->spellRoot($dimRootPc);

        // Fetch all o7 shapes (bare and extended) without a stored bass note.
        $shapes = DB::table('sbn_chord_diagrams')
            ->where('quality', 'o7')
            ->whereRaw("(bass_note = '' OR bass_note IS NULL)")
            ->orderByDesc('popularity')
            ->orderBy('voicing_category')
            ->orderBy('root_string')
            ->get();
        if ($shapes->isEmpty()) {
            return [];
        }

        // Base tones for the rootless voicing (3, 5, b7, b9).
        $tones = $this->dimSymmetry->spellDom7b9($root);

        $results = [];
        $seenFretPos = [];
        foreach ($shapes as $shape) {
            $calculated = $this->calculator->calculateFrets($shape, $targetNote);

            if (empty($calculated['diagram_data']) ||
                (empty($calculated['diagram_data']['positions']) && empty($calculated['diagram_data']['open']))) {
                continue;
            }

            $dedupeKey = $shape->id . ':' . ($calculated['start_fret'] ?? '?');
            if (isset($seenFretPos[$dedupeKey])) continue;
            $seenFretPos[$dedupeKey] = true;

            // Determine the dim shape's extension and what it maps to from this
            // dom root. dominantReadings() returns four slots in dim-root order
            // (+0,+3,+6,+9 semitones); find the slot whose domRootPc matches the
            // searched root so we compute the extension label from the right frame.
            $dimExt      = (string) ($shape->extensions ?? '');
            $aliasSlotIdx = 0;
            if ($dimExt !== '' && isset(DiminishedSymmetry::DIM_EXTENSION_SEMITONES[$dimExt])) {
                foreach ($this->dimSymmetry->dominantReadings($dimRootPc) as $k => $r) {
                    if ($r['domRootPc'] === $reqRootSemi) {
                        $aliasSlotIdx = $k;
                        break;
                    }
                }
            }
            $domExtLabel  = ($dimExt !== '' && isset(DiminishedSymmetry::DIM_EXTENSION_SEMITONES[$dimExt]))
                ? $this->dimSymmetry->extensionLabelForReading($dimExt, $aliasSlotIdx, true)
                : null;

            // When the extension lands on the dom root (label=null), the voicing
            // contains the root — include it but mark as not purely rootless.
            $isRootless    = !($dimExt !== '' && $domExtLabel === null);
            $domExtensions = 'b9' . ($domExtLabel ? ',' . $domExtLabel : '');
            $chordName     = $root . '7(' . $domExtensions . ')';

            // Deep-link alias_extensions must match what buildDiminishedReadings()
            // generates on the dim7 detail page for this alias slot.
            $aliasExtensions = $domExtensions;

            $notes = implode(',', [$tones['3'], $tones['5'], $tones['b7'], $tones['b9']]);

            $results[] = [
                'id'               => $shape->id,
                'slug'             => $shape->slug ?? '',
                'name'             => $chordName,
                'root_note'        => $root,
                'original_root'    => $shape->root_note,
                'quality'          => 'dom7',
                'extensions'       => $domExtensions,
                'voicing_category' => $shape->voicing_category,
                'root_string'      => $shape->root_string,
                'inversion'        => 'root',
                'start_fret'       => $calculated['start_fret'],
                'diagram_data'     => $calculated['diagram_data'],
                'interval_labels'  => $calculated['interval_labels'] ?? ($shape->interval_labels ?? ''),
                'notes'            => $notes,
                'bass_note'        => '',
                'popularity'       => $shape->popularity ?? 0,
                'difficulty'       => $shape->difficulty ?? null,
                'alias_match'      => true,
                'rootless'         => $isRootless,
                'display_root'     => $targetNote,
                'alias_root'       => $root,
                'alias_quality'    => 'dom7',
                'alias_extensions' => $aliasExtensions,
                'alias_bass'       => '',
            ];
        }

        return $results;
    }

    /**
     * Generate the full dim7 inversion + dom7(b9) alias set for the voicing picker.
     *
     * For an o7 query the picker needs:
     *   - 4 dim inversions (each named by its own root, e.g. C°7 / Eb°7 / Gb°7 / A°7)
     *   - 4 dom7(b9) alias readings per shape (rootless, with bass-function inversion slot)
     *
     * Mirrors ChordLibraryController::buildDiminishedReadings() but works from raw
     * stdClass DB rows and outputs the flat picker-result shape (frets/position come
     * from diagram_data via the normal calculateFrets path).
     *
     * @param  \Illuminate\Support\Collection $shapes   DB rows for quality=o7
     * @param  string                         $root     Requested display root
     * @param  string                         $filter   'dim'|'dom'|'all' — which readings to return
     * @return array<int, array>
     */
    public function findDiminishedPickerResults(Collection $shapes, string $root, string $filter = 'all'): array
    {
        $rootPc   = $this->dimSymmetry->toPc($root);
        $symRoots = $this->dimSymmetry->symmetricRoots($rootPc);
        $slots    = ['root', 'inv1', 'inv2', 'inv3'];

        // Bass-function interval → inversion slot (semitones from dom root)
        $bassFunctionSlot = [
            1  => 'rootless',
            4  => 'inv1',
            7  => 'inv2',
            10 => 'inv3',
        ];

        $results  = [];
        $seenKeys = [];

        foreach ($shapes as $shape) {
            $dimExt = (string) ($shape->extensions ?? '');
            $hasExt = $dimExt !== '' && isset(DiminishedSymmetry::DIM_EXTENSION_SEMITONES[$dimExt]);

            // ── Dim7 inversions (4 positions, each named by its own root) ──────────
            if ($filter === 'dim' || $filter === 'all') {
                foreach ($slots as $idx => $slot) {
                    $invRootPc = $symRoots[$idx];
                    $invRoot   = $this->dimSymmetry->spellRoot($invRootPc);
                    $extLabel  = $hasExt
                        ? $this->dimSymmetry->extensionLabelForReading($dimExt, $idx, false)
                        : null;
                    $extStr    = $extLabel ?? ($hasExt ? $dimExt : '');

                    $calculated = $this->calculator->calculateFrets($shape, $root);
                    if (empty($calculated['diagram_data']) ||
                        (empty($calculated['diagram_data']['positions']) && empty($calculated['diagram_data']['open']))) {
                        continue;
                    }

                    $key = $shape->id . ':dim:' . $idx;
                    if (isset($seenKeys[$key])) continue;
                    $seenKeys[$key] = true;

                    $chordName = $invRoot . 'o7' . ($extStr ? "($extStr)" : '');

                    $tones = $this->dimSymmetry->spellDim7($invRoot);

                    $results[] = [
                        'id'               => $shape->id,
                        'slug'             => $shape->slug ?? '',
                        'name'             => $chordName,
                        'root_note'        => $invRoot,
                        'original_root'    => $shape->root_note,
                        'quality'          => 'o7',
                        'extensions'       => $extStr,
                        'voicing_category' => $shape->voicing_category,
                        'root_string'      => $shape->root_string,
                        'inversion'        => $slot,
                        'start_fret'       => $calculated['start_fret'],
                        'diagram_data'     => $calculated['diagram_data'],
                        'interval_labels'  => $calculated['interval_labels'] ?? ($shape->interval_labels ?? ''),
                        'notes'            => implode(',', array_values($tones)),
                        'bass_note'        => '',
                        'popularity'       => $shape->popularity ?? 0,
                        'difficulty'       => $shape->difficulty ?? null,
                        'dim_inversion'    => true,
                        'display_root'     => $invRoot,
                    ];
                }
            }

            // ── Dom7(b9) alias readings (4 per shape) ────────────────────────────
            if ($filter === 'dom' || $filter === 'all') {
                foreach ($this->dimSymmetry->dominantReadings($rootPc) as $i => $reading) {
                    $domRoot   = $this->dimSymmetry->spellRoot($reading['domRootPc']);
                    $domRootPc = $reading['domRootPc'];

                    $domExtLabel  = $hasExt
                        ? $this->dimSymmetry->extensionLabelForReading($dimExt, $i, true)
                        : null;
                    $domExtStr    = 'b9' . ($domExtLabel ? ',' . $domExtLabel : '');
                    $isRootless   = !($hasExt && $domExtLabel === null);
                    $chordName    = $domRoot . '7(' . $domExtStr . ')';

                    // Transpose the shape to the dim root (b9 of this dom reading).
                    $dimRootForDom = $this->dimSymmetry->spellRoot(($domRootPc + 1) % 12);
                    $calculated = $this->calculator->calculateFrets($shape, $dimRootForDom);
                    if (empty($calculated['diagram_data']) ||
                        (empty($calculated['diagram_data']['positions']) && empty($calculated['diagram_data']['open']))) {
                        continue;
                    }

                    // Determine bass-function inversion slot by computing the interval
                    // from the dom root to the physical lowest note of the shape.
                    $dd = $calculated['diagram_data'];
                    $lowestString = null;
                    if (!empty($dd['open'])) $lowestString = min($dd['open']);
                    foreach ($dd['positions'] ?? [] as $pos) {
                        if ($lowestString === null || $pos['string'] < $lowestString) {
                            $lowestString = $pos['string'];
                        }
                    }
                    // Infer from slot index: slot 0 = root pos (b9 in bass = rootless),
                    // slots 1/2/3 rotate by +3 semitones each from the dim root.
                    $slotDimRootPc = ($rootPc + $i * 3) % 12;
                    $bassIntervalFromDom = ($slotDimRootPc - $domRootPc + 12) % 12; // =1,4,7,10
                    $invSlot = $bassFunctionSlot[$bassIntervalFromDom] ?? 'root';

                    $key = $shape->id . ':dom:' . $i;
                    if (isset($seenKeys[$key])) continue;
                    $seenKeys[$key] = true;

                    $tones = $this->dimSymmetry->spellDom7b9($domRoot);

                    $results[] = [
                        'id'               => $shape->id,
                        'slug'             => $shape->slug ?? '',
                        'name'             => $chordName,
                        'root_note'        => $domRoot,
                        'original_root'    => $shape->root_note,
                        'quality'          => 'dom7',
                        'extensions'       => $domExtStr,
                        'voicing_category' => $shape->voicing_category,
                        'root_string'      => $shape->root_string,
                        'inversion'        => $invSlot,
                        'start_fret'       => $calculated['start_fret'],
                        'diagram_data'     => $calculated['diagram_data'],
                        'interval_labels'  => $calculated['interval_labels'] ?? ($shape->interval_labels ?? ''),
                        'notes'            => implode(',', array_values($tones)),
                        'bass_note'        => '',
                        'popularity'       => $shape->popularity ?? 0,
                        'difficulty'       => $shape->difficulty ?? null,
                        'alias_match'      => true,
                        'rootless'         => $isRootless,
                        'display_root'     => $dimRootForDom,
                        'alias_root'       => $domRoot,
                        'alias_quality'    => 'dom7',
                        'alias_extensions' => $domExtStr,
                        'alias_bass'       => '',
                    ];
                }
            }
        }

        return $results;
    }

    public function queryInversionShapes(string $quality, string $extension, string $inversion): Collection
    {
        $invQuery = DB::table('sbn_chord_diagrams')
            ->where('quality', $quality)
            ->where('inversion', $inversion)
            ->whereRaw("(bass_note = '' OR bass_note IS NULL)");

        if (!empty($extension)) {
            $withExt = (clone $invQuery)->where('extensions', $extension)->get();
            if ($withExt->isNotEmpty()) return $withExt;

            $withoutExt = (clone $invQuery)->get();
            if ($withoutExt->isNotEmpty()) return $withoutExt;
        } else {
            $invShapes = (clone $invQuery)
                ->whereRaw("(extensions = '' OR extensions IS NULL)")
                ->get();
            if ($invShapes->isNotEmpty()) return $invShapes;
        }

        if (!empty($extension)) {
            $fallback = DB::table('sbn_chord_diagrams')
                ->where('quality', $quality)
                ->where('extensions', $extension)
                ->whereRaw("(bass_note = '' OR bass_note IS NULL)")
                ->orderBy('voicing_category')
                ->orderBy('root_string')
                ->orderBy('inversion')
                ->get();

            if ($fallback->isNotEmpty()) return $fallback;
        }

        return DB::table('sbn_chord_diagrams')
            ->where('quality', $quality)
            ->whereRaw("(extensions = '' OR extensions IS NULL)")
            ->whereRaw("(bass_note = '' OR bass_note IS NULL)")
            ->orderBy('voicing_category')
            ->orderBy('root_string')
            ->orderBy('inversion')
            ->get();
    }

    // =========================================================================
    // TRANSPOSITION
    // =========================================================================

    public function transposeShapes(
        Collection $shapes,
        string $root,
        string $quality,
        string $bassNote,
        string $slashType
    ): array {
        $results = [];

        foreach ($shapes as $shape) {
            if (!empty($shape->is_fixed_position) && strtolower($shape->root_note ?? '') !== strtolower($root)) {
                continue;
            }

            if ($slashType === 'slash' && !empty($bassNote) && !empty($shape->bass_note)) {
                $calculated = $this->calculator->calculateFretsWithBass($shape, $root, $bassNote);
            } else {
                $calculated = $this->calculator->calculateFrets($shape, $root);
            }

            if (empty($calculated['diagram_data']) || (empty($calculated['diagram_data']['positions']) && empty($calculated['diagram_data']['open']))) {
                continue;
            }

            $chordName = $root . $shape->quality . ($shape->extensions ?? '');
            $inversion = $shape->inversion ?? 'root';
            $bassNoteName = '';

            if ($slashType === 'slash' && !empty($bassNote)) {
                $bassNoteName = $bassNote;
                $chordName .= '/' . $bassNote;
            } elseif ($inversion !== 'root') {
                $bassNoteName = ChordShapeCalculator::deriveBassNote($root, $shape->quality, $inversion) ?? '';
                if ($bassNoteName) {
                    $chordName .= '/' . $bassNoteName;
                }
            }

            $results[] = [
                'id'               => $shape->id,
                'slug'             => $shape->slug ?? '',
                'name'             => $chordName,
                'root_note'        => $root,
                'original_root'    => $shape->root_note,
                'quality'          => $shape->quality,
                'extensions'       => $shape->extensions ?? '',
                'voicing_category' => $shape->voicing_category,
                'root_string'      => $shape->root_string,
                'inversion'        => $inversion,
                'start_fret'       => $calculated['start_fret'],
                'diagram_data'     => $calculated['diagram_data'],
                'interval_labels'  => $calculated['interval_labels'] ?: ($shape->interval_labels ?? ''),
                'notes'            => $calculated['notes'],
                'bass_note'        => $bassNoteName,
                'popularity'       => $shape->popularity ?? 0,
                'difficulty'       => $shape->difficulty ?? null,
            ];
        }

        return $results;
    }
}

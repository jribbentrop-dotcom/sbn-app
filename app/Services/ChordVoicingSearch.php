<?php

namespace App\Services;

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
    public function __construct(protected ChordShapeCalculator $calculator)
    {
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

        return array_merge($results, $aliasResults);
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
                    'interval_labels'  => $calculated['interval_labels'] ?? ($shape->interval_labels ?? ''),
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

        $noteToSemitone = [
            'C' => 0, 'C#' => 1, 'Db' => 1, 'D' => 2, 'D#' => 3, 'Eb' => 3,
            'E' => 4, 'F' => 5, 'F#' => 6, 'Gb' => 6, 'G' => 7, 'G#' => 8,
            'Ab' => 8, 'A' => 9, 'A#' => 10, 'Bb' => 10, 'B' => 11,
        ];
        $semitoneToNote = [
            0 => 'C', 1 => 'Db', 2 => 'D', 3 => 'Eb', 4 => 'E', 5 => 'F',
            6 => 'Gb', 7 => 'G', 8 => 'Ab', 9 => 'A', 10 => 'Bb', 11 => 'B',
        ];

        $invIntervals = [
            'maj7' => ['inv1' => 4, 'inv2' => 7, 'inv3' => 11],
            'maj6' => ['inv1' => 4, 'inv2' => 7, 'inv3' => 9],
            'm7'   => ['inv1' => 3, 'inv2' => 7, 'inv3' => 10],
            'm6'   => ['inv1' => 3, 'inv2' => 7, 'inv3' => 9],
            'dom7' => ['inv1' => 4, 'inv2' => 7, 'inv3' => 10],
            'm7b5' => ['inv1' => 3, 'inv2' => 6, 'inv3' => 10],
            'o7'   => ['inv1' => 3, 'inv2' => 6, 'inv3' => 9],
            'mMaj7'=> ['inv1' => 3, 'inv2' => 7, 'inv3' => 11],
            'aug7' => ['inv1' => 4, 'inv2' => 8, 'inv3' => 10],
            'maj'  => ['inv1' => 4, 'inv2' => 7],
            'min'  => ['inv1' => 3, 'inv2' => 7],
            'aug'  => ['inv1' => 4, 'inv2' => 8],
            'dim'  => ['inv1' => 3, 'inv2' => 6],
            'sus4' => ['inv1' => 5, 'inv2' => 7],
            'sus2' => ['inv1' => 2, 'inv2' => 7],
            'add9' => ['inv1' => 2, 'inv2' => 4, 'inv3' => 7],
        ];

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
                $qualKey = $shape->quality;
                if (isset($invIntervals[$qualKey][$inversion], $noteToSemitone[$root])) {
                    $rootSemi = $noteToSemitone[$root];
                    $intSemi  = $invIntervals[$qualKey][$inversion];
                    $bassSemi = ($rootSemi + $intSemi) % 12;
                    $bassNoteName = $semitoneToNote[$bassSemi];
                }
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

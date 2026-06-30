<?php

namespace App\Services;

use App\Models\Leadsheet;

/**
 * SBN Harmonic Context
 *
 * Builds a structured harmonic context from a leadsheet, wrapping the
 * ProgressionDetector's analysis into a format consumable by the
 * Progression Builder, tab identifier, and other tools.
 *
 * This is a thin layer over ProgressionDetector — it does NOT duplicate
 * the detection logic. It restructures the output into per-chord context
 * objects with prev/next links and parsed chord data.
 *
 * @package App\Services
 */
class HarmonicContext
{
    protected ProgressionDetector $detector;
    protected LeadsheetParser $parser;

    public function __construct(ProgressionDetector $detector, LeadsheetParser $parser)
    {
        $this->detector = $detector;
        $this->parser   = $parser;
    }

    // =========================================================================
    // MAIN ENTRY POINT
    // =========================================================================

    /**
     * Build a full harmonic context from a leadsheet.
     *
     * @param  Leadsheet $leadsheet
     * @return array{
     *     song_key: string,
     *     sections: array<int, array{
     *         section_index: int,
     *         section_id: string,
     *         section_name: string,
     *         section_key: string,
     *         chords: array<int, array{
     *             measure_index: int,
     *             beat_position: int,
     *             chord_name: string,
     *             root: string|null,
     *             quality: string,
     *             extension: string,
     *             bass_note: string,
     *             roman_numeral: string,
     *             detected_progressions: array,
     *         }>
     *     }>
     * }
     */
    public function buildFromLeadsheet(Leadsheet $leadsheet): array
    {
        // Parse the leadsheet structure
        $song = $this->parser->parse($leadsheet->shortcode_content ?? '');
        $key  = $song['key'] ?? 'C';

        if (empty($song['sections'])) {
            return ['song_key' => $key, 'sections' => []];
        }

        // Run the detector's analysis (without storing — read-only)
        $analysis = $this->detector->analyseLeadsheet($leadsheet);

        // Build per-section key map
        $sectionKeys = [];
        foreach ($song['sections'] as $si => $section) {
            $sectionKeys[$si] = !empty($section['tonality']) ? $section['tonality'] : $key;
        }

        // Build structured context
        $sections = [];

        foreach ($song['sections'] as $si => $section) {
            $secKey         = $sectionKeys[$si];
            $analysisSection = $analysis['sections'][$si] ?? null;

            // Flatten chords with position info
            $chords    = [];
            $beatPos   = 0;

            foreach ($section['measures'] as $mi => $measure) {
                $beatPos = 0;
                foreach ($measure['chords'] as $ci => $chord) {
                    $parsed = $this->detector->parseChordName($chord['name']);
                    $numeral = $this->detector->chordToNumeral($chord['name'], $secKey);

                    // Find which detected progressions this chord participates in
                    $progressions = [];
                    if ($analysisSection) {
                        foreach ($analysisSection['matches'] as $match) {
                            if ($mi >= $match['start_measure'] &&
                                $mi <= $match['end_measure']) {
                                $progressions[] = [
                                    'progression_id' => $match['progression_id'],
                                    'name'           => $match['name'],
                                    'category'       => $match['category'],
                                    'numerals'       => $match['numerals'],
                                ];
                            }
                        }
                    }

                    $chords[] = [
                        'measure_index'          => $mi,
                        'chord_index'            => $ci,
                        'beat_position'          => $beatPos,
                        'chord_name'             => $chord['name'],
                        'root'                   => $parsed['root'] ?? null,
                        'quality'                => $parsed['quality'] ?? '',
                        'extension'              => $parsed['extension'] ?? '',
                        'bass_note'              => $parsed['bass_note'] ?? '',
                        'roman_numeral'          => $numeral ?? '?',
                        'beats'                  => $chord['beats'] ?? null,
                        'detected_progressions'  => $progressions,
                    ];

                    $beatPos += ($chord['beats'] ?? 1);
                }
            }

            // Wire prev/next indices (not object refs — arrays are values in PHP)
            for ($i = 0; $i < count($chords); $i++) {
                $chords[$i]['prev_index'] = $i > 0 ? $i - 1 : null;
                $chords[$i]['next_index'] = $i < count($chords) - 1 ? $i + 1 : null;
            }

            $sections[] = [
                'section_index' => $si,
                'section_id'    => $section['id'] ?? 'A',
                'section_name'  => $section['name'] ?? $section['id'] ?? 'A',
                'section_key'   => $secKey,
                'chords'        => $chords,
            ];
        }

        return [
            'song_key' => $key,
            'sections' => $sections,
        ];
    }

    // =========================================================================
    // BUILD FROM RAW CHORD SEQUENCE (for standalone progression builder)
    // =========================================================================

    /**
     * Build a minimal harmonic context from a chord sequence string and key.
     * Used when the progression builder is invoked standalone (not from a leadsheet).
     *
     * @param  string $key       e.g. 'C', 'Dm'
     * @param  array  $chordNames  e.g. ['Dm7', 'G7', 'Cmaj7']
     * @return array  Same structure as buildFromLeadsheet but with one section
     */
    public function buildFromChordSequence(string $key, array $chordData): array
    {
        $chords = [];
        foreach ($chordData as $i => $item) {
            $name = is_array($item) ? ($item['chord_name'] ?? $item['name'] ?? '?') : $item;
            $name = self::reSpellChordName($name, $key);
            $mIdx = is_array($item) ? ($item['measure_index'] ?? $i) : $i;
            $cIdx = is_array($item) ? ($item['chord_index'] ?? 0) : 0;

            $parsed  = $this->detector->parseChordName($name);
            $numeral = $this->detector->chordToNumeral($name, $key);

            $chords[] = [
                'measure_index'          => $mIdx,
                'chord_index'            => $cIdx,
                'beat_position'          => 0,
                'chord_name'             => $name,
                'root'                   => $parsed['root'] ?? null,
                'quality'                => $parsed['quality'] ?? '',
                'extension'              => $parsed['extension'] ?? '',
                'bass_note'              => $parsed['bass_note'] ?? '',
                'roman_numeral'          => $numeral ?? '?',
                'beats'                  => null,
                'detected_progressions'  => [],
                'prev_index'             => $i > 0 ? $i - 1 : null,
                'next_index'             => $i < count($chordData) - 1 ? $i + 1 : null,
            ];
        }

        return [
            'song_key' => $key,
            'sections' => [
                [
                    'section_index' => 0,
                    'section_id'    => 'A',
                    'section_name'  => 'Main',
                    'section_key'   => $key,
                    'chords'        => $chords,
                ],
            ],
        ];
    }

    // =========================================================================
    // BUILD FROM NUMERALS (for progression library / builder UI)
    // =========================================================================

    /**
     * Build a harmonic context from a numeral string + key.
     * Resolves each numeral to a concrete chord name in the given key.
     *
     * @param  string $key       e.g. 'C', 'Dm'
     * @param  string $numerals  e.g. 'IIm7,V7,Imaj7'
     * @return array  Same structure
     */
    public function buildFromNumerals(string $key, string $numerals): array
    {
        $tokens = array_values(array_filter(array_map('trim', explode(',', $numerals))));
        if (empty($tokens)) {
            return ['song_key' => $key, 'sections' => []];
        }

        $chordNames = [];
        foreach ($tokens as $token) {
            $chordNames[] = $this->numeralToChordName($token, $key);
        }

        $context = $this->buildFromChordSequence($key, $chordNames);

        // Ensure the roman_numeral field matches the original tokens
        foreach ($tokens as $i => $token) {
            if (isset($context['sections'][0]['chords'][$i])) {
                $context['sections'][0]['chords'][$i]['roman_numeral'] = $token;
            }
        }

        return $context;
    }

    // =========================================================================
    // NUMERAL → CHORD NAME RESOLUTION
    // =========================================================================

    /**
     * Note semitone map (same as ProgressionDetector).
     */
    private const NOTE_TO_SEMI = [
        'C'  => 0,  'B#' => 0,
        'C#' => 1,  'Db' => 1,
        'D'  => 2,
        'D#' => 3,  'Eb' => 3,
        'E'  => 4,  'Fb' => 4,
        'F'  => 5,  'E#' => 5,
        'F#' => 6,  'Gb' => 6,
        'G'  => 7,
        'G#' => 8,  'Ab' => 8,
        'A'  => 9,
        'A#' => 10, 'Bb' => 10,
        'B'  => 11, 'Cb' => 11,
    ];

    private const SEMI_TO_NOTE_FLAT  = ['C', 'Db', 'D', 'Eb', 'E', 'F', 'Gb', 'G', 'Ab', 'A', 'Bb', 'B'];
    private const SEMI_TO_NOTE_SHARP = ['C', 'C#', 'D', 'D#', 'E', 'F', 'F#', 'G', 'G#', 'A', 'A#', 'B'];

    // Keys whose natural spelling uses flats (circle of fifths, flat side).
    private const FLAT_KEYS = ['F','Bb','Eb','Ab','Db','Gb','Dm','Gm','Cm','Fm','Bbm','Ebm'];

    // Keys whose natural spelling uses sharps (circle of fifths, sharp side, +
    // their relative minors). Everything NOT in this list — the flat keys AND the
    // neutral keys C major / A minor — defaults to flats. This is the app's house
    // style (jazz/bossa lean flat) and avoids spurious sharps in neutral keys.
    private const SHARP_KEYS = ['G','D','A','E','B','F#','C#','Em','Bm','F#m','C#m','G#m','D#m','A#m'];

    private const MAJOR_SCALE = [0, 2, 4, 5, 7, 9, 11];

    private const ROMAN_TO_DEGREE = [
        'I' => 0, 'II' => 1, 'III' => 2, 'IV' => 3, 'V' => 4, 'VI' => 5, 'VII' => 6,
    ];

    /**
     * Numeral suffix → display quality.
     */
    private const SUFFIX_TO_DISPLAY = [
        ''        => '',       // major triad
        'maj7'    => 'maj7',
        'maj9'    => 'maj9',
        '7'       => '7',
        '9'       => '9',
        '13'      => '13',
        'm'       => 'm',
        'm7'      => 'm7',
        'm6'      => 'm6',
        'm9'      => 'm9',
        'm7b5'    => 'm7b5',
        'o7'      => 'dim7',
        'o'       => 'dim',
        'mMaj7'   => 'mMaj7',
        'aug'     => 'aug',
        'aug7'    => 'aug7',
        'sus4'    => 'sus4',
        'sus2'    => 'sus2',
        // Line-cliché chromatic inner-voice suffixes — parenthesised form
        // so numeralToChordName emits e.g. "Dm(b6)" → parseChordName splits
        // quality=m / extension=b6 correctly.
        '(b6)'    => '(b6)',
        '(6)'     => '(6)',
        '(#5)'    => '(#5)',
    ];

    /**
     * Map a chord-name quality suffix (as written, e.g. '7', 'maj7', 'min7',
     * 'm7b5', 'dim7') to the internal quality token used by
     * ChordShapeCalculator::QUALITY_FLAT_INTERVALS ('dom7', 'maj7', 'm7', …).
     * Only the qualities that carry a flat-lean need exact mapping; anything
     * unrecognised returns '' and the chord rule treats it as neutral.
     */
    private static function normalizeQualityToken(string $suffix): string
    {
        $s = strtolower(trim($suffix));
        // Strip a slash-bass if it sneaked in, and any parenthesised extension.
        $s = preg_replace('#/.*$#', '', $s);
        $s = preg_replace('/\(.*$/', '', $s);
        if ($s === '' || $s === 'maj') return '';                 // major triad: neutral
        if (preg_match('/^(maj|ma|M)(7|9|11|13)/', $suffix)) return 'maj7';
        if (preg_match('/^(m|min|-)(7b5|7\(b5\))/', $s))     return 'm7b5';
        if (preg_match('/^(o|dim|°)7/', $s))                 return 'm7b5'; // dim7 ~ flat lean
        if (preg_match('/^(m|min|-)maj7/', $s))              return 'mMaj7';
        if (preg_match('/^(m|min|-)6/', $s))                 return 'm6';
        if (preg_match('/^(m|min|-)/', $s))                  return 'm7';   // any minor ⇒ flat-lean
        if (preg_match('/^aug7|^\+7/', $s))                  return 'aug7';
        if (preg_match('/^(7|9|11|13)/', $s))                return 'dom7'; // dominant
        return '';
    }

    /**
     * Decide flat vs sharp spelling for one chord, combining the app's two
     * accidental rules in priority order:
     *
     *   1. Key-related accidentals — when a key is known, the whole chart follows
     *      the key's flat/sharp family (flat keys → flats, sharp keys → sharps).
     *      This is dominant: a leadsheet should not contradict its key signature.
     *   2. Chord-related accidentals — when no key is known, the chord's own
     *      root + quality decides (minor/dominant qualities lean flat, etc.),
     *      via ChordShapeCalculator::useFlatsForQuality().
     *
     * This is the single source of truth both PHP and the JS twin
     * (window.sbnSpellChordName) draw from.
     *
     * @param string $rootNote  e.g. 'C', 'F#', 'Bb'
     * @param string $quality   internal quality token e.g. 'm7', 'dom7', 'maj7'
     * @param string $key        song key (''/unknown ⇒ fall back to chord rule)
     */
    public static function useFlatsFor(string $rootNote, string $quality, string $key = ''): bool
    {
        if (trim($key) !== '') {
            return self::spellingUsesFlats($key);
        }
        return ChordShapeCalculator::useFlatsForQuality($rootNote, $quality);
    }

    /**
     * Re-spell a chord name's root and bass note to match the app's accidental
     * rules (see useFlatsFor()): key family when a key is given, else the chord's
     * own root+quality lean.  Handles naturals, enharmonic pairs, and slash chords.
     * Examples: reSpellChordName('D/Gb', 'D')  → 'D/F#'   (key D ⇒ sharps)
     *           reSpellChordName('Db7',  'G')  → 'C#7'    (key G ⇒ sharps)
     *           reSpellChordName('A#7',  'C')  → 'Bb7'    (neutral key ⇒ flats)
     *           reSpellChordName('F#m7', '')   → 'F#m7'   (no key; sharp root kept)
     * Notes that are unambiguous (naturals, or already correct) are returned as-is.
     */
    public static function reSpellChordName(string $name, string $key = ''): string
    {
        // Parse root note + quality up front so the chord-rule fallback has context.
        $head = strpos($name, '/') !== false ? substr($name, 0, strpos($name, '/')) : $name;
        preg_match('/^([A-G][#b]?)(.*)$/s', $head, $hm);
        $rootForRule    = $hm[1] ?? $name;
        $qualityForRule = self::normalizeQualityToken($hm[2] ?? '');

        $useFlats = self::useFlatsFor($rootForRule, $qualityForRule, $key);
        $sharp    = self::SEMI_TO_NOTE_SHARP;
        $flat     = self::SEMI_TO_NOTE_FLAT;
        $semi     = self::NOTE_TO_SEMI;

        $reSpellNote = static function (string $note) use ($useFlats, $sharp, $flat, $semi): string {
            // Parse note letter + accidental (handles b and #, single char accidental)
            if (!preg_match('/^([A-G])([#b]?)$/', $note, $m)) return $note;
            $s = $semi[$m[1] . $m[2]] ?? $semi[$m[1]] ?? null;
            if ($s === null) return $note;
            return $useFlats ? $flat[$s] : $sharp[$s];
        };

        // Split off slash bass
        $slash = strpos($name, '/');
        if ($slash !== false) {
            $root = substr($name, 0, $slash);
            $bass = substr($name, $slash + 1);
        } else {
            $root = $name;
            $bass = null;
        }

        // Root = leading note letter + optional accidental, rest is quality
        if (!preg_match('/^([A-G][#b]?)(.*)$/s', $root, $rm)) {
            return $name;
        }
        $rootNote    = $reSpellNote($rm[1]);
        $rootQuality = $rm[2];

        $result = $rootNote . $rootQuality;
        if ($bass !== null) {
            // Bass is just a note (e.g. "F#", "Gb")
            if (preg_match('/^([A-G][#b]?)(.*)$/', $bass, $bm)) {
                $result .= '/' . $reSpellNote($bm[1]) . $bm[2];
            } else {
                $result .= '/' . $bass;
            }
        }
        return $result;
    }

    /**
     * Whether a key should be spelled with flats (true) or sharps (false).
     *
     * House style: flats are the default. Only the genuine sharp keys (G, D, A,
     * E, B, F#, C# and their relative minors) spell with sharps. The flat keys
     * AND the neutral keys C major / A minor all use flats — this kills spurious
     * sharps in neutral keys (a Bb chord in C major stays Bb, not A#).
     */
    public static function spellingUsesFlats(string $key): bool
    {
        $key     = trim($key);
        $keyRoot = preg_replace('/[mM].*$/', '', $key);
        $isMinor = (bool) preg_match('/[mM]/', $key);

        if ($isMinor) {
            // Normalise the minor key (root may be written sharp or flat) to a
            // canonical minor token, then test against the sharp-minor list.
            $semi = self::NOTE_TO_SEMI[$keyRoot] ?? null;
            if ($semi === null) return true; // unknown ⇒ flats
            $sharpMinor = self::SEMI_TO_NOTE_SHARP[$semi] . 'm';
            return !in_array($sharpMinor, self::SHARP_KEYS, true);
        }

        $semi = self::NOTE_TO_SEMI[$keyRoot] ?? null;
        if ($semi === null) return true;     // unknown ⇒ flats
        $sharpMajor = self::SEMI_TO_NOTE_SHARP[$semi];
        return !in_array($sharpMajor, self::SHARP_KEYS, true);
    }

    /**
     * Convert a Roman numeral token to a concrete chord name in a key.
     *
     * @param  string $numeral  e.g. 'IIm7', 'V7', 'bVII7', 'Imaj7'
     * @param  string $key      e.g. 'C', 'Dm'
     * @return string           e.g. 'Dm7', 'G7', 'Bb7', 'Cmaj7'
     */
    public function numeralToChordName(string $numeral, string $key): string
    {
        // Parse: optional accidental + Roman degree + quality suffix
        if (!preg_match('/^(b|#)?([IViv]+)(.*)$/', $numeral, $m)) {
            return 'C'; // fallback
        }

        $accidental = $m[1];     // 'b', '#', or ''
        $romanPart  = strtoupper($m[2]);  // 'I', 'II', 'III', etc.
        $suffix     = $m[3];     // 'maj7', 'm7', '7', etc.

        // Key root
        $keyNote = preg_replace('/[mM].*$/', '', trim($key));
        $keySemi = self::NOTE_TO_SEMI[$keyNote] ?? 0;

        // Degree → semitone offset
        $degree = self::ROMAN_TO_DEGREE[$romanPart] ?? null;
        if ($degree === null) {
            return 'C';
        }

        $semitoneOffset = self::MAJOR_SCALE[$degree];

        // Apply accidental
        if ($accidental === 'b') $semitoneOffset--;
        if ($accidental === '#') $semitoneOffset++;

        // Compute root note — flat numerals (bVI, bVII, bIII…) always spell flat;
        // sharp numerals always spell sharp; natural numerals follow the key.
        $rootSemi   = ($keySemi + $semitoneOffset + 12) % 12;
        if ($accidental === 'b') {
            $noteMap = self::SEMI_TO_NOTE_FLAT;
        } elseif ($accidental === '#') {
            $noteMap = self::SEMI_TO_NOTE_SHARP;
        } else {
            $noteMap = self::spellingUsesFlats($key) ? self::SEMI_TO_NOTE_FLAT : self::SEMI_TO_NOTE_SHARP;
        }
        $rootNote   = $noteMap[$rootSemi];

        // Quality suffix → display quality
        $displayQuality = self::SUFFIX_TO_DISPLAY[$suffix] ?? $suffix;

        return $rootNote . $displayQuality;
    }

    /**
     * Given a Roman numeral and the chord's concrete root note, back-solve the key.
     *
     * Inverse of numeralToChordName: keySemi = (rootSemi - numeralOffset + 12) % 12.
     * Returns 'C' on parse failure.
     */
    public function keyFromNumeralAndRoot(string $numeral, string $rootNote): string
    {
        if (!preg_match('/^(b|#)?([IViv]+)(.*)$/', $numeral, $m)) {
            return 'C';
        }

        $accidental = $m[1];
        $romanPart  = strtoupper($m[2]);

        $degree = self::ROMAN_TO_DEGREE[$romanPart] ?? null;
        if ($degree === null) {
            return 'C';
        }

        $semitoneOffset = self::MAJOR_SCALE[$degree];
        if ($accidental === 'b') $semitoneOffset--;
        if ($accidental === '#') $semitoneOffset++;

        $rootSemi = self::NOTE_TO_SEMI[$rootNote] ?? 0;
        $keySemi  = ($rootSemi - $semitoneOffset + 12) % 12;

        // Spell the key name itself on the flat side when appropriate.
        // We use the flat array as the default for key names (Bb not A#, Eb not D#).
        return self::SEMI_TO_NOTE_FLAT[$keySemi];
    }
}

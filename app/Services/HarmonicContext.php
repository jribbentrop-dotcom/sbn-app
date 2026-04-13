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
    public function buildFromChordSequence(string $key, array $chordNames): array
    {
        $chords = [];
        foreach ($chordNames as $i => $name) {
            $parsed  = $this->detector->parseChordName($name);
            $numeral = $this->detector->chordToNumeral($name, $key);

            $chords[] = [
                'measure_index'          => $i,
                'chord_index'            => 0,
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
                'next_index'             => $i < count($chordNames) - 1 ? $i + 1 : null,
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

    private const SEMI_TO_NOTE = ['C', 'Db', 'D', 'Eb', 'E', 'F', 'F#', 'G', 'Ab', 'A', 'Bb', 'B'];

    private const MAJOR_SCALE = [0, 2, 4, 5, 7, 9, 11];

    private const ROMAN_TO_DEGREE = [
        'I' => 0, 'II' => 1, 'III' => 2, 'IV' => 3, 'V' => 4, 'VI' => 5, 'VII' => 6,
    ];

    /**
     * Numeral suffix → display quality.
     */
    private const SUFFIX_TO_DISPLAY = [
        ''      => '',       // major triad
        'maj7'  => 'maj7',
        'maj9'  => 'maj9',
        '7'     => '7',
        '9'     => '9',
        '13'    => '13',
        'm'     => 'm',
        'm7'    => 'm7',
        'm6'    => 'm6',
        'm9'    => 'm9',
        'm7b5'  => 'm7b5',
        'o7'    => 'dim7',
        'o'     => 'dim',
        'mMaj7' => 'mMaj7',
        'aug'   => 'aug',
        'aug7'  => 'aug7',
        'sus4'  => 'sus4',
        'sus2'  => 'sus2',
    ];

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

        // Compute root note
        $rootSemi = ($keySemi + $semitoneOffset + 12) % 12;
        $rootNote = self::SEMI_TO_NOTE[$rootSemi];

        // Quality suffix → display quality
        $displayQuality = self::SUFFIX_TO_DISPLAY[$suffix] ?? $suffix;

        return $rootNote . $displayQuality;
    }
}

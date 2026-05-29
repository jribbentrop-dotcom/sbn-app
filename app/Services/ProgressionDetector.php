<?php

namespace App\Services;

use App\Models\ChordProgression;
use App\Models\Leadsheet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SBN Progression Detector
 *
 * Analyses leadsheets for chord progressions using Roman-numeral analysis.
 * Ported from WP class-chord-progressions.php (Phase 5d).
 *
 * Pipeline:
 *   1. Parse leadsheet into sections with chord names
 *   2. Convert each chord to a Roman numeral (chord_to_numeral)
 *   3. Resolve diminished 7ths functioning as dominant 7b9 (resolve_dominant_dim7s)
 *   4. Sliding-window match against stored progression patterns (detect_matches)
 *   5. Filter overlapping/contained matches
 *   6. Store results in sbn_progression_occurrences
 *
 * @package App\Services
 */
class ProgressionDetector
{
    // =========================================================================
    // CONSTANTS — CHROMATIC UTILITIES
    // =========================================================================

    /**
     * Chromatic note names (prefer flats — standard in jazz/bossa contexts).
     */
    private const NOTES = ['C', 'Db', 'D', 'Eb', 'E', 'F', 'Gb', 'G', 'Ab', 'A', 'Bb', 'B'];

    /**
     * Semitone values for all note spellings.
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

    /**
     * Major scale semitone offsets from root.
     */
    private const MAJOR_SCALE_SEMITONES = [0, 2, 4, 5, 7, 9, 11];

    /**
     * Roman numeral labels.
     */
    private const ROMAN = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII'];

    /**
     * Chromatic degree map for non-diatonic intervals.
     */
    private const CHROMATIC_MAP = [
        1  => 'bII',
        3  => 'bIII',
        6  => 'bV',
        8  => 'bVI',
        10 => 'bVII',
    ];

    /**
     * Harmonic family map for quality-relaxed matching.
     */
    private const FAMILY_MAP = [
        ''      => 'major',
        'maj7'  => 'major',
        'maj9'  => 'major',
        '6'     => 'major',
        '7'     => 'dom',
        '9'     => 'dom',
        '13'    => 'dom',
        'm'     => 'minor',
        'm7'    => 'minor',
        'm6'    => 'minor',
        'm9'    => 'minor',
        'mmaj7' => 'minor',
        'm7b5'  => 'halfdim',
        'o7'    => 'dim',
        'o'     => 'dim',
        'sus4'  => 'sus',
        'sus2'  => 'sus',
        'aug'   => 'aug',
        'aug7'  => 'aug',
    ];

    // =========================================================================
    // DEPENDENCIES
    // =========================================================================

    protected LeadsheetParser $parser;

    public function __construct(LeadsheetParser $parser)
    {
        $this->parser = $parser;
    }

    // =========================================================================
    // CHORD NAME PARSING (uses LeadsheetController logic)
    // =========================================================================

    /**
     * Quality patterns: input notation → DB canonical form.
     * Ported from LeadsheetController::QUALITY_PATTERNS.
     */
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
     * Parse a chord name into root, quality, extension, bass_note.
     * Faithful port of sbn_parse_chord_name().
     */
    public function parseChordName(string $input): ?array
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

        // 1. Parenthesised: "m7(9)", "m7(b9,#11)"
        if (preg_match('/^(.+?)\(([^)]+)\)$/', $qualityPart, $m)) {
            $qualityPart = $m[1];
            $extension   = $m[2];
        }
        // 2. Space-separated: "m7 9", "m7 b9"
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

        // Standard quality matching (case-sensitive first)
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

        // Progressive decomposition: "m79" → "m7" + "9"
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
    // ROMAN NUMERAL CONVERSION
    // =========================================================================

    /**
     * Normalize a quality string to its base harmonic function.
     *
     * Extended qualities (9, 11, 13, b9, #11 etc.) are reduced to their
     * base function: dom7, m7, or maj7. Progression detection cares about
     * harmonic function, not extensions.
     */
    private function normalizeQualityForDetection(string $quality): string
    {
        $q = strtolower($quality);

        // Already a known base quality — return as-is
        $known = [
            'maj7', 'maj', 'dom7', '7', 'm7', 'min7', '-7',
            'min', 'm', 'minor', '-', 'm7b5', 'o7', 'dim7', 'dim', 'mmaj7',
            'maj6', '6', 'm6', 'sus4', 'sus2', 'aug', 'aug7',
        ];
        if (in_array($q, $known)) {
            return $q;
        }

        // Extended/concatenated forms — check longer prefixes first
        if (preg_match('/^m7b5/', $q))    return 'm7b5';
        if (preg_match('/^mmaj7/', $q))   return 'mmaj7';
        if (preg_match('/^maj[79]/', $q)) return 'maj7';  // maj9, maj11, maj13 etc.
        if (preg_match('/^m[79]/', $q))   return 'm7';    // m9, m11, m13 etc.
        if (preg_match('/^-[79]/', $q))   return 'm7';    // jazz minus notation
        if (preg_match('/^\d/', $q))      return 'dom7';  // 9, 9b9, 13b9 etc.

        return $q;
    }

    /**
     * Map a normalized quality to a Roman numeral suffix.
     */
    private function qualityToSuffix(string $quality): string
    {
        $q = $this->normalizeQualityForDetection($quality);

        if (in_array($q, ['maj7', 'maj6', '6'])) return 'maj7';
        if ($q === 'maj')                         return '';
        if (in_array($q, ['dom7', '7']))          return '7';
        if (in_array($q, ['m7', 'min7', '-7']))   return 'm7';
        if (in_array($q, ['min', 'm', 'minor', '-'])) return 'm';
        if ($q === 'm7b5')                        return 'm7b5';
        if (in_array($q, ['o7', 'dim7']))         return 'o7';
        if ($q === 'dim')                         return 'o';
        if ($q === 'mmaj7')                       return 'mMaj7';
        if ($q === 'm6')                          return 'm6';
        if ($q === 'sus4' || $q === 'sus2')       return $q;
        if ($q === 'aug')                         return 'aug';
        if ($q === 'aug7')                        return 'aug7';

        return $quality; // fallback: pass through raw
    }

    /**
     * Convert a scale degree + quality to a Roman numeral token.
     *
     * @param int    $degree  0-indexed (0=I, 1=II ... 6=VII)
     * @param string $quality Parsed quality from parseChordName
     */
    private function degreeToNumeral(int $degree, string $quality): string
    {
        return self::ROMAN[$degree] . $this->qualityToSuffix($quality);
    }

    /**
     * Convert a chord name + key to a Roman numeral token.
     *
     * @param  string $chordName  e.g. "Dm7", "G7", "Bb7", "E7/A"
     * @param  string $key        e.g. "C", "F", "Bb"
     * @return string|null        e.g. "IIm7", "V7", "bVII7", "VII7/III"
     */
    public function chordToNumeral(string $chordName, string $key): ?string
    {
        $parsed = $this->parseChordName($chordName);
        if (!$parsed || empty($parsed['root'])) {
            return null;
        }

        $rootSemi = self::NOTE_TO_SEMI[$parsed['root']] ?? null;
        $keyNote  = preg_replace('/[mM].*$/', '', trim($key));
        $keySemi  = self::NOTE_TO_SEMI[$keyNote] ?? null;

        if ($rootSemi === null || $keySemi === null) {
            return null;
        }

        $interval = ($rootSemi - $keySemi + 12) % 12;

        // Exact diatonic degree
        $degreeIdx = array_search($interval, self::MAJOR_SCALE_SEMITONES, true);
        if ($degreeIdx !== false) {
            $numeral = $this->degreeToNumeral($degreeIdx, $parsed['quality']);
        } elseif (isset(self::CHROMATIC_MAP[$interval])) {
            // Chromatic alteration
            $romanBase = self::CHROMATIC_MAP[$interval];
            $suffix    = $this->qualityToSuffix($parsed['quality']);
            $numeral = $romanBase . $suffix;
        } else {
            // Unknown chromatic degree
            $numeral = 'chr' . $interval;
        }

        // Handle slash chords: translate bass note and append
        if (!empty($parsed['bass_note'])) {
            $bassSemi = self::NOTE_TO_SEMI[$parsed['bass_note']] ?? null;
            if ($bassSemi !== null) {
                $bassInterval = ($bassSemi - $keySemi + 12) % 12;
                $bassDegreeIdx = array_search($bassInterval, self::MAJOR_SCALE_SEMITONES, true);
                if ($bassDegreeIdx !== false) {
                    $bassNumeral = self::ROMAN[$bassDegreeIdx];
                    $numeral .= '/' . $bassNumeral;
                } elseif (isset(self::CHROMATIC_MAP[$bassInterval])) {
                    $numeral .= '/' . self::CHROMATIC_MAP[$bassInterval];
                } else {
                    // Unknown bass degree - just use the note name
                    $numeral .= '/' . $parsed['bass_note'];
                }
            }
        }

        return $numeral;
    }

    // =========================================================================
    // SECTION TO NUMERALS
    // =========================================================================

    /**
     * Convert a leadsheet section into an array of numeral tokens.
     * Includes dim7 → dom7b9 resolution post-processing.
     *
     * @param  array  $section Section from parser (with 'measures')
     * @param  string $key     Song key
     * @return array{numerals: string[], chordRoots: (int|null)[]}
     */
    public function sectionToNumerals(array $section, string $key): array
    {
        $numerals   = [];
        $chordRoots = [];

        foreach ($section['measures'] as $measure) {
            foreach ($measure['chords'] as $chord) {
                $n = $this->chordToNumeral($chord['name'], $key);
                $numerals[] = $n ?? '?';

                $parsed = $this->parseChordName($chord['name']);
                $chordRoots[] = ($parsed && !empty($parsed['root']))
                    ? (self::NOTE_TO_SEMI[$parsed['root']] ?? null)
                    : null;
            }
        }

        // Post-process: resolve dim7 chords functioning as dominants
        $numerals = $this->resolveDominantDim7s($numerals, $chordRoots, $key);

        return [
            'numerals'   => $numerals,
            'chordRoots' => $chordRoots,
        ];
    }

    /**
     * Resolve dim7 chords functioning as rootless dom7b9 chords.
     *
     * Theory: every dim7 chord is enharmonically the upper structure of four
     * possible dom7b9 chords. For dim7 root R, the candidate dominant roots are:
     *   (R-1)%12, (R+2)%12, (R+5)%12, (R+8)%12
     *
     * Resolution check: does the candidate resolve to the next chord by
     * ascending P4 (interval 5) or ascending semitone (interval 1)?
     */
    private function resolveDominantDim7s(array $numerals, array $chordRoots, string $key): array
    {
        $keyNote = preg_replace('/[mM].*$/', '', trim($key));
        $keySemi = self::NOTE_TO_SEMI[$keyNote] ?? null;
        if ($keySemi === null) {
            return $numerals;
        }

        $count = count($numerals);

        for ($i = 0; $i < $count - 1; $i++) {
            // Only process dim7/o7 tokens
            if (!preg_match('/o7$/i', $numerals[$i])) {
                continue;
            }

            // Skip repeated chord (not a resolution)
            if ($numerals[$i] === $numerals[$i + 1]) {
                continue;
            }

            $dimRoot  = $chordRoots[$i];
            $nextRoot = $chordRoots[$i + 1];
            if ($dimRoot === null || $nextRoot === null) {
                continue;
            }

            // Four candidate dominant roots
            $candidates = [
                ($dimRoot - 1 + 12) % 12,
                ($dimRoot + 2) % 12,
                ($dimRoot + 5) % 12,
                ($dimRoot + 8) % 12,
            ];

            $resolvedDomRoot = null;

            // Check ascending P4 resolution (interval 5)
            foreach ($candidates as $cand) {
                if (($nextRoot - $cand + 12) % 12 === 5) {
                    $resolvedDomRoot = $cand;
                    break;
                }
            }

            // Fallback: ascending semitone resolution (interval 1)
            if ($resolvedDomRoot === null) {
                foreach ($candidates as $cand) {
                    if (($nextRoot - $cand + 12) % 12 === 1) {
                        $resolvedDomRoot = $cand;
                        break;
                    }
                }
            }

            if ($resolvedDomRoot === null) {
                continue;
            }

            // Map to Roman numeral
            $domInterval = ($resolvedDomRoot - $keySemi + 12) % 12;
            $degreeIdx   = array_search($domInterval, self::MAJOR_SCALE_SEMITONES, true);

            $originalToken = $numerals[$i];
            $newToken      = null;

            if ($degreeIdx !== false) {
                $newToken = $this->degreeToNumeral($degreeIdx, 'dom7');
            } elseif (isset(self::CHROMATIC_MAP[$domInterval])) {
                $newToken = self::CHROMATIC_MAP[$domInterval] . '7';
            }

            if ($newToken !== null) {
                $numerals[$i] = $newToken;

                // Back-propagate to preceding identical dim7 tokens
                for ($j = $i - 1; $j >= 0; $j--) {
                    if ($numerals[$j] === $originalToken && $chordRoots[$j] === $dimRoot) {
                        $numerals[$j] = $newToken;
                    } else {
                        break;
                    }
                }
            }
        }

        return $numerals;
    }

    // =========================================================================
    // PATTERN MATCHING ENGINE
    // =========================================================================

    /**
     * Flatten progression rows into canonical + variant entries for matching.
     *
     * Each entry is an array with: id, numerals, tonality, match_mode,
     * variant_index (null = canonical), variant_label (null = canonical).
     */
    private function flattenProgressions(array $progressions): array
    {
        $flat = [];

        foreach ($progressions as $prog) {
            // Canonical entry
            $flat[] = [
                'id'            => (int) $prog->id,
                'numerals'      => $prog->numerals,
                'tonality'      => $prog->tonality ?? 'both',
                'match_mode'    => $prog->match_mode ?? 'strict',
                'variant_index' => null,
                'variant_label' => null,
            ];

            // Variant entries
            $altNumerals = $prog->alt_numerals ?? null;
            if ($altNumerals) {
                $variants = is_string($altNumerals)
                    ? json_decode($altNumerals, true)
                    : $altNumerals;

                if (is_array($variants)) {
                    foreach ($variants as $vi => $variant) {
                        $flat[] = [
                            'id'            => (int) $prog->id,
                            'numerals'      => $variant['numerals'] ?? '',
                            'tonality'      => $prog->tonality ?? 'both',
                            'match_mode'    => $prog->match_mode ?? 'strict',
                            'variant_index' => $vi,
                            'variant_label' => $variant['label'] ?? null,
                        ];
                    }
                }
            }
        }

        return $flat;
    }

    /**
     * Parse a stored numeral sequence string into tokens.
     * Normalizes dim7 suffix variants (dim7 → o7, °7 → o7).
     */
    private function parseNumeralSequence(string $numeralsStr): array
    {
        $tokens = array_values(array_filter(array_map('trim', explode(',', $numeralsStr))));

        return array_map(function (string $t): string {
            $t = str_replace('°7', 'o7', $t);
            $t = preg_replace('/dim7$/i', 'o7', $t);
            return $t;
        }, $tokens);
    }

    /**
     * Compare a section numeral token against a pattern token.
     * Returns confidence: 0 = no match, 1 = exact, 0.85 = quality-relaxed.
     *
     * @param string $sectionToken  e.g. "IIm7"
     * @param string $patternToken  e.g. "IIm7" or "IIm"
     * @param bool   $degreeOnly    Match on degree only, ignore quality
     * @param bool   $flexTonic     Allow major↔minor on I chord
     */
    private function tokenScore(
        string $sectionToken,
        string $patternToken,
        bool $degreeOnly = false,
        bool $flexTonic = false
    ): float {
        // Exact match
        if ($sectionToken === $patternToken) {
            return 1.0;
        }

        // Unknown degree
        if ($sectionToken === '?') {
            return 0.0;
        }

        // Extract Roman numeral base
        $romanRe = '/^(b?#?)([IVi]+)(.*)/';

        if (!preg_match($romanRe, $sectionToken, $sm) || !preg_match($romanRe, $patternToken, $pm)) {
            return 0.0;
        }

        // Accidental + numeral must match
        if (strtoupper($sm[1] . $sm[2]) !== strtoupper($pm[1] . $pm[2])) {
            return 0.0;
        }

        // Degree-only mode
        if ($degreeOnly) {
            return ($sectionToken === $patternToken) ? 1.0 : 0.8;
        }

        // Compare quality suffixes
        $sq = strtolower($sm[3]);
        $pq = strtolower($pm[3]);

        $sqFamily = $this->resolveFamily($sq);
        $pqFamily = $this->resolveFamily($pq);

        // Different families → no match (unless tonic flex)
        if ($sqFamily !== $pqFamily) {
            if ($flexTonic) {
                $tonicFamilies = ['major', 'minor', 'dom'];
                if (in_array($sqFamily, $tonicFamilies, true) && in_array($pqFamily, $tonicFamilies, true)) {
                    return 0.8;
                }
            }
            return 0.0;
        }

        // Same family, different suffix → quality-relaxed match
        if ($sq !== $pq) {
            return 0.85;
        }

        return 1.0;
    }

    /**
     * Resolve a quality suffix to its harmonic family.
     */
    private function resolveFamily(string $suffix): string
    {
        if (isset(self::FAMILY_MAP[$suffix])) {
            return self::FAMILY_MAP[$suffix];
        }

        // Prefix-based fallback (order matters: longer prefixes first)
        if (str_starts_with($suffix, 'mmaj'))  return 'minor';
        if (str_starts_with($suffix, 'maj'))   return 'major';
        if (str_starts_with($suffix, 'm7b5'))  return 'halfdim';
        if (str_starts_with($suffix, 'm'))     return 'minor';
        if (str_starts_with($suffix, 'o'))     return 'dim';
        if (preg_match('/^\d/', $suffix))       return 'dom';

        return 'other';
    }

    /**
     * Sliding-window match of stored progressions against a section's numerals.
     *
     * @param  array  $sectionNumerals Flat array of numeral tokens
     * @param  array  $progressions    Rows from sbn_chord_progressions
     * @param  float  $minConfidence   Minimum confidence to store (0–1)
     * @return array  Array of match arrays
     */
    private function detectMatches(array $sectionNumerals, array $progressions, float $minConfidence = 0.75): array
    {
        $matches = [];

        // ── Deduplication ────────────────────────────────────────
        // Collapse runs of the same numeral (e.g. IIm7, IIm7, V7 → IIm7, V7)
        $deduped    = [];
        $slotMap    = [];  // dedup idx → first original slot
        $slotEndMap = []; // dedup idx → last original slot

        foreach ($sectionNumerals as $origIdx => $numeral) {
            $last = count($deduped) - 1;
            if ($last >= 0 && $deduped[$last] === $numeral) {
                $slotEndMap[$last] = $origIdx;
            } else {
                $deduped[]    = $numeral;
                $slotMap[]    = $origIdx;
                $slotEndMap[] = $origIdx;
            }
        }

        $dedupTotal = count($deduped);

        foreach ($progressions as $prog) {
            $pattern = $this->parseNumeralSequence($prog['numerals']);

            $degreeOnly = (($prog['match_mode'] ?? 'strict') === 'degree');
            $flexTonic  = (($prog['tonality'] ?? 'both') === 'both');

            // Deduplicate pattern too
            $patternDeduped = [];
            foreach ($pattern as $tok) {
                $lastP = count($patternDeduped) - 1;
                if ($lastP < 0 || $patternDeduped[$lastP] !== $tok) {
                    $patternDeduped[] = $tok;
                }
            }
            $plen = count($patternDeduped);

            if ($plen < 2 || $plen > $dedupTotal) {
                continue;
            }

            // Pre-compute tonic flags
            $isTonic = [];
            $tonicRe = '/^I(?:[^IViv]|$)/';
            for ($t = 0; $t < $plen; $t++) {
                $isTonic[$t] = $flexTonic && (bool) preg_match($tonicRe, $patternDeduped[$t]);
            }

            // Slide the window
            for ($start = 0; $start <= $dedupTotal - $plen; $start++) {
                $scoreSum = 0.0;
                $valid    = true;

                for ($j = 0; $j < $plen; $j++) {
                    $ts = $this->tokenScore(
                        $deduped[$start + $j],
                        $patternDeduped[$j],
                        $degreeOnly,
                        $isTonic[$j]
                    );
                    if ($ts <= 0) {
                        $valid = false;
                        break;
                    }
                    $scoreSum += $ts;
                }

                if (!$valid) {
                    continue;
                }

                $confidence = $scoreSum / $plen;

                if ($confidence >= $minConfidence) {
                    $origStart  = $slotMap[$start];
                    $origEnd    = $slotEndMap[$start + $plen - 1];
                    $origLength = $origEnd - $origStart + 1;

                    $matches[] = [
                        'progression_id' => (int) $prog['id'],
                        'variant_index'  => $prog['variant_index'] ?? null,
                        'variant_label'  => $prog['variant_label'] ?? null,
                        'start_idx'      => $origStart,
                        'length'         => $origLength,
                        'confidence'     => round($confidence, 3),
                        'pattern_tokens' => $patternDeduped,
                    ];
                }
            }
        }

        // ── Containment filter ───────────────────────────────────
        // Sort: longest first, highest confidence first
        usort($matches, function ($a, $b) {
            if ($b['length'] !== $a['length']) return $b['length'] - $a['length'];
            return $b['confidence'] <=> $a['confidence'];
        });

        // Pass 1: full containment
        $accepted = [];
        foreach ($matches as $m) {
            $mStart = $m['start_idx'];
            $mEnd   = $mStart + $m['length'] - 1;

            $contained = false;
            foreach ($accepted as $a) {
                $aStart = $a['start_idx'];
                $aEnd   = $aStart + $a['length'] - 1;
                if ($mStart >= $aStart && $mEnd <= $aEnd) {
                    $contained = true;
                    break;
                }
            }

            if (!$contained) {
                $accepted[] = $m;
            }
        }

        // Pass 2: cyclic subsequence suppression
        $final = [];
        foreach ($accepted as $m) {
            $mStart   = $m['start_idx'];
            $mEnd     = $mStart + $m['length'] - 1;
            $mTokens  = $m['pattern_tokens'];
            $mTlen    = count($mTokens);
            $suppressed = false;

            foreach ($accepted as $a) {
                if ($a === $m) continue;

                $aTokens = $a['pattern_tokens'];
                $aTlen   = count($aTokens);

                if ($aTlen <= $mTlen) continue;

                // Must have overlapping slot ranges
                $aStart = $a['start_idx'];
                $aEnd   = $aStart + $a['length'] - 1;
                $overlap = min($mEnd, $aEnd) - max($mStart, $aStart) + 1;
                if ($overlap <= 0) continue;

                // Check if B's pattern is a contiguous subsequence of A's cyclic repetition
                $cyclic = array_merge($aTokens, $aTokens);
                $found  = false;
                for ($k = 0; $k <= $aTlen; $k++) {
                    $match = true;
                    for ($t = 0; $t < $mTlen; $t++) {
                        if ($this->tokenScore($cyclic[$k + $t], $mTokens[$t]) <= 0) {
                            $match = false;
                            break;
                        }
                    }
                    if ($match) {
                        $found = true;
                        break;
                    }
                }

                if ($found) {
                    $suppressed = true;
                    break;
                }
            }

            if (!$suppressed) {
                $final[] = $m;
            }
        }

        return $final;
    }

    // =========================================================================
    // SLOT ↔ MEASURE MAPPING
    // =========================================================================

    /**
     * Convert a chord-slot index back to a measure index within a section.
     */
    private function slotToMeasure(array $section, int $slotIdx): int
    {
        $cursor = 0;
        foreach ($section['measures'] as $mIdx => $measure) {
            $count = count($measure['chords']);
            if ($slotIdx < $cursor + $count) {
                return $mIdx;
            }
            $cursor += $count;
        }
        return max(0, count($section['measures']) - 1);
    }

    // =========================================================================
    // MAIN ENTRY POINT — PROCESS A LEADSHEET
    // =========================================================================

    /**
     * Analyse a leadsheet for progression occurrences and store results.
     *
     * @param  Leadsheet $leadsheet  Eloquent model (must have shortcode_content)
     * @return array  Summary: ['occurrences' => int, 'sections_analysed' => int]
     */
    public function processLeadsheet(Leadsheet $leadsheet): array
    {
        $song = $this->parser->parse($leadsheet->shortcode_content ?? '');
        $key  = $song['key'] ?? 'C';

        $songIsMinor = (bool) preg_match('/m$/i', trim($key));

        // Clear previous results
        DB::table('sbn_progression_occurrences')
            ->where('leadsheet_id', $leadsheet->id)
            ->delete();

        if (empty($song['sections'])) {
            return ['occurrences' => 0, 'sections_analysed' => 0];
        }

        // Load all progressions (with variant data)
        $progressions = DB::table('sbn_chord_progressions')
            ->select('id', 'numerals', 'alt_numerals', 'tonality', 'match_mode')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->all();

        if (empty($progressions)) {
            return ['occurrences' => 0, 'sections_analysed' => count($song['sections'])];
        }

        // Flatten into canonical + variant entries for matching
        $flatProgs = $this->flattenProgressions($progressions);

        // Build per-section key map (section tonality overrides song key)
        $sectionKeys = [];
        foreach ($song['sections'] as $si => $section) {
            $sectionKeys[$si] = !empty($section['tonality']) ? $section['tonality'] : $key;
        }

        $sectionCount   = count($song['sections']);
        $totalInserted  = 0;

        DB::transaction(function () use (
            $song, $leadsheet, $progressions, $flatProgs, $sectionKeys, $sectionCount, &$totalInserted
        ) {
            foreach ($song['sections'] as $si => $section) {
                $secKey     = $sectionKeys[$si];
                $secIsMinor = (bool) preg_match('/m$/i', trim($secKey));

                // Filter progressions by tonality
                $filteredProgs = array_filter($progressions, function ($p) use ($secIsMinor) {
                    $ton = $p->tonality ?? 'both';
                    if ($ton === 'both') return true;
                    if ($ton === 'minor' && $secIsMinor) return true;
                    if ($ton === 'major' && !$secIsMinor) return true;
                    return false;
                });

                if (empty($filteredProgs)) {
                    continue;
                }

                $result   = $this->sectionToNumerals($section, $secKey);
                $numerals = $result['numerals'];

                // ── Cross-section cadence detection ──────────────────
                $prependLen = 0;
                $appendLen  = 0;

                // Prepend: up to 4 chords from end of previous section
                // (re-analysed in current section's key)
                if ($si > 0 && $sectionKeys[$si - 1] !== $secKey) {
                    $prevSection  = $song['sections'][$si - 1];
                    $prevMeasures = $prevSection['measures'];
                    $tailChords   = [];
                    $tailCount    = 0;

                    for ($mi = count($prevMeasures) - 1; $mi >= 0 && $tailCount < 4; $mi--) {
                        foreach (array_reverse($prevMeasures[$mi]['chords']) as $chord) {
                            if ($tailCount >= 4) break;
                            array_unshift($tailChords, $chord['name']);
                            $tailCount++;
                        }
                    }

                    if ($tailCount > 0) {
                        $tailNumerals = [];
                        foreach ($tailChords as $cn) {
                            $n = $this->chordToNumeral($cn, $secKey);
                            $tailNumerals[] = $n ?? '?';
                        }
                        $numerals   = array_merge($tailNumerals, $numerals);
                        $prependLen = $tailCount;
                    }
                }

                // Append: up to 4 chords from start of next section
                if ($si < $sectionCount - 1 && $sectionKeys[$si + 1] !== $secKey) {
                    $nextSection  = $song['sections'][$si + 1];
                    $nextMeasures = $nextSection['measures'];
                    $headChords   = [];
                    $headCount    = 0;

                    foreach ($nextMeasures as $measure) {
                        foreach ($measure['chords'] as $chord) {
                            if ($headCount >= 4) break 2;
                            $headChords[] = $chord['name'];
                            $headCount++;
                        }
                    }

                    if ($headCount > 0) {
                        $headNumerals = [];
                        foreach ($headChords as $cn) {
                            $n = $this->chordToNumeral($cn, $secKey);
                            $headNumerals[] = $n ?? '?';
                        }
                        $numerals  = array_merge($numerals, $headNumerals);
                        $appendLen = $headCount;
                    }
                }

                if (count($numerals) < 2) {
                    continue;
                }

                // Filter flat progressions by tonality
                $filteredFlat = array_filter($flatProgs, function ($p) use ($secIsMinor) {
                    $ton = $p['tonality'] ?? 'both';
                    if ($ton === 'both') return true;
                    if ($ton === 'minor' && $secIsMinor) return true;
                    if ($ton === 'major' && !$secIsMinor) return true;
                    return false;
                });

                $matches = $this->detectMatches($numerals, array_values($filteredFlat));

                foreach ($matches as $m) {
                    // Adjust for prepended cross-section chords
                    $adjStart = $m['start_idx'] - $prependLen;
                    $adjEnd   = $adjStart + $m['length'] - 1;

                    // Skip matches entirely outside this section
                    $ownLen = count($numerals) - $prependLen - $appendLen;
                    if ($adjEnd < 0 || $adjStart >= $ownLen) {
                        continue;
                    }

                    // Skip matches that start in the prepend zone.
                    // These are progressions whose beginning is in the previous
                    // section — they'll be detected from that section's append
                    // window instead. Only matches that start within this
                    // section's own chords are stored here.
                    if ($adjStart < 0) {
                        continue;
                    }

                    // Clamp to this section's bounds.
                    // For cross-section matches, storeStart is clamped to 0
                    // and storeEnd is clamped to exclude appended chords.
                    // This ensures only the portion within this section is stored.
                    $storeStart = max(0, $adjStart);
                    $storeEnd   = min($adjEnd, $ownLen - 1);

                    // Detect the tonic root of the matched progression
                    $detectedRoot = $this->detectMatchRoot(
                        $m, $section, $secKey, $storeStart, $progressions
                    );

                    $startMeasure   = $this->slotToMeasure($section, $storeStart);
                    $endMeasure     = $this->slotToMeasure($section, $storeEnd);
                    $lengthMeasures = max(1, $endMeasure - $startMeasure + 1);

                    DB::table('sbn_progression_occurrences')->insert([
                        'progression_id'  => $m['progression_id'],
                        'variant_index'   => $m['variant_index'] ?? null,
                        'variant_label'   => $m['variant_label'] ?? null,
                        'leadsheet_id'    => $leadsheet->id,
                        'section_id'      => $section['id'],
                        'start_measure'   => $startMeasure,
                        'length_measures' => $lengthMeasures,
                        'detected_root'   => $detectedRoot,
                        'confidence'      => $m['confidence'],
                    ]);

                    $totalInserted++;
                }
            }
        });

        return [
            'occurrences'       => $totalInserted,
            'sections_analysed' => $sectionCount,
        ];
    }

    /**
     * Detect the tonic root of a matched progression.
     *
     * Finds the I chord in the pattern, then walks the original chord array
     * to find the corresponding chord name and extract its root.
     */
    private function detectMatchRoot(
        array $match,
        array $section,
        string $secKey,
        int $storeStart,
        array $progressions
    ): string {
        // Find the progression row
        $progRow = null;
        foreach ($progressions as $p) {
            if ((int) $p->id === $match['progression_id']) {
                $progRow = $p;
                break;
            }
        }

        if (!$progRow) {
            return $secKey;
        }

        $patternTokens = $this->parseNumeralSequence($progRow->numerals);

        // Find the first tonic (I) token in the pattern
        $iTokenIdx = null;
        foreach ($patternTokens as $ptIdx => $ptTok) {
            if (preg_match('/^I(?!I|V)[^IVi]/i', $ptTok) || $ptTok === 'I' || $ptTok === 'Imaj7' || $ptTok === 'Im7' || $ptTok === 'Im') {
                $iTokenIdx = $ptIdx;
                break;
            }
        }

        if ($iTokenIdx === null) {
            return $secKey;
        }

        // Flatten section chords
        $flatChords = [];
        foreach ($section['measures'] as $measure) {
            foreach ($measure['chords'] as $chord) {
                $flatChords[] = $chord['name'];
            }
        }

        // Walk from storeStart, counting distinct chord changes to find I chord slot
        $sectionSlot = $storeStart;
        $steps       = 0;
        $prevNumeral = isset($flatChords[$sectionSlot])
            ? $this->chordToNumeral($flatChords[$sectionSlot], $secKey)
            : null;

        for ($walk = $storeStart + 1; $walk < count($flatChords) && $steps < $iTokenIdx; $walk++) {
            $curNumeral = $this->chordToNumeral($flatChords[$walk], $secKey);
            if ($curNumeral !== $prevNumeral) {
                $steps++;
                $sectionSlot = $walk;
                $prevNumeral = $curNumeral;
            }
        }

        if (isset($flatChords[$sectionSlot])) {
            $parsedRoot = $this->parseChordName($flatChords[$sectionSlot]);
            if ($parsedRoot && !empty($parsedRoot['root'])) {
                return $parsedRoot['root'];
            }
        }

        return $secKey;
    }

    // =========================================================================
    // BATCH PROCESSING
    // =========================================================================

    /**
     * Process ALL leadsheets (batch reprocessing).
     *
     * @return array Summary
     */
    public function processAllLeadsheets(): array
    {
        $sheets = Leadsheet::whereNotNull('shortcode_content')
            ->where('shortcode_content', '!=', '')
            ->get();

        $processed   = 0;
        $totalOcc    = 0;

        foreach ($sheets as $sheet) {
            $result     = $this->processLeadsheet($sheet);
            $totalOcc  += $result['occurrences'];
            $processed++;
        }

        return [
            'processed'        => $processed,
            'total_occurrences' => $totalOcc,
        ];
    }

    // =========================================================================
    // ANALYSIS HELPERS (for editor UI / API)
    // =========================================================================

    /**
     * Analyse a leadsheet and return the full analysis data (without storing).
     * Used by the editor UI to show detected progressions in real-time.
     *
     * @return array Sections with numerals and detected progressions
     */
    public function analyseLeadsheet(Leadsheet $leadsheet): array
    {
        $song = $this->parser->parse($leadsheet->shortcode_content ?? '');
        $key  = $song['key'] ?? 'C';

        $progressions = DB::table('sbn_chord_progressions')
            ->select('id', 'name', 'category', 'numerals', 'alt_numerals', 'tonality', 'match_mode')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->all();

        $flatProgs = $this->flattenProgressions($progressions);

        // Build per-section key map
        $sectionKeys = [];
        foreach ($song['sections'] as $si => $section) {
            $sectionKeys[$si] = !empty($section['tonality']) ? $section['tonality'] : $key;
        }

        $sectionCount = count($song['sections']);
        $analysis     = [];
        $crossSectionHighlights = [];

        foreach ($song['sections'] as $si => $section) {
            $secKey     = $sectionKeys[$si];
            $secIsMinor = (bool) preg_match('/m$/i', trim($secKey));

            $filteredFlat = array_filter($flatProgs, function ($p) use ($secIsMinor) {
                $ton = $p['tonality'] ?? 'both';
                if ($ton === 'both') return true;
                if ($ton === 'minor' && $secIsMinor) return true;
                if ($ton === 'major' && !$secIsMinor) return true;
                return false;
            });

            $result   = $this->sectionToNumerals($section, $secKey);
            $numerals = $result['numerals'];

            // Build per-measure numeral mapping for the UI
            $measureNumerals = [];
            $slotIdx = 0;
            foreach ($section['measures'] as $mIdx => $measure) {
                $measureNumerals[$mIdx] = [];
                foreach ($measure['chords'] as $chord) {
                    $measureNumerals[$mIdx][] = [
                        'chord'   => $chord['name'],
                        'numeral' => $numerals[$slotIdx] ?? '?',
                        'beats'   => $chord['beats'] ?? null,
                    ];
                    $slotIdx++;
                }
            }

            // Cross-section prepend/append (same logic as processLeadsheet)
            $detectNumerals = $numerals;
            $prependLen     = 0;
            $appendLen      = 0;

            if ($si > 0 && $sectionKeys[$si - 1] !== $secKey) {
                $prevSection  = $song['sections'][$si - 1];
                $tailChords   = [];
                $tailCount    = 0;
                for ($mi = count($prevSection['measures']) - 1; $mi >= 0 && $tailCount < 4; $mi--) {
                    foreach (array_reverse($prevSection['measures'][$mi]['chords']) as $chord) {
                        if ($tailCount >= 4) break;
                        array_unshift($tailChords, $chord['name']);
                        $tailCount++;
                    }
                }
                if ($tailCount > 0) {
                    $tailNumerals = [];
                    foreach ($tailChords as $cn) {
                        $n = $this->chordToNumeral($cn, $secKey);
                        $tailNumerals[] = $n ?? '?';
                    }
                    $detectNumerals = array_merge($tailNumerals, $detectNumerals);
                    $prependLen     = $tailCount;
                }
            }

            if ($si < $sectionCount - 1 && $sectionKeys[$si + 1] !== $secKey) {
                $nextSection  = $song['sections'][$si + 1];
                $headChords   = [];
                $headCount    = 0;
                foreach ($nextSection['measures'] as $measure) {
                    foreach ($measure['chords'] as $chord) {
                        if ($headCount >= 4) break 2;
                        $headChords[] = $chord['name'];
                        $headCount++;
                    }
                }
                if ($headCount > 0) {
                    $headNumerals = [];
                    foreach ($headChords as $cn) {
                        $n = $this->chordToNumeral($cn, $secKey);
                        $headNumerals[] = $n ?? '?';
                    }
                    $detectNumerals = array_merge($detectNumerals, $headNumerals);
                    $appendLen      = $headCount;
                }
            }

            // Detect matches
            $sectionMatches = [];
            if (count($detectNumerals) >= 2 && !empty($filteredFlat)) {
                $rawMatches = $this->detectMatches($detectNumerals, array_values($filteredFlat));

                foreach ($rawMatches as $m) {
                    $adjStart = $m['start_idx'] - $prependLen;
                    $adjEnd   = $adjStart + $m['length'] - 1;
                    $ownLen   = count($detectNumerals) - $prependLen - $appendLen;

                    if ($adjEnd < 0 || $adjStart >= $ownLen) {
                        continue;
                    }

                    // Skip matches that start in the prepend zone
                    if ($adjStart < 0) {
                        continue;
                    }

                    // Clamp to this section's bounds
                    $storeStart = max(0, $adjStart);
                    $storeEnd   = min($adjEnd, $ownLen - 1);

                    $startMeasure  = $this->slotToMeasure($section, $storeStart);
                    $endMeasure    = $this->slotToMeasure($section, $storeEnd);

                    // Find progression name + category
                    $progInfo = null;
                    foreach ($progressions as $p) {
                        if ((int) $p->id === $m['progression_id']) {
                            $progInfo = $p;
                            break;
                        }
                    }

                    $detectedRoot = $this->detectMatchRoot($m, $section, $secKey, $storeStart, $progressions);

                    $sectionMatches[] = [
                        'progression_id'  => $m['progression_id'],
                        'name'            => $progInfo->name ?? 'Unknown',
                        'category'        => $progInfo->category ?? 'other',
                        'numerals'        => $progInfo->numerals ?? '',
                        'start_measure'   => $startMeasure,
                        'end_measure'     => $endMeasure,
                        'length_measures' => max(1, $endMeasure - $startMeasure + 1),
                        'detected_root'   => $detectedRoot,
                        'confidence'      => $m['confidence'],
                    ];

                    // Track cross-section overflow: if this match extends
                    // into the next section's append zone, record a resolution
                    // highlight on THIS section (where the pill lives), pointing
                    // to the target section where the resolution bars are.
                    if ($adjEnd >= $ownLen && $appendLen > 0 && $si < $sectionCount - 1) {
                        $overflowSlots = $adjEnd - $ownLen + 1;
                        $nextSi = $si + 1;
                        $nextSection = $song['sections'][$nextSi];
                        $resolveEndSlot = min($overflowSlots - 1, count($nextSection['measures']) - 1);
                        $crossSectionHighlights[] = [
                            'source_section_idx'  => $si,
                            'target_section_id'   => $nextSection['id'],
                            'from_progression'    => $progInfo->name ?? 'Unknown',
                            'start_measure'       => 0,
                            'end_measure'         => $this->slotToMeasure($nextSection, max(0, $resolveEndSlot)),
                        ];
                    }
                }
            }

            $analysis[] = [
                'section_id'       => $section['id'],
                'section_name'     => $section['name'] ?? $section['id'],
                'key'              => $secKey,
                'measure_numerals' => $measureNumerals,
                'matches'          => $sectionMatches,
                'resolutions'      => [], // populated in post-pass below
            ];
        }

        // Post-pass: inject cross-section resolution highlights onto source sections
        foreach ($crossSectionHighlights as $highlight) {
            $idx = $highlight['source_section_idx'];
            if (isset($analysis[$idx])) {
                $analysis[$idx]['resolutions'][] = [
                    'target_section_id' => $highlight['target_section_id'],
                    'start_measure'     => $highlight['start_measure'],
                    'end_measure'       => $highlight['end_measure'],
                    'from_progression'  => $highlight['from_progression'],
                ];
            }
        }

        return [
            'song_key'  => $key,
            'sections'  => $analysis,
        ];
    }

    // =========================================================================
    // QUALITY MATCHING (for chord detail page integration)
    // =========================================================================

    /**
     * Match a chord quality against a single Roman numeral token.
     * Used by the chord detail page to find relevant progressions.
     *
     * Match tiers:
     *   1.00 — exact quality match
     *   0.90 — same harmonic family
     *   0.75 — tonic flex across major/minor (when $flexTonic=true)
     *   0.00 — no match
     *
     * @param string $quality       e.g. 'maj7', 'm7', '7'
     * @param string $numeralToken  e.g. 'Imaj7', 'IIm7', 'V7'
     * @param bool   $flexTonic     Allow major↔minor on I-chord slots
     */
    public function qualityMatchesNumeralToken(string $quality, string $numeralToken, bool $flexTonic = false): float
    {
        $q = strtolower(trim($quality));

        // Extract suffix from numeral token
        if (!preg_match('/^(b?#?[IVi]+)(.*)$/', $numeralToken, $m)) {
            return 0.0;
        }
        $tokenSuffix = strtolower($m[2]);

        // Normalize quality to canonical base
        $qNorm = $this->normalizeQualityForDetection($q);

        // Further aliases
        if (in_array($qNorm, ['min7', '-7'], true))               $qNorm = 'm7';
        if (in_array($qNorm, ['min', 'minor', '-'], true))        $qNorm = 'm';
        if ($qNorm === 'dom7')                                      $qNorm = '7';
        if ($qNorm === 'dim7')                                      $qNorm = 'o7';
        if (in_array($qNorm, ['maj6', '6'], true))                $qNorm = 'maj7';

        // Exact suffix match
        if ($qNorm === $tokenSuffix) {
            return 1.0;
        }

        $qFamily     = $this->resolveFamily($qNorm);
        $tokenFamily = $this->resolveFamily($tokenSuffix);

        // Same family
        if ($qFamily === $tokenFamily) {
            return 0.90;
        }

        // Tonic flex
        if ($flexTonic) {
            $tonicFamilies = ['major', 'minor', 'dom'];
            if (in_array($qFamily, $tonicFamilies, true) && in_array($tokenFamily, $tonicFamilies, true)) {
                return 0.75;
            }
        }

        return 0.0;
    }

    /**
     * Find the best progression to showcase a given chord quality.
     * Ported from WP get_progression_for_quality().
     *
     * @param string      $quality     e.g. 'maj7', 'm7', '7'
     * @param string|null $diagramRoot Note name if a specific diagram is selected
     * @return array|null
     */
    public function getProgressionForQuality(string $quality, ?string $diagramRoot = null): ?array
    {
        $rows = DB::table('sbn_chord_progressions as p')
            ->select('p.*')
            ->selectRaw('COUNT(DISTINCT o.leadsheet_id) AS song_count')
            ->leftJoin('sbn_progression_occurrences as o', 'o.progression_id', '=', 'p.id')
            ->groupBy('p.id')
            ->orderByDesc('p.featured')
            ->orderByDesc('song_count')
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $best          = null;
        $bestScore     = -1.0;
        $bestSlot      = 0;
        $bestSongCount = 0;

        foreach ($rows as $prog) {
            $tokens = $this->parseNumeralSequence($prog->numerals);
            if (empty($tokens)) continue;

            $flexTonic = (isset($prog->tonality) && $prog->tonality === 'both');

            $slotBestScore = 0.0;
            $slotBestIdx   = 0;

            foreach ($tokens as $idx => $token) {
                $isTonic = (bool) preg_match('/^(b?#?I)([^IViv]|$)/', $token);
                $score   = $this->qualityMatchesNumeralToken(
                    $quality,
                    $token,
                    $isTonic && $flexTonic
                );

                if ($score > $slotBestScore) {
                    $slotBestScore = $score;
                    $slotBestIdx   = $idx;
                }
            }

            if ($slotBestScore <= 0.0) continue;

            // Ranking: featured > match score > song count
            $rank = ($prog->featured ? 10.0 : 0.0) + $slotBestScore + ($prog->song_count / 1000.0);

            if ($rank > $bestScore) {
                $bestScore     = $rank;
                $best          = $prog;
                $bestSlot      = $slotBestIdx;
                $bestSongCount = $prog->song_count;
            }
        }

        if (!$best) {
            return null;
        }

        return [
            'progression' => $best,
            'best_slot'   => $bestSlot,
            'match_score' => $bestScore,
            'song_count'  => $bestSongCount,
            'default_key' => $diagramRoot ?? 'C',
        ];
    }
}

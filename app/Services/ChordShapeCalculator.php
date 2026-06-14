<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * SBN Chord Shape Calculator
 *
 * Computes fret positions from chord shape formulas and root notes.
 * This allows storing one shape (e.g., "maj7-drop2-roota") and generating
 * all 12 root variations (Cmaj7, Dbmaj7, etc.) dynamically.
 *
 * Ported from WordPress class-chord-shape-calculator.php (Phase 5c).
 *
 * INTERNAL STRING NUMBERING (inverted from guitar convention):
 * String 1 = Low E (guitar 6th / thickest / leftmost in diagram)
 * String 2 = A (guitar 5th)
 * String 3 = D (guitar 4th)
 * String 4 = G (guitar 3rd)
 * String 5 = B (guitar 2nd)
 * String 6 = High E (guitar 1st / thinnest / rightmost in diagram)
 *
 * WARNING: This numbering is used in all stored diagram_data JSON.
 * Do NOT renumber without a full database migration.
 */
class ChordShapeCalculator
{
    // =========================================================================
    // CONSTANTS / LOOKUP TABLES
    // =========================================================================

    /**
     * Standard tuning: internal string number → semitone relative to C.
     */
    private const TUNING = [
        1 => 4,  // Low E
        2 => 9,  // A
        3 => 2,  // D
        4 => 7,  // G
        5 => 11, // B
        6 => 4,  // High E
    ];

    /**
     * Public alias for note→semitone lookup (used by controllers for interval math).
     */
    public const NOTE_SEMITONES = [
        'C' => 0, 'C#' => 1, 'Db' => 1,
        'D' => 2, 'D#' => 3, 'Eb' => 3,
        'E' => 4,
        'F' => 5, 'F#' => 6, 'Gb' => 6,
        'G' => 7, 'G#' => 8, 'Ab' => 8,
        'A' => 9, 'A#' => 10, 'Bb' => 10,
        'B' => 11,
    ];

    private const NOTE_TO_SEMITONE = [
        'C' => 0, 'C#' => 1, 'Db' => 1,
        'D' => 2, 'D#' => 3, 'Eb' => 3,
        'E' => 4,
        'F' => 5, 'F#' => 6, 'Gb' => 6,
        'G' => 7, 'G#' => 8, 'Ab' => 8,
        'A' => 9, 'A#' => 10, 'Bb' => 10,
        'B' => 11,
    ];

    private const SEMITONE_TO_NOTE_SHARP = [
        0 => 'C', 1 => 'C#', 2 => 'D', 3 => 'D#', 4 => 'E', 5 => 'F',
        6 => 'F#', 7 => 'G', 8 => 'G#', 9 => 'A', 10 => 'A#', 11 => 'B',
    ];

    private const SEMITONE_TO_NOTE_FLAT = [
        0 => 'C', 1 => 'Db', 2 => 'D', 3 => 'Eb', 4 => 'E', 5 => 'F',
        6 => 'Gb', 7 => 'G', 8 => 'Ab', 9 => 'A', 10 => 'Bb', 11 => 'B',
    ];

    // Roots whose natural spelling uses flats (matches HarmonicContext::FLAT_KEYS root set).
    private const FLAT_ROOT_NOTES = ['F', 'Bb', 'Eb', 'Ab', 'Db', 'Gb'];

    // Semitones (black keys) that unambiguously belong to the flat side.
    // pc 6 (F#/Gb) is excluded — it's the tritone and belongs to both sides;
    // the root's own accidental determines which spelling to use there.
    private const FLAT_SIDE_PCS = [1, 3, 8, 10]; // Db Eb Ab Bb

    // Only the minor/diminished intervals of each quality — the ones that can
    // land on a flat-side pc and spell as flats (b3=3, b5=6, b7=10).
    // Major/perfect intervals (4, 7, 11) landing on black keys always spell sharp
    // (e.g. B7's major 3rd lands on pc 3 = D#, never Eb).
    private const QUALITY_FLAT_INTERVALS = [
        'min'   => [3],        // b3
        'm7'    => [3, 10],    // b3, b7
        'm6'    => [3],        // b3 (6th is major, natural)
        'm7b5'  => [3, 6, 10], // b3, b5, b7
        'mMaj7' => [3],        // b3 (maj7 is sharp)
        'dom7'  => [10],       // b7 only (major 3rd always sharp)
        'aug7'  => [10],       // b7 only (major 3rd, aug 5th both sharp)
    ];

    private const ROOT_STRING_MAP = [
        'roote'     => 1,
        'roota'     => 2,
        'rootd'     => 3,
        'rootg'     => 4,
        'rootb'     => 5,
        'roothighe' => 6,
    ];

    /**
     * Inversion → bass interval index within the ordered chord tones.
     */
    private const INVERSION_BASS_INDEX = [
        'root' => 0,
        'inv1' => 1,
        'inv2' => 2,
        'inv3' => 3,
    ];

    /**
     * Interval formulas: quality → inversion → semitones from root.
     */
    private const INVERSION_INTERVALS = [
        'maj7'  => ['root' => 0, 'inv1' => 4,  'inv2' => 7,  'inv3' => 11],
        'maj6'  => ['root' => 0, 'inv1' => 4,  'inv2' => 7,  'inv3' => 9],
        'm7'    => ['root' => 0, 'inv1' => 3,  'inv2' => 7,  'inv3' => 10],
        'm6'    => ['root' => 0, 'inv1' => 3,  'inv2' => 7,  'inv3' => 9],
        '7'     => ['root' => 0, 'inv1' => 4,  'inv2' => 7,  'inv3' => 10],
        'dom7'  => ['root' => 0, 'inv1' => 4,  'inv2' => 7,  'inv3' => 10],
        'm7b5'  => ['root' => 0, 'inv1' => 3,  'inv2' => 6,  'inv3' => 10],
        'o7'    => ['root' => 0, 'inv1' => 3,  'inv2' => 6,  'inv3' => 9],
        'mMaj7' => ['root' => 0, 'inv1' => 3,  'inv2' => 7,  'inv3' => 11],
        'aug7'  => ['root' => 0, 'inv1' => 4,  'inv2' => 8,  'inv3' => 10],
        'maj'   => ['root' => 0, 'inv1' => 4,  'inv2' => 7],
        'min'   => ['root' => 0, 'inv1' => 3,  'inv2' => 7],
        'aug'   => ['root' => 0, 'inv1' => 4,  'inv2' => 8],
        'dim'   => ['root' => 0, 'inv1' => 3,  'inv2' => 6],
        'sus4'  => ['root' => 0, 'inv1' => 5,  'inv2' => 7],
        'sus2'  => ['root' => 0, 'inv1' => 2,  'inv2' => 7],
        'add9'  => ['root' => 0, 'inv1' => 2,  'inv2' => 4, 'inv3' => 7],
        'madd9' => ['root' => 0, 'inv1' => 2,  'inv2' => 3, 'inv3' => 7],
        '5'     => ['root' => 0, 'inv1' => 7],
        '7sus4' => ['root' => 0, 'inv1' => 5,  'inv2' => 7,  'inv3' => 10],
    ];

    /**
     * Slash chord analysis: quality → [interval_semitones → inversion_name].
     */
    private const SLASH_CHORD_TONES = [
        'maj7'  => [0 => 'root', 4 => 'inv1', 7 => 'inv2', 11 => 'inv3'],
        'maj6'  => [0 => 'root', 4 => 'inv1', 7 => 'inv2', 9 => 'inv3'],
        'm7'    => [0 => 'root', 3 => 'inv1', 7 => 'inv2', 10 => 'inv3'],
        'm6'    => [0 => 'root', 3 => 'inv1', 7 => 'inv2', 9 => 'inv3'],
        'dom7'  => [0 => 'root', 4 => 'inv1', 7 => 'inv2', 10 => 'inv3'],
        '7'     => [0 => 'root', 4 => 'inv1', 7 => 'inv2', 10 => 'inv3'],
        'm7b5'  => [0 => 'root', 3 => 'inv1', 6 => 'inv2', 10 => 'inv3'],
        'o7'    => [0 => 'root', 3 => 'inv1', 6 => 'inv2', 9 => 'inv3'],
        'mMaj7' => [0 => 'root', 3 => 'inv1', 7 => 'inv2', 11 => 'inv3'],
        'aug7'  => [0 => 'root', 4 => 'inv1', 8 => 'inv2', 10 => 'inv3'],
        'aug'   => [0 => 'root', 4 => 'inv1', 8 => 'inv2'],
        'maj'   => [0 => 'root', 4 => 'inv1', 7 => 'inv2'],
        'min'   => [0 => 'root', 3 => 'inv1', 7 => 'inv2'],
        'dim'   => [0 => 'root', 3 => 'inv1', 6 => 'inv2'],
        'sus4'  => [0 => 'root', 5 => 'inv1', 7 => 'inv2'],
        'sus2'  => [0 => 'root', 2 => 'inv1', 7 => 'inv2'],
        // Extended chords
        '9'     => [0 => 'root', 4 => 'inv1', 7 => 'inv2', 10 => 'inv3', 2 => 'inv1'],
        'maj9'  => [0 => 'root', 4 => 'inv1', 7 => 'inv2', 11 => 'inv3', 2 => 'inv1'],
        'm9'    => [0 => 'root', 3 => 'inv1', 7 => 'inv2', 10 => 'inv3', 2 => 'inv1'],
    ];

    /**
     * Generic interval labels (semitones from root → label).
     */
    private const GENERIC_INTERVALS = [
        0 => 'R', 1 => 'b9', 2 => '9', 3 => '#9', 4 => '3', 5 => '11',
        6 => '#11', 7 => '5', 8 => 'b13', 9 => '13', 10 => 'b7', 11 => '7',
    ];

    /**
     * Quality-specific core-tone interval maps (semitone offset → label).
     * Used by computeIntervalLabels() to produce correct b3 / b7 / etc.
     * labels for alias voicings that are transposed to a new root.
     */
    private const QUALITY_INTERVALS = [
        'maj7'  => [0 => 'R', 4 => '3',  7 => '5',  11 => '7'],
        'maj6'  => [0 => 'R', 4 => '3',  7 => '5',  9  => '6'],
        'm7'    => [0 => 'R', 3 => 'b3', 7 => '5',  10 => 'b7'],
        'm6'    => [0 => 'R', 3 => 'b3', 7 => '5',  9  => '6'],
        '7'     => [0 => 'R', 4 => '3',  7 => '5',  10 => 'b7'],
        'dom7'  => [0 => 'R', 4 => '3',  7 => '5',  10 => 'b7'],
        'm7b5'  => [0 => 'R', 3 => 'b3', 6 => 'b5', 10 => 'b7'],
        'o7'    => [0 => 'R', 3 => 'b3', 6 => 'b5', 9  => 'bb7'],
        'mMaj7' => [0 => 'R', 3 => 'b3', 7 => '5',  11 => '7'],
        'aug7'  => [0 => 'R', 4 => '3',  8 => '#5', 10 => 'b7'],
        '7sus4' => [0 => 'R', 5 => '4',  7 => '5',  10 => 'b7'],
        'maj'   => [0 => 'R', 4 => '3',  7 => '5'],
        'min'   => [0 => 'R', 3 => 'b3', 7 => '5'],
        'aug'   => [0 => 'R', 4 => '3',  8 => '#5'],
        'dim'   => [0 => 'R', 3 => 'b3', 6 => 'b5'],
        'sus4'  => [0 => 'R', 5 => '4',  7 => '5'],
        'sus2'  => [0 => 'R', 2 => '2',  7 => '5'],
        'add9'  => [0 => 'R', 2 => '9',  4 => '3',  7 => '5'],
        'madd9' => [0 => 'R', 2 => '9',  3 => 'b3', 7 => '5'],
        '5'     => [0 => 'R', 7 => '5'],
    ];

    // =========================================================================
    // PUBLIC: STANDARD TRANSPOSITION
    // =========================================================================

    /**
     * Derive the bass note for an inversion given the root and quality.
     * Returns null for root position or unknown inversions.
     */
    public static function deriveBassNote(string $rootNote, string $quality, string $inversion): ?string
    {
        if ($inversion === 'root' || $inversion === '') return null;
        $semitones = self::INVERSION_INTERVALS[$quality] ?? null;
        if (!$semitones || !isset($semitones[$inversion])) return null;
        $rootSemitone = self::NOTE_TO_SEMITONE[$rootNote] ?? null;
        if ($rootSemitone === null) return null;
        $target     = ($rootSemitone + $semitones[$inversion]) % 12;
        $sharpNames = ['C','C#','D','D#','E','F','F#','G','G#','A','A#','B'];
        $flatNames  = ['C','Db','D','Eb','E','F','Gb','G','Ab','A','Bb','B'];
        // Qualities whose inversion bass tones are spelled with flats in standard
        // notation regardless of the key (b3, b5, b7 intervals).
        // Dim7: spell bass tone via DiminishedSymmetry for correct root-relative accidentals
        if ($quality === 'o7') {
            $sym   = new \App\Services\HarmonicContext\DiminishedSymmetry();
            $tones = $sym->spellDim7($rootNote);
            $pcMap = [];
            foreach ($tones as $name) {
                $pcMap[$sym->toPc($name)] = $name;
            }
            return $pcMap[$target] ?? $flatNames[$target];
        }
        $useFlats = self::useFlatsForQuality($rootNote, $quality);
        return $useFlats ? $flatNames[$target] : $sharpNames[$target];
    }

    public function calculateFrets(object $shape, string $rootNote): array
    {
        if (!isset(self::NOTE_TO_SEMITONE[$rootNote])) {
            Log::warning("ChordShapeCalculator: Invalid root note: {$rootNote}");
            return self::emptyDiagram();
        }

        $rootSemitone = self::NOTE_TO_SEMITONE[$rootNote];

        // Parse shape data
        $shapeData = $this->getShapeData($shape);
        if (!$shapeData || empty($shapeData['positions'])) {
            Log::warning("ChordShapeCalculator: Invalid shape data for shape ID: " . ($shape->id ?? '?'));
            return self::emptyDiagram();
        }

        // Get root string number
        $rootStringNum = self::ROOT_STRING_MAP[$shape->root_string ?? ''] ?? null;
        if ($rootStringNum === null) {
            Log::warning("ChordShapeCalculator: Invalid root string: " . ($shape->root_string ?? ''));
            return self::emptyDiagram();
        }

        $quality   = $shape->quality ?? '';
        $inversion = $shape->inversion ?? 'root';

        // Calculate target interval based on inversion
        $targetInterval = $this->getInversionInterval($quality, $inversion);
        if ($targetInterval === null) {
            Log::warning("ChordShapeCalculator: Invalid inversion '{$inversion}' for quality '{$quality}'");
            return self::emptyDiagram();
        }

        // Calculate target bass note (root + interval)
        $targetBassSemitone = ($rootSemitone + $targetInterval) % 12;

        // Find the lowest sounding position (bass note)
        $openStrings = $shapeData['open'] ?? [];
        $bassPos     = $this->findLowestPosition($shapeData['positions'], $openStrings);
        if (!$bassPos) {
            Log::warning("ChordShapeCalculator: No positions found in shape " . ($shape->slug ?? '?'));
            return self::emptyDiagram();
        }

        // Calculate where the bass note SHOULD be on its string
        $targetBassFret = $this->calculateTargetFret($bassPos['string'], $targetBassSemitone);

        // Calculate offset
        $storedFret = $bassPos['fret'];
        $fretOffset = $targetBassFret - $storedFret;

        // Negative-fret guard: shift up one octave if needed
        $fretOffset = $this->guardNegativeFrets($shapeData, $fretOffset, $targetBassFret, $storedFret);

        // Detect if original shape had open strings
        $hadOpenStrings = $this->shapeHasOpenStrings($shapeData);

        // Transpose positions, open strings, and barres
        [$calculatedPositions, $openResult] = $this->transposePositions(
            $shapeData['positions'], $openStrings, $fretOffset, $hadOpenStrings
        );
        $calculatedBarres = $this->transposeBarres($shapeData['barres'] ?? [], $fretOffset, $hadOpenStrings);

        // Build result
        $calculatedData = [
            'positions' => $calculatedPositions,
            'barres'    => $calculatedBarres,
            'muted'     => $shapeData['muted'] ?? [],
            'open'      => $openResult,
        ];

        $useFlats = $this->useFlatsForQuality($rootNote, $quality);

        return [
            'diagram_data'    => $calculatedData,
            'start_fret'      => $this->calculateStartFret($calculatedData),
            'interval_labels' => $this->computeIntervalLabels(
                $calculatedPositions,
                $openResult,
                $calculatedData['muted'] ?? [],
                $rootNote,
                $quality
            ),
            'notes'           => $this->calculateNoteNames($calculatedPositions, $useFlats, $openResult, $rootNote, $quality),
        ];
    }

    // =========================================================================
    // PUBLIC: SLASH CHORD ANALYSIS
    // =========================================================================

    /**
     * Analyze a slash chord to determine if it's an inversion or a true slash.
     *
     * Compares the bass note against the chord's interval formula.
     *   - Inversion: bass note is a chord tone (e.g., Am/C → C is the b3)
     *   - True slash: bass note is foreign (e.g., F/G → G is not in F major)
     *
     * @param  string $rootNote Root note (e.g., 'A', 'F')
     * @param  string $quality  Chord quality (e.g., 'min', 'dom7')
     * @param  string $bassNote Bass note (e.g., 'C', 'G')
     * @return array  {type: 'inversion'|'slash', inversion: string, interval: int}
     */
    public function analyzeSlashChord(string $rootNote, string $quality, string $bassNote): array
    {
        $rootSemitone = self::NOTE_TO_SEMITONE[$rootNote] ?? null;
        $bassSemitone = self::NOTE_TO_SEMITONE[$bassNote] ?? null;

        if ($rootSemitone === null || $bassSemitone === null) {
            return ['type' => 'slash', 'inversion' => '', 'interval' => -1];
        }

        $interval = ($bassSemitone - $rootSemitone + 12) % 12;

        $tones = self::SLASH_CHORD_TONES[$quality] ?? null;

        if ($tones !== null && isset($tones[$interval])) {
            $inv = $tones[$interval];

            if ($inv === 'root') {
                return ['type' => 'inversion', 'inversion' => 'root', 'interval' => 0];
            }

            return ['type' => 'inversion', 'inversion' => $inv, 'interval' => $interval];
        }

        // Bass note is foreign → true slash chord
        return ['type' => 'slash', 'inversion' => '', 'interval' => $interval];
    }

    // =========================================================================
    // PUBLIC: SLASH CHORD TRANSPOSITION (explicit bass note)
    // =========================================================================

    /**
     * Calculate fret positions for a shape with an explicit bass note.
     *
     * Used for true slash chords (F/G) where the bass note is foreign
     * to the chord. Transposes based on the bass note position.
     *
     * @param  object $shape    Shape with bass_note set
     * @param  string $rootNote Target root note (e.g., 'F')
     * @param  string $bassNote Target bass note (e.g., 'G')
     * @return array  {diagram_data, start_fret, interval_labels, notes}
     */
    public function calculateFretsWithBass(object $shape, string $rootNote, string $bassNote): array
    {
        if (!isset(self::NOTE_TO_SEMITONE[$rootNote]) || !isset(self::NOTE_TO_SEMITONE[$bassNote])) {
            Log::warning("ChordShapeCalculator: Invalid root/bass note: {$rootNote} / {$bassNote}");
            return self::emptyDiagram();
        }

        $rootSemitone = self::NOTE_TO_SEMITONE[$rootNote];
        $bassSemitone = self::NOTE_TO_SEMITONE[$bassNote];

        $shapeData = $this->getShapeData($shape);
        if (!$shapeData || empty($shapeData['positions'])) {
            return self::emptyDiagram();
        }

        // Find the lowest sounding string — this is the bass string
        $openStrings = $shapeData['open'] ?? [];
        $bassPos     = $this->findLowestPosition($shapeData['positions'], $openStrings);
        if (!$bassPos) {
            return self::emptyDiagram();
        }

        // Calculate where the BASS NOTE should be on its string
        $targetBassFret = $this->calculateTargetFret($bassPos['string'], $bassSemitone);

        // Calculate offset
        $storedFret = $bassPos['fret'];
        $fretOffset = $targetBassFret - $storedFret;

        // Negative-fret guard
        $fretOffset = $this->guardNegativeFrets($shapeData, $fretOffset, $targetBassFret, $storedFret);

        // Detect open strings
        $hadOpenStrings = $this->shapeHasOpenStrings($shapeData);

        // Transpose
        [$calculatedPositions, $openResult] = $this->transposePositions(
            $shapeData['positions'], $openStrings, $fretOffset, $hadOpenStrings
        );
        $calculatedBarres = $this->transposeBarres($shapeData['barres'] ?? [], $fretOffset, $hadOpenStrings);

        $calculatedData = [
            'positions' => $calculatedPositions,
            'barres'    => $calculatedBarres,
            'muted'     => $shapeData['muted'] ?? [],
            'open'      => $openResult,
        ];

        $startFret    = $this->calculateStartFret($calculatedData);
        $shapeQuality = $shape->quality ?? '';
        $useFlats     = $this->useFlatsForQuality($rootNote, $shapeQuality);
        $notes        = $this->calculateNoteNames($calculatedPositions, $useFlats, $openResult, $rootNote, $shapeQuality);

        // Recompute interval labels relative to the BASS note
        // For slash chords like F/G, the bass note (G) is the reference
        $intervalLabels = $this->computeSlashIntervalLabels(
            $calculatedPositions, $openResult, $calculatedData['muted'] ?? [], $bassSemitone
        );

        return [
            'diagram_data'    => $calculatedData,
            'start_fret'      => $startFret,
            'interval_labels' => $intervalLabels,
            'notes'           => $notes,
        ];
    }

    // =========================================================================
    // PRIVATE: TRANSPOSITION HELPERS
    // =========================================================================

    /**
     * Decode diagram_data from shape object (handles both string JSON and array).
     */
    private function getShapeData(object $shape): ?array
    {
        $raw = $shape->diagram_data ?? null;

        if (is_array($raw)) {
            return $raw;
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }

    /**
     * Get interval in semitones for a given inversion.
     */
    private function getInversionInterval(string $quality, string $inversion): ?int
    {
        $intervals = self::INVERSION_INTERVALS[$quality] ?? null;

        if ($intervals === null) {
            Log::info("ChordShapeCalculator: No interval map for quality '{$quality}', defaulting to maj7");
            $intervals = self::INVERSION_INTERVALS['maj7'];
        }

        return $intervals[$inversion] ?? null;
    }

    /**
     * Find the lowest-pitched (bass) position across fretted + open strings.
     *
     * Uses string number as primary sort (lower string = lower pitch),
     * then open semitone + fret as tiebreaker within the same string.
     *
     * @return array|null {string, fret, finger}
     */
    private function findLowestPosition(array $positions, array $openStrings = []): ?array
    {
        if (empty($positions) && empty($openStrings)) {
            return null;
        }

        $lowest      = null;
        $lowestPitch = PHP_INT_MAX;

        // Check fretted positions
        foreach ($positions as $pos) {
            $s = (int) $pos['string'];
            if ($s < 1 || $s > 6 || !isset(self::TUNING[$s])) continue;
            $pitch = ($s * 100) + self::TUNING[$s] + (int) $pos['fret'];

            if ($pitch < $lowestPitch) {
                $lowestPitch = $pitch;
                $lowest      = $pos;
            }
        }

        // Check open strings (fret = 0)
        foreach ($openStrings as $s) {
            $s = (int) $s;
            if ($s < 1 || $s > 6 || !isset(self::TUNING[$s])) continue;
            $pitch = ($s * 100) + self::TUNING[$s];

            if ($pitch < $lowestPitch) {
                $lowestPitch = $pitch;
                $lowest      = ['string' => $s, 'fret' => 0, 'finger' => null];
            }
        }

        return $lowest;
    }

    /**
     * Calculate the fret on a given string for a target semitone (within one octave).
     */
    private function calculateTargetFret(int $string, int $targetSemitone): int
    {
        $openSemitone = self::TUNING[$string];
        return ($targetSemitone - $openSemitone + 12) % 12;
    }

    /**
     * Guard against negative frets by shifting up one octave if needed.
     *
     * @return int Adjusted fret offset
     */
    private function guardNegativeFrets(array $shapeData, int $fretOffset, int $targetBassFret, int $storedFret): int
    {
        $minFret = PHP_INT_MAX;

        foreach ($shapeData['positions'] as $pos) {
            $potential = (int) $pos['fret'] + $fretOffset;
            if ($potential < $minFret) {
                $minFret = $potential;
            }
        }

        foreach ($shapeData['open'] ?? [] as $s) {
            $potential = 0 + $fretOffset;
            if ($potential < $minFret) {
                $minFret = $potential;
            }
        }

        if ($minFret < 0) {
            $targetBassFret += 12;
            return $targetBassFret - $storedFret;
        }

        return $fretOffset;
    }

    /**
     * Check if the original shape has any open strings (fret 0 or open array).
     */
    private function shapeHasOpenStrings(array $shapeData): bool
    {
        foreach ($shapeData['positions'] as $pos) {
            if ((int) $pos['fret'] === 0) {
                return true;
            }
        }

        return !empty($shapeData['open']);
    }

    /**
     * Transpose fretted positions and open strings by offset.
     *
     * Handles finger reassignment when open strings become fretted
     * (open → finger 1, shift other fingers up by 1).
     *
     * @return array [calculatedPositions[], openStrings[]]
     */
    private function transposePositions(array $positions, array $openStrings, int $fretOffset, bool $hadOpenStrings): array
    {
        $calculated = [];
        $openResult = [];

        // Process fretted positions
        foreach ($positions as $pos) {
            $newFret         = (int) $pos['fret'] + $fretOffset;
            $originalFinger  = $pos['finger'] ?? null;
            $newFinger       = $originalFinger;

            if ($newFret === 0) {
                // Becomes an open string
                $openResult[] = (int) $pos['string'];
                $newFinger    = null;
            } elseif ($hadOpenStrings && $fretOffset !== 0 && $originalFinger !== null) {
                // Reassign fingerings for transposed open-string shapes
                if ((int) $pos['fret'] === 0) {
                    $newFinger = 1; // Was open, now needs finger 1
                } elseif ($originalFinger < 4) {
                    $newFinger = $originalFinger + 1; // Shift up
                }
                // Finger 4 stays as 4
            }

            $calculated[] = [
                'string' => (int) $pos['string'],
                'fret'   => $newFret,
                'finger' => $newFinger,
            ];
        }

        // Process originally open strings
        foreach ($openStrings as $string) {
            $newFret = 0 + $fretOffset;

            if ($newFret === 0) {
                $openResult[] = (int) $string;
            } else {
                $calculated[] = [
                    'string' => (int) $string,
                    'fret'   => $newFret,
                    'finger' => ($hadOpenStrings && $fretOffset !== 0) ? 1 : null,
                ];
            }
        }

        return [$calculated, $openResult];
    }

    /**
     * Transpose barres by offset with finger reassignment.
     */
    private function transposeBarres(array $barres, int $fretOffset, bool $hadOpenStrings): array
    {
        $result = [];

        foreach ($barres as $barre) {
            $newFret         = (int) $barre['fret'] + $fretOffset;
            $originalFinger  = $barre['finger'] ?? null;
            $newFinger       = $originalFinger;

            if ($hadOpenStrings && $fretOffset !== 0 && $originalFinger !== null && $originalFinger < 4) {
                $newFinger = $originalFinger + 1;
            }

            $result[] = [
                'fret'       => $newFret,
                'fromString' => $barre['fromString'],
                'toString'   => $barre['toString'],
                'finger'     => $newFinger,
            ];
        }

        return $result;
    }

    // =========================================================================
    // PRIVATE: CALCULATION HELPERS
    // =========================================================================

    /**
     * Calculate start fret (lowest non-zero fret in the diagram).
     */
    private function calculateStartFret(array $diagramData): int
    {
        $minFret = PHP_INT_MAX;

        foreach ($diagramData['positions'] as $pos) {
            $fret = (int) $pos['fret'];
            if ($fret > 0 && $fret < $minFret) {
                $minFret = $fret;
            }
        }

        foreach ($diagramData['barres'] ?? [] as $barre) {
            $fret = (int) $barre['fret'];
            if ($fret > 0 && $fret < $minFret) {
                $minFret = $fret;
            }
        }

        return ($minFret === PHP_INT_MAX) ? 1 : max(1, $minFret);
    }

    /**
     * Calculate note names for each position (comma-separated).
     * Open strings (array of string numbers) are included at fret 0.
     *
     * For o7 quality, uses DiminishedSymmetry::spellDim7() so the root and b3
     * are spelled correctly relative to the root (e.g. A#°7 → A#, C#, E, G)
     * instead of forcing flat names for sharp-rooted chords.
     */

    /**
     * Decide flat vs sharp spelling for a root+quality combination.
     * Flat roots and b-accidental roots always use flats.
     * For natural roots, checks whether any chord tone of the quality lands on
     * a flat-side pitch class (Db Eb Gb Ab Bb) — if so, use flats.
     * Sharp roots (#) always use sharps.
     */
    public static function useFlatsForQuality(string $rootNote, string $quality): bool
    {
        if (in_array($rootNote, self::FLAT_ROOT_NOTES, true) || str_contains($rootNote, 'b')) {
            return true;
        }
        if (str_contains($rootNote, '#')) {
            return false;
        }
        // Natural root: check if any minor/diminished interval of this quality
        // lands on a flat-side pc (major/perfect intervals always spell sharp).
        $rootPc    = self::NOTE_TO_SEMITONE[$rootNote] ?? null;
        $intervals = self::QUALITY_FLAT_INTERVALS[$quality] ?? null;
        if ($rootPc === null || $intervals === null) {
            return false;
        }
        foreach ($intervals as $interval) {
            if (in_array(($rootPc + $interval) % 12, self::FLAT_SIDE_PCS, true)) {
                return true;
            }
        }
        return false;
    }

    private function calculateNoteNames(
        array  $positions,
        bool   $useFlats = false,
        array  $openStrings = [],
        string $rootNote = '',
        string $quality = ''
    ): string {
        // Build per-string fret map (fretted + open, no double-counting), sorted low→high string
        $stringFrets = [];
        foreach ($positions as $pos) {
            $s = (int) $pos['string'];
            $stringFrets[$s] = (int) $pos['fret'];
        }
        foreach ($openStrings as $s) {
            $s = (int) $s;
            if (!isset($stringFrets[$s])) {
                $stringFrets[$s] = 0;
            }
        }
        ksort($stringFrets);

        // For dim7: build a pc→name map from DiminishedSymmetry spelling
        if ($quality === 'o7' && $rootNote !== '') {
            $sym   = new \App\Services\HarmonicContext\DiminishedSymmetry();
            $tones = $sym->spellDim7($rootNote); // [R, b3, b5, bb7]
            $pcMap = [];
            foreach ($tones as $name) {
                $pc = $sym->toPc($name);
                $pcMap[$pc] = $name; // last writer wins (all four are distinct pcs for dim7)
            }
            $noteMap = null; // signal: use $pcMap
        } else {
            $noteMap = $useFlats ? self::SEMITONE_TO_NOTE_FLAT : self::SEMITONE_TO_NOTE_SHARP;
            $pcMap   = null;
        }

        $notes = [];
        foreach ($stringFrets as $string => $fret) {
            if ($string < 1 || $string > 6 || !isset(self::TUNING[$string])) continue;
            $pc      = (self::TUNING[$string] + $fret) % 12;
            $notes[] = $pcMap !== null ? ($pcMap[$pc] ?? self::SEMITONE_TO_NOTE_FLAT[$pc]) : $noteMap[$pc];
        }

        return implode(',', $notes);
    }

    /**
     * Compute interval labels relative to a bass note (for slash chords).
     */
    private function computeSlashIntervalLabels(
        array $positions,
        array $openStrings,
        array $muted,
        int   $bassSemitone
    ): string {
        // Build per-string fret lookup
        $stringFrets = [];
        foreach ($positions as $pos) {
            $stringFrets[(int) $pos['string']] = (int) $pos['fret'];
        }
        foreach ($openStrings as $s) {
            $s = (int) $s;
            if (!isset($stringFrets[$s])) {
                $stringFrets[$s] = 0;
            }
        }

        $labels = [];
        for ($s = 1; $s <= 6; $s++) {
            if (in_array($s, $muted) && !isset($stringFrets[$s])) {
                $labels[] = 'x';
            } elseif (isset($stringFrets[$s])) {
                $noteSemitone = (self::TUNING[$s] + $stringFrets[$s]) % 12;
                $interval     = ($noteSemitone - $bassSemitone + 12) % 12;
                $labels[]     = self::GENERIC_INTERVALS[$interval];
            } else {
                $labels[] = 'x';
            }
        }

        return implode(',', $labels);
    }

    /**
     * Compute quality-aware interval labels for transposed positions.
     * Public so ChordVoicingSearch can call it with the alias root+quality
     * rather than the parent shape's root+quality.
     */
    public function computeIntervalLabelsPublic(
        array  $positions,
        array  $openStrings,
        array  $muted,
        string $rootNote,
        string $quality
    ): string {
        return $this->computeIntervalLabels($positions, $openStrings, $muted, $rootNote, $quality);
    }

    /**
     * Compute quality-aware interval labels for transposed positions.
     * Used after alias transposition so colours reflect the new root.
     */
    private function computeIntervalLabels(
        array  $positions,
        array  $openStrings,
        array  $muted,
        string $rootNote,
        string $quality
    ): string {
        $rootSemitone = self::NOTE_TO_SEMITONE[$rootNote] ?? null;
        if ($rootSemitone === null) return '';

        $imap = self::QUALITY_INTERVALS[$quality] ?? null;

        $stringFrets = [];
        foreach ($positions as $pos) {
            $stringFrets[(int) $pos['string']] = (int) $pos['fret'];
        }
        foreach ($openStrings as $s) {
            $s = (int) $s;
            if (!isset($stringFrets[$s])) $stringFrets[$s] = 0;
        }

        $labels = [];
        for ($s = 1; $s <= 6; $s++) {
            if (in_array($s, $muted) && !isset($stringFrets[$s])) {
                $labels[] = 'x';
            } elseif (isset($stringFrets[$s])) {
                $interval = (self::TUNING[$s] + $stringFrets[$s] - $rootSemitone + 12) % 12;
                $labels[] = ($imap && isset($imap[$interval]))
                    ? $imap[$interval]
                    : self::GENERIC_INTERVALS[$interval];
            } else {
                $labels[] = 'x';
            }
        }

        return implode(',', $labels);
    }

    // =========================================================================
    // STATIC HELPERS
    // =========================================================================

    /**
     * Return an empty diagram structure (used for error cases).
     */
    public static function emptyDiagram(): array
    {
        return [
            'diagram_data'    => [
                'positions' => [],
                'barres'    => [],
                'muted'     => [],
                'open'      => [],
            ],
            'start_fret'      => 1,
            'interval_labels' => '',
            'notes'           => '',
        ];
    }
}

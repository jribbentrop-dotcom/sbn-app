<?php

namespace App\Services\HarmonicContext;

/**
 * DiminishedSymmetry — the shared dim7 harmonic primitive.
 *
 * A diminished-7th chord divides the octave into four equal minor thirds, so it
 * is inversionally symmetric: one shape simultaneously names four dim7 chords
 * (roots a minor third apart) AND four rootless dominant-7(b9) chords (each a
 * major third below one of the dim tones, with that dim tone as the b9).
 *
 * This class owns that math + the spelling rules in ONE place. Both the
 * identifier's HarmonicContext resolvers and the chord library generate from it.
 *
 * Pure: no DB, no I/O, no Laravel.
 *
 * ── Spelling policy (locked) ────────────────────────────────────────────────
 *  Structural tones (3rd, 5th, b7) are spelled STRICTLY by function: the correct
 *  letter for the degree, with a single accidental. A major third above B is some
 *  kind of D (→ D#), never an E. These never need a double accidental for the
 *  chords we generate.
 *
 *  The diminished-7th tone (the "bb7") and tensions (b9) are spelled PRAGMATICALLY:
 *  we prefer the simplest enharmonic natural rather than a theoretically-correct
 *  double-flat. So C°7's 7th renders as A (not Bbb) and Ab7(b9)'s b9 renders as A.
 */
class DiminishedSymmetry
{
    /** Qualities that are diminished-7th (inversionally symmetric). */
    public const DIM_QUALITIES = ['dim7', 'dim', 'o7'];

    /**
     * Semitone offset above the dim root for each known °7 extension.
     * Used to compute what interval that extension lands on for each
     * inversion slot or dom7(b9) alias reading.
     */
    public const DIM_EXTENSION_SEMITONES = [
        'b13' => 8,
        '11'  => 5,
    ];

    /**
     * Semitone offset → extension label. Chord tones (0,3,6,9 for dim;
     * 0,4,7,10 for dom7) are absent — they carry no extension label.
     */
    public const EXTENSION_INTERVAL_LABELS = [
        1  => 'b9',
        2  => '9',
        3  => '#9',
        5  => '11',
        6  => '#11',
        8  => 'b13',
        9  => '13',
        11 => 'maj7',
    ];

    /**
     * Given a dim7 extension (e.g. 'b13') and a reading index, return the
     * extension label as seen from that reading's root — or null when the
     * extension lands on a chord tone (root, b3, b5, bb7 for dim; root, 3,
     * 5, b7 for dom7).
     *
     * $slotIndex : 0-3 — which of the four symmetric slots (each +3 semitones)
     * $isDom     : false → dim inversion reading; true → dom7(b9) alias reading
     *              (dom root is 4 semitones below the slot's dim root, i.e. +8)
     */
    public function extensionLabelForReading(string $dimExtension, int $slotIndex, bool $isDom): ?string
    {
        $extSemitones = self::DIM_EXTENSION_SEMITONES[$dimExtension] ?? null;
        if ($extSemitones === null) {
            return null;
        }

        // Shift the extension interval into the coordinate frame of this slot's root.
        // Each slot rotates +3 semitones; dom root is a further -4 (+8) from the dim root.
        $shift    = $isDom ? ($extSemitones + 4 - $slotIndex * 3) : ($extSemitones - $slotIndex * 3);
        $interval = (($shift % 12) + 12) % 12;

        return self::EXTENSION_INTERVAL_LABELS[$interval] ?? null;
    }

    private const NOTE_TO_PC = [
        'C' => 0, 'C#' => 1, 'Db' => 1,
        'D' => 2, 'D#' => 3, 'Eb' => 3,
        'E' => 4, 'Fb' => 4, 'E#' => 5,
        'F' => 5, 'F#' => 6, 'Gb' => 6,
        'G' => 7, 'G#' => 8, 'Ab' => 8,
        'A' => 9, 'A#' => 10, 'Bb' => 10,
        'B' => 11, 'Cb' => 11, 'B#' => 0,
    ];

    /** Letter → its natural pitch class, in circle-of-letters order. */
    private const LETTERS = ['C', 'D', 'E', 'F', 'G', 'A', 'B'];
    private const LETTER_PC = ['C' => 0, 'D' => 2, 'E' => 4, 'F' => 5, 'G' => 7, 'A' => 9, 'B' => 11];

    /** Simple enharmonic names (pragmatic), flat-biased to match dim/minor convention. */
    private const PC_TO_FLAT = ['C', 'Db', 'D', 'Eb', 'E', 'F', 'Gb', 'G', 'Ab', 'A', 'Bb', 'B'];

    /** Sharp-biased enharmonic names. */
    private const PC_TO_SHARP = ['C', 'C#', 'D', 'D#', 'E', 'F', 'F#', 'G', 'G#', 'A', 'A#', 'B'];

    // ── Core symmetry math ──────────────────────────────────────────────────

    /**
     * The four symmetric roots of a dim7 chord, as pitch classes.
     * @return int[]  [pc, pc+3, pc+6, pc+9] mod 12
     */
    public function symmetricRoots(int $pc): array
    {
        return [
            $pc % 12,
            ($pc + 3) % 12,
            ($pc + 6) % 12,
            ($pc + 9) % 12,
        ];
    }

    /**
     * The four dominant-7(b9) readings of a dim7 chord.
     *
     * For each symmetric root r (a dim tone), the dominant root is a major third
     * below (r - 4), and r itself is the b9 of that dominant. The dominant root is
     * always ABSENT from the dim voicing — these are true rootless readings.
     *
     * @return array<int, array{domRootPc:int, b9Pc:int}>
     */
    public function dominantReadings(int $pc): array
    {
        $out = [];
        foreach ($this->symmetricRoots($pc) as $r) {
            $out[] = [
                'domRootPc' => ($r - 4 + 12) % 12,
                'b9Pc'      => $r,
            ];
        }
        return $out;
    }

    // ── Spelling ──────────────────────────────────────────────────────────────

    /**
     * Spell a dim7's chord tones for a given (spelled) root.
     *
     * Structural intervals R/b3/b5 strict-by-letter; the bb7 tone pragmatic
     * (simple enharmonic natural — A for C°7, not Bbb).
     *
     * @return array{R:string, b3:string, b5:string, bb7:string}
     */
    public function spellDim7(string $root): array
    {
        $rootPc = $this->toPc($root);
        return [
            'R'   => $this->strictDegree($root, $rootPc, 0, 0),       // root letter, +0
            'b3'  => $this->strictDegree($root, ($rootPc + 3) % 12, 2, 3),
            'b5'  => $this->strictDegree($root, ($rootPc + 6) % 12, 4, 6),
            'bb7' => $this->pragmatic(($rootPc + 9) % 12),            // °7 = pragmatic natural
        ];
    }

    /**
     * Spell a rootless dom7(b9)'s present chord tones (3, 5, b7, b9) for a given
     * (spelled) dominant root. Structural 3/5/b7 strict; b9 pragmatic.
     *
     * @return array{3:string, 5:string, b7:string, b9:string}
     */
    public function spellDom7b9(string $domRoot): array
    {
        $rootPc = $this->toPc($domRoot);
        return [
            '3'  => $this->strictDegree($domRoot, ($rootPc + 4) % 12, 2, 4),
            '5'  => $this->strictDegree($domRoot, ($rootPc + 7) % 12, 4, 7),
            'b7' => $this->strictDegree($domRoot, ($rootPc + 10) % 12, 6, 10),
            'b9' => $this->pragmatic(($rootPc + 1) % 12),            // tension = pragmatic
        ];
    }

    /**
     * Spell a pitch class as a chord root, flat-biased (dim/minor convention).
     * Used when naming the symmetric dim roots and the dom7(b9) roots.
     */
    public function spellRoot(int $pc): string
    {
        return $this->pragmatic($pc);
    }

    /**
     * Sharp-biased pitch-class name. The identifier's resolvers historically
     * spell with sharps; they call this so the symmetry math is shared without
     * changing their output. (Library generation uses spellRoot/spellDim7 etc.)
     */
    public function spellSharp(int $pc): string
    {
        return self::PC_TO_SHARP[$pc % 12];
    }

    // ── Internals ───────────────────────────────────────────────────────────

    /**
     * Spelling for a STRUCTURAL tone (3rd, 5th, b7). Functional-first: place the
     * pitch class on the letter $letterSteps above the root's letter. But dim7
     * stacks minor thirds, so strict spelling can force a double accidental on a
     * structural tone (e.g. the b5 of Eb°7 → Bbb). The locked rule forbids that:
     * if the functional letter needs a double accidental, fall back to the simple
     * enharmonic natural (Bbb → A). Single-accidental functional spellings — the
     * normal case, incl. B7's 3rd = D# — are always kept.
     *
     * $expectedSemitones is unused at runtime; kept as documentation of the degree.
     */
    private function strictDegree(string $root, int $targetPc, int $letterSteps, int $expectedSemitones): string
    {
        $rootLetter = $root[0];
        $li = array_search($rootLetter, self::LETTERS, true);
        $letter = self::LETTERS[($li + $letterSteps) % 7];
        $natural = self::LETTER_PC[$letter];

        // Signed accidental in [-2, +2] to reach the target pitch class.
        $delta = (($targetPc - $natural + 12) % 12);
        if ($delta > 6) {
            $delta -= 12;
        }

        // Never emit a double accidental on a structural tone — prefer readable.
        if ($delta <= -2 || $delta >= 2) {
            return $this->pragmatic($targetPc);
        }

        return $letter . $this->accidental($delta);
    }

    /** Simple flat-biased enharmonic name for a pitch class. */
    private function pragmatic(int $pc): string
    {
        return self::PC_TO_FLAT[$pc % 12];
    }

    private function accidental(int $delta): string
    {
        return match ($delta) {
            -2 => 'bb',
            -1 => 'b',
            0  => '',
            1  => '#',
            2  => '##',
            default => '', // unreachable for our intervals; degrade gracefully
        };
    }

    public function toPc(string $note): int
    {
        return self::NOTE_TO_PC[$note] ?? 0;
    }
}

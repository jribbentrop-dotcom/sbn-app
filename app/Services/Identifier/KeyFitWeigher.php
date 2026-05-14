<?php

namespace App\Services\Identifier;

/**
 * Phase 3.2: Key-fit weighting for chord identification candidates.
 *
 * Returns a multiplier in [1.00, 1.20] based on how a candidate's root
 * relates to the song key. Never penalizes — chromatic and out-of-key
 * candidates get the neutral 1.00, so jazz idioms (secondary dominants,
 * tritone subs, modal mixture) are NOT downweighted.
 *
 * Spec: docs/SBN-Identifier-Phase3-Plan.md §4.
 *
 * Tonicization is NOT handled here (deferred to Layer 3, which absorbs
 * secondary-ii-V groupings as high-probability transitions).
 */
class KeyFitWeigher
{
    /** Note name → pitch class. */
    private const NOTE_TO_PC = [
        'C'=>0,'C#'=>1,'Db'=>1,'D'=>2,'D#'=>3,'Eb'=>3,'E'=>4,'F'=>5,
        'F#'=>6,'Gb'=>6,'G'=>7,'G#'=>8,'Ab'=>8,'A'=>9,'A#'=>10,'Bb'=>10,'B'=>11,
    ];

    /** Major scale intervals from tonic in semitones: I, ii, iii, IV, V, vi, vii. */
    private const MAJOR_SCALE = [0, 2, 4, 5, 7, 9, 11];

    /** Common modal-mixture roots (from parallel minor): bIII, bVI, bVII. */
    private const MIXTURE_INTERVALS = [3, 8, 10];

    /**
     * Weights by scale-degree role. Spec §4.2.
     */
    private const WEIGHTS = [
        'tonic_primary'    => 1.20, // I, IV, V — the primary functions
        'tonic_diatonic'   => 1.15, // ii, iii, vi — diatonic minor triads
        'tonic_leading'    => 1.10, // vii°
        'secondary_dom'    => 1.10, // V/x where x is diatonic (excluding V/I which IS V)
        'tritone_sub'      => 1.05, // bII7, bV7 etc. — tritone subs of any diatonic dominant
        'modal_mixture'    => 1.05, // bIII, bVI, bVII from parallel minor
        'chromatic_other'  => 1.00, // anything else — neutral
    ];

    /**
     * Compute the key-fit weight for a candidate.
     *
     * @param  int         $candidateRootPc  Pitch class 0..11
     * @param  string      $candidateQuality Quality token (e.g. 'maj7', 'm7', 'dom7')
     * @param  string|null $songKey          Song key (e.g. 'Db', 'F#', 'Bb minor', 'Am')
     * @return float Multiplier in [1.00, 1.20]
     */
    public function weight(int $candidateRootPc, string $candidateQuality, ?string $songKey): float
    {
        if ($songKey === null || trim($songKey) === '') {
            return 1.00;
        }
        $tonicPc = $this->parseTonicPc($songKey);
        if ($tonicPc === null) {
            return 1.00;
        }
        $isMinorKey = $this->isMinorKey($songKey);

        // Scale-degree interval from tonic
        $iv = ($candidateRootPc - $tonicPc + 12) % 12;
        $isDom = in_array($candidateQuality, ['dom7', '7'], true);

        // Diatonic scale-degree maps by key quality.
        // Major key:   I=0, ii=2, iii=4, IV=5, V=7, vi=9, vii=11
        // Minor key (natural):  i=0, ii°=2, bIII=3, iv=5, v=7, bVI=8, bVII=10
        // Primary functions in both: root (0), 4th (5), 5th (7).
        $diatonicMinor = $isMinorKey ? [2, 3, 8, 10] : [2, 4, 9]; // ii, iii/bIII, vi/bVI, vii?
        $leadingTone   = $isMinorKey ? null : 11;

        // For dominant 7 chords, the secondary-dominant reading takes precedence
        // over the bare diatonic-degree label. A7 in C is FUNCTIONALLY V7/ii (1.10),
        // not "vi quality-promoted" (that interpretation applies to A m7 only).
        if ($isDom) {
            $targetPc = ($candidateRootPc + 5) % 12;
            $targetIv = ($targetPc - $tonicPc + 12) % 12;
            // Is the resolution target a diatonic root of the song key?
            $diatonicRoots = $isMinorKey ? [0, 2, 3, 5, 7, 8, 10] : self::MAJOR_SCALE;
            if (in_array($targetIv, $diatonicRoots, true)) {
                // V7/I is just V — primary, not secondary
                if ($iv === 7) return self::WEIGHTS['tonic_primary'];
                return self::WEIGHTS['secondary_dom'];
            }
            // Tritone sub: same resolution target via tritone-distant dominant.
            $tritoneTargetPc = ($candidateRootPc + 6 + 5) % 12;
            $tritoneTargetIv = ($tritoneTargetPc - $tonicPc + 12) % 12;
            if (in_array($tritoneTargetIv, $diatonicRoots, true)) {
                return self::WEIGHTS['tritone_sub'];
            }
        }

        // Primary functions: tonic, IV, V
        if (in_array($iv, [0, 5, 7], true)) {
            return self::WEIGHTS['tonic_primary'];
        }
        // Diatonic minor degrees
        if (in_array($iv, $diatonicMinor, true)) {
            return self::WEIGHTS['tonic_diatonic'];
        }
        // Leading-tone diatonic (major only)
        if ($leadingTone !== null && $iv === $leadingTone) {
            return self::WEIGHTS['tonic_leading'];
        }
        // Modal mixture: borrowed from parallel minor (only meaningful in major).
        if (!$isMinorKey && in_array($iv, self::MIXTURE_INTERVALS, true)) {
            return self::WEIGHTS['modal_mixture'];
        }
        return self::WEIGHTS['chromatic_other'];
    }

    /**
     * Parse the tonic note from a song-key string like 'Db', 'F# minor', 'Am', 'Bb maj'.
     */
    private function parseTonicPc(?string $songKey): ?int
    {
        if ($songKey === null) return null;
        $trimmed = trim($songKey);
        if ($trimmed === '') return null;

        // Match leading note (1-2 chars: letter + optional accidental)
        if (!preg_match('/^([A-Ga-g])([#b♯♭]?)/u', $trimmed, $m)) return null;
        $note = strtoupper($m[1]) . $this->normalizeAccidental($m[2] ?? '');
        return self::NOTE_TO_PC[$note] ?? null;
    }

    /**
     * Detect minor-key from song-key string. Treats 'X minor', 'Xm', 'Xmin' as minor.
     */
    private function isMinorKey(string $songKey): bool
    {
        $trimmed = strtolower(trim($songKey));
        // Strip leading note + accidental
        $rest = preg_replace('/^[a-g][#b♯♭]?/u', '', $trimmed);
        $rest = trim($rest);
        if ($rest === '') return false;
        return str_starts_with($rest, 'm') && !str_starts_with($rest, 'maj');
    }

    private function normalizeAccidental(string $acc): string
    {
        return match ($acc) {
            '♯' => '#',
            '♭' => 'b',
            default => $acc,
        };
    }
}

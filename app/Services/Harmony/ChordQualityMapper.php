<?php

namespace App\Services\Harmony;

/**
 * Shared chord-quality string mapping for ProgressionBuilder and
 * ProgressionDetector, which previously carried independent copies of this
 * logic that had silently diverged (SBN-Security-Audit-2026-07-09.md #6).
 *
 * Two mapping paths are kept separate rather than merged into one
 * "normalize", because they answer different questions and would disagree
 * if unified: the display path preserves near-exact quality (maj6 stays
 * "6", not "maj7") for chord-name/Roman-suffix display; the functional
 * path collapses extended qualities to their base harmonic function
 * (maj6/maj9/maj11/maj13 -> maj7) for progression-pattern detection, which
 * only cares about functional identity, not the exact extension.
 */
class ChordQualityMapper
{
    // ---- Display path (ex ProgressionBuilder::normalizeQuality / qualityToChordNameSuffix) ----

    public function normalizeAlias(string $quality): string
    {
        $q = trim($quality);

        $aliases = [
            'dom7' => '7',
            'dominant' => '7',
            'major' => 'maj',
            'minor' => 'm',
            'half-dim' => 'm7b5',
            'half-diminished' => 'm7b5',
        ];

        return $aliases[$q] ?? $q;
    }

    /** Chord-NAME suffix (e.g. "Bdim", "Caug") — distinct from toRomanSuffix(). */
    public function toChordNameSuffix(string $quality): string
    {
        $q = $this->normalizeAlias($quality);

        $suffixMap = [
            'maj'   => '',
            'dom7'  => '7',
            '7'     => '7',
            'maj7'  => 'maj7',
            'min'   => 'm',
            'm'     => 'm',
            'm7'    => 'm7',
            'm7b5'  => 'm7b5',
            'dim'   => 'dim',
            'o'     => 'dim',
            '°'     => 'dim',
            'dim7'  => 'dim7',
            'o7'    => 'dim7',
            '°7'    => 'dim7',
            'aug'   => 'aug',
            'aug7'  => 'aug7',
            'sus4'  => 'sus4',
            'sus2'  => 'sus2',
            'maj6'  => '6',
            'm6'    => 'm6',
            'mMaj7' => 'mMaj7',
            'add9'  => 'add9',
            '9'     => '9',
            '11'    => '11',
            '13'    => '13',
        ];

        return $suffixMap[$q] ?? $q;
    }

    // ---- Functional path (ex ProgressionDetector::normalizeQualityForDetection / qualityToSuffix) ----

    /**
     * Reduce extended qualities (9, 11, 13, b9, #11 etc.) to their base
     * harmonic function: dom7, m7, or maj7.
     */
    public function normalizeForFunction(string $quality): string
    {
        $q = strtolower($quality);

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

    /** Roman-NUMERAL suffix (e.g. "vii°", "bIIo") — distinct from toChordNameSuffix(). */
    public function toRomanSuffix(string $quality): string
    {
        $q = $this->normalizeForFunction($quality);

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
}

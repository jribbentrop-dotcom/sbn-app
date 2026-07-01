<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * TabXmlTransposer
 *
 * Transposes all fretted notes in a MusicXML 3.1 partwise string by a given
 * number of semitones.  Uses DOMDocument for lossless round-trip — the rest of
 * the document is preserved verbatim (divisions, durations, beams, ties, etc.).
 *
 * Call: TabXmlTransposer::transpose(string $xml, int $semitones, string $targetKey): string
 */
class TabXmlTransposer
{
    /**
     * Chromatic note table — used to convert pitch step+alter ↔ semitone index.
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

    private const SEMI_TO_NOTE_SHARP = ['C', 'C#', 'D', 'D#', 'E', 'F', 'F#', 'G', 'G#', 'A', 'A#', 'B'];
    private const SEMI_TO_NOTE_FLAT  = ['C', 'Db', 'D', 'Eb', 'E', 'F', 'Gb', 'G', 'Ab', 'A', 'Bb', 'B'];

    /**
     * Transpose all fretted notes in a MusicXML partwise string.
     *
     * @param  string|null $xml        MusicXML content.  null / empty → returned unchanged.
     * @param  int         $semitones  Signed semitone shift (e.g. +2 or -5).
     * @param  string      $targetKey  Target key for pitch re-spelling (e.g. 'D', 'Bb', 'Am').
     * @return string|null             Transposed XML, or the original value if empty/null.
     */
    public static function transpose(?string $xml, int $semitones, string $targetKey): ?string
    {
        if ($xml === null || trim($xml) === '') {
            return $xml;
        }
        if ($semitones === 0) {
            return $xml;
        }

        $useFlats = HarmonicContext::spellingUsesFlats($targetKey);

        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->preserveWhiteSpace = true;
        $doc->formatOutput       = false;

        // Suppress namespace / DTD warnings that MusicXML files often carry.
        libxml_use_internal_errors(true);
        $loaded = $doc->loadXML($xml);
        libxml_clear_errors();

        if (!$loaded) {
            return $xml;  // parse failure — return original, never crash
        }

        $xpath = new DOMXPath($doc);

        // Find every <note> that has <notations><technical><fret>
        $notes = $xpath->query('//note');
        foreach ($notes as $noteNode) {
            /** @var DOMElement $noteNode */

            // Skip grace notes (they have a <grace> child)
            if ($xpath->query('grace', $noteNode)->length > 0) {
                continue;
            }
            // Skip rests
            if ($xpath->query('rest', $noteNode)->length > 0) {
                continue;
            }

            // ── Fret ────────────────────────────────────────────────────────
            $fretNodes = $xpath->query('notations/technical/fret', $noteNode);
            foreach ($fretNodes as $fretNode) {
                $oldFret = (int) $fretNode->nodeValue;
                $newFret = max(0, min(24, $oldFret + $semitones));
                $fretNode->nodeValue = (string) $newFret;
            }

            // ── Pitch ───────────────────────────────────────────────────────
            // Pitch is used for playback/MIDI export only; transpose it to keep
            // the file consistent even though the chord SYMBOLS come from json_data.
            $pitchNodes = $xpath->query('pitch', $noteNode);
            if ($pitchNodes->length > 0) {
                /** @var DOMElement $pitchEl */
                $pitchEl = $pitchNodes->item(0);

                $stepNodes  = $xpath->query('step',   $pitchEl);
                $alterNodes = $xpath->query('alter',  $pitchEl);
                $octaveNodes = $xpath->query('octave', $pitchEl);

                if ($stepNodes->length > 0 && $octaveNodes->length > 0) {
                    $step   = $stepNodes->item(0)->nodeValue;
                    $alter  = $alterNodes->length > 0 ? (int) $alterNodes->item(0)->nodeValue : 0;
                    $octave = (int) $octaveNodes->item(0)->nodeValue;

                    // Decode MusicXML step+alter into the note-name key used by NOTE_TO_SEMI.
                    $noteKey = $step . ($alter === 1 ? '#' : ($alter === -1 ? 'b' : ''));
                    $result  = self::transposePitchStep($noteKey, $octave, $semitones, $targetKey);

                    if ($result !== null) {
                        [$newNote, $newOctave] = $result;

                        // Decode new note into MusicXML step + alter
                        $newAlter = 0;
                        if (strlen($newNote) === 2) {
                            $newAlter = $newNote[1] === '#' ? 1 : -1;
                            $newStep  = $newNote[0];
                        } else {
                            $newStep = $newNote;
                        }

                        $stepNodes->item(0)->nodeValue = $newStep;
                        $octaveNodes->item(0)->nodeValue = (string) $newOctave;

                        // <alter> element — create/update/remove as needed
                        if ($newAlter !== 0) {
                            if ($alterNodes->length > 0) {
                                $alterNodes->item(0)->nodeValue = (string) $newAlter;
                            } else {
                                // Insert <alter> after <step>
                                $alterEl = $doc->createElement('alter', (string) $newAlter);
                                $stepNode = $stepNodes->item(0);
                                $stepNode->parentNode->insertBefore($alterEl, $stepNode->nextSibling);
                            }
                        } else {
                            // Remove any existing <alter> element (natural note)
                            if ($alterNodes->length > 0) {
                                $alterNodes->item(0)->parentNode->removeChild($alterNodes->item(0));
                            }
                        }
                    }
                }
            }
        }

        // Serialize — preserve the XML declaration if present in the original.
        $out = $doc->saveXML();
        if ($out === false) {
            return $xml;
        }
        return $out;
    }

    // -------------------------------------------------------------------------
    // Chord-name helpers (used by the controller for json_data)
    // -------------------------------------------------------------------------

    /**
     * Transpose a single chord name string by $semitones, re-spelling against $targetKey.
     * Delegates to HarmonicContext::reSpellChordName after a chromatic root shift.
     *
     * @param  string $name      e.g. 'Cmaj7', 'G7', 'Fm7/Bb'
     * @param  int    $semitones
     * @param  string $targetKey  for spelling
     * @return string
     */
    public static function transposeChordName(string $name, int $semitones, string $targetKey): string
    {
        if (trim($name) === '' || $semitones === 0) {
            return $name;
        }

        // Parse: root + quality + optional /bass
        if (!preg_match('/^([A-G][#b]?)(.*?)(?:\/([A-G][#b]?))?$/', $name, $m)) {
            return $name;
        }

        [, $root, $body, $bass] = $m + [3 => ''];

        $shiftedRoot = self::shiftNote($root, $semitones);
        $result      = $shiftedRoot . $body;

        if ($bass !== '') {
            $shiftedBass = self::shiftNote($bass, $semitones);
            $result .= '/' . $shiftedBass;
        }

        // Now re-spell via the enharmonic authority
        return HarmonicContext::reSpellChordName($result, $targetKey);
    }

    /**
     * Transpose a key string by $semitones (e.g. "C" → "D", "Bbm" → "Cm", "A minor" → "B minor").
     * Spelling is applied by HarmonicContext for the final note.
     */
    public static function transposeKey(string $key, int $semitones): string
    {
        if (trim($key) === '' || $semitones === 0) {
            return $key;
        }

        // Handle "A minor" form
        $parts = explode(' ', trim($key), 2);
        $tonic = $parts[0];
        $suffix = isset($parts[1]) ? ' ' . $parts[1] : '';

        // Handle trailing 'm' for minor (e.g. "Am", "Bbm")
        $minor = false;
        $minorSuffix = '';
        if (str_ends_with($tonic, 'm') && strlen($tonic) <= 3) {
            $minor = true;
            $minorSuffix = 'm';
            $tonic = substr($tonic, 0, -1);
        }

        $shifted = self::shiftNote($tonic, $semitones);

        // Re-spell the key root.  A minor key = use flats for the same set as the
        // relative major (e.g. Am → no accidentals → flat camp; Em → sharp camp).
        // We need to pick the correct enharmonic: construct the candidate key and
        // check spellingUsesFlats to decide which enharmonic to use.
        // For major keys: try the flat version if the sharp one sounds weird.
        $semi = self::NOTE_TO_SEMI[self::shiftNote($tonic, $semitones, true)] ?? null;
        if ($semi !== null) {
            $tryKey = self::SEMI_TO_NOTE_FLAT[$semi] . $minorSuffix;
            if (HarmonicContext::spellingUsesFlats($tryKey)) {
                $shifted = self::SEMI_TO_NOTE_FLAT[$semi];
            } else {
                $shifted = self::SEMI_TO_NOTE_SHARP[$semi];
            }
        }

        return $shifted . $minorSuffix . $suffix;
    }

    /**
     * Transpose a 6-char voicing frets string (hex digits + 'x') by semitones.
     * Octave-folds the whole voicing until all frets are in 0..24.
     * Returns the transposed frets string (same length).
     *
     * @param  string $frets  e.g. "x32010"
     * @param  int    $semitones
     * @return string
     */
    public static function transposeVoicingFrets(string $frets, int $semitones): string
    {
        if ($semitones === 0 || strlen($frets) === 0) {
            return $frets;
        }

        $decoded = [];
        foreach (str_split($frets) as $ch) {
            $decoded[] = ($ch === 'x' || $ch === 'X') ? null : hexdec($ch);
        }

        // Shift all fretted strings
        $shifted = array_map(
            fn($f) => ($f === null) ? null : $f + $semitones,
            $decoded
        );

        // Octave-fold until all fretted strings are in 0..24
        $fretted = fn() => array_filter($shifted, fn($f) => $f !== null);
        $guard = 0;
        while (count($fretted()) > 0 && $guard++ < 4) {
            $vals = array_values($fretted());
            $hi   = max($vals);
            $lo   = min($vals);
            if ($hi > 24) {
                $shifted = array_map(fn($f) => $f !== null ? $f - 12 : null, $shifted);
            } elseif ($lo < 0) {
                $shifted = array_map(fn($f) => $f !== null ? $f + 12 : null, $shifted);
            } else {
                break;
            }
        }

        // Encode back to hex/x
        $out = '';
        foreach ($shifted as $i => $f) {
            if ($f === null) {
                $out .= $decoded[$i] === null ? ($frets[$i] ?? 'x') : 'x';
            } else {
                $clamped = max(0, min(24, $f));
                $out .= dechex($clamped);
            }
        }
        return $out;
    }

    /**
     * Compute the position (lowest non-zero fret, min 1) from a frets string.
     * Mirrors JS fretsToPosition().
     */
    public static function fretsToPosition(string $frets): int
    {
        $nonzero = [];
        foreach (str_split($frets) as $ch) {
            if ($ch !== 'x' && $ch !== 'X') {
                $f = hexdec($ch);
                if ($f > 0) $nonzero[] = $f;
            }
        }
        return count($nonzero) > 0 ? min($nonzero) : 1;
    }

    /**
     * Transpose a pitch (note name + octave) by $semitones, re-spelling for $targetKey.
     *
     * This is the shared pitch-math core used by both the MusicXML DOM walk and the
     * json_data melody array walk.  Input $noteKey is a combined note string like
     * 'C', 'C#', 'Db', 'Bb' etc. (letter + optional '#' or 'b').  $octave is the
     * integer octave (MusicXML convention: C4 = middle C).
     *
     * Returns [newNoteKey, newOctave] on success, or null if $noteKey is unrecognised.
     * The returned newNoteKey is spelled appropriately for $targetKey (flats or sharps).
     */
    public static function transposePitchStep(string $noteKey, int $octave, int $semitones, string $targetKey): ?array
    {
        $semi = self::NOTE_TO_SEMI[$noteKey] ?? null;
        if ($semi === null) {
            return null;
        }

        $useFlats  = HarmonicContext::spellingUsesFlats($targetKey);
        $absOld    = $octave * 12 + $semi;
        $absNew    = $absOld + $semitones;
        $newOctave = (int) floor($absNew / 12);
        $newSemi   = (($absNew % 12) + 12) % 12;

        $newNote = $useFlats
            ? self::SEMI_TO_NOTE_FLAT[$newSemi]
            : self::SEMI_TO_NOTE_SHARP[$newSemi];

        return [$newNote, $newOctave];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Shift a note name (e.g. 'C', 'Bb', 'F#') by semitones.
     * Returns the sharp spelling by default (caller re-spells via HarmonicContext).
     *
     * @param  bool $rawSharp  Return the raw CHROMA sharp name (for key detection)
     */
    private static function shiftNote(string $note, int $semitones, bool $rawSharp = false): string
    {
        $semi = self::NOTE_TO_SEMI[$note] ?? null;
        if ($semi === null) return $note;
        $newSemi = (($semi + $semitones) % 12 + 12) % 12;
        return $rawSharp ? self::SEMI_TO_NOTE_SHARP[$newSemi] : self::SEMI_TO_NOTE_SHARP[$newSemi];
    }
}

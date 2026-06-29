<?php

namespace App\Services;

/**
 * VoicingMaterializer
 *
 * Converts selected chord voicings into MusicXML and melody data structures.
 * Extracted from LeadsheetController::applyProgression in Phase L.
 */
class VoicingMaterializer
{
    protected RhythmMaterializer $rhythmMaterializer;

    public function __construct(RhythmMaterializer $rhythmMaterializer)
    {
        $this->rhythmMaterializer = $rhythmMaterializer;
    }

    /**
     * Build the tab_xml and melody from a sequence of chord selections.
     *
     * @param array $selections [['chord_name' => 'Dm7', 'frets' => 'x57565', 'position' => 5], ...]
     * @param string $timeSignature '4/4'
     * @param mixed|null $rhythm Optional rhythm pattern
     * @return array{tab_xml: string, melody: array, voicings: array, measures: int}
     */
    public function materialize(array $selections, string $timeSignature = '4/4', $rhythm = null): array
    {
        if (empty($selections)) {
            return [
                'tab_xml' => null,
                'melody' => [],
                'voicings' => [],
                'measures' => 0,
            ];
        }

        [$beats, $beatType] = array_map('intval', explode('/', $timeSignature) + [4, 4]);
        $divisions = 480;
        $tpm       = $divisions * $beats * (4 / $beatType); // ticks per measure

        $measuresXml   = '';
        $chordVoicings = [];
        $melody        = [];         
        $measureCount  = 0;

        // Group selections by measure_index if available, else flat 1-chord-per-bar
        $grouped = [];
        $hasExplicitMeasures = isset($selections[0]['measure_index']);

        if ($hasExplicitMeasures) {
            foreach ($selections as $sel) {
                $mIdx = $sel['measure_index'];
                $grouped[$mIdx][] = $sel;
            }
            ksort($grouped);
        } else {
            foreach ($selections as $idx => $sel) {
                $grouped[$idx] = [$sel];
            }
        }

        // ── Pattern bar length ────────────────────────────────────────────────
        // Derive how many bars the rhythm pattern spans so we can group measures
        // into windows and call expand() once per window.
        $patternBars = 1;
        if ($rhythm) {
            $gridType  = $rhythm->grid_type ?? 'sixteenth';
            $stepBeats = match ($gridType) {
                'eighth'   => 0.5,
                'triplet'  => 1.0 / 3.0,
                default    => 0.25,
            };
            $patTimeSig = $rhythm->time_signature ?? '4/4';
            [$patB, $patBT] = array_map('intval', explode('/', $patTimeSig) + [4, 4]);
            $stepsPerBar = (int) round(($patB * (4 / $patBT)) / $stepBeats);
            $patternLen  = max(strlen($rhythm->rhythm_pattern ?? ''), strlen($rhythm->thumb_pattern ?? ''));
            if ($stepsPerBar > 0 && $patternLen > $stepsPerBar) {
                $patternBars = (int) ceil($patternLen / $stepsPerBar);
            }
        }

        $prevFingerStrings = [];
        $groupedKeys       = array_keys($grouped);
        $totalMeasures     = count($groupedKeys);
        $windowStart       = 0;

        while ($windowStart < $totalMeasures) {
            // Collect up to $patternBars measures into this window
            $windowSize   = min($patternBars, $totalMeasures - $windowStart);
            $windowMIdxs  = array_slice($groupedKeys, $windowStart, $windowSize);

            // ── Build voicingsByBar for expand() ─────────────────────────────
            // Each bar in the window contributes its first (or only) chord's voicing.
            // Multiple chords within a single bar still share one voicing slot for the
            // rhythm pattern; their tick windows are filtered when emitting notes below.
            $voicingsByBar = [];
            foreach ($windowMIdxs as $barPos => $mIdx) {
                $firstSel = $grouped[$mIdx][0] ?? null;
                if ($firstSel && !empty($firstSel['frets']) && strlen($firstSel['frets']) === 6) {
                    $voicingsByBar[$barPos] = [
                        'frets'    => $firstSel['frets'],
                        'position' => (int) ($firstSel['position'] ?? 1),
                    ];
                }
            }

            // Expand the full window in one call (empty voicingsByBar = no rhythm for this window)
            $windowStrokes = [];
            if ($rhythm && !empty($voicingsByBar)) {
                $windowStrokes = $this->rhythmMaterializer->expand(
                    $voicingsByBar,
                    $rhythm,
                    $divisions,
                    (int) ($tpm / $divisions),
                    $prevFingerStrings
                );
                // Carry voice-leading state to the next window
                foreach ($windowStrokes as $stroke) {
                    if (!empty($stroke['finger_strings'])) {
                        $prevFingerStrings = $stroke['finger_strings'];
                        break;
                    }
                }
            }

            // ── Emit one XML measure per bar in the window ───────────────────
            foreach ($windowMIdxs as $barPos => $mIdx) {
                $mChords    = $grouped[$mIdx];
                $measureNum = $mIdx + 1;
                $globalTick = $mIdx * $tpm;
                $measureCount++;

                $attrs = ($measureNum === 1)
                    ? '<attributes>'
                        . '<divisions>' . $divisions . '</divisions>'
                        . '<key><fifths>0</fifths><mode>major</mode></key>'
                        . '<time><beats>' . $beats . '</beats><beat-type>' . $beatType . '</beat-type></time>'
                        . '<staves>1</staves><clef><sign>TAB</sign></clef>'
                        . '</attributes>'
                    : '';

                $notesXml   = '';
                $harmonyXml = '';

                $chordCount    = count($mChords);
                $ticksPerChord = (int) ($tpm / $chordCount);

                // Tick offset of this bar within the window (for stroke filtering)
                $barTickStart = $barPos * $tpm;

                foreach ($mChords as $cIdx => $sel) {
                    $chordName   = $sel['chord_name']  ?? '';
                    $frets       = $sel['frets']       ?? null;
                    $position    = (int) ($sel['position'] ?? 1);
                    $diagramData = $sel['diagram_data'] ?? null;
                    $chordTick   = $globalTick + ($cIdx * $ticksPerChord);

                    // Derive fingers string from diagram_data positions
                    $fingers = null;
                    if ($diagramData && !empty($diagramData['positions'])) {
                        $f = ['0','0','0','0','0','0'];
                        foreach ($diagramData['positions'] as $pos) {
                            $s = ($pos['string'] ?? 0) - 1;
                            if ($s >= 0 && $s < 6 && !empty($pos['finger']) && $pos['finger'] !== '0') {
                                $f[$s] = (string) $pos['finger'];
                            }
                        }
                        $fStr = implode('', $f);
                        if (!preg_match('/^0+$/', $fStr)) {
                            $fingers = $fStr;
                        }
                    }

                    // Use unique key for the Chord Editor if indices are available
                    $voicingKey = $hasExplicitMeasures
                        ? "{$chordName}@{$mIdx}.{$cIdx}"
                        : $chordName;

                    if ($frets && strlen($frets) === 6) {
                        $entry = [
                            'frets'      => $frets,
                            'position'   => $position,
                            'start_fret' => $position,
                        ];
                        if ($fingers && strlen($fingers) === 6 && !preg_match('/^0+$/', $fingers)) {
                            $entry['fingers'] = $fingers;
                        }
                        $chordVoicings[$voicingKey] = $entry;
                        if (!isset($chordVoicings[$chordName])) {
                            $chordVoicings[$chordName] = $entry;
                        }
                    }

                    $harmonyXml .= '<harmony>'
                        . '<root><root-step>' . htmlspecialchars(substr($chordName, 0, 1)) . '</root-step></root>'
                        . '<kind text="' . htmlspecialchars($chordName) . '">other</kind>'
                        . '</harmony>';

                    if ($rhythm && $frets && strlen($frets) === 6 && !empty($windowStrokes)) {
                        // Chord's window within this bar (for multi-chord bars)
                        $chordOffsetStart = $barTickStart + ($cIdx * $ticksPerChord);
                        $chordOffsetEnd   = $chordOffsetStart + $ticksPerChord;

                        foreach ($windowStrokes as $stroke) {
                            $to = $stroke['tickOffset'];
                            if ($to < $chordOffsetStart || $to >= $chordOffsetEnd) continue;

                            // Use the frets baked into the stroke (correct chord for this tick)
                            $strokeFrets    = $stroke['frets'] ?? $frets;
                            $strokeTick     = $globalTick + ($to - $barTickStart);
                            $durTicksStroke = $stroke['durTicks'];
                            $durNameStroke  = $stroke['durName'];

                            $durTypeStroke = 'sixteenth';
                            if ($durNameStroke === 'w') $durTypeStroke = 'whole';
                            elseif ($durNameStroke === 'h') $durTypeStroke = 'half';
                            elseif ($durNameStroke === 'q') $durTypeStroke = 'quarter';
                            elseif ($durNameStroke === 'e') $durTypeStroke = 'eighth';

                            $first = true;
                            foreach ($stroke['strings'] as $tabString) {
                                $di   = 6 - $tabString;
                                $ch   = $strokeFrets[$di] ?? '0';
                                $fret = ctype_digit($ch) ? (int) $ch : hexdec($ch);
                                $chordEl = $first ? '' : '<chord/>';
                                $first = false;

                                $notesXml .= '<note>'
                                    . $chordEl
                                    . '<pitch><step>E</step><octave>4</octave></pitch>'
                                    . '<duration>' . $durTicksStroke . '</duration>'
                                    . '<type>' . $durTypeStroke . '</type>'
                                    . '<voice>1</voice><staff>1</staff>'
                                    . '<notations><technical>'
                                    . '<string>' . $tabString . '</string>'
                                    . '<fret>' . $fret . '</fret>'
                                    . '</technical></notations>'
                                    . '</note>';

                                $melody[] = [
                                    'tick'        => $strokeTick,
                                    'pitch'       => null,
                                    'octave'      => null,
                                    'duration'    => $durNameStroke,
                                    'ticks'       => $durTicksStroke,
                                    'tieStart'    => false,
                                    'tieStop'     => false,
                                    'voice'       => 1,
                                    'string'      => $tabString,
                                    'fret'        => $fret,
                                    'isChordNote' => ($tabString !== reset($stroke['strings'])),
                                    'isRest'      => false,
                                    'beam1'       => null,
                                    'beam2'       => null,
                                ];
                            }
                        }
                    } elseif (!$rhythm || empty($windowStrokes)) {
                        // Simple whole-note/half-note per chord (no rhythm)
                        if ($frets && strlen($frets) === 6) {
                            $first = true;
                            for ($di = 0; $di < 6; $di++) {
                                $ch = $frets[$di];
                                if ($ch === 'x' || $ch === 'X') continue;
                                $fret      = ctype_digit($ch) ? (int) $ch : hexdec($ch);
                                $tabString = 6 - $di;
                                $chordEl   = $first ? '' : '<chord/>';

                                $notesXml .= '<note>'
                                    . $chordEl
                                    . '<pitch><step>E</step><octave>4</octave></pitch>'
                                    . '<duration>' . $ticksPerChord . '</duration>'
                                    . '<type>' . ($chordCount === 1 ? 'whole' : 'half') . '</type>'
                                    . '<voice>1</voice><staff>1</staff>'
                                    . '<notations><technical>'
                                    . '<string>' . $tabString . '</string>'
                                    . '<fret>' . $fret . '</fret>'
                                    . '</technical></notations>'
                                    . '</note>';

                                $melody[] = [
                                    'tick'        => $chordTick,
                                    'pitch'       => null,
                                    'octave'      => null,
                                    'duration'    => $chordCount === 1 ? 'w' : 'h',
                                    'ticks'       => $ticksPerChord,
                                    'tieStart'    => false,
                                    'tieStop'     => false,
                                    'voice'       => 1,
                                    'string'      => $tabString,
                                    'fret'        => $fret,
                                    'isChordNote' => !$first,
                                    'isRest'      => false,
                                    'beam1'       => null,
                                    'beam2'       => null,
                                ];
                                $first = false;
                            }
                        }
                    }
                }

                if (empty($notesXml)) {
                    $notesXml = '<note><rest/><duration>' . $tpm . '</duration>'
                        . '<type>whole</type><voice>1</voice><staff>1</staff></note>';
                    $melody[] = [
                        'tick'     => $globalTick,
                        'duration' => 'w',
                        'ticks'    => $tpm,
                        'voice'    => 1,
                        'isRest'   => true,
                    ];
                }

                $measuresXml .= '<measure number="' . $measureNum . '">'
                    . $attrs . $harmonyXml . $notesXml
                    . '</measure>';
            }

            $windowStart += $windowSize;
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<score-partwise version="3.1">'
            . '<part-list><score-part id="P1"><part-name>Guitar</part-name></score-part></part-list>'
            . '<part id="P1">' . $measuresXml . '</part>'
            . '</score-partwise>';

        return [
            'tab_xml' => $xml,
            'melody' => $melody,
            'voicings' => $chordVoicings,
            'measures' => $measureCount,
        ];
    }
}

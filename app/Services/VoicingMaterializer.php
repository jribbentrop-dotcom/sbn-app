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

        foreach ($grouped as $mIdx => $mChords) {
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

            $notesXml = '';
            $harmonyXml = '';
            
            $chordCount = count($mChords);
            $ticksPerChord = (int) ($tpm / $chordCount);

            foreach ($mChords as $cIdx => $sel) {
                $chordName = $sel['chord_name'] ?? '';
                $frets     = $sel['frets']      ?? null;
                $position  = (int) ($sel['position'] ?? 1);
                $chordTick = $globalTick + ($cIdx * $ticksPerChord);

                // Use unique key for the Chord Editor if indices are available
                $voicingKey = ($hasExplicitMeasures) 
                    ? "{$chordName}@{$mIdx}.{$cIdx}" 
                    : $chordName;
                
                if ($frets && strlen($frets) === 6) {
                    $chordVoicings[$voicingKey] = [
                        'frets'      => $frets, 
                        'position'   => $position,
                        'start_fret' => $position, // Alias for frontend compatibility
                    ];
                    
                    // Fallback: also store under the generic chord name
                    if (!isset($chordVoicings[$chordName])) {
                        $chordVoicings[$chordName] = [
                            'frets'      => $frets, 
                            'position'   => $position,
                            'start_fret' => $position, // Alias for frontend compatibility
                        ];
                    }
                }

                $harmonyXml .= '<harmony>'
                    . '<root><root-step>' . htmlspecialchars(substr($chordName, 0, 1)) . '</root-step></root>'
                    . '<kind text="' . htmlspecialchars($chordName) . '">other</kind>'
                    . '</harmony>';

                if ($rhythm && $frets && strlen($frets) === 6) {
                    // Note: Rhythm expander currently assumes full bar. 
                    // For multiple chords per bar, we either need to scale or just use the first chord's rhythm.
                    // For now, we'll split the bar's rhythm pulses among the chords.
                    $strokes = $this->rhythmMaterializer->expand(
                        ['frets' => $frets, 'position' => $position],
                        $rhythm,
                        $divisions,
                        $beats
                    );

                    if (!empty($strokes)) {
                        // Filter strokes to fit in this chord's window within the measure
                        $startOffset = $cIdx * $ticksPerChord;
                        $endOffset   = ($cIdx + 1) * $ticksPerChord;

                        foreach ($strokes as $stroke) {
                            if ($stroke['tickOffset'] < $startOffset || $stroke['tickOffset'] >= $endOffset) continue;

                            $strokeTick = $globalTick + $stroke['tickOffset'];
                            $durTicksStroke = $stroke['durTicks'];
                            $durNameStroke = $stroke['durName'];
                            
                            $durTypeStroke = 'sixteenth';
                            if ($durNameStroke === 'w') $durTypeStroke = 'whole';
                            elseif ($durNameStroke === 'h') $durTypeStroke = 'half';
                            elseif ($durNameStroke === 'q') $durTypeStroke = 'quarter';
                            elseif ($durNameStroke === 'e') $durTypeStroke = 'eighth';

                            $first = true;
                            foreach ($stroke['strings'] as $tabString) {
                                $di = 6 - $tabString;
                                $ch = $frets[$di] ?? '0';
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
                    }
                } else {
                    // Simple whole-note/half-note per chord (Pass 1)
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

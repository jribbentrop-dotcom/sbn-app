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

        $measuresXml  = '';
        $chordVoicings = [];
        $melody        = [];         // json_data.melody — consumed by useTabModel on load
        $measureNum    = 1;
        $globalTick    = 0;

        foreach ($selections as $sel) {
            $chordName = $sel['chord_name'] ?? '';
            $frets     = $sel['frets']      ?? null;
            $position  = (int) ($sel['position'] ?? 1);

            $harmonyXml = '<harmony>'
                . '<root><root-step>' . htmlspecialchars(substr($chordName, 0, 1)) . '</root-step></root>'
                . '<kind text="' . htmlspecialchars($chordName) . '">other</kind>'
                . '</harmony>';

            $notesXml = '';

            if ($rhythm && $frets && strlen($frets) === 6) {
                $chordVoicings[$chordName] = ['frets' => $frets, 'position' => $position];

                $strokes = $this->rhythmMaterializer->expand(
                    ['frets' => $frets, 'position' => $position],
                    $rhythm,
                    $divisions,
                    $beats
                );

                if (!empty($strokes)) {
                    foreach ($strokes as $stroke) {
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
                } else {
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

            } else {
                if ($frets && strlen($frets) === 6) {
                    $chordVoicings[$chordName] = ['frets' => $frets, 'position' => $position];

                    $first        = true;
                    $isChordNote  = false;
                    for ($di = 0; $di < 6; $di++) {
                        $ch = $frets[$di];
                        if ($ch === 'x' || $ch === 'X') continue;
                        $fret      = ctype_digit($ch) ? (int) $ch : hexdec($ch);
                        $tabString = 6 - $di;
                        $chordEl   = $first ? '' : '<chord/>';
                        $first     = false;

                        $notesXml .= '<note>'
                            . $chordEl
                            . '<pitch><step>E</step><octave>4</octave></pitch>'
                            . '<duration>' . $tpm . '</duration>'
                            . '<type>whole</type>'
                            . '<voice>1</voice><staff>1</staff>'
                            . '<notations><technical>'
                            . '<string>' . $tabString . '</string>'
                            . '<fret>' . $fret . '</fret>'
                            . '</technical></notations>'
                            . '</note>';

                        $melody[] = [
                            'tick'        => $globalTick,
                            'pitch'       => null,
                            'octave'      => null,
                            'duration'    => 'w',
                            'ticks'       => $tpm,
                            'tieStart'    => false,
                            'tieStop'     => false,
                            'voice'       => 1,
                            'string'      => $tabString,
                            'fret'        => $fret,
                            'isChordNote' => $isChordNote,
                            'isRest'      => false,
                            'beam1'       => null,
                            'beam2'       => null,
                        ];
                        $isChordNote = true;
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
            }

            $attrs = $measureNum === 1
                ? '<attributes>'
                    . '<divisions>' . $divisions . '</divisions>'
                    . '<key><fifths>0</fifths><mode>major</mode></key>'
                    . '<time><beats>' . $beats . '</beats><beat-type>' . $beatType . '</beat-type></time>'
                    . '<staves>1</staves><clef><sign>TAB</sign></clef>'
                    . '</attributes>'
                : '';

            $measuresXml .= '<measure number="' . $measureNum . '">'
                . $attrs . $harmonyXml . $notesXml
                . '</measure>';

            $globalTick += $tpm;
            $measureNum++;
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
            'measures' => $measureNum - 1,
        ];
    }
}

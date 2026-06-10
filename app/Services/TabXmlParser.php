<?php

namespace App\Services;

/**
 * TabXmlParser
 *
 * Parses a MusicXML 3.1 partwise string (sbn_leadsheets.tab_xml) into the
 * measure/event array expected by render-tab.cjs.
 *
 * Chord names come from shortcode_content / json_data — not from tab_xml
 * (which only contains TAB notes). The caller may inject chordNames per measure.
 */
class TabXmlParser
{
    private int $divisions = 480;

    /**
     * @param  string  $xml           Content of tab_xml column
     * @param  array   $chordNames    Optional per-measure chord name arrays,
     *                                keyed by measure index (0-based).
     *                                e.g. [0 => ['Dm7'], 1 => ['G7', 'Cmaj7']]
     */
    public function parse(string $xml, array $chordNames = []): array
    {
        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        if (! $doc) {
            return ['timeSig' => '4/4', 'measures' => []];
        }

        $timeSig    = '4/4';
        $globalTick = 0;
        $measureIdx = 0;
        $measures   = [];

        // MusicXML partwise: <score-partwise><part id="P1"><measure number="1">...
        $parts = $doc->part;
        $part  = $parts[0] ?? $doc;

        foreach ($part->measure as $mNode) {
            $tpm = $this->ticksPerMeasure($timeSig);

            // <attributes> may update divisions / time signature
            if (isset($mNode->attributes[0])) {
                $attrs = $mNode->attributes[0];
                if (isset($attrs->divisions)) {
                    $this->divisions = (int) $attrs->divisions;
                }
                if (isset($attrs->time)) {
                    $beats    = (int) ($attrs->time->beats ?? 4);
                    $beatType = (int) ($attrs->time->{'beat-type'} ?? 4);
                    $timeSig  = $beats . '/' . $beatType;
                    $tpm      = $this->ticksPerMeasure($timeSig);
                }
            }

            $events        = [];
            $eventCounter  = 0;
            $currentTick   = $globalTick;
            $pendingNotes  = null;   // current chord event being built
            $repeatStart   = false;
            $repeatEnd     = false;

            foreach ($mNode->note as $noteNode) {
                $isChord  = isset($noteNode->chord);
                $durTicks = $this->durationTicks($noteNode, $tpm);
                $isRest   = isset($noteNode->rest);
                $voice    = (int) ($noteNode->voice ?? 1);

                $noteData = null;
                if (! $isRest && isset($noteNode->notations->technical)) {
                    $tech   = $noteNode->notations->technical;
                    $str    = isset($tech->string)  ? (int) $tech->string  : null;
                    $fret   = isset($tech->fret)    ? (int) $tech->fret    : null;
                    if ($str !== null && $fret !== null) {
                        $noteData = ['string' => $str, 'fret' => $fret, 'tieStart' => false, 'tieEndEvent' => null];
                    }
                }

                if ($isChord && $pendingNotes !== null) {
                    // Add to current event
                    if ($noteData) {
                        $pendingNotes['notes'][] = $noteData;
                    }
                } else {
                    // Flush previous event
                    if ($pendingNotes !== null) {
                        $events[] = $this->finalizeEvent($pendingNotes, $tpm, $globalTick);
                    }

                    $tickInMeasure = $currentTick - $globalTick;
                    $pendingNotes = [
                        'id'            => 'te_' . $measureIdx . '_' . ($eventCounter++),
                        'tick'          => $currentTick,
                        'tickInMeasure' => $tickInMeasure,
                        'ticks'         => $durTicks,
                        'xPos'          => $tpm > 0 ? $tickInMeasure / $tpm : 0.0,
                        'isRest'        => $isRest,
                        'voice'         => $voice,
                        'stemDir'       => $voice === 2 ? 'up' : 'down',
                        'flagCount'     => $this->flagCount($durTicks),
                        'notes'         => $noteData ? [$noteData] : [],
                        'beamWith'      => null,
                        'beamStart'     => false,
                        'beamContinue'  => false,
                        'beamEnd'       => false,
                        'noBeamBar'     => false,
                        'tupletActual'  => null,
                        'tupletBracket' => false,
                    ];

                    $currentTick += $durTicks;
                }
            }

            if ($pendingNotes !== null) {
                $events[] = $this->finalizeEvent($pendingNotes, $tpm, $globalTick);
            }

            // Barline repeat markers
            foreach ($mNode->barline as $bl) {
                $loc = (string) ($bl['location'] ?? '');
                $rpt = (string) ($bl->repeat['direction'] ?? '');
                if ($rpt === 'forward')  $repeatStart = true;
                if ($rpt === 'backward') $repeatEnd   = true;
            }

            $measures[] = [
                'index'       => $measureIdx,
                'chordNames'  => $chordNames[$measureIdx] ?? [],
                'events'      => $events,
                'repeatStart' => $repeatStart,
                'repeatEnd'   => $repeatEnd,
                'volta'       => null,
                'pickupBeats' => null,
                'actualTicks' => null,
            ];

            $globalTick += $tpm;
            $measureIdx++;
        }

        return [
            'timeSig'  => $timeSig,
            'measures' => $measures,
        ];
    }

    // -------------------------------------------------------------------------

    private function ticksPerMeasure(string $timeSig): int
    {
        [$beats, $beatType] = array_map('intval', explode('/', $timeSig) + [4, 4]);
        return (int) (480 * $beats * (4 / max(1, $beatType)));
    }

    private function durationTicks(\SimpleXMLElement $noteNode, int $tpm): int
    {
        $dur = (int) ($noteNode->duration ?? 480);
        if ($this->divisions !== 480 && $this->divisions > 0) {
            $dur = (int) round($dur * 480 / $this->divisions);
        }
        // Clamp to measure capacity so overfill doesn't break xPos
        return max(1, min($dur, $tpm));
    }

    private function flagCount(int $ticks): int
    {
        $base = $this->baseDuration($ticks);
        if ($base <= 60)  return 3;
        if ($base <= 120) return 2;
        if ($base <= 240) return 1;
        return 0;
    }

    private function baseDuration(int $ticks): int
    {
        return match ($ticks) {
            1440 => 960, 720 => 480, 360 => 240, 180 => 120,
            640  => 960, 320 => 240, 160 => 160, 80  => 80,
            default => $ticks,
        };
    }

    private function finalizeEvent(array $ev, int $tpm, int $globalTick): array
    {
        $ev['xPos'] = $tpm > 0 ? ($ev['tickInMeasure'] / $tpm) : 0.0;
        return $ev;
    }
}

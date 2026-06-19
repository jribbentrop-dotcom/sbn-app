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

                // Read <tie type="start/stop"> elements
                $tieStart = false;
                $tieStop  = false;
                foreach ($noteNode->tie as $tieNode) {
                    $ttype = (string) ($tieNode['type'] ?? '');
                    if ($ttype === 'start') $tieStart = true;
                    if ($ttype === 'stop')  $tieStop  = true;
                }

                $noteData = null;
                if (! $isRest && isset($noteNode->notations->technical)) {
                    $tech   = $noteNode->notations->technical;
                    $str    = isset($tech->string)  ? (int) $tech->string  : null;
                    $fret   = isset($tech->fret)    ? (int) $tech->fret    : null;
                    if ($str !== null && $fret !== null) {
                        $noteData = ['string' => $str, 'fret' => $fret, 'tieStart' => $tieStart, 'tieStop' => $tieStop, 'tieEndEvent' => null, 'tieEndNote' => null];
                    }
                }

                // Read MusicXML beam element for this note
                $beam1 = null;
                foreach ($noteNode->beam as $beamNode) {
                    if ((int) ($beamNode['number'] ?? 1) === 1) {
                        $beam1 = (string) $beamNode;
                        break;
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
                        'measureIdx'    => $measureIdx,
                        'beamWith'      => null,
                        'beamStart'     => false,
                        'beamContinue'  => false,
                        'beamEnd'       => false,
                        'noBeamBar'     => false,
                        'tupletActual'  => null,
                        'tupletBracket' => false,
                        '_beam1'        => $beam1,
                    ];

                    $currentTick += $durTicks;
                }
            }

            if ($pendingNotes !== null) {
                $events[] = $this->finalizeEvent($pendingNotes, $tpm, $globalTick);
            }

            // Derive event-level tie flags
            foreach ($events as &$ev) {
                $ev['tieStart'] = !$ev['isRest'] && collect($ev['notes'])->contains('tieStart', true);
                $ev['tieStop']  = !$ev['isRest'] && collect($ev['notes'])->contains('tieStop',  true);
            }
            unset($ev);

            // Build beamWith groups from _beam1 markers
            $events = $this->linkBeamGroups($events);

            // Barline repeat markers
            foreach ($mNode->barline as $bl) {
                $loc = (string) ($bl['location'] ?? '');
                $rpt = (string) ($bl->repeat['direction'] ?? '');
                if ($rpt === 'forward')  $repeatStart = true;
                if ($rpt === 'backward') $repeatEnd   = true;
            }

            // Compute actual ticks used in this measure (sum of non-chord note durations)
            $actualTicks = 0;
            foreach ($mNode->note as $noteNode) {
                if (!isset($noteNode->chord)) {
                    $actualTicks += $this->durationTicks($noteNode, $tpm);
                }
            }

            // Pickup bar: measure 1 with fewer ticks than a full measure
            $isPickup    = ($measureIdx === 0 && $actualTicks < $tpm && $actualTicks > 0);
            $pickupBeats = $isPickup ? ($actualTicks / ($tpm / ($this->beatsFromTimeSig($timeSig)))) : null;

            $measures[] = [
                'index'       => $measureIdx,
                'chordNames'  => $chordNames[$measureIdx] ?? [],
                'events'      => $events,
                'repeatStart' => $repeatStart,
                'repeatEnd'   => $repeatEnd,
                'volta'       => null,
                'pickup'      => $isPickup,
                'pickupBeats' => $pickupBeats,
                'actualTicks' => $actualTicks,
            ];

            $globalTick += $tpm;
            $measureIdx++;
        }

        // Link tie pairs across all measures: per voice, match tieStart note to
        // the next event on the same string with tieStop.
        $this->linkTies($measures);

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

    private function beatsFromTimeSig(string $timeSig): int
    {
        [$beats] = array_map('intval', explode('/', $timeSig) + [4, 4]);
        return max(1, $beats);
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

    private function linkBeamGroups(array $events): array
    {
        $group = [];

        $flush = function () use (&$group, &$events) {
            if (count($group) >= 2) {
                // Build the beamWith array (same structure as useTabModel.js)
                $refs = array_map(fn($e) => [
                    'id'           => $e['id'],
                    'xPos'         => $e['xPos'],
                    'stemDir'      => $e['stemDir'],
                    'voice'        => $e['voice'],
                    'ticks'        => $e['ticks'],
                    'isRest'       => $e['isRest'],
                    'measureIdx'   => $e['measureIdx'],
                    'noBeamBar'    => false,
                    'tupletActual' => null,
                ], $group);

                foreach ($group as $ev) {
                    foreach ($events as &$e) {
                        if ($e['id'] === $ev['id']) {
                            $e['beamWith'] = $refs;
                            break;
                        }
                    }
                    unset($e);
                }
            }
            $group = [];
        };

        foreach ($events as &$ev) {
            $beam1 = $ev['_beam1'] ?? null;
            unset($ev['_beam1']);

            if ($beam1 === 'begin') {
                $flush();
                $group = [&$ev];
            } elseif (($beam1 === 'continue' || $beam1 === 'end') && count($group)) {
                $group[] = &$ev;
                if ($beam1 === 'end') {
                    $flush();
                }
            } else {
                $flush();
            }
        }
        unset($ev);
        $flush();

        return $events;
    }

    /**
     * Cross-measure tie linking.
     * Collects all events across all measures into per-voice lists (sorted by tick),
     * then for each note with tieStart finds the next event on the same string
     * with tieStop and writes tieEndEvent/tieEndNote back into the measure arrays.
     */
    private function linkTies(array &$measures): void
    {
        // Build flat per-voice event list with measure references
        $byVoice = [];
        foreach ($measures as $mIdx => &$measure) {
            foreach ($measure['events'] as $eIdx => &$ev) {
                if ($ev['isRest']) continue;
                $v = $ev['voice'] ?? 1;
                if (!isset($byVoice[$v])) $byVoice[$v] = [];
                $byVoice[$v][] = [
                    'mIdx' => $mIdx,
                    'eIdx' => $eIdx,
                    'ev'   => &$ev,
                ];
            }
        }
        unset($measure, $ev);

        foreach ($byVoice as &$voiceList) {
            usort($voiceList, fn($a, $b) => $a['ev']['tick'] - $b['ev']['tick']);
            $count = count($voiceList);

            for ($i = 0; $i < $count; $i++) {
                $entry = &$voiceList[$i];
                $ev    = &$entry['ev'];

                foreach ($ev['notes'] as &$note) {
                    if (!($note['tieStart'] ?? false)) continue;

                    // Find next event on same string with tieStop
                    for ($j = $i + 1; $j < $count; $j++) {
                        $tEntry = &$voiceList[$j];
                        $tEv    = &$tEntry['ev'];

                        foreach ($tEv['notes'] as &$tNote) {
                            if (($tNote['tieStop'] ?? false) && $tNote['string'] === $note['string']) {
                                $note['tieEndEvent'] = [
                                    'xPos'       => $tEv['xPos'],
                                    'measureIdx' => $tEv['measureIdx'],
                                ];
                                $note['tieEndNote'] = ['string' => $tNote['string']];
                                break 2;
                            }
                        }
                        unset($tNote);
                    }
                    unset($tEntry, $tEv);
                }
                unset($note);
            }
            unset($entry, $ev);
        }
        unset($voiceList);
    }

    private function finalizeEvent(array $ev, int $tpm, int $globalTick): array
    {
        $ev['xPos'] = $tpm > 0 ? ($ev['tickInMeasure'] / $tpm) : 0.0;
        return $ev;
    }
}

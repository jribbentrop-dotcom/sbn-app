<?php

namespace App\Http\Controllers\Admin\Concerns;

use App\Models\Leadsheet;

/**
 * Shared read/serialize helpers used by the CRUD and transcription admin
 * controllers: chord-name normalization, the public leadsheet payload shape,
 * and backfilling finger positions onto voicings via ChordVoicingSearch
 * (requires the consuming class to expose a `$voicingSearch` property).
 */
trait SerializesLeadsheets
{
    /**
     * Walk a parsed leadsheet's chordVoicings map and, for any entry whose
     * fingers default to '000000', look up the canonical sbn_chord_diagrams
     * shape by name+frets and substitute the real fingerings.
     *
     * Pure read-through: never persists, never mutates the source row.
     */
    private function backfillFingersFromCrossref($parsed)
    {
        if (!is_array($parsed) || empty($parsed['chordVoicings']) || !is_array($parsed['chordVoicings'])) {
            return $parsed;
        }

        $cache = [];
        foreach ($parsed['chordVoicings'] as $key => &$voicing) {
            if (!is_array($voicing)) continue;
            $fingers = $voicing['fingers'] ?? '';
            if ($fingers !== '' && $fingers !== '000000') continue;

            $frets = $voicing['frets'] ?? '';
            if (strlen($frets) !== 6) continue;

            $name = preg_match('/^(.+)@\d+\.\d+$/', $key, $m) ? $m[1] : $key;

            if (!array_key_exists($name, $cache)) {
                try {
                    $cache[$name] = $this->voicingSearch->searchByName($name);
                } catch (\Throwable $e) {
                    $cache[$name] = [];
                }
            }
            $matches = $cache[$name];
            if (empty($matches)) continue;

            $canonical = $this->findCanonicalFingersForFrets($matches, $frets);
            if ($canonical !== null) {
                $voicing['fingers'] = $canonical;
            }
        }
        unset($voicing);

        return $parsed;
    }

    /**
     * Given a list of canonical matches (each carrying diagram_data) and a
     * 6-char fret string, return fingerings mapped onto the leadsheet's frets.
     *
     * Matching tiers (prefer earlier):
     *   1. exact     — canonical and leadsheet fret-strings are identical
     *   2. superset  — leadsheet has every canonical sounding string PLUS
     *                  one or more doubled notes (extra octaves on open or
     *                  barred strings). Extra string gets finger 0 if open,
     *                  or inherits the barre finger if its fret lies under
     *                  a canonical barre.
     *   3. subset    — leadsheet omits some canonical sounding strings.
     */
    private function findCanonicalFingersForFrets(array $matches, string $frets): ?string
    {
        $leadArr = $this->fretStringToArray($frets);
        $bestTier = 99;
        $bestFingers = null;

        foreach ($matches as $match) {
            $dd = $match['diagram_data'] ?? null;
            if (is_string($dd)) $dd = json_decode($dd, true);
            if (!is_array($dd)) continue;

            $canonFrets = $this->canonicalFretsFromDiagram($dd);
            $canonArr   = $this->fretStringToArray($canonFrets);

            $tier = $this->classifyFretMatch($leadArr, $canonArr);
            if ($tier === null || $tier >= $bestTier) continue;

            $fingers = $this->mapCanonicalFingersToLeadFrets($dd, $leadArr);
            if ($fingers === null) continue;

            $bestTier    = $tier;
            $bestFingers = $fingers;
            if ($tier === 0) break; // exact — can't do better
        }
        return $bestFingers;
    }

    /**
     * Compare two fret arrays (length 6, each entry 'x' or int 0..n).
     * Returns 0 (exact), 1 (superset — lead has extras), 2 (subset — lead omits),
     * or null (mismatch).
     */
    private function classifyFretMatch(array $lead, array $canon): ?int
    {
        if (count($lead) !== 6 || count($canon) !== 6) return null;

        $leadExtra = 0;
        $canonExtra = 0;
        for ($i = 0; $i < 6; $i++) {
            $l = $lead[$i];
            $c = $canon[$i];
            if ($l === 'x' && $c === 'x') continue;
            if ($l === 'x') { $canonExtra++; continue; }
            if ($c === 'x') { $leadExtra++; continue; }
            if (intval($l) !== intval($c)) return null;
        }
        if ($leadExtra === 0 && $canonExtra === 0) return 0;
        if ($leadExtra > 0 && $canonExtra === 0) return 1;
        if ($leadExtra === 0 && $canonExtra > 0) return 2;
        return null; // both sides have extras → too different
    }

    /**
     * Take the canonical diagram's fingerings and map them onto the leadsheet's
     * actual sounding strings. For extra leadsheet strings (superset case),
     * inherit a barre finger if the leadsheet fret lies on that barre, else 0.
     */
    private function mapCanonicalFingersToLeadFrets(array $dd, array $leadArr): ?string
    {
        $canonFingers = $this->canonicalFingersFromDiagram($dd);
        if (strlen($canonFingers) !== 6) return null;

        $out = ['0', '0', '0', '0', '0', '0'];
        for ($i = 0; $i < 6; $i++) {
            if ($leadArr[$i] === 'x' || $leadArr[$i] === 0 || $leadArr[$i] === '0') {
                $out[$i] = '0';
                continue;
            }
            $cf = $canonFingers[$i];
            if ($cf !== '0') { $out[$i] = $cf; continue; }

            // Lead string sounds but canonical was silent — try barre inheritance.
            $leadFret = intval($leadArr[$i]);
            foreach ($dd['barres'] ?? [] as $b) {
                if (($b['fret'] ?? 0) === $leadFret) {
                    $out[$i] = ($b['finger'] ?? 0) > 0 ? (string) $b['finger'] : '1';
                    break;
                }
            }
        }
        return implode('', $out);
    }

    private function fretStringToArray(string $frets): array
    {
        $out = [];
        for ($i = 0; $i < 6; $i++) {
            $c = $frets[$i] ?? 'x';
            if ($c === 'x' || $c === 'X') $out[] = 'x';
            elseif (ctype_xdigit($c))     $out[] = hexdec($c);
            else                          $out[] = 'x';
        }
        return $out;
    }

    private function canonicalFretsFromDiagram(array $dd): string
    {
        $out = ['x', 'x', 'x', 'x', 'x', 'x'];
        foreach ($dd['open'] ?? [] as $s) {
            if ($s >= 1 && $s <= 6) $out[$s - 1] = '0';
        }
        foreach ($dd['positions'] ?? [] as $p) {
            $s = $p['string'] ?? 0; $f = $p['fret'] ?? 0;
            if ($s >= 1 && $s <= 6 && $f > 0) {
                $out[$s - 1] = $f <= 9 ? (string) $f : dechex($f);
            }
        }
        foreach ($dd['barres'] ?? [] as $b) {
            $from = min($b['fromString'] ?? 0, $b['toString'] ?? 0);
            $to   = max($b['fromString'] ?? 0, $b['toString'] ?? 0);
            for ($s = $from; $s <= $to; $s++) {
                if ($s >= 1 && $s <= 6 && $out[$s - 1] === 'x') {
                    $f = $b['fret'] ?? 0;
                    $out[$s - 1] = $f <= 9 ? (string) $f : dechex($f);
                }
            }
        }
        return implode('', $out);
    }

    private function canonicalFingersFromDiagram(array $dd): string
    {
        $out = ['0', '0', '0', '0', '0', '0'];
        foreach ($dd['positions'] ?? [] as $p) {
            $s = $p['string'] ?? 0; $f = $p['finger'] ?? 0;
            if ($s >= 1 && $s <= 6 && $f > 0) $out[$s - 1] = (string) $f;
        }
        foreach ($dd['barres'] ?? [] as $b) {
            $from = min($b['fromString'] ?? 0, $b['toString'] ?? 0);
            $to   = max($b['fromString'] ?? 0, $b['toString'] ?? 0);
            $finger = ($b['finger'] ?? 0) > 0 ? (string) $b['finger'] : '1';
            for ($s = $from; $s <= $to; $s++) {
                if ($s >= 1 && $s <= 6 && $out[$s - 1] === '0') $out[$s - 1] = $finger;
            }
        }
        return implode('', $out);
    }

    /**
     * Normalize chord names embedded in a leadsheet's json_data payload.
     * Strips bare "maj" (Gmaj → G) and renormalizes any chordVoicings keys
     * so existing voicings continue to resolve after the rename.
     */
    private function normalizeChordNamesInJson(string $json): string
    {
        $data = json_decode($json, true);
        if (!is_array($data)) return $json;

        $walkMeasures = function (array &$measures) {
            foreach ($measures as &$measure) {
                if (!isset($measure['chords']) || !is_array($measure['chords'])) continue;
                foreach ($measure['chords'] as &$chord) {
                    if (is_array($chord) && isset($chord['name'])) {
                        $chord['name'] = \App\Helpers\ChordName::normalize($chord['name']);
                    } elseif (is_string($chord)) {
                        $chord = \App\Helpers\ChordName::normalize($chord);
                    }
                }
                unset($chord);
            }
            unset($measure);
        };

        if (isset($data['measures']) && is_array($data['measures'])) {
            $walkMeasures($data['measures']);
        }
        if (isset($data['sections']) && is_array($data['sections'])) {
            foreach ($data['sections'] as &$section) {
                if (isset($section['measures']) && is_array($section['measures'])) {
                    $walkMeasures($section['measures']);
                }
            }
            unset($section);
        }

        if (!empty($data['chordVoicings']) && is_array($data['chordVoicings'])) {
            $remapped = [];
            foreach ($data['chordVoicings'] as $key => $voicing) {
                // Keys may be "Name" or "Name@gi.ci" — normalize the name portion only.
                $atIdx = strpos($key, '@');
                if ($atIdx === false) {
                    $newKey = \App\Helpers\ChordName::normalize($key);
                } else {
                    $namePart = substr($key, 0, $atIdx);
                    $newKey   = \App\Helpers\ChordName::normalize($namePart) . substr($key, $atIdx);
                }
                $remapped[$newKey] = $voicing;
            }
            $data['chordVoicings'] = $remapped;
        }

        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function serializeLeadsheet(Leadsheet $leadsheet, $jsonData): array
    {
        return [
            'id'                => $leadsheet->id,
            'title'             => $leadsheet->title,
            'composer'          => $leadsheet->composer,
            'song_key'          => $leadsheet->song_key,
            'tempo'             => $leadsheet->tempo,
            'time_signature'    => $leadsheet->time_signature,
            'rhythm'            => $leadsheet->rhythm,
            'shortcode_content' => $leadsheet->shortcode_content,
            'json_data'         => $jsonData,
            'tab_xml'           => $leadsheet->tab_xml,
            'chord_tab_xml'     => $leadsheet->chord_tab_xml ?? null,
            'description'       => $leadsheet->description,
            'harmony_notes'     => $leadsheet->harmony_notes,
            'form_notes'        => $leadsheet->form_notes,
            'voicing_notes'     => $leadsheet->voicing_notes,
        ];
    }

    private function diagramDataToFretString(array $data): string
    {
        $frets = array_fill(0, 6, 'x');
        foreach ($data['strings'] ?? [] as $i => $stringData) {
            if (isset($stringData['fret'])) {
                $f = $stringData['fret'];
                if ($f === -1 || $f === 'x')   $frets[$i] = 'x';
                elseif ($f === 0)              $frets[$i] = '0';
                else                           $frets[$i] = $f <= 9 ? (string)$f : dechex($f);
            }
        }
        return implode('', $frets);
    }

}

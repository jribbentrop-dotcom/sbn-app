<?php

namespace App\Services;

use App\Models\ChordDiagram;
use App\Models\ChordProgression;
use App\Models\Leadsheet;
use App\Models\LeadsheetVersion;
use App\Services\ChordFretString;
use App\Services\HarmonicContext;

/**
 * Enriches a leadsheet with chord cards, progression list, and quality
 * metadata needed by the viewer page. Both the Inertia viewer route and
 * the JSON viewer-data API route call enrich() — keeping the two in sync
 * mechanically.
 */
class LeadsheetViewerService
{
    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * @return array{
     *   progressions: array,
     *   chordCards:   array,
     *   qualityByKey: array,
     * }
     */
    /**
     * @param  LeadsheetVersion|null  $version  The arrangement to enrich. When null
     *   (callers not yet migrated to versions), falls back to the leadsheet's default
     *   version, then to a synthesized version off the legacy columns.
     */
    public function enrich(Leadsheet $leadsheet, ChordVoicingSearch $search, ?LeadsheetVersion $version = null): array
    {
        $version ??= $this->resolveVersion($leadsheet);

        $progressions = $this->fetchProgressions($version);
        [$chordCards, $qualityByKey] = $this->buildChordCards($leadsheet, $search, $version);

        return [
            'progressions' => $progressions,
            'chordCards'   => $chordCards,
            'qualityByKey' => $qualityByKey,
        ];
    }

    /**
     * Default arrangement for a leadsheet, or a synthesized version wrapping the
     * legacy columns when no version row exists (dual-read defensive fallback).
     */
    private function resolveVersion(Leadsheet $leadsheet): LeadsheetVersion
    {
        $version = $leadsheet->defaultVersion ?? $leadsheet->versions()->first();
        if ($version) {
            return $version;
        }

        return new LeadsheetVersion([
            'leadsheet_id'   => $leadsheet->id,
            'version_slug'   => 'basic',
            'song_key'       => $leadsheet->song_key,
            'json_data'      => $leadsheet->json_data,
            'melody_tab_xml' => $leadsheet->tab_xml,
        ]);
    }

    // =========================================================================
    // Private — progression list
    // =========================================================================

    /**
     * One row per progression occurrence (NOT distinct) so each carries its
     * measure range — start_measure is section-relative; section_id keys the
     * section it lives in. The frontend resolves these to grid global indices.
     * Rows are grouped by progression so the EduPanel still shows one entry
     * per progression, with a `ranges` array spanning every occurrence.
     */
    private function fetchProgressions(LeadsheetVersion $version): array
    {
        // No persisted version (synthesized fallback) ⇒ no version-scoped occurrences.
        if (!$version->exists) {
            return [];
        }

        $rows = ChordProgression::query()
            ->join('sbn_progression_occurrences as o', 'sbn_chord_progressions.id', '=', 'o.progression_id')
            ->where('o.version_id', $version->id)
            ->select(
                'sbn_chord_progressions.id',
                'sbn_chord_progressions.slug',
                'sbn_chord_progressions.name',
                'sbn_chord_progressions.category',
                'sbn_chord_progressions.numerals',
                'o.section_id as occ_section_id',
                'o.start_measure as occ_start_measure',
                'o.length_measures as occ_length_measures',
                'o.start_chord as occ_start_chord',
                'o.end_chord as occ_end_chord',
                'o.end_chord_start as occ_end_chord_start',
            )
            ->orderBy('sbn_chord_progressions.name')
            ->get();

        $grouped = [];
        foreach ($rows as $p) {
            if (!isset($grouped[$p->id])) {
                $grouped[$p->id] = [
                    'id'              => $p->id,
                    'slug'            => $p->slug,
                    'name'            => $p->name,
                    'category'        => $p->category,
                    'numeralsDisplay' => $p->numerals_display,
                    'sectionId'       => null,
                    'ranges'          => [],
                ];
            }
            $grouped[$p->id]['ranges'][] = [
                'sectionId'    => $p->occ_section_id,
                'startMeasure' => (int) $p->occ_start_measure,
                'length'       => max(1, (int) $p->occ_length_measures),
                'startChord'    => (int) $p->occ_start_chord,
                'endChord'      => (int) $p->occ_end_chord,
                'endChordStart' => (int) $p->occ_end_chord_start,
            ];
        }

        return array_values($grouped);
    }

    // =========================================================================
    // Private — chord cards
    // =========================================================================

    private function buildChordCards(Leadsheet $leadsheet, ChordVoicingSearch $search, LeadsheetVersion $version): array
    {
        $voicings     = $version->parsed_data['chordVoicings'] ?? [];
        $chordCards   = [];
        $qualityByKey = [];
        $searchCache  = [];
        $songKey      = ($version->song_key ?: $leadsheet->song_key) ?: 'C';

        foreach ($voicings as $key => $voicing) {
            if (preg_match('/^(.+)@\d+\.\d+$/', $key, $m)) {
                $chordName = $m[1];
            } else {
                $chordName = $key;
            }

            // Re-spell root and bass note to match the song key's flat/sharp family
            // so that e.g. "D/Gb" stored from an old MusicXML import finds "D/F#" in the DB.
            $chordName = HarmonicContext::reSpellChordName($chordName, $songKey);

            $parsed = $search->parseChordName($chordName);
            $qualityByKey[$key] = $parsed['quality'] ?? 'maj';

            if (!isset($searchCache[$chordName])) {
                $searchCache[$chordName] = $search->searchByName($chordName);
            }
            $matches = $searchCache[$chordName];

            $best = $this->pickBestVoicing($matches, $voicing['frets'] ?? null);

            if ($best) {
                $chordCards[$key] = $best;
            } else {
                $card = $this->synthesizeMinimalCard($chordName, $voicing, $search);
                if (!empty($matches) && isset($matches[0]['slug'])) {
                    $card['slug'] = $matches[0]['slug'];
                } else {
                    $card['slug'] = ChordDiagram::where('quality', $card['quality'])->first()?->slug ?? '';
                }
                $chordCards[$key] = $card;
            }
        }

        return [$chordCards, $qualityByKey];
    }

    // =========================================================================
    // Voicing helpers — public so SongLibraryController::show() can call them
    // directly for its own top-4 aggregation loop.
    // =========================================================================

    public function pickBestVoicing(array $matches, ?string $targetFrets): ?array
    {
        if (empty($matches) || !$targetFrets) {
            return null;
        }

        $targetDiagram = $this->fretStringToDiagramData($targetFrets);

        // Pass 1 — exact match
        foreach ($matches as $match) {
            if ($this->diagramDataMatches($match['diagram_data'] ?? [], $targetDiagram)) {
                return $match;
            }
        }

        // Pass 2 — enriched bass: target has open string 1 (root added below),
        // DB shape has it muted. Strings 2–6 must match exactly.
        $targetMapS1 = in_array(1, $targetDiagram['open'] ?? []) ? 0 : -1;
        if ($targetMapS1 === 0) {
            foreach ($matches as $match) {
                if ($this->diagramDataMatches($match['diagram_data'] ?? [], $targetDiagram, [1])) {
                    return $match;
                }
            }
        }

        // Pass 3 — E-string swap: strings 1 and 6 are both tuned to E so a note
        // on string 6 with string 1 muted is the same pitch as string 1 with
        // string 6 muted (same fret). Swap and retry exact + ignore-string-1.
        $s1Muted = in_array(1, $targetDiagram['muted'] ?? []);
        $s6Fret  = null;
        foreach ($targetDiagram['positions'] ?? [] as $p) {
            if ($p['string'] === 6) { $s6Fret = $p['fret']; break; }
        }
        if ($s1Muted && $s6Fret !== null) {
            $swapped = $targetDiagram;
            $swapped['muted']     = array_values(array_diff($swapped['muted'], [1]));
            $swapped['positions'] = array_values(array_filter(
                $swapped['positions'], fn($p) => $p['string'] !== 6
            ));
            $swapped['positions'][] = ['string' => 1, 'fret' => $s6Fret];
            $swapped['muted'][]     = 6;

            foreach ($matches as $match) {
                if ($this->diagramDataMatches($match['diagram_data'] ?? [], $swapped)) {
                    return $match;
                }
            }
            // Also allow the swapped target to have extra open string 1 ignored
            foreach ($matches as $match) {
                if ($this->diagramDataMatches($match['diagram_data'] ?? [], $swapped, [6])) {
                    return $match;
                }
            }
        }

        return null;
    }

    public function synthesizeMinimalCard(string $chordName, array $voicing, ChordVoicingSearch $search): array
    {
        $frets    = $voicing['frets']   ?? 'xxxxxx';
        $fingers  = $voicing['fingers'] ?? null;
        $position = $voicing['position'] ?? 0;

        $parsed  = $search->parseChordName($chordName);
        $quality = $parsed['quality'] ?? 'maj';

        $qualityLabels = [
            'maj' => 'Major', 'min' => 'Minor', 'dom7' => 'Dominant 7',
            'maj7' => 'Major 7', 'm7' => 'Minor 7', 'm7b5' => 'Half-diminished',
            'dim' => 'Diminished', 'o7' => 'Diminished 7', 'aug' => 'Augmented',
            'aug7' => 'Augmented 7', 'mMaj7' => 'Minor-Major 7', 'sus4' => 'Suspended 4',
            'sus2' => 'Suspended 2', 'maj6' => 'Major 6', 'm6' => 'Minor 6',
            'add9' => 'Add 9', '7sus4' => '7 sus 4', '5' => 'Power chord',
        ];

        $diagramData = $this->fretStringToDiagramData($frets);

        if ($fingers && strlen($fingers) === 6) {
            foreach ($diagramData['positions'] as &$pos) {
                $fingerChar = $fingers[$pos['string'] - 1] ?? '0';
                if ($fingerChar !== 'x' && $fingerChar !== '0') {
                    $pos['finger'] = intval($fingerChar);
                }
            }
        }

        $startFret = $position;
        if (!$startFret) {
            $fretValues = array_column($diagramData['positions'], 'fret');
            if (!empty($fretValues)) {
                $minFret   = min($fretValues);
                $startFret = $minFret > 0 ? $minFret : 1;
            } else {
                $startFret = 1;
            }
        }

        return [
            'id'              => 0,
            'slug'            => '',
            'name'            => $chordName,
            'root_note'       => $parsed['root'] ?? '',
            'quality'         => $quality,
            'quality_label'   => $qualityLabels[$quality] ?? $quality,
            'extensions'      => $parsed['extension'] ?? null,
            'voicing_category'=> 'standard',
            'category_label'  => 'Standard',
            'root_string'     => '',
            'root_string_label' => '',
            'inversion'       => 'root',
            'inversion_label' => 'Root position',
            'bass_note'       => $parsed['bass_note'] ?? null,
            'shape_family'    => null,
            'start_fret'      => $startFret,
            'diagram_data'    => $diagramData,
            'interval_labels' => null,
            'notes'           => null,
            'popularity'      => null,
            'difficulty'      => null,
            'description'     => null,
        ];
    }

    private function fretStringToDiagramData(string $frets): array
    {
        return ChordFretString::fretStringToDiagramData($frets);
    }

    private function diagramDataMatches(array $a, array $b, array $ignoreStrings = []): bool
    {
        $mapA = [];
        $mapB = [];

        foreach ($a['positions'] ?? [] as $pos) { $mapA[$pos['string']] = $pos['fret']; }
        foreach ($a['open']      ?? [] as $s)   { $mapA[$s] = 0; }
        foreach ($a['muted']     ?? [] as $s)   { $mapA[$s] = -1; }

        foreach ($b['positions'] ?? [] as $pos) { $mapB[$pos['string']] = $pos['fret']; }
        foreach ($b['open']      ?? [] as $s)   { $mapB[$s] = 0; }
        foreach ($b['muted']     ?? [] as $s)   { $mapB[$s] = -1; }

        for ($s = 1; $s <= 6; $s++) {
            if (!isset($mapA[$s])) $mapA[$s] = -1;
            if (!isset($mapB[$s])) $mapB[$s] = -1;
        }

        for ($s = 1; $s <= 6; $s++) {
            if (in_array($s, $ignoreStrings)) continue;
            if ($mapA[$s] !== $mapB[$s]) return false;
        }

        return true;
    }

    public function getVoicingShapePattern(?array $diagramData): string
    {
        if (!$diagramData) return '';

        $fretMap = array_fill(1, 6, -1);

        foreach ($diagramData['positions'] ?? [] as $pos) { $fretMap[$pos['string']] = $pos['fret']; }
        foreach ($diagramData['open']      ?? [] as $s)   { $fretMap[$s] = 0; }
        foreach ($diagramData['muted']     ?? [] as $s)   { $fretMap[$s] = -1; }

        $minFret    = 99;
        $hasFretted = false;
        foreach ($fretMap as $fret) {
            if ($fret > 0 && $fret < $minFret) { $minFret = $fret; $hasFretted = true; }
        }

        $offset  = $hasFretted ? $minFret : 0;
        $pattern = '';
        for ($s = 1; $s <= 6; $s++) {
            $fret     = $fretMap[$s];
            $pattern .= match(true) {
                $fret === -1 => 'x',
                $fret === 0  => '0',
                default      => (string)($fret - $offset),
            };
        }

        return $pattern;
    }
}

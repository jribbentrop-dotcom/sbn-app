<?php

namespace App\Services\Identifier;

use Illuminate\Support\Facades\DB;

/**
 * Phase 3.1: DB shape lookup as evidence for chord identification.
 *
 * Queries `sbn_chord_diagrams` for filed shapes whose pitch-class set
 * matches the target voicing under any transposition. Returns ranked
 * hits with confidence metadata.
 *
 * Design and shadow-dump findings: docs/SBN-Identifier-Phase3-Plan.md §3.
 *
 * Two DB-schema traps this service works around (see spec §3.4a):
 *   1. `root_note` column is sometimes a positional archetype tag, not the
 *      chord root. True root derived from `interval_labels` ('R' position).
 *   2. `inversion` field can disagree with the shape's actual lowest pitch.
 *      Slash bass derived from `diagram_data`, not from `inversion`.
 */
class DbVoicingMatcher
{
    /** String number → open-string MIDI. Legacy WP convention: 1=low E, 6=high E. */
    private const STRING_OPEN_MIDI = [1=>40, 2=>45, 3=>50, 4=>55, 5=>59, 6=>64];

    /** Mirrors VoicingCrossref::IDENTIFY_NOTE_SHARP / _FLAT for output consistency. */
    private const PC_SHARP = ['C','C#','D','D#','E','F','F#','G','G#','A','A#','B'];
    private const PC_FLAT  = ['C','Db','D','Eb','E','F','Gb','G','Ab','A','Bb','B'];

    /** Note name → pitch class (handles sharps, flats, and unicode accidentals). */
    private const NOTE_TO_PC = [
        'C'=>0,'C#'=>1,'Db'=>1,'D'=>2,'D#'=>3,'Eb'=>3,'E'=>4,'F'=>5,
        'F#'=>6,'Gb'=>6,'G'=>7,'G#'=>8,'Ab'=>8,'A'=>9,'A#'=>10,'Bb'=>10,'B'=>11,
    ];

    /** Quality token → display label. */
    private const QUALITY_DISPLAY = [
        'maj'   => '',
        'min'   => 'm',
        'm'     => 'm',
        'dom7'  => '7',
        'maj7'  => 'maj7',
        'maj6'  => '6',
        'm6'    => 'm6',
        'm7'    => 'm7',
        'm7b5'  => 'm7b5',
        'o7'    => 'o7',
        'dim7'  => 'dim7',
        'dim'   => 'dim',
        'aug'   => 'aug',
        'aug7'  => 'aug7',
        'mMaj7' => 'mMaj7',
        'sus4'  => 'sus4',
        'sus2'  => 'sus2',
        '7sus4' => '7sus4',
        'add9'  => 'add9',
    ];

    /** Cached shape signatures keyed by diagram id. Populated lazily. */
    private ?array $shapeCache = null;

    /**
     * Look up DB shape matches for a fret string + bass.
     *
     * @param  string      $frets    6-char fret string, index 0 = string 1 (low E),
     *                               'x' = muted, '0'-'9' = fret, 'a'-'f' = frets 10-15.
     * @param  string|null $songKey  Optional song key for enharmonic preference.
     *                               Flat keys (Db, Eb, Ab, Bb, F, Gb) → flat spelling.
     * @return array {
     *     hits: list<array{name, root_pc, quality, extensions, bass_pc,
     *                      bass_match, root_equals_bass, root_bass_aligned,
     *                      popularity, db_id, inversion}>,
     *     gate: 'too_few_pcs'|'no_match'|null,
     *     target_pcs: list<int>,
     *     target_bass_pc: int|null,
     * }
     */
    public function lookup(string $frets, ?string $songKey = null): array
    {
        $target = $this->fretsToTarget($frets);
        if ($target === null) {
            return ['hits' => [], 'gate' => 'no_match', 'target_pcs' => [], 'target_bass_pc' => null];
        }
        [$targetPcs, $targetBassPc] = $target;

        // Gate: <3 unique PCs → too thin for reliable DB matching, defer to Pass 1.
        if (count($targetPcs) < 3) {
            return ['hits' => [], 'gate' => 'too_few_pcs', 'target_pcs' => $targetPcs, 'target_bass_pc' => $targetBassPc];
        }

        $pcNames = $this->preferFlats($songKey) ? self::PC_FLAT : self::PC_SHARP;
        $bassInPcs = ($targetBassPc !== null && in_array($targetBassPc, $targetPcs, true));

        $hits = [];
        foreach ($this->shapes() as $shape) {
            for ($t = 0; $t < 12; $t++) {
                $transposed = array_map(fn($pc) => ($pc + $t) % 12, $shape['pcs']);
                sort($transposed);
                if ($transposed !== $targetPcs) continue;

                $transposedBassPc = ($shape['bass_pc'] + $t) % 12;
                $newRootPc = ($shape['root_pc'] + $t) % 12;

                $bassMatch = ($targetBassPc !== null && $transposedBassPc === $targetBassPc);
                $rootEqualsBass = ($targetBassPc !== null && $newRootPc === $targetBassPc);
                $rootBassAligned = $bassInPcs && $rootEqualsBass && ($shape['inversion'] === 'root');

                $hits[] = [
                    'db_id' => $shape['id'],
                    'name' => $this->formatName($newRootPc, $shape['quality'], $shape['extensions'], $transposedBassPc, $pcNames),
                    'root_pc' => $newRootPc,
                    'quality' => $shape['quality'],
                    'extensions' => $shape['extensions'],
                    'bass_pc' => $transposedBassPc,
                    'bass_match' => $bassMatch,
                    'root_equals_bass' => $rootEqualsBass,
                    'root_bass_aligned' => $rootBassAligned,
                    'popularity' => $shape['popularity'],
                    'inversion' => $shape['inversion'],
                ];
                break; // one match per shape regardless of how many transpositions fit
            }
        }

        // Rank: bass_match → root_bass_aligned → popularity. Spec §3.3.
        usort($hits, function ($a, $b) {
            if ($a['bass_match'] !== $b['bass_match']) return $b['bass_match'] <=> $a['bass_match'];
            if ($a['root_bass_aligned'] !== $b['root_bass_aligned']) return $b['root_bass_aligned'] <=> $a['root_bass_aligned'];
            return $b['popularity'] <=> $a['popularity'];
        });

        return [
            'hits' => $hits,
            'gate' => empty($hits) ? 'no_match' : null,
            'target_pcs' => $targetPcs,
            'target_bass_pc' => $targetBassPc,
        ];
    }

    /**
     * Convert a fret string to [sorted unique PCs, bass PC].
     */
    private function fretsToTarget(string $frets): ?array
    {
        $pcs = [];
        $bassPc = null;
        $bassString = null;
        $len = min(6, strlen($frets));
        for ($i = 0; $i < $len; $i++) {
            $f = $frets[$i];
            if ($f === 'x' || $f === 'X') continue;
            // Hex frets a-f = 10-15
            $fret = ctype_digit($f) ? (int)$f : hexdec($f);
            $stringNum = $i + 1;
            $midi = self::STRING_OPEN_MIDI[$stringNum] + $fret;
            $pcs[] = $midi % 12;
            if ($bassString === null || $stringNum < $bassString) {
                $bassString = $stringNum;
                $bassPc = $midi % 12;
            }
        }
        if (empty($pcs)) return null;
        $pcs = array_values(array_unique($pcs));
        sort($pcs);
        return [$pcs, $bassPc];
    }

    /**
     * Load + cache all DB shapes with computed (pcs, bass_pc, root_pc, ...).
     */
    private function shapes(): array
    {
        if ($this->shapeCache !== null) return $this->shapeCache;

        $rows = DB::table('sbn_chord_diagrams')
            ->select('id', 'root_note', 'quality', 'extensions', 'inversion',
                     'interval_labels', 'diagram_data', 'is_fixed_position',
                     'start_fret', 'popularity')
            ->get();

        $cache = [];
        foreach ($rows as $row) {
            $absolute = $this->positionsToAbsoluteMidi($row);
            if (empty($absolute)) continue;

            ksort($absolute);
            $bassString = array_key_first($absolute);
            $bassMidi = $absolute[$bassString];

            $pcs = array_values(array_unique(array_map(fn($m) => $m % 12, $absolute)));
            sort($pcs);

            $rootPc = $this->deriveChordRootPc($row, $absolute);
            if ($rootPc === null) continue;

            $cache[] = [
                'id' => $row->id,
                'pcs' => $pcs,
                'bass_pc' => $bassMidi % 12,
                'root_pc' => $rootPc,
                'quality' => $row->quality,
                'extensions' => $row->extensions ?? '',
                'inversion' => $row->inversion ?? 'root',
                'popularity' => (int)$row->popularity,
            ];
        }
        return $this->shapeCache = $cache;
    }

    /**
     * Compute absolute MIDI for each fretted/open string of a diagram row.
     * Returns [stringNum => midi].
     */
    private function positionsToAbsoluteMidi($row): array
    {
        $data = json_decode($row->diagram_data, true);
        if (!is_array($data)) return [];

        $startFretOffset = $row->is_fixed_position ? 0 : (($row->start_fret ?? 1) - 1);
        $absolute = [];

        foreach ($data['positions'] ?? [] as $p) {
            $str = (int)$p['string'];
            if (!isset(self::STRING_OPEN_MIDI[$str])) continue;
            $fret = (int)$p['fret'] + ($row->is_fixed_position ? 0 : $startFretOffset);
            $absolute[$str] = self::STRING_OPEN_MIDI[$str] + $fret;
        }
        foreach ($data['open'] ?? [] as $s) {
            $str = (int)$s;
            if (!isset(self::STRING_OPEN_MIDI[$str])) continue;
            $absolute[$str] = self::STRING_OPEN_MIDI[$str];
        }
        return $absolute;
    }

    /**
     * Derive true chord-root PC from `interval_labels` ('R' marker).
     * Falls back to `root_note` column if interval_labels is empty/unusable.
     */
    private function deriveChordRootPc($row, array $absolute): ?int
    {
        $labels = trim($row->interval_labels ?? '');
        if ($labels !== '') {
            $parts = array_map('trim', explode(',', $labels));
            foreach ($parts as $i => $lab) {
                if ($lab === 'R' || $lab === '1') {
                    $stringNum = $i + 1;
                    if (isset($absolute[$stringNum])) {
                        return $absolute[$stringNum] % 12;
                    }
                }
            }
        }
        return self::NOTE_TO_PC[$row->root_note] ?? null;
    }

    /**
     * Format a chord name from root + quality + ext + bass.
     * Suppresses slash when bass = root.
     */
    private function formatName(int $rootPc, string $quality, string $extensions, ?int $bassPc, array $pcNames): string
    {
        $rootName = $pcNames[$rootPc];
        $qDisplay = self::QUALITY_DISPLAY[$quality] ?? $quality;
        $extDisplay = $extensions === '' ? '' : "($extensions)";
        $name = $rootName . $qDisplay . $extDisplay;
        if ($bassPc !== null && $bassPc !== $rootPc) {
            $name .= '/' . $pcNames[$bassPc];
        }
        return $name;
    }

    /**
     * Heuristic: flat-keyed song → prefer flat spelling for output names.
     */
    private function preferFlats(?string $songKey): bool
    {
        if ($songKey === null || $songKey === '') return false;
        $root = strtoupper(substr(trim($songKey), 0, 2));
        // Match: starts with Db, Eb, Gb, Ab, Bb, or F (F is conventionally flat-keyed in jazz)
        return in_array($root, ['DB','EB','GB','AB','BB'], true)
            || strtoupper(trim($songKey)) === 'F';
    }
}

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
 * Design and shadow-dump findings: docs/SBN-Identifier-Reference.md §5.1.
 *
 * Two DB-schema traps this service works around (see spec §5.1):
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

    /** Interval label → semitones above root. Mirrors the admin editor's label set. */
    private const LABEL_TO_OFFSET = [
        'R'=>0,'1'=>0,'b2'=>1,'2'=>2,'9'=>2,'b3'=>3,'#9'=>3,'3'=>4,'4'=>5,'11'=>5,
        'b5'=>6,'#11'=>6,'5'=>7,'#5'=>8,'b6'=>8,'b13'=>8,'6'=>9,'13'=>9,'bb7'=>9,
        'b7'=>10,'7'=>11,
    ];

    /** Quality → base interval template, for root-consistency scoring. */
    private const QUALITY_INTERVALS = [
        'maj'=>[0,4,7],'min'=>[0,3,7],'m'=>[0,3,7],'dim'=>[0,3,6],'aug'=>[0,4,8],
        'sus2'=>[0,2,7],'sus4'=>[0,5,7],'5'=>[0,7],
        'maj7'=>[0,4,7,11],'maj6'=>[0,4,7,9],'m7'=>[0,3,7,10],'m6'=>[0,3,7,9],
        'dom7'=>[0,4,7,10],'7'=>[0,4,7,10],'m7b5'=>[0,3,6,10],'o7'=>[0,3,6,9],
        'dim7'=>[0,3,6,9],'mMaj7'=>[0,3,7,11],'add9'=>[0,2,4,7],'madd9'=>[0,2,3,7],
        'aug7'=>[0,4,8,10],'7sus4'=>[0,5,7,10],
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
     *
     * `diagram_data.fret` is an ABSOLUTE neck position — the DB stores the real
     * position a chord is played at, not a window-local dot. `start_fret` is a
     * derived render hint (ChordShapeCalculator::calculateStartFret computes it
     * as min(fret)) and must never be added back in. Verified table-wide: no row
     * has min(fret) < start_fret, and 214/271 have start_fret == min(fret) exactly.
     *
     * This previously added `start_fret - 1`, sharpening 105 shapes by up to a
     * major third and pushing some to fret 27. It went unnoticed because lookup()
     * compares pitch-class sets across all 12 transpositions, so a uniform offset
     * cancels out — as does most of deriveChordRootPc(), which reads labels against
     * the same shifted pitches. The offset only bites where an *absolute* claim
     * meets a label-derived one: `root_note` disagrees with the labels by exactly
     * the offset, so the vote elects the wrong root (id 273 Ebmaj7#11/Bb → G), and
     * the cached root_pc/bass_pc are wrong for any reader outside lookup().
     */
    private function positionsToAbsoluteMidi($row): array
    {
        $data = json_decode($row->diagram_data, true);
        if (!is_array($data)) return [];

        $absolute = [];

        foreach ($data['positions'] ?? [] as $p) {
            $str = (int)$p['string'];
            if (!isset(self::STRING_OPEN_MIDI[$str])) continue;
            $absolute[$str] = self::STRING_OPEN_MIDI[$str] + (int)$p['fret'];
        }
        foreach ($data['open'] ?? [] as $s) {
            $str = (int)$s;
            if (!isset(self::STRING_OPEN_MIDI[$str])) continue;
            $absolute[$str] = self::STRING_OPEN_MIDI[$str];
        }
        // Barred strings sound too — mirrors VoicingCrossref::diagramToFretArray().
        // An explicit position/open on the same string wins over the barre.
        foreach ($data['barres'] ?? [] as $barre) {
            $from = min((int)$barre['fromString'], (int)$barre['toString']);
            $to   = max((int)$barre['fromString'], (int)$barre['toString']);
            for ($str = $from; $str <= $to; $str++) {
                if (!isset(self::STRING_OPEN_MIDI[$str]) || isset($absolute[$str])) continue;
                $absolute[$str] = self::STRING_OPEN_MIDI[$str] + (int)$barre['fret'];
            }
        }

        return $absolute;
    }

    /**
     * Derive true chord-root PC — by template-consistency vote, not by trusting
     * any single column.
     *
     * Neither source is reliable on its own (spec §5.1 traps, plus draft rows):
     *   - `root_note` can be a positional archetype tag (or describe the shape
     *     at a different transposition than diagram_data computes — id 123's
     *     rootless 6/9 shape says Db while its pcs spell Eb6(9)).
     *   - the 'R' interval label can be wrong too (id 193 marks the BASS G as
     *     'R' on what root_note correctly calls an F-over-G shape).
     *
     * So: gather candidate roots from every interval label (root = string pc −
     * label offset) AND from root_note, then keep the candidate whose stored
     * quality template best fits the shape's actual pitch classes. A shape may
     * legitimately omit its root or 5th, so the winner needs ≥ (template−1)
     * tones present; if no candidate reaches that, the row is internally
     * inconsistent (junk draft like cmaj-draft-7111) and is dropped from the
     * matcher entirely — a wrong-root hit is worse than no hit.
     */
    private function deriveChordRootPc($row, array $absolute): ?int
    {
        $pcs = array_values(array_unique(array_map(fn($m) => $m % 12, $absolute)));

        // Candidate roots: [pc => label votes], votes seed tie-breaks only.
        $votes = [];
        $rLabelPc = null;
        $labels = trim($row->interval_labels ?? '');
        if ($labels !== '') {
            $parts = array_map('trim', explode(',', $labels));
            foreach ($parts as $i => $lab) {
                if (!isset(self::LABEL_TO_OFFSET[$lab])) continue;
                $stringNum = $i + 1;
                if (!isset($absolute[$stringNum])) continue;
                $rootPc = ($absolute[$stringNum] % 12 - self::LABEL_TO_OFFSET[$lab] + 12) % 12;
                $votes[$rootPc] = ($votes[$rootPc] ?? 0) + 1;
                if (($lab === 'R' || $lab === '1') && $rLabelPc === null) {
                    $rLabelPc = $rootPc;
                }
            }
        }
        $rootNotePc = self::NOTE_TO_PC[$row->root_note] ?? null;
        if ($rootNotePc !== null && !isset($votes[$rootNotePc])) {
            $votes[$rootNotePc] = 0;
        }

        if (empty($votes)) return null;

        $template = self::QUALITY_INTERVALS[$row->quality] ?? null;
        if ($template === null) {
            // Unknown quality — can't score consistency; legacy behavior.
            return $rLabelPc ?? $rootNotePc;
        }

        $best = null;
        $bestKey = null;
        foreach ($votes as $rootPc => $voteCount) {
            $fit = 0;
            foreach ($template as $iv) {
                if (in_array(($rootPc + $iv) % 12, $pcs, true)) $fit++;
            }
            // Rank: template fit, then label votes, then R-label, then root_note.
            $key = [$fit, $voteCount, $rootPc === $rLabelPc ? 1 : 0, $rootPc === $rootNotePc ? 1 : 0];
            if ($bestKey === null || $key > $bestKey) {
                $bestKey = $key;
                $best = $rootPc;
            }
        }

        // Consistency gate: allow at most one omitted template tone (root or
        // 5th of a shell/rootless shape). Below that the row contradicts its
        // own quality — drop it rather than emit a wrong-root hit.
        if ($bestKey[0] < count($template) - 1) {
            return null;
        }
        return $best;
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
     * Flat vs sharp for DB-hit output names — defers to the app's single spelling
     * authority (HarmonicContext::spellingUsesFlats) so this matcher never carries
     * its own house-style rule. House style is flats-by-default: a null/neutral key
     * spells flat, only the genuine sharp keys (G D A E B F# C# + rel. minors) sharp.
     * VoicingCrossref re-spells the injected candidate's root/bass per-quality via
     * pcToNoteName afterwards, so this only needs the key-layer decision.
     */
    private function preferFlats(?string $songKey): bool
    {
        return \App\Services\HarmonicContext::spellingUsesFlats($songKey ?? '');
    }
}

<?php

namespace App\Console\Commands;

use App\Helpers\ChordName;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Phase 3.3a: Regenerate the chord-transition bigram table from sbn_jazz_standards.
 *
 * Reads every standard's chord_string (iReal-style: bars separated by `|`,
 * intra-bar chord changes by `,`), parses into a flat chord sequence, normalizes
 * each chord name, and counts bigram transitions (prev → next).
 *
 * Output: storage/app/harmonic-transitions.generated.php — a PHP return array:
 *   [
 *     'bigrams' => [
 *       'Dm7' => ['G7' => 612, 'C7' => 47, 'Cmaj7' => 12, ...],
 *       'G7'  => ['Cmaj7' => 580, 'C7' => 35, 'Bbmaj7' => 12, ...],
 *       ...
 *     ],
 *     'totals' => [
 *       'Dm7' => 871,  // total outgoing transitions from Dm7
 *       'G7'  => 1024,
 *       ...
 *     ],
 *     'meta' => [
 *       'corpus_size' => 1382,
 *       'total_bigrams' => 52516,
 *       'unique_prev_chords' => 287,
 *       'generated_at' => '2026-05-14T...',
 *     ],
 *   ]
 *
 * The file is committed to git and loaded at runtime by TransitionScorer
 * with zero DB access.
 *
 * Smoothing: not applied here. Consumers apply Laplace add-K smoothing at
 * read time so the smoothing parameter can be tuned without regenerating.
 *
 * Spec: docs/SBN-Identifier-Reference.md §5.3.
 */
class ReseedTransitions extends Command
{
    protected $signature = 'sbn:reseed-transitions
                            {--out=storage/app/harmonic-transitions.generated.php : Output path}';

    protected $description = 'Regenerate the chord-transition bigram table from sbn_jazz_standards';

    public function handle(\App\Services\ProgressionDetector $detector): int
    {
        $standards = DB::table('sbn_jazz_standards')
            ->select('id', 'title', 'song_key', 'chord_string')
            ->whereNotNull('chord_string')
            ->where('chord_string', '!=', '')
            ->get();

        $this->info("Loaded " . $standards->count() . " standards from sbn_jazz_standards");

        $bigrams = []; // [prev => [next => count]]
        // Trigram tables (Phase 3.3b): widen the sequence viewport so patterns
        // like II7→V7→I are visible as a unit (a bigram can't see them). Two
        // parallel tables consulted with backoff by TransitionScorer:
        //   surface    — chord-name triples "prev2|prev1" => [curr => count]
        //   functional — scale-degree triples (key-relative), so every II-V-I in
        //                any key reinforces the same entry. Requires song_key.
        $surfaceTri    = []; // ["p2\x1fp1" => [curr => count]]
        $functionalTri = []; // ["d2\x1fd1" => [degCurr => count]]
        $totalBigrams = 0;
        $totalSurfaceTri = 0;
        $totalFunctionalTri = 0;
        $keyedStandards = 0;
        $skippedStandards = 0;

        // Delimiter for composite trigram-context keys (a control char that can't
        // appear in a chord/numeral token). Keeps the two-chord context a single
        // array key so the generated file stays a plain nested map.
        $SEP = "\x1f";

        foreach ($standards as $std) {
            $sequence = $this->parseChordSequence($std->chord_string);
            if (count($sequence) < 2) {
                $skippedStandards++;
                continue;
            }
            for ($i = 0; $i < count($sequence) - 1; $i++) {
                $prev = $sequence[$i];
                $next = $sequence[$i + 1];
                // Self-transitions ARE included. Held chords across bars are a
                // real harmonic phenomenon — common in bossa, ballads, vamps.
                // Excluding them would teach the model "never repeat a chord"
                // which would hijack any sequence with sustained voicings.
                $bigrams[$prev][$next] = ($bigrams[$prev][$next] ?? 0) + 1;
                $totalBigrams++;
            }

            // ── Trigrams ──
            $key = trim((string)($std->song_key ?? ''));
            // Precompute the functional (numeral) token for each chord ONCE per
            // tune. Null when there's no key or the chord can't be placed.
            $numerals = null;
            if ($key !== '') {
                $keyedStandards++;
                $numerals = array_map(
                    fn($ch) => $this->functionalToken($detector, $ch, $key),
                    $sequence
                );
            }

            for ($i = 0; $i < count($sequence) - 2; $i++) {
                // Surface trigram: exact chord-name triple.
                $ctx = $sequence[$i] . $SEP . $sequence[$i + 1];
                $curr = $sequence[$i + 2];
                $surfaceTri[$ctx][$curr] = ($surfaceTri[$ctx][$curr] ?? 0) + 1;
                $totalSurfaceTri++;

                // Functional trigram: key-relative numeral triple (skip if any of
                // the three chords couldn't be tokenized — a partial numeral triple
                // would pollute the table with mis-generalized entries).
                if ($numerals !== null
                    && $numerals[$i] !== null
                    && $numerals[$i + 1] !== null
                    && $numerals[$i + 2] !== null) {
                    $dctx  = $numerals[$i] . $SEP . $numerals[$i + 1];
                    $dcurr = $numerals[$i + 2];
                    $functionalTri[$dctx][$dcurr] = ($functionalTri[$dctx][$dcurr] ?? 0) + 1;
                    $totalFunctionalTri++;
                }
            }
        }

        // Compute outgoing totals per prev chord
        $totals = [];
        foreach ($bigrams as $prev => $nexts) {
            $totals[$prev] = array_sum($nexts);
        }

        // Sort each transitions array by count descending, then alphabetic for stability
        foreach ($bigrams as $prev => $nexts) {
            arsort($nexts);
            $bigrams[$prev] = $nexts;
        }
        ksort($bigrams);
        ksort($totals);

        // Trigram totals per two-chord context + stable sort (mirror the bigram treatment).
        $surfaceTriTotals    = $this->finalizeTrigram($surfaceTri);
        $functionalTriTotals = $this->finalizeTrigram($functionalTri);

        $meta = [
            'corpus_size'          => $standards->count(),
            'corpus_used'          => $standards->count() - $skippedStandards,
            'keyed_standards'      => $keyedStandards,
            'total_bigrams'        => $totalBigrams,
            'total_surface_tri'    => $totalSurfaceTri,
            'total_functional_tri' => $totalFunctionalTri,
            'unique_prev_chords'   => count($bigrams),
            'unique_surface_ctx'   => count($surfaceTri),
            'unique_functional_ctx'=> count($functionalTri),
            'generated_at'         => date('c'),
        ];

        $payload = [
            'bigrams' => $bigrams,
            'totals'  => $totals,
            // Trigram tables. Context keys are "tokenA\x1ftokenB" (see $SEP).
            'surface_trigrams'        => $surfaceTri,
            'surface_tri_totals'      => $surfaceTriTotals,
            'functional_trigrams'     => $functionalTri,
            'functional_tri_totals'   => $functionalTriTotals,
            'meta'    => $meta,
        ];

        $out = $this->option('out');
        $absPath = base_path($out);
        File::ensureDirectoryExists(dirname($absPath));
        File::put($absPath, "<?php\n\nreturn " . var_export($payload, true) . ";\n");

        $this->info("Wrote $out");
        $this->table(['Metric', 'Value'], [
            ['Standards in corpus', $meta['corpus_size']],
            ['Standards used', $meta['corpus_used']],
            ['Standards with key (functional corpus)', $meta['keyed_standards']],
            ['Total bigrams (self-transitions included)', $meta['total_bigrams']],
            ['Total surface trigrams', $meta['total_surface_tri']],
            ['Total functional trigrams', $meta['total_functional_tri']],
            ['Unique prev chords', $meta['unique_prev_chords']],
            ['Unique surface 2-chord contexts', $meta['unique_surface_ctx']],
            ['Unique functional 2-numeral contexts', $meta['unique_functional_ctx']],
        ]);

        // Sanity: show the II-V-I functional trigram if present — the case this
        // whole feature targets. Context keys use \x1f as separator.
        $sep = "\x1f";
        foreach (["II7{$sep}V7", "IIm7{$sep}V7", "IIø7{$sep}V7"] as $ctx) {
            if (isset($functionalTri[$ctx])) {
                $top = array_slice($functionalTri[$ctx], 0, 3, true);
                $disp = str_replace($sep, '→', $ctx);
                $parts = [];
                foreach ($top as $next => $cnt) $parts[] = "$next ($cnt)";
                $this->line("  functional  $disp → " . implode(', ', $parts));
            }
        }

        // Show a sanity sample: top 5 outgoing transitions for the most common chords
        $this->info("\nTop transitions for the 5 most-seen 'prev' chords:");
        arsort($totals);
        $top = array_slice($totals, 0, 5, true);
        foreach ($top as $prev => $total) {
            $top3Next = array_slice($bigrams[$prev], 0, 3, true);
            $samples = [];
            foreach ($top3Next as $next => $c) {
                $samples[] = "$next ($c)";
            }
            $this->line("  $prev (n=$total): " . implode(', ', $samples));
        }

        return self::SUCCESS;
    }

    /**
     * Key-relative functional token for one chord (degree + quality-class), e.g.
     * Eb7 in Db → "II7", Ab7 → "V7", Dbmaj7 → "Imaj7". Delegates to
     * ProgressionDetector::chordToNumeral so the numeral logic (chromatic degrees,
     * slash handling) stays consistent with the rest of the identifier — do not
     * reimplement roman-numeral math here. Returns null when the chord can't be
     * placed in the key (so the caller skips the trigram rather than emit a
     * partial/mis-generalized numeral triple).
     */
    private function functionalToken(\App\Services\ProgressionDetector $detector, string $chord, string $key): ?string
    {
        $n = $detector->chordToNumeral($chord, $key);
        if ($n === null || $n === '') return null;
        // Drop the slash-bass degree: a chord's FUNCTION is its root-relative
        // degree — a pedal/inversion bass (Eb7/Bb → 'II7/VI') doesn't change that
        // it acts as II7. The iReal corpus is overwhelmingly slash-less, so
        // keeping the '/VI' suffix would make every pedal-voiced chord miss the
        // trigram table entirely (this is exactly what sank Ipanema's II7-V7-I).
        // Slash detail still lives in the surface trigram.
        if (str_contains($n, '/')) {
            $n = explode('/', $n, 2)[0];
        }
        // chordToNumeral emits 'chrN' for un-placeable chromatic degrees — those
        // don't generalize functionally, so drop them from the functional table.
        if ($n === '' || str_starts_with($n, 'chr')) return null;
        return $n;
    }

    /**
     * Per-context totals + stable sort for a trigram table (in place). Returns the
     * [context => total] map. Mirrors the bigram finalize so the scorer can apply
     * the same Laplace smoothing shape at read time.
     *
     * @param array<string, array<string,int>> $tri  (by reference)
     * @return array<string, int>
     */
    private function finalizeTrigram(array &$tri): array
    {
        $totals = [];
        foreach ($tri as $ctx => $nexts) {
            $totals[$ctx] = array_sum($nexts);
            arsort($nexts);
            $tri[$ctx] = $nexts;
        }
        ksort($tri);
        ksort($totals);
        return $totals;
    }

    /**
     * Parse iReal Pro chord_string into a flat list of normalized chord names.
     * Format: bars separated by `|`, optional double-bars `||`, intra-bar changes
     * by `,`. Empty tokens, parse failures, and explicit no-chord markers are skipped.
     */
    private function parseChordSequence(string $chordString): array
    {
        // Replace double-bars with single (normalize to a single separator)
        $s = str_replace('||', '|', $chordString);
        // Split on | or , into tokens
        $tokens = preg_split('/[|,]/', $s);
        $out = [];
        foreach ($tokens as $t) {
            $t = trim($t);
            if ($t === '' || $t === '*' || $t === '/') continue;
            // Strip iReal hint markers like 'n.c.', 'NC', leading/trailing punctuation
            $t = trim($t, " \t/.-");
            if ($t === '' || strcasecmp($t, 'nc') === 0 || strcasecmp($t, 'n.c.') === 0) continue;

            $normalized = ChordName::normalize($t);
            if ($normalized === '') continue;
            $out[] = $normalized;
        }
        return $out;
    }
}

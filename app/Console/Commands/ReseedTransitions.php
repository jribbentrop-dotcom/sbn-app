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
 * Spec: docs/SBN-Identifier-Phase3-Plan.md §5.3.
 */
class ReseedTransitions extends Command
{
    protected $signature = 'sbn:reseed-transitions
                            {--out=storage/app/harmonic-transitions.generated.php : Output path}';

    protected $description = 'Regenerate the chord-transition bigram table from sbn_jazz_standards';

    public function handle(): int
    {
        $standards = DB::table('sbn_jazz_standards')
            ->select('id', 'title', 'song_key', 'chord_string')
            ->whereNotNull('chord_string')
            ->where('chord_string', '!=', '')
            ->get();

        $this->info("Loaded " . $standards->count() . " standards from sbn_jazz_standards");

        $bigrams = []; // [prev => [next => count]]
        $totalBigrams = 0;
        $skippedStandards = 0;

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

        $meta = [
            'corpus_size'        => $standards->count(),
            'corpus_used'        => $standards->count() - $skippedStandards,
            'total_bigrams'      => $totalBigrams,
            'unique_prev_chords' => count($bigrams),
            'generated_at'       => date('c'),
        ];

        $payload = [
            'bigrams' => $bigrams,
            'totals'  => $totals,
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
            ['Total bigrams (self-transitions included)', $meta['total_bigrams']],
            ['Unique prev chords', $meta['unique_prev_chords']],
        ]);

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

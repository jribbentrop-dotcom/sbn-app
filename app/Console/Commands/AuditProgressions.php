<?php

namespace App\Console\Commands;

use App\Models\ChordProgression;
use App\Services\HarmonicContext;
use App\Services\ProgressionBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Run every progression in sbn_chord_progressions through ProgressionBuilder
 * and emit a diagnostic report flagging suspicious outputs.
 *
 * Diagnostic flags:
 *   1. position_thrash       — adjacent voicings >5 frets apart
 *   2. group_thrash          — voicing_category changes mid-progression
 *   3. over_extended_triad   — plain triad numeral got 4+ note voicing with extensions
 *   4. filter_breach_dom_min — dom7→minor with natural 9/13/#9 on the dominant
 *   5. filter_breach_hdim_9  — m7b5 with natural 9
 *   6. high_vl_score         — adjacent VL score in top decile across the corpus
 */
class AuditProgressions extends Command
{
    protected $signature = 'sbn:audit-progressions
                            {--mode=all : default | simple(simple-lookup) | jazz | category | all}
                            {--out=storage/audits : Output directory}';

    protected $description = 'Audit ProgressionBuilder output across all progressions in the DB';

    private const MAJOR_KEY = 'C';
    private const MINOR_KEY = 'A';

    private const PLAIN_TRIAD_NUMERALS = [
        'I', 'II', 'III', 'IV', 'V', 'VI', 'VII',
        'Im', 'IIm', 'IIIm', 'IVm', 'Vm', 'VIm', 'VIIm',
        'i', 'ii', 'iii', 'iv', 'v', 'vi', 'vii',
        'bII', 'bIII', 'bV', 'bVI', 'bVII',
        'bIIm', 'bIIIm', 'bVIm', 'bVIIm',
    ];

    private const EXTENSION_LABELS_RICH = ['9', 'b9', '#9', '11', '#11', '13', 'b13'];

    public function handle(HarmonicContext $context, ProgressionBuilder $builder): int
    {
        $mode = $this->option('mode');
        $modes = $mode === 'all' ? ['default', 'simple', 'jazz', 'category'] : [$mode];

        $progressions = ChordProgression::orderBy('category')->orderBy('name')->get();
        $this->info("Auditing {$progressions->count()} progressions across modes: " . implode(', ', $modes));

        $report = [
            'generated_at' => now()->toIso8601String(),
            'progression_count' => $progressions->count(),
            'modes' => $modes,
            'runs' => [],
        ];

        $allVlScores = [];

        foreach ($modes as $modeName) {
            foreach ($progressions as $prog) {
                $options = $this->optionsForMode($modeName, $prog->category);
                $key = $this->keyForTonality($prog->tonality);

                try {
                    $hc = $context->buildFromNumerals($key, $prog->numerals);
                    $result = $builder->buildVoicings($hc, $options);
                } catch (\Throwable $e) {
                    $report['runs'][] = [
                        'mode' => $modeName,
                        'progression_id' => $prog->id,
                        'name' => $prog->name,
                        'category' => $prog->category,
                        'numerals' => $prog->numerals,
                        'key' => $key,
                        'error' => $e->getMessage(),
                    ];
                    continue;
                }

                $analysis = $this->analyzeRun($prog, $result, $modeName);
                foreach ($result['vl_scores'] as $s) {
                    if ($s !== null) $allVlScores[] = $s;
                }

                $report['runs'][] = [
                    'mode' => $modeName,
                    'progression_id' => $prog->id,
                    'name' => $prog->name,
                    'category' => $prog->category,
                    'numerals' => $prog->numerals,
                    'key' => $key,
                    'options' => $options,
                    'diagnostics' => $result['diagnostics'] ?? null,
                    'chords' => array_map(fn($s) => [
                        'chord_name' => $s['chord_name'],
                        'numeral' => $s['roman_numeral'] ?? '',
                        'frets' => $s['voicing']['frets'] ?? null,
                        'position' => $s['voicing']['position'] ?? null,
                        'category' => $s['voicing']['voicing_category'] ?? null,
                        'extensions' => $s['voicing']['extensions'] ?? '',
                        'inversion' => $s['voicing']['inversion'] ?? '',
                    ], $result['selections']),
                    'vl_scores' => $result['vl_scores'],
                    'flags' => $analysis['flags'],
                    'metrics' => $analysis['metrics'],
                ];
            }
        }

        // Top-decile VL threshold for flag #6
        sort($allVlScores);
        $count = count($allVlScores);
        $topDecileIdx = (int) floor($count * 0.9);
        $vlThreshold = $allVlScores[$topDecileIdx] ?? null;

        if ($vlThreshold !== null) {
            foreach ($report['runs'] as &$run) {
                if (!isset($run['vl_scores'])) continue;
                $high = [];
                foreach ($run['vl_scores'] as $i => $s) {
                    if ($s !== null && $s >= $vlThreshold) $high[] = $i;
                }
                if (!empty($high)) {
                    $run['flags'][] = [
                        'kind' => 'high_vl_score',
                        'detail' => "Adjacent VL score(s) at indices " . implode(',', $high) . " ≥ top-decile threshold {$vlThreshold}",
                    ];
                }
            }
            unset($run);
            $report['vl_threshold_top_decile'] = $vlThreshold;
        }

        // Aggregate flag counts
        $flagCounts = [];
        foreach ($report['runs'] as $run) {
            foreach ($run['flags'] ?? [] as $f) {
                $flagCounts[$f['kind']] = ($flagCounts[$f['kind']] ?? 0) + 1;
            }
        }
        $report['flag_counts'] = $flagCounts;

        // Write outputs
        $outDir = base_path($this->option('out'));
        if (!File::isDirectory($outDir)) File::makeDirectory($outDir, 0755, true);
        $stamp = date('Ymd-His');
        $jsonPath = "$outDir/progressions-$stamp.json";
        $mdPath = "$outDir/progressions-$stamp.md";

        File::put($jsonPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        File::put($mdPath, $this->renderMarkdown($report));

        $this->info("Wrote $jsonPath");
        $this->info("Wrote $mdPath");
        $this->newLine();
        $this->info("Flag summary:");
        foreach ($flagCounts as $kind => $n) {
            $this->line("  $kind: $n");
        }

        return self::SUCCESS;
    }

    private function optionsForMode(string $mode, ?string $category = null): array
    {
        return match ($mode) {
            'simple' => ['mode' => 'simple', 'style' => '', 'extensions' => false, 'rootOnly' => true],
            'jazz' => ['style' => 'drop', 'extensions' => true, 'rootOnly' => false],
            'category' => ['category' => $category, 'style' => '', 'extensions' => false, 'rootOnly' => false],
            default => ['style' => '', 'extensions' => false, 'rootOnly' => false],
        };
    }

    private function keyForTonality(?string $tonality): string
    {
        return $tonality === 'minor' ? self::MINOR_KEY : self::MAJOR_KEY;
    }

    private function analyzeRun(ChordProgression $prog, array $result, string $mode): array
    {
        $flags = [];
        $metrics = [];

        $selections = $result['selections'];
        $n = count($selections);

        $positions = [];
        $categories = [];
        foreach ($selections as $s) {
            $v = $s['voicing'] ?? null;
            $positions[] = $v['position'] ?? null;
            $categories[] = $v['voicing_category'] ?? null;
        }
        $metrics['positions'] = $positions;
        $metrics['categories'] = $categories;

        // Flag 1: position thrashing (adjacent jump > 5 frets)
        for ($i = 0; $i < $n - 1; $i++) {
            $a = $positions[$i];
            $b = $positions[$i + 1];
            if ($a === null || $b === null) continue;
            $jump = abs($a - $b);
            if ($jump > 5) {
                $flags[] = [
                    'kind' => 'position_thrash',
                    'detail' => "Position jump from fret $a to fret $b between chord $i ({$selections[$i]['chord_name']}) and chord " . ($i + 1) . " ({$selections[$i + 1]['chord_name']}): {$jump} frets",
                ];
            }
        }

        // Flag 2: group thrashing
        $structuredCats = array_filter($categories, fn($c) => $c !== null && $c !== '');
        $distinctCats = array_unique($structuredCats);
        if (count($distinctCats) > 2) {
            $flags[] = [
                'kind' => 'group_thrash',
                'detail' => "Multiple voicing groups in one progression: " . implode(', ', $distinctCats),
            ];
        }

        // Flag 3: over-extended triads
        // Skip for jazz/latin categories where plain numerals are intentionally upgraded to 7-chords
        $category = $result['diagnostics']['category_normalized'] ?? $result['options']['category'] ?? null;
        $skipOverExtended = in_array($category, ['jazz', 'latin'], true);
        
        if (!$skipOverExtended) {
            $numerals = preg_split('/\s*,\s*/', $prog->numerals);
            foreach ($selections as $i => $s) {
                $numeral = $numerals[$i] ?? '';
                $isPlainTriad = in_array($numeral, self::PLAIN_TRIAD_NUMERALS, true);
                if (!$isPlainTriad) continue;

                $v = $s['voicing'] ?? null;
                if (!$v) continue;
                $frets = $v['frets'] ?? '';
                $soundingCount = strlen(str_replace('x', '', strtolower($frets)));
                $ext = trim($v['extensions'] ?? '');
                $hasRichExt = false;
                foreach (self::EXTENSION_LABELS_RICH as $label) {
                    if (str_contains($ext, $label)) { $hasRichExt = true; break; }
                }
                if ($soundingCount >= 4 && $hasRichExt) {
                    $flags[] = [
                        'kind' => 'over_extended_triad',
                        'detail' => "Numeral '$numeral' (plain triad) at index $i got voicing {$v['chord_name']} with extensions '$ext' ($soundingCount sounding notes)",
                    ];
                }
            }
        }

        // Flag 4 & 5: filter breaches — inspect interval_labels of selected voicings
        // We only have voicing-level data here, so check based on selection name + extensions.
        for ($i = 0; $i < $n - 1; $i++) {
            $cur = $selections[$i]['voicing'] ?? null;
            $next = $selections[$i + 1]['voicing'] ?? null;
            if (!$cur || !$next) continue;

            $curName = $selections[$i]['chord_name'];
            $nextName = $selections[$i + 1]['chord_name'];

            $curIsDom = $this->nameIsDom($curName);
            $nextIsMinor = $this->nameIsMinor($nextName);
            if ($curIsDom && $nextIsMinor) {
                $ext = $cur['extensions'] ?? '';
                foreach (['13', '6', '9', '#9'] as $bad) {
                    if (str_contains($ext, $bad) && !str_starts_with(ltrim($ext, '('), 'b9')) {
                        // crude — check exact tokens
                        $tokens = preg_split('/[(),\s]+/', $ext, -1, PREG_SPLIT_NO_EMPTY);
                        if (in_array($bad, $tokens, true)) {
                            $flags[] = [
                                'kind' => 'filter_breach_dom_min',
                                'detail' => "Dom→minor: $curName ($ext) → $nextName has natural '$bad' which the harmony filter should have excluded",
                            ];
                            break;
                        }
                    }
                }
            }

            // Flag 5: m7b5 with natural 9
            if ($this->nameIsHalfDim($curName)) {
                $tokens = preg_split('/[(),\s]+/', $cur['extensions'] ?? '', -1, PREG_SPLIT_NO_EMPTY);
                if (in_array('9', $tokens, true)) {
                    $flags[] = [
                        'kind' => 'filter_breach_hdim_9',
                        'detail' => "m7b5 chord $curName has natural 9 — filter should exclude this",
                    ];
                }
            }
        }

        // Metric: total fret variance
        $numericPositions = array_filter($positions, fn($p) => is_numeric($p));
        if (count($numericPositions) >= 2) {
            $metrics['fret_range'] = max($numericPositions) - min($numericPositions);
        }

        return ['flags' => $flags, 'metrics' => $metrics];
    }

    private function nameIsDom(string $name): bool
    {
        // C7, G7, F#7, Bb7, etc. — but NOT Cmaj7, Cm7, Cm7b5
        return (bool) preg_match('/^[A-G][#b]?7(\(|$|b9|#9|#11|b13|alt)/', $name);
    }

    private function nameIsMinor(string $name): bool
    {
        return (bool) preg_match('/^[A-G][#b]?m($|7|6|9|11|13|\(|Maj)/', $name)
            && !preg_match('/m7b5/', $name);
    }

    private function nameIsHalfDim(string $name): bool
    {
        return (bool) preg_match('/m7b5/', $name);
    }

    private function renderMarkdown(array $report): string
    {
        $md = "# ProgressionBuilder Audit\n\n";
        $md .= "Generated: {$report['generated_at']}\n\n";
        $md .= "Progressions audited: {$report['progression_count']} × " . count($report['modes']) . " modes = " . count($report['runs']) . " runs\n\n";

        $md .= "## Flag Summary\n\n";
        if (empty($report['flag_counts'])) {
            $md .= "_No flags raised._\n\n";
        } else {
            $md .= "| Flag | Count |\n|---|---|\n";
            foreach ($report['flag_counts'] as $kind => $n) {
                $md .= "| $kind | $n |\n";
            }
            $md .= "\n";
        }
        if (isset($report['vl_threshold_top_decile'])) {
            $md .= "Top-decile VL score threshold: **{$report['vl_threshold_top_decile']}**\n\n";
        }

        $md .= "## Runs\n\n";
        foreach ($report['runs'] as $run) {
            $md .= "### [{$run['mode']}] {$run['name']} ({$run['category']}) — id {$run['progression_id']}\n\n";
            $md .= "Numerals: `{$run['numerals']}` (key {$run['key']})\n\n";

            if (isset($run['error'])) {
                $md .= "**ERROR:** {$run['error']}\n\n";
                continue;
            }

            $md .= "| # | Numeral | Chord | Frets | Pos | Cat | Inv | Ext |\n";
            $md .= "|---|---|---|---|---|---|---|---|\n";
            foreach ($run['chords'] as $i => $c) {
                $md .= "| $i | {$c['numeral']} | {$c['chord_name']} | `" . ($c['frets'] ?? '—') . "` | " . ($c['position'] ?? '—') . " | " . ($c['category'] ?? '—') . " | " . ($c['inversion'] ?? '—') . " | " . ($c['extensions'] ?: '—') . " |\n";
            }
            $md .= "\n";

            if (!empty($run['vl_scores'])) {
                $md .= "VL scores: " . implode(', ', array_map(fn($s) => $s ?? '—', $run['vl_scores'])) . "\n\n";
            }

            if (!empty($run['flags'])) {
                $md .= "**Flags:**\n";
                foreach ($run['flags'] as $f) {
                    $md .= "- `{$f['kind']}` — {$f['detail']}\n";
                }
                $md .= "\n";
            }

            if (!empty($run['diagnostics'])) {
                $md .= "**Diagnostics:**\n";
                $diag = $run['diagnostics'];
                if (!empty($diag['category_normalized'])) {
                    $md .= "- Category normalized: {$diag['category_input']} → {$diag['category_normalized']}\n";
                }
                if (!empty($diag['style_ignored'])) {
                    $md .= "- Style ignored: {$diag['style_ignored']['reason']}\n";
                }
                if (!empty($diag['category_pool_fallbacks'])) {
                    $md .= "- Pool fallbacks:\n";
                    foreach ($diag['category_pool_fallbacks'] as $fb) {
                        $md .= "  - Slot {$fb['slot']}: {$fb['requested_pool']} → {$fb['fallback_pool']} ({$fb['reason']})\n";
                    }
                }
                $md .= "\n";
            }
        }

        return $md;
    }
}

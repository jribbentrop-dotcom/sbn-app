<?php

namespace App\Console\Commands;

use App\Models\ChordProgression;
use App\Services\HarmonicContext;
use App\Services\ProgressionBuilder;
use Illuminate\Console\Command;

/**
 * Pedagogical cadence coverage audit.
 *
 * Runs every progression that has at least one cadence-requirement edge
 * (see Phase-E-Extension-Table.yaml `cadence_requirements`) through the
 * builder with `pedagogical_vl: true` in all 12 keys and reports the
 * enforcement level each edge landed on:
 *
 *   same_string — the guide-tone motion is visible as one dot sliding on
 *                 one string (the teaching ideal)
 *   same_voice  — motion fired but crosses strings
 *   dropped     — no candidate pair could satisfy the requirement at all;
 *                 this is the curation worklist (missing voicing pairs)
 *   abandoned   — requirements were feasible per-edge but no global path
 *                 existed (position/bass constraints in conflict)
 */
class CadenceAudit extends Command
{
    protected $signature = 'builder:cadence-audit
                            {--slug= : Audit a single progression slug}
                            {--verbose-edges : Print every non-same_string edge}';

    protected $description = 'Audit pedagogical cadence enforcement across all progressions and keys';

    private const KEYS = ['C', 'Db', 'D', 'Eb', 'E', 'F', 'Gb', 'G', 'Ab', 'A', 'Bb', 'B'];

    public function handle(HarmonicContext $ctx, ProgressionBuilder $bld): int
    {
        $query = ChordProgression::orderBy('sort_order');
        if ($this->option('slug')) {
            $query->where('slug', $this->option('slug'));
        }
        $progs = $query->get();

        $totals = ['same_string' => 0, 'same_voice' => 0, 'dropped' => 0];
        $abandonedRuns = 0;
        $rows = [];
        $worklist = [];

        foreach ($progs as $p) {
            $counts = ['same_string' => 0, 'same_voice' => 0, 'dropped' => 0];
            $abandoned = 0;
            $sawRequirement = false;

            foreach (self::KEYS as $key) {
                try {
                    $built = $bld->buildVoicings($ctx->buildFromNumerals($key, $p->numerals), [
                        'category'       => $p->category,
                        'pedagogical_vl' => true,
                    ]);
                } catch (\Throwable $e) {
                    $this->warn("  {$p->slug} [$key] ERROR: {$e->getMessage()}");
                    continue;
                }

                $diag = $built['diagnostics'] ?? [];
                $edges = $diag['pedagogical_vl'] ?? [];
                if (empty($edges)) {
                    continue;
                }
                $sawRequirement = true;

                if (!empty($diag['pedagogical_vl_abandoned'])) {
                    $abandoned++;
                }

                foreach ($edges as $e) {
                    $level = $e['level'];
                    $counts[$level] = ($counts[$level] ?? 0) + 1;
                    if ($level !== 'same_string' && $this->option('verbose-edges')) {
                        $this->line(sprintf('  %-40s [%s] edge %d (%s): %s',
                            $p->slug, $key, $e['edge'], $e['requirement'], $level));
                    }
                    if ($level === 'dropped') {
                        $worklist["{$p->slug} edge {$e['edge']} ({$e['requirement']})"][] = $key;
                    }
                }
            }

            if (!$sawRequirement) {
                continue;
            }

            foreach ($counts as $k => $v) {
                $totals[$k] += $v;
            }
            $abandonedRuns += $abandoned;

            $rows[] = [
                $p->slug,
                $p->category,
                $counts['same_string'],
                $counts['same_voice'],
                $counts['dropped'],
                $abandoned,
            ];
        }

        if (empty($rows)) {
            $this->info('No progression has a cadence-requirement edge.');
            return self::SUCCESS;
        }

        $this->table(
            ['progression', 'category', 'same_string', 'same_voice', 'dropped', 'abandoned'],
            $rows
        );

        $edgeTotal = array_sum($totals);
        $this->info(sprintf(
            'Edges×keys: %d total — same_string %d (%.0f%%), same_voice %d, dropped %d; abandoned runs: %d',
            $edgeTotal,
            $totals['same_string'], $edgeTotal ? 100 * $totals['same_string'] / $edgeTotal : 0,
            $totals['same_voice'], $totals['dropped'], $abandonedRuns
        ));

        if (!empty($worklist)) {
            $this->newLine();
            $this->warn('Curation worklist (requirement unsatisfiable — missing voicing pairs):');
            foreach ($worklist as $edge => $keys) {
                $this->line("  $edge: " . implode(',', $keys));
            }
        }

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Services\VoicingCrossref;
use Illuminate\Console\Command;
use Tests\Unit\IdentifierSequenceCases;

/**
 * Drives the curated sequence fixtures (Tests\Unit\IdentifierSequenceCases)
 * through the real end-to-end pipeline (identifyVoicingsBatch = Phase 1 per
 * slot + Phase 2 contextual rerank) and prints the resolved name sequence.
 *
 * Purpose: hand-verification. The sequence regression suite must NOT freeze a
 * Phase-2 "expected" name until a human has confirmed it against this output —
 * that is the guard against re-blessing a drifted reading as ground truth.
 *
 *   php artisan sbn:audit-identifier-sequences
 *
 * Read-only. Uses the DB configured for the app (point it at sbn.db).
 */
class AuditIdentifierSequences extends Command
{
    protected $signature = 'sbn:audit-identifier-sequences';
    protected $description = 'Run curated fret-sequence fixtures through Phase 1+2 and print resolved names for verification';

    public function handle(VoicingCrossref $crossref): int
    {
        $groups = [
            'TIER1_RESOLVED'  => IdentifierSequenceCases::TIER1_RESOLVED,
            'TIER1_TRIGRAM'   => IdentifierSequenceCases::TIER1_TRIGRAM,
            'TIER3_AMBIGUOUS' => IdentifierSequenceCases::TIER3_AMBIGUOUS,
        ];

        foreach ($groups as $tier => $cases) {
            $this->line('');
            $this->info("=== {$tier} ===");
            foreach ($cases as $name => $case) {
                $key = $case['song_key'] ?? null;
                $this->line('');
                $this->line("  {$name}  (key=" . ($key ?: 'none') . ")");
                $this->line("    why: {$case['why']}");

                $voicings = [];
                foreach ($case['slots'] as $i => $slot) {
                    $voicings["slot$i"] = ['frets' => $slot['frets'], 'position' => 1];
                }
                $ctx = $key ? ['song_key' => $key] : null;
                $results = $crossref->identifyVoicingsBatch($voicings, $ctx);

                foreach ($case['slots'] as $i => $slot) {
                    $r = $results["slot$i"] ?? [];
                    $resolved = $r['name'] ?? '(none)';
                    $reason   = $r['reinterpret_reason'] ?? null;
                    $tag      = ($r['reinterpreted'] ?? false) ? " [reinterpreted: {$reason}]" : '';
                    $isolated = $slot['isolated'] ?? $slot['role_a']
                        ?? (isset($slot['want_root']) ? "want:{$slot['want_root']}" : '?');
                    $this->line(sprintf(
                        "    slot%d  %-8s  isolated=%-14s  ->  Phase2=%-16s%s",
                        $i, $slot['frets'], $isolated, $resolved, $tag
                    ));
                }
            }
        }

        $this->line('');
        $this->comment('Verify each Phase2 name by ear/theory, THEN pin it as `expected` in IdentifierSequenceCases::TIER1_RESOLVED.');
        return self::SUCCESS;
    }
}

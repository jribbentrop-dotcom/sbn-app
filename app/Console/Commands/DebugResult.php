<?php

namespace App\Console\Commands;

use App\Services\VoicingCrossref;
use Illuminate\Console\Command;

class DebugResult extends Command
{
    protected $signature = 'sbn:debug-result {frets}';
    protected $description = 'Debug why actual result differs from expected';

    public function handle(VoicingCrossref $crossref): int
    {
        $frets = $this->argument('frets');
        $this->info("=== Debugging result for: $frets ===");
        
        $result = $crossref->identifyFromFrets($frets);
        
        $this->info("Actual result details:");
        $this->line("Name: {$result['name']}");
        $this->line("Root: {$result['root']}");
        $this->line("Quality: {$result['quality']}");
        $this->line("Extensions: '{$result['extensions']}'");
        $this->line("Bass note: {$result['bass_note']}");
        $this->line("Inversion: {$result['inversion']}");
        $this->line("Confidence: {$result['confidence']}");
        $this->line("Rootless: " . ($result['rootless'] ? 'YES' : 'NO'));
        
        // Let's manually check what should happen for x5533x
        if ($frets === 'x5533x') {
            $this->info("\nExpected analysis for x5533x:");
            $this->line("Should be: Gm/D");
            $this->line("Root: G");
            $this->line("Quality: min");
            $this->line("Bass note: D");
            $this->line("Extensions: (empty)");
        }
        
        return self::SUCCESS;
    }
}

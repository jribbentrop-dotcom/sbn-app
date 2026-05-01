<?php

namespace App\Console\Commands;

use App\Services\VoicingCrossref;
use Illuminate\Console\Command;

class DebugWine12 extends Command
{
    protected $signature = 'sbn:debug-wine12';
    protected $description = 'Debug Wine #12 chord parsing issue';

    public function handle(VoicingCrossref $crossref): int
    {
        $this->info("=== Debugging Wine #12 chord parsing ===");
        
        // First, let's find what the Wine #12 chord actually is
        // We need to examine the MusicXML or find the fret pattern
        
        // Let's check if we can find it in any audit files or data
        $this->info("Looking for Wine #12 chord data...");
        
        // For now, let's test some common Gm7(11) voicings to see what we get
        $testPatterns = [
            '353333', // Common Gm7(11) voicing
            '3x3333', // Another possibility
            'x5433x', // Gm7(11) variant
            'x10x10x', // Higher position Gm7(11)
        ];
        
        foreach ($testPatterns as $frets) {
            $this->info("\nTesting pattern: $frets");
            $result = $crossref->identifyFromFrets($frets);
            $this->line("Result: {$result['name']}");
            
            if ($result['name'] === 'Csus4(9)/G') {
                $this->line("→ Found the problematic pattern!");
                
                // Debug this specific case
                $this->call('sbn:debug-all-candidates', ['frets' => $frets]);
            }
        }
        
        // Let's also check what Csus4(9)/G actually looks like
        $this->info("\n=== Analyzing Csus4(9)/G ===");
        $this->info("Csus4(9) = C + F + Bb + D");
        $this->info("With G bass: G + C + F + Bb + D");
        $this->info("This could be reinterpreted as Gm7(11) = G + Bb + D + F + C");
        
        return self::SUCCESS;
    }
}

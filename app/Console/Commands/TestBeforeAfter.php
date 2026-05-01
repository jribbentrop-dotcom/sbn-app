<?php

namespace App\Console\Commands;

use App\Services\VoicingCrossref;
use Illuminate\Console\Command;

class TestBeforeAfter extends Command
{
    protected $signature = 'sbn:test-before-after {frets}';
    protected $description = 'Test chord identification before and after slash chord changes';

    public function handle(VoicingCrossref $crossref): int
    {
        $frets = $this->argument('frets');
        $this->info("=== Testing chord: $frets ===");
        
        // Test with current implementation (with slash chord fix)
        $this->info("\nCurrent result (with slash chord fix):");
        $currentResult = $crossref->identifyFromFrets($frets);
        $this->line("{$currentResult['name']}");
        
        // Let's manually test what it would be without slash chord bonus
        $this->info("\nSimulating result without slash chord bonus:");
        
        // Compute pitch classes
        $tuning = [4, 9, 2, 7, 11, 4];
        $pitchClasses = [];
        $bassPc = null;
        
        for ($i = 0; $i < 6; $i++) {
            $ch = strtolower($frets[$i]);
            if ($ch === 'x') continue;
            $fret = ctype_xdigit($ch) ? hexdec($ch) : (int)$ch;
            $pc = ($tuning[$i] + $fret) % 12;
            $pitchClasses[] = $pc;
            if ($bassPc === null) $bassPc = $pc;
        }
        
        $pcSet = array_values(array_unique($pitchClasses));
        $this->line("Bass PC: $bassPc, PC Set: [" . implode(', ', $pcSet) . "]");
        
        // Test G as root (for Gm7(11))
        $rootPc = 7; // G
        $qualities = [
            'm7' => [0, 3, 7, 10],
            'm7b5' => [0, 3, 6, 10],
            'm6' => [0, 3, 7, 9],
        ];
        
        foreach ($qualities as $quality => $intervals) {
            $expectedPcs = array_map(fn($iv) => ($rootPc + $iv) % 12, $intervals);
            $matched = count(array_intersect($expectedPcs, $pcSet));
            $total = count($expectedPcs);
            $leftover = array_diff($pcSet, $expectedPcs);
            
            // Check if leftover notes are valid extensions
            $unexplained = 0;
            foreach ($leftover as $lpc) {
                $ivLeft = ($lpc - $rootPc + 12) % 12;
                $extensionIntervals = [1, 2, 3, 5, 6, 8, 9]; // b9,9,#9,11,#11,b13,13
                if (!in_array($ivLeft, $extensionIntervals, true)) {
                    $unexplained++;
                }
            }
            
            if ($matched >= 2 && $matched >= $total - 1) {
                $raw = $matched * 100 - 50 - $unexplained * 50;
                $score = $raw * 4; // bass boost
                
                $this->line("G $quality: matched=$matched/$total, unexplained=$unexplained, raw=$raw, score=$score");
                
                if ($unexplained === 0) {
                    $this->line("  → All notes explained - this could be G$quality with extensions");
                }
            }
        }
        
        return self::SUCCESS;
    }
}

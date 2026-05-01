<?php

namespace App\Console\Commands;

use App\Services\VoicingCrossref;
use Illuminate\Console\Command;

class DebugSlash extends Command
{
    protected $signature = 'sbn:debug-slash';
    protected $description = 'Debug specific slash chord cases';

    public function handle(VoicingCrossref $crossref): int
    {
        // Debug the failing cases
        $failingCases = [
            'xx333x', // Expected: Bb/F
            'xxaaax', // Expected: F/C
        ];

        foreach ($failingCases as $frets) {
            $this->info("=== Debugging: $frets ===");
            
            // Compute pitch classes manually
            $tuning = [4, 9, 2, 7, 11, 4]; // E A D G B E
            $pitchClasses = [];
            $bassPc = null;
            
            for ($i = 0; $i < 6; $i++) {
                $ch = strtolower($frets[$i]);
                if ($ch === 'x') continue;
                $fret = ctype_xdigit($ch) ? hexdec($ch) : (int)$ch;
                $pc = ($tuning[$i] + $fret) % 12;
                $pitchClasses[] = $pc;
                if ($bassPc === null) $bassPc = $pc;
                $this->line("String $i: fret $ch, tuning {$tuning[$i]}, pc $pc");
            }
            
            $pcSet = array_values(array_unique($pitchClasses));
            $this->line("Bass PC: $bassPc");
            $this->line("PC Set: [" . implode(', ', $pcSet) . "]");
            
            // Test different root candidates
            $qualities = ['maj7', 'maj', 'm7', 'min'];
            foreach ($qualities as $quality) {
                $this->line("\n--- Testing quality: $quality ---");
                
                // Test Bb as root for xx333x
                if ($frets === 'xx333x') {
                    $testRoot = 10; // Bb
                } else {
                    $testRoot = 5; // F
                }
                
                $intervals = [
                    'maj7' => [0, 4, 7, 11],
                    'maj' => [0, 4, 7],
                    'm7' => [0, 3, 7, 10],
                    'min' => [0, 3, 7],
                ];
                
                $expectedPcs = array_map(fn($iv) => ($testRoot + $iv) % 12, $intervals[$quality]);
                $matched = count(array_intersect($expectedPcs, $pcSet));
                $total = count($expectedPcs);
                $leftover = array_diff($pcSet, $expectedPcs);
                
                $this->line("Root PC: $testRoot");
                $this->line("Expected PCs: [" . implode(', ', $expectedPcs) . "]");
                $this->line("Matched: $matched/$total");
                $this->line("Leftover: [" . implode(', ', $leftover) . "]");
                
                // Check slash candidate conditions
                $bassIv = ($bassPc - $testRoot + 12) % 12;
                $isSlashCandidate = !($testRoot === $bassPc)
                    && in_array($bassIv, [3, 4, 7, 10, 11], true)
                    && ($matched === $total || empty($leftover));
                
                $this->line("Bass interval: $bassIv");
                $this->line("Is slash candidate: " . ($isSlashCandidate ? 'YES' : 'NO'));
                
                if ($isSlashCandidate) {
                    $this->line("*** SLASH CANDIDATE FOUND! ***");
                }
            }
            
            // Show actual result
            $result = $crossref->identifyFromFrets($frets);
            $this->line("\nActual result: {$result['name']}");
            $this->line("");
        }

        return self::SUCCESS;
    }
}

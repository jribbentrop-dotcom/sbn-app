<?php

namespace App\Console\Commands;

use App\Services\VoicingCrossref;
use Illuminate\Console\Command;
use ReflectionClass;

class DebugScoring extends Command
{
    protected $signature = 'sbn:debug-scoring';
    protected $description = 'Debug scoring for specific slash chord cases';

    public function handle(VoicingCrossref $crossref): int
    {
        // Debug the failing cases with detailed scoring
        $failingCases = [
            'xx333x', // Expected: Bb/F
        ];

        foreach ($failingCases as $frets) {
            $this->info("=== Debugging scoring for: $frets ===");
            
            // Manually trace through the logic
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
            
            // Constants
            $bassBoost = 4;
            $exactBonus = 3;
            $slashBonus = 3.5;
            
            // Test the two competing candidates
            $candidates = [
                ['root' => $bassPc, 'quality' => 'm7', 'desc' => 'Gm7 (bass-rooted)'],
                ['root' => 10, 'quality' => 'maj', 'desc' => 'Bb (slash candidate)']
            ];
            
            foreach ($candidates as $candidate) {
                $rootPc = $candidate['root'];
                $quality = $candidate['quality'];
                $isBass = ($rootPc === $bassPc);
                
                $intervals = $quality === 'm7' ? [0, 3, 7, 10] : [0, 4, 7];
                $expectedPcs = array_map(fn($iv) => ($rootPc + $iv) % 12, $intervals);
                $matched = count(array_intersect($expectedPcs, $pcSet));
                $total = count($expectedPcs);
                $leftover = array_diff($pcSet, $expectedPcs);
                $unexplained = 0;
                foreach ($leftover as $lpc) {
                    $ivLeft = ($lpc - $rootPc + 12) % 12;
                    $extensionIntervals = [1, 2, 3, 5, 6, 8, 9]; // b9,9,#9,11,#11,b13,13
                    if (!in_array($ivLeft, $extensionIntervals, true)) {
                        $unexplained++;
                    }
                }
                
                if ($matched === $total) {
                    $raw = $total * 100 - $unexplained * 50;
                    if ($total >= 4) $raw *= $exactBonus;
                } elseif ($matched >= 2 && $matched >= $total - 1) {
                    $raw = $matched * 100 - 50 - $unexplained * 50;
                } else {
                    $raw = 0;
                }
                
                // Slash-chord candidate check
                $bassIv = ($bassPc - $rootPc + 12) % 12;
                $isSlashCandidate = !$isBass
                    && in_array($bassIv, [3, 4, 7, 10, 11], true)
                    && ($matched === $total || $unexplained === 0);
                
                $score = $raw;
                if ($isBass) {
                    $score *= $bassBoost;
                } elseif ($isSlashCandidate) {
                    $score *= $slashBonus;
                }
                
                $this->line(sprintf("%s: root=%d, matched=%d/%d, raw=%d, %s → score=%d", 
                    $candidate['desc'], 
                    $rootPc, 
                    $matched, 
                    $total, 
                    $raw,
                    $isBass ? 'bass×4' : ($isSlashCandidate ? 'slash×3.5' : 'no bonus'),
                    $score
                ));
            }
            
            $this->line("");
        }

        return self::SUCCESS;
    }
}

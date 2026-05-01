<?php

namespace App\Console\Commands;

use App\Services\VoicingCrossref;
use Illuminate\Console\Command;

class DebugAllCandidates extends Command
{
    protected $signature = 'sbn:debug-all-candidates {frets}';
    protected $description = 'Debug all candidates for a specific fret pattern';

    public function handle(VoicingCrossref $crossref): int
    {
        $frets = $this->argument('frets');
        $this->info("=== Debugging all candidates for: $frets ===");
        
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
        
        // Constants
        $bassBoost = 4;
        $exactBonus = 3;
        $slashBonus = 3.5;
        
        // All qualities from IDENTIFY_QUALITY_INTERVALS
        $qualities = [
            'maj7' => [0, 4, 7, 11],
            'maj6' => [0, 4, 7, 9],
            'm7' => [0, 3, 7, 10],
            'm6' => [0, 3, 7, 9],
            'dom7' => [0, 4, 7, 10],
            '7' => [0, 4, 7, 10],
            'm7b5' => [0, 3, 6, 10],
            'o7' => [0, 3, 6, 9],
            'dim7' => [0, 3, 6, 9],
            'mMaj7' => [0, 3, 7, 11],
            'add9' => [0, 2, 4, 7],
            'aug' => [0, 4, 8],
            'dim' => [0, 3, 6],
            'sus4' => [0, 5, 7],
            'sus2' => [0, 2, 7],
            '5' => [0, 7],
            'maj' => [0, 4, 7],
            'min' => [0, 3, 7],
            'm' => [0, 3, 7],
        ];
        
        // Candidate roots: bass first, then voicing PCs, then chromatic fill
        $candidateRoots = array_values(array_unique(array_merge(
            $bassPc !== null ? [$bassPc] : [],
            $pcSet,
            range(0, 11)
        )));
        
        $this->line("\nCandidate roots: [" . implode(', ', $candidateRoots) . "]");
        
        $bestScore = -1;
        $bestCandidate = null;
        
        foreach ($candidateRoots as $rootPc) {
            $isBass = ($rootPc === $bassPc);
            
            foreach ($qualities as $quality => $intervals) {
                $expectedPcs = array_map(fn($iv) => ($rootPc + $iv) % 12, $intervals);
                $matched = count(array_intersect($expectedPcs, $pcSet));
                $total = count($expectedPcs);
                
                // Count unexplained leftovers
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
                    continue;
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
                
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestCandidate = "root=$rootPc, quality=$quality";
                }
                
                // Show all non-zero scores
                if ($score > 0) {
                    $bonus = $isBass ? 'bass×4' : ($isSlashCandidate ? 'slash×3.5' : 'no bonus');
                    $this->line(sprintf("root=%d, %s: matched=%d/%d, raw=%d, %s → score=%d %s", 
                        $rootPc, 
                        $quality,
                        $matched, 
                        $total, 
                        $raw,
                        $bonus,
                        $score,
                        $score === $bestScore ? '← BEST' : ''
                    ));
                }
            }
        }
        
        $this->line("\nBest candidate: $bestCandidate with score $bestScore");
        
        // Show actual result
        $result = $crossref->identifyFromFrets($frets);
        $this->line("Actual result: {$result['name']}");

        return self::SUCCESS;
    }
}

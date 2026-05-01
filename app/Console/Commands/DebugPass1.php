<?php

namespace App\Console\Commands;

use App\Services\VoicingCrossref;
use Illuminate\Console\Command;

class DebugPass1 extends Command
{
    protected $signature = 'sbn:debug-pass1 {frets}';
    protected $description = 'Debug Pass 1 scoring with detailed output';

    public function handle(VoicingCrossref $crossref): int
    {
        $frets = $this->argument('frets');
        $this->info("=== Debugging Pass 1 for: $frets ===");
        
        // Temporarily add debug output to identifyFromFrets by creating a test version
        $result = $this->debugIdentifyFromFrets($crossref, $frets);
        
        return self::SUCCESS;
    }
    
    private function debugIdentifyFromFrets(VoicingCrossref $crossref, string $frets): array
    {
        // Copy the exact logic from identifyFromFrets but add debug output
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

        if (empty($pitchClasses)) {
            return $this->noResult();
        }

        $pcSet = array_values(array_unique($pitchClasses));

        // ── Step 2: Score (root, quality) pairs — Pass 1 ──
        $bassBoost  = 4;
        $exactBonus = 3;
        $slashBonus = 3.5;

        $bestScore   = -1;
        $bestRootPc  = null;
        $bestQuality = null;

        // Sort qualities longest-first (more specific wins ties)
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
        uasort($qualities, fn($a, $b) => count($b) - count($a));

        // Candidate roots: bass first, then voicing PCs, then chromatic fill
        $candidateRoots = array_values(array_unique(array_merge(
            $bassPc !== null ? [$bassPc] : [],
            $pcSet,
            range(0, 11)
        )));

        $this->line("Candidate roots: [" . implode(', ', $candidateRoots) . "]");
        $this->line("");

        foreach ($candidateRoots as $rootPc) {
            $isBass = ($rootPc === $bassPc);

            foreach ($qualities as $quality => $intervals) {
                $expectedPcs = array_map(fn($iv) => ($rootPc + $iv) % 12, $intervals);
                $matched = count(array_intersect($expectedPcs, $pcSet));
                $total = count($expectedPcs);

                // Count unexplained leftovers (not a chord tone or known extension)
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
                    // Exact bonus only for 7th chords (4+ tones); triads excluded
                    if ($total >= 4) $raw *= $exactBonus;
                } elseif ($matched >= 2 && $matched >= $total - 1) {
                    $raw = $matched * 100 - 50 - $unexplained * 50;
                } else {
                    continue;
                }

                // Slash-chord candidate check: bass is chord tone (3rd, 5th, 7th, maj7th)
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
                    $bestRootPc = $rootPc;
                    $bestQuality = $quality;
                }
                
                // Show all candidates with scores
                if ($score > 0) {
                    $bonus = $isBass ? 'bass×4' : ($isSlashCandidate ? 'slash×3.5' : 'no bonus');
                    $status = $score === $bestScore ? '← CURRENT BEST' : '';
                    $this->line(sprintf("root=%d, %s: matched=%d/%d, raw=%d, %s → score=%d %s", 
                        $rootPc, 
                        $quality,
                        $matched, 
                        $total, 
                        $raw,
                        $bonus,
                        $score,
                        $status
                    ));
                }
            }
        }

        $this->line("");
        $this->line("Pass 1 winner: root=$bestRootPc, quality=$bestQuality, score=$bestScore");
        
        // Now check what happens with rootless detection
        if ($bestRootPc === null) {
            $this->line("Pass 1 found nothing - checking rootless...");
            // Would check rootless here
        } else {
            $this->line("Pass 1 found something - checking if rootless upgrade applies...");
            $isPlainTriad = in_array($bestQuality, ['maj','min','m','aug'], true);
            $frettedCount = strlen(str_replace('x', '', strtolower($frets)));
            $this->line("Best quality: $bestQuality (plain triad: " . ($isPlainTriad ? 'YES' : 'NO') . ")");
            $this->line("PC set count: " . count($pcSet) . " (≤3: " . (count($pcSet) <= 3 ? 'YES' : 'NO') . ")");
            $this->line("Fretted count: $frettedCount (≤3: " . ($frettedCount <= 3 ? 'YES' : 'NO') . ")");
            
            if ($isPlainTriad && count($pcSet) <= 3 && $frettedCount <= 3) {
                $this->line("→ ROOTLESS UPGRADE WOULD APPLY!");
            } else {
                $this->line("→ No rootless upgrade");
            }
        }
        
        // Show actual result
        $actualResult = $crossref->identifyFromFrets($frets);
        $this->line("Actual result: {$actualResult['name']}");

        return $actualResult;
    }
    
    private function noResult(): array
    {
        return [
            'name' => '',
            'root' => '',
            'quality' => '',
            'extensions' => '',
            'bass_note' => '',
            'inversion' => '',
            'diagram_id' => null,
            'confidence' => 'none',
            'rootless' => false,
        ];
    }
}

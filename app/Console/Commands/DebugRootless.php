<?php

namespace App\Console\Commands;

use App\Services\VoicingCrossref;
use Illuminate\Console\Command;

class DebugRootless extends Command
{
    protected $signature = 'sbn:debug-rootless {frets}';
    protected $description = 'Debug rootless voicing detection';

    public function handle(VoicingCrossref $crossref): int
    {
        $frets = $this->argument('frets');
        $this->info("=== Debugging rootless detection for: $frets ===");
        
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
        
        // Check rootless templates from the code
        $rootlessTemplates = [
            'm7' => [[3,7,10],[3,10,5]],
            '7(#9)' => [[3,4,10]],
            '7' => [[4,7,10],[4,10,2]],
            'maj7' => [[4,7,11]],
            'm7b5' => [[3,6,10]],
            '7(b9)' => [[1,4,10]],
            '7(#11)' => [[4,6,10]],
            '6' => [[3,5,9]],
            'maj6' => [[3,5,9]],
            'm6' => [[3,9,5]],
            '9' => [[4,7,10,2]],
            'maj9' => [[4,7,11,2]],
            'm9' => [[3,10,5,2]],
            '13' => [[4,7,10,9]],
            'maj13' => [[4,7,11,9]],
            'm13' => [[3,10,5,9]],
        ];
        
        $this->line("\nChecking rootless templates:");
        
        foreach ($rootlessTemplates as $quality => $templates) {
            foreach ($templates as $template) {
                $matched = 0;
                foreach ($template as $iv) {
                    $pc = ($bassPc + $iv) % 12;
                    if (in_array($pc, $pcSet, true)) {
                        $matched++;
                    }
                }
                
                if ($matched === count($template)) {
                    $this->line("✓ Rootless $quality matches template [" . implode(', ', $template) . "]");
                }
            }
        }
        
        // Show actual result
        $result = $crossref->identifyFromFrets($frets);
        $this->line("\nActual result: {$result['name']}");
        $this->line("Rootless: " . ($result['rootless'] ? 'YES' : 'NO'));

        return self::SUCCESS;
    }
}

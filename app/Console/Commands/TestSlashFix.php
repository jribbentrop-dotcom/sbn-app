<?php

namespace App\Console\Commands;

use App\Services\VoicingCrossref;
use Illuminate\Console\Command;

class TestSlashFix extends Command
{
    protected $signature = 'sbn:test-slash-fix';
    protected $description = 'Test the Phase 1 slash chord fix implementation';

    public function handle(VoicingCrossref $crossref): int
    {
        // Test cases from the spec - Pattern 1 cases that should flip to slash chords
        $pattern1Cases = [
            ['frets' => 'x5533x', 'expected' => 'Gm/D', 'description' => 'Wine #10, #43'],
            ['frets' => 'ax8aax', 'expected' => 'Bbmaj7/D', 'description' => 'Wine #9, #42'],
            ['frets' => 'xx333x', 'expected' => 'Bb/F', 'description' => 'Wine #44'],
            ['frets' => '8xaaax', 'expected' => 'F/C', 'description' => 'Wine #54'],
            ['frets' => '7x577x', 'expected' => 'Gmaj7/B', 'description' => 'Barquinho #0'],
            ['frets' => '5x355x', 'expected' => 'Fmaj7/A', 'description' => 'Barquinho #11'],
            ['frets' => '3x133x', 'expected' => 'Ebmaj7/G', 'description' => 'Barquinho #22'],
            ['frets' => 'xxaaax', 'expected' => 'F/C', 'description' => 'Nature Boy #14'],
        ];

        // Bucket 2 cases that should NOT change
        $bucket2Cases = [
            ['frets' => 'xx799a', 'expected' => 'E7(11)/A', 'description' => 'Wine #6, #39'],
            ['frets' => 'xx7898', 'expected' => 'F7(#9)/A', 'description' => 'Wine #7, #40'],
            ['frets' => 'xx7978', 'expected' => 'Am6', 'description' => 'Wine #8, #41'],
        ];

        $this->info("=== Testing Phase 1 Slash Chord Fix ===\n");

        $this->info("Pattern 1 Cases (should flip to slash chords):");
        $pattern1Success = 0;
        foreach ($pattern1Cases as $i => $case) {
            $result = $crossref->identifyFromFrets($case['frets']);
            $actual = $result['name'];
            $success = $actual === $case['expected'];
            if ($success) $pattern1Success++;
            
            $status = $success ? '✓' : '✗';
            $this->line(sprintf("%2d. %-20s → %-20s %s %s", 
                $i + 1, 
                $case['frets'], 
                $actual, 
                $status, 
                $case['description']
            ));
            if (!$success) {
                $this->line("    Expected: {$case['expected']}");
            }
        }

        $this->info("\nBucket 2 Cases (should NOT change):");
        $bucket2Success = 0;
        foreach ($bucket2Cases as $i => $case) {
            $result = $crossref->identifyFromFrets($case['frets']);
            $actual = $result['name'];
            $success = $actual === $case['expected'];
            if ($success) $bucket2Success++;
            
            $status = $success ? '✓' : '✗';
            $this->line(sprintf("%2d. %-20s → %-20s %s %s", 
                $i + 1, 
                $case['frets'], 
                $actual, 
                $status, 
                $case['description']
            ));
            if (!$success) {
                $this->line("    Expected: {$case['expected']}");
            }
        }

        $this->info("\n=== Results ===");
        $this->line(sprintf("Pattern 1: %d/%d cases correct", $pattern1Success, count($pattern1Cases)));
        $this->line(sprintf("Bucket 2:  %d/%d cases correct", $bucket2Success, count($bucket2Cases)));

        $totalSuccess = $pattern1Success === count($pattern1Cases) && $bucket2Success === count($bucket2Cases);
        $this->info("\nOverall: " . ($totalSuccess ? "✓ ALL TESTS PASS" : "✗ SOME TESTS FAIL"));

        return $totalSuccess ? self::SUCCESS : self::FAILURE;
    }
}

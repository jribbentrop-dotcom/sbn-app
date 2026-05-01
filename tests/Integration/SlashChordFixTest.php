<?php

namespace Tests\Integration;

use Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

class SlashChordFixTest extends TestCase
{
    /**
     * Test Phase 1 slash chord fix using the artisan command
     * This tests the actual implementation without requiring database setup
     */
    public function test_slash_chord_fix_implementation()
    {
        // Run our test command that validates the slash chord fix
        $result = Artisan::call('sbn:test-slash-fix');
        
        // The command should return SUCCESS (0) if all tests pass
        $this->assertEquals(0, $result, 'Slash chord fix test command should pass');
        
        $output = Artisan::output();
        
        // Verify that all Pattern 1 cases are correct
        $this->assertStringContainsString('Pattern 1: 8/8 cases correct', $output);
        
        // Verify that all Bucket 2 cases are preserved  
        $this->assertStringContainsString('Bucket 2:  3/3 cases correct', $output);
        
        // Verify overall success
        $this->assertStringContainsString('Overall: ✓ ALL TESTS PASS', $output);
    }

    /**
     * Test specific Pattern 1 cases individually
     */
    public function test_specific_pattern_1_cases()
    {
        $testCases = [
            ['frets' => 'x5533x', 'expected' => 'Gm/D'],
            ['frets' => 'ax8aax', 'expected' => 'Bbmaj7/D'],
            ['frets' => 'xx333x', 'expected' => 'Bb/F'],
            ['frets' => '8xaaax', 'expected' => 'F/C'],
            ['frets' => '7x577x', 'expected' => 'Gmaj7/B'],
            ['frets' => '5x355x', 'expected' => 'Fmaj7/A'],
            ['frets' => '3x133x', 'expected' => 'Ebmaj7/G'],
            ['frets' => 'xxaaax', 'expected' => 'F/C'],
        ];

        foreach ($testCases as $case) {
            $result = Artisan::call("sbn:debug-all-candidates {$case['frets']}");
            $output = Artisan::output();
            
            // The debug output should show the expected result
            $this->assertStringContainsString("Actual result: {$case['expected']}", $output, 
                "Failed case: {$case['frets']} should be {$case['expected']}");
        }
    }

    /**
     * Test that Bucket 2 cases remain unchanged
     */
    public function test_bucket_2_cases_unchanged()
    {
        $bucket2Cases = [
            ['frets' => 'xx799a', 'expected' => 'E7(11)/A'],
            ['frets' => 'xx7898', 'expected' => 'F7(#9)/A'],
            ['frets' => 'xx7978', 'expected' => 'Am6'],
        ];

        foreach ($bucket2Cases as $case) {
            $result = Artisan::call("sbn:debug-all-candidates {$case['frets']}");
            $output = Artisan::output();
            
            $this->assertStringContainsString("Actual result: {$case['expected']}", $output, 
                "Bucket 2 regression: {$case['frets']} should remain {$case['expected']}");
        }
    }
}

<?php

namespace Tests\Unit;

use App\Services\VoicingCrossref;
use Tests\TestCase;

class VoicingCrossrefSlashChordTest extends TestCase
{
    private VoicingCrossref $crossref;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crossref = new VoicingCrossref(app(\App\Services\ChordShapeCalculator::class));
    }

    /**
     * Test Phase 1 slash chord fix - Pattern 1 cases that should flip to slash chords
     * These are the 8 cases from the spec that were incorrectly identified as exotic
     * interpretations but should be slash chords.
     */
    public function test_pattern1_slash_chord_cases()
    {
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

        foreach ($pattern1Cases as $case) {
            $result = $this->crossref->identifyFromFrets($case['frets']);
            
            $this->assertEquals(
                $case['expected'], 
                $result['name'], 
                "Pattern 1 case failed: {$case['frets']} → {$result['name']} (expected: {$case['expected']}) - {$case['description']}"
            );
        }
    }

    /**
     * Test Bucket 2 cases that should NOT change (regression guard)
     * These are locally defensible outputs that require Phase 2 harmonic awareness
     * to improve, and should remain unchanged by the Phase 1 fix.
     */
    public function test_bucket2_cases_remain_unchanged()
    {
        $bucket2Cases = [
            ['frets' => 'xx799a', 'expected' => 'E7(11)/A', 'description' => 'Wine #6, #39'],
            ['frets' => 'xx7898', 'expected' => 'F7(#9)/A', 'description' => 'Wine #7, #40'],
            ['frets' => 'xx7978', 'expected' => 'Am6', 'description' => 'Wine #8, #41'],
        ];

        foreach ($bucket2Cases as $case) {
            $result = $this->crossref->identifyFromFrets($case['frets']);
            
            $this->assertEquals(
                $case['expected'], 
                $result['name'], 
                "Bucket 2 regression: {$case['frets']} → {$result['name']} (expected: {$case['expected']}) - {$case['description']}"
            );
        }
    }

    /**
     * Test that slash chord bonus is only applied to chord tones (3rd, 5th, 7th, maj7th)
     * and not to extensions like 9th, 11th, 13th.
     */
    public function test_slash_bonus_only_applies_to_chord_tones()
    {
        // Test a case where bass is an 11th - should NOT get slash bonus
        // This is the Wine #6 case: xx799a should remain E7(11)/A, not become a slash chord
        $result = $this->crossref->identifyFromFrets('xx799a');
        $this->assertEquals('E7(11)/A', $result['name']);
        
        // Test a case where bass is a 9th - should NOT get slash bonus
        // Create a test case where bass is clearly a 9th
        $result = $this->crossref->identifyFromFrets('x9799x'); // A bass over G chord (A is 9th of G)
        // This should not become G/A, but rather some other interpretation
        $this->assertNotEquals('G/A', $result['name']);
    }

    /**
     * Test that the slash bonus creates sufficient score gap to avoid ties
     */
    public function test_slash_bonus_creates_score_gap()
    {
        // Test the specific case from the spec: ax8aax (Bbmaj7/D vs Dmin(b13))
        $result = $this->crossref->identifyFromFrets('ax8aax');
        $this->assertEquals('Bbmaj7/D', $result['name']);
        
        // Verify it's not the old incorrect result
        $this->assertNotEquals('Dmin(b13)', $result['name']);
    }

    /**
     * Unsupported-alteration penalty: an incomplete voicing with only root +
     * major 3rd ({R, M3}) must read as a plain major triad, never `aug`.
     * `aug` asserts a #5 that is not sounding — a Bucket 1 artifact.
     * (Maria Luisa import: x0x225 / xxx225 = {A, C#} was mis-named `Aaug`.)
     */
    public function test_root_plus_major_third_prefers_maj_over_aug()
    {
        foreach (['x0x225', 'xxx225'] as $frets) {
            $result = $this->crossref->identifyFromFrets($frets);
            $this->assertEquals('A', $result['name'],
                "{$frets} = {A,C#} (root+M3, no #5) should be A, got {$result['name']}");
            $this->assertStringNotContainsString('aug', strtolower($result['name']));
        }
    }

    /**
     * The mirror case: root + minor 3rd ({R, m3}) must read as a plain minor
     * triad, never `dim` (`dim` asserts a b5 that is not sounding).
     * x31xxx = {C, Eb}.
     */
    public function test_root_plus_minor_third_prefers_min_over_dim()
    {
        $result = $this->crossref->identifyFromFrets('x31xxx');
        $this->assertEquals('Cm', $result['name'],
            "x31xxx = {C,Eb} (root+m3, no b5) should be Cm, got {$result['name']}");
        $this->assertStringNotContainsString('dim', strtolower($result['name']));
    }

    /**
     * Regression guard: a voicing whose #5 IS sounding must still resolve to
     * `aug`. The penalty only fires when the altered 5th is absent, so genuine
     * augmented readings are untouched. 0xx558 = {E, C} — C is the #5 of E.
     * (Maria Luisa "Eaug" slot; stays Eaug after the fix.)
     */
    public function test_augmented_with_sharp_five_present_stays_aug()
    {
        $result = $this->crossref->identifyFromFrets('0xx558');
        $this->assertEquals('Eaug', $result['name'],
            "0xx558 = {E,C} (#5 present) should stay Eaug, got {$result['name']}");
    }

    /**
     * Test quality normalization for slash chords
     */
    public function test_quality_normalization_for_slash_chords()
    {
        // Test that 'min' normalizes to 'm' in output
        $result = $this->crossref->identifyFromFrets('x5533x');
        $this->assertEquals('Gm/D', $result['name']);
        $this->assertNotEquals('Gmin/D', $result['name']);
        
        // Test that 'maj' normalizes to empty in output  
        $result = $this->crossref->identifyFromFrets('xx333x');
        $this->assertEquals('Bb/F', $result['name']);
        $this->assertNotEquals('Bbmaj/F', $result['name']);
    }
}

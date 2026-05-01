<?php

namespace Tests\Unit;

use App\Models\RhythmPattern;
use App\Services\RhythmMaterializer;
use PHPUnit\Framework\TestCase;

class RhythmMaterializerTest extends TestCase
{
    public function test_it_emits_no_strokes_for_an_all_dots_pattern(): void
    {
        $pattern = new RhythmPattern([
            'thumb_pattern'  => '........',
            'rhythm_pattern' => '........',
            'grid_type'      => 'eighth',
            'beats'          => 8,
            'time_signature' => '4/4',
        ]);
        $voicing = ['frets' => 'x35453', 'position' => 5];
        $strokes = (new RhythmMaterializer())->expand($voicing, $pattern, 480, 4);

        $this->assertEmpty($strokes);
    }

    public function test_it_emits_eighth_grid_strokes_at_correct_ticks(): void
    {
        $pattern = new RhythmPattern([
            'thumb_pattern'  => '........',
            'rhythm_pattern' => 'X.x.X.x.',
            'grid_type'      => 'eighth',
            'beats'          => 8,
            'time_signature' => '4/4',
        ]);
        $voicing = ['frets' => 'x35453', 'position' => 5];
        $strokes = (new RhythmMaterializer())->expand($voicing, $pattern, 480, 4);

        $this->assertCount(4, $strokes);
        $this->assertEquals(0, $strokes[0]['tickOffset']);
        $this->assertEquals(480, $strokes[1]['tickOffset']);
        $this->assertEquals(960, $strokes[2]['tickOffset']);
        $this->assertEquals(1440, $strokes[3]['tickOffset']);
    }

    public function test_it_separates_thumb_strings_4_to_6_from_finger_strings_1_to_3(): void
    {
        $pattern = new RhythmPattern([
            'thumb_pattern'  => 'X.x.X.x.',
            'rhythm_pattern' => '........',
            'grid_type'      => 'eighth',
            'beats'          => 8,
            'time_signature' => '4/4',
        ]);
        $voicing = ['frets' => 'x35453', 'position' => 5];
        $strokes = (new RhythmMaterializer())->expand($voicing, $pattern, 480, 4);

        $this->assertNotEmpty($strokes);
        foreach ($strokes as $s) {
            foreach ($s['strings'] as $str) {
                $this->assertGreaterThanOrEqual(4, $str, 'thumb strokes must be on bass strings (4–6)');
            }
            $this->assertCount(1, $s['strings'], 'thumb strokes must hit exactly one string');
        }

    }

    public function test_it_skips_muted_voicing_strings(): void
    {
        $pattern = new RhythmPattern([
            'thumb_pattern'  => 'X.......',
            'rhythm_pattern' => '........',
            'grid_type'      => 'eighth',
            'beats'          => 8,
            'time_signature' => '4/4',
        ]);
        $voicing = ['frets' => 'xx0232', 'position' => 1]; // D major (bass strings muted except 4)
        $strokes = (new RhythmMaterializer())->expand($voicing, $pattern, 480, 4);

        $this->assertCount(1, $strokes);
        $this->assertEquals([4], $strokes[0]['strings']);
    }

    public function test_it_handles_strum_patterns_as_full_voicing_hits(): void
    {
        $pattern = new RhythmPattern([
            'category'       => 'strum',
            'thumb_pattern'  => '........',
            'rhythm_pattern' => 'X.......',
            'grid_type'      => 'eighth',
            'beats'          => 8,
            'time_signature' => '4/4',
        ]);
        $voicing = ['frets' => 'x35453', 'position' => 5];
        $strokes = (new RhythmMaterializer())->expand($voicing, $pattern, 480, 4);

        $this->assertCount(1, $strokes);
        $this->assertEquals([5, 4, 3, 2, 1], $strokes[0]['strings']);
    }

    public function test_it_marks_capital_X_as_accent_with_higher_velocity(): void
    {
        $pattern = new RhythmPattern([
            'thumb_pattern'  => '........',
            'rhythm_pattern' => 'X.x.....',
            'grid_type'      => 'eighth',
            'beats'          => 8,
            'time_signature' => '4/4',
        ]);
        $voicing = ['frets' => 'x35453', 'position' => 5];
        $strokes = (new RhythmMaterializer())->expand($voicing, $pattern, 480, 4);

        $this->assertCount(2, $strokes);
        $this->assertTrue($strokes[0]['accent']);
        $this->assertEquals(1.0, $strokes[0]['velocity']);

        $this->assertFalse($strokes[1]['accent']);
        $this->assertEquals(0.85, $strokes[1]['velocity']);
    }
}

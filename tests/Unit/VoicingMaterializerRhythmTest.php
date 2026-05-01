<?php

namespace Tests\Unit;

use App\Models\RhythmPattern;
use App\Services\RhythmMaterializer;
use App\Services\VoicingMaterializer;
use PHPUnit\Framework\TestCase;

class VoicingMaterializerRhythmTest extends TestCase
{
    public function test_it_falls_back_to_whole_notes_when_no_rhythm_supplied(): void
    {
        $rhythmMaterializer = new RhythmMaterializer();
        $materializer = new VoicingMaterializer($rhythmMaterializer);

        $selections = [
            ['chord_name' => 'Cmaj7', 'frets' => 'x35453', 'position' => 5],
        ];

        $resultNoRhythm = $materializer->materialize($selections, '4/4', null);

        $this->assertNotEmpty($resultNoRhythm['tab_xml']);
        $this->assertCount(5, $resultNoRhythm['melody']); // 5 strings for Cmaj7

        // Duration of first melody note should be 'w'
        $this->assertEquals('w', $resultNoRhythm['melody'][0]['duration']);
        $this->assertEquals(1920, $resultNoRhythm['melody'][0]['ticks']);
    }

    public function test_it_produces_one_harmony_per_bar_regardless_of_stroke_count(): void
    {
        $rhythmMaterializer = new RhythmMaterializer();
        $materializer = new VoicingMaterializer($rhythmMaterializer);

        $pattern = new RhythmPattern([
            'thumb_pattern'  => 'X.......',
            'rhythm_pattern' => 'X.x.X.x.',
            'grid_type'      => 'eighth',
            'beats'          => 8,
            'time_signature' => '4/4',
        ]);

        $selections = [
            ['chord_name' => 'Cmaj7', 'frets' => 'x35453', 'position' => 5],
        ];

        $result = $materializer->materialize($selections, '4/4', $pattern);

        // Check MusicXML output for exact count of <harmony> tags
        $xml = $result['tab_xml'];
        $harmonyCount = substr_count($xml, '<harmony>');
        $this->assertEquals(1, $harmonyCount);
    }

    public function test_it_produces_n_strokes_per_bar_matching_the_pattern(): void
    {
        $rhythmMaterializer = new RhythmMaterializer();
        $materializer = new VoicingMaterializer($rhythmMaterializer);

        $pattern = new RhythmPattern([
            'thumb_pattern'  => '........',
            'rhythm_pattern' => 'X.x.....',
            'grid_type'      => 'eighth',
            'beats'          => 8,
            'time_signature' => '4/4',
        ]);

        $selections = [
            ['chord_name' => 'Cmaj7', 'frets' => 'x35453', 'position' => 5],
        ];

        $result = $materializer->materialize($selections, '4/4', $pattern);

        // Treble is 3 strings. 2 strokes * 3 strings = 6 melody entries
        $this->assertCount(6, $result['melody']);
    }
}

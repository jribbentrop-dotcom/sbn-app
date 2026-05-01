<?php

namespace Tests\Unit;

use App\Services\AnalysisToLeadsheet;
use App\Services\LeadsheetParser;
use Tests\TestCase;

class AnalysisToLeadsheetTest extends TestCase
{
    public function test_it_converts_a_simple_aaba_analysis_into_scaffold_shape()
    {
        $analysis = [
            'title' => 'Test Song',
            'composer' => 'Test Composer',
            'key' => 'C',
            'tempo' => 120,
            'timeSignature' => '4/4',
            'sections' => [
                [
                    'name' => 'A1',
                    'bars' => [
                        ['chords' => [['label' => 'Cmaj7', 'beats' => 4]]],
                        ['chords' => [['label' => 'Dm7', 'beats' => 2], ['label' => 'G7', 'beats' => 2]]],
                    ],
                ]
            ]
        ];

        $converter = new AnalysisToLeadsheet();
        $result = $converter->convert($analysis);

        $this->assertEquals(2, $result['measure_count']);
        
        $jsonData = json_decode($result['json_data'], true);
        $this->assertEquals('Test Song', $jsonData['title']);
        $this->assertCount(1, $jsonData['sections']);
        $this->assertEquals('Cmaj7', $jsonData['sections'][0]['measures'][0]['chords'][0]['name']);
        
        $this->assertStringContainsString('[A label="A1"]', $result['shortcode_content']);
        $this->assertStringContainsString('| Cmaj7 | Dm7 G7 |', $result['shortcode_content']);
    }

    public function test_it_round_trips_through_leadsheet_parser()
    {
        $analysis = [
            'title' => 'Test Song',
            'composer' => 'Test Composer',
            'key' => 'C',
            'tempo' => 120,
            'timeSignature' => '4/4',
            'sections' => [
                [
                    'name' => 'A1',
                    'bars' => [
                        ['chords' => [['label' => 'Cmaj7', 'beats' => 4]]],
                        ['chords' => [['label' => 'Dm7', 'beats' => 2], ['label' => 'G7', 'beats' => 2]]],
                    ],
                ]
            ]
        ];

        $converter = new AnalysisToLeadsheet();
        $result = $converter->convert($analysis);
        
        $parser = new LeadsheetParser();
        $parsed = $parser->parse($result['shortcode_content']);

        $jsonData = json_decode($result['json_data'], true);

        // The parser logic sets beat counts dynamically. We'll assert sections match exactly.
        $this->assertEquals($jsonData['sections'], $parsed['sections']);
        $this->assertEquals($jsonData['title'], $parsed['title']);
    }

    public function test_it_emits_object_for_empty_chord_voicings()
    {
        $converter = new AnalysisToLeadsheet();
        $result = $converter->convert([]);
        $this->assertStringContainsString('"chordVoicings":{}', $result['json_data']);
    }

    public function test_it_handles_uneven_beats_per_bar_without_throwing()
    {
        $analysis = [
            'timeSignature' => '4/4',
            'sections' => [
                [
                    'name' => 'A1',
                    'bars' => [
                        ['chords' => [['label' => 'Cmaj7', 'beats' => 3]]],
                    ],
                ]
            ]
        ];

        $converter = new AnalysisToLeadsheet();
        $result = $converter->convert($analysis);
        
        $jsonData = json_decode($result['json_data'], true);
        $this->assertEquals(3, $jsonData['sections'][0]['measures'][0]['chords'][0]['beats']);
    }
}

<?php

namespace Tests\Unit;

use App\Services\LeadsheetScaffolder;
use App\Services\LeadsheetParser;
use PHPUnit\Framework\TestCase;

class LeadsheetScaffolderTest extends TestCase
{
    protected LeadsheetScaffolder $scaffolder;
    protected LeadsheetParser $parser;

    protected function setUp(): void
    {
        $this->scaffolder = new LeadsheetScaffolder();
        $this->parser = new LeadsheetParser();
    }

    /**
     * Test scaffolding a simple blank in C 4/4.
     */
    public function test_scaffolds_a_simple_blank_in_c_4_4(): void
    {
        $result = $this->scaffolder->scaffoldBlank([
            'title' => 'Test Song',
            'composer' => 'Test Composer',
            'song_key' => 'C',
            'tempo' => 120,
            'time_signature' => '4/4',
            'rhythm' => '',
            'structure' => ['mode' => 'simple', 'bar_count' => 16],
            'pickup_bar' => false,
        ]);

        $this->assertEquals(16, $result['measure_count']);
        $this->assertNotEmpty($result['shortcode_content']);
        $this->assertNotEmpty($result['json_data']);

        $jsonData = json_decode($result['json_data'], true);
        $this->assertIsArray($jsonData);
        $this->assertCount(1, $jsonData['sections']);
        $this->assertEquals('Main', $jsonData['sections'][0]['name']);
        $this->assertCount(16, $jsonData['sections'][0]['measures']);
        $this->assertIsArray($jsonData['chordVoicings']);
        $this->assertEmpty($jsonData['chordVoicings']);
        $this->assertIsArray($jsonData['melody'] ?? []);
        $this->assertNotEmpty($jsonData['melody'] ?? []);

        // Verify measures have placeholder chords for editor rendering
        foreach ($jsonData['sections'][0]['measures'] as $measure) {
            $this->assertIsArray($measure['chords']);
            $this->assertCount(1, $measure['chords']);
            $this->assertEquals('?', $measure['chords'][0]['name']);
            $this->assertEquals(4, $measure['chords'][0]['beats']);
        }
    }

    /**
     * Test scaffolding a sectioned layout.
     */
    public function test_scaffolds_sectioned_layout(): void
    {
        $result = $this->scaffolder->scaffoldBlank([
            'title' => 'Test Song',
            'composer' => '',
            'song_key' => 'F',
            'tempo' => 140,
            'time_signature' => '3/4',
            'rhythm' => '',
            'structure' => [
                'mode' => 'sectioned',
                'sections' => [
                    ['name' => 'Verse', 'bars' => 8],
                    ['name' => 'Chorus', 'bars' => 8],
                ],
            ],
            'pickup_bar' => false,
        ]);

        $this->assertEquals(16, $result['measure_count']);

        $jsonData = json_decode($result['json_data'], true);
        $this->assertCount(2, $jsonData['sections']);
        $this->assertEquals('Verse', $jsonData['sections'][0]['name']);
        $this->assertEquals('Chorus', $jsonData['sections'][1]['name']);
        $this->assertCount(8, $jsonData['sections'][0]['measures']);
        $this->assertCount(8, $jsonData['sections'][1]['measures']);
    }

    /**
     * Test round-trip through parser.
     */
    public function test_round_trips_through_parser(): void
    {
        $result = $this->scaffolder->scaffoldBlank([
            'title' => 'Round Trip Test',
            'composer' => 'Test',
            'song_key' => 'G',
            'tempo' => 100,
            'time_signature' => '4/4',
            'rhythm' => '',
            'structure' => ['mode' => 'simple', 'bar_count' => 4],
            'pickup_bar' => false,
        ]);

        $originalJson = json_decode($result['json_data'], true);
        $parsed = $this->parser->parse($result['shortcode_content']);

        // Compare key fields that should match
        $this->assertEquals($originalJson['title'], $parsed['title']);
        $this->assertEquals($originalJson['key'], $parsed['key']);
        $this->assertEquals($originalJson['tempo'], $parsed['tempo']);
        $this->assertEquals($originalJson['timeSignature'], $parsed['timeSignature']);
        $this->assertEquals($originalJson['displayBeats'], $parsed['displayBeats']);

        // Compare sections structure
        $this->assertCount(count($originalJson['sections']), $parsed['sections']);
        foreach ($originalJson['sections'] as $i => $section) {
            $this->assertEquals($section['name'], $parsed['sections'][$i]['name']);
            $this->assertCount(count($section['measures']), $parsed['sections'][$i]['measures']);
        }

        // Compare empty measures - parser will parse placeholders as chords
        // Just verify the structure is correct (measures exist)
        foreach ($parsed['sections'] as $section) {
            foreach ($section['measures'] as $measure) {
                $this->assertIsArray($measure['chords']);
                // Chords may contain placeholders from parsing
            }
        }
    }

    /**
     * Test validation of structure input - missing bar count in simple mode.
     */
    public function test_validates_structure_input_missing_bar_count(): void
    {
        $result = $this->scaffolder->scaffoldBlank([
            'title' => 'Test',
            'song_key' => 'C',
            'tempo' => 120,
            'time_signature' => '4/4',
            'structure' => ['mode' => 'simple'],
        ]);

        // Should default to a reasonable value or handle gracefully
        $this->assertArrayHasKey('json_data', $result);
    }

    /**
     * Test validation of structure input - empty sections in sectioned mode.
     */
    public function test_validates_structure_input_empty_sections(): void
    {
        $result = $this->scaffolder->scaffoldBlank([
            'title' => 'Test',
            'song_key' => 'C',
            'tempo' => 120,
            'time_signature' => '4/4',
            'structure' => ['mode' => 'sectioned', 'sections' => []],
        ]);

        // Should handle gracefully - may create no sections or default section
        $this->assertArrayHasKey('json_data', $result);
    }

    /**
     * Test scaffolding from a sequence.
     */
    public function test_scaffolds_from_sequence(): void

    {
        $opts = [
            'title' => 'Sequence Test',
            'song_key' => 'C',
            'tempo' => 120,
            'time_signature' => '4/4',
        ];

        $parsedSequence = [
            'mode' => 'sequence',
            'items' => ['Am7', 'Dm7', 'G7', 'Cmaj7'],
        ];

        $result = $this->scaffolder->scaffoldFromSequence($opts, $parsedSequence, 2);

        $this->assertEquals(8, $result['measure_count']);
        $jsonData = json_decode($result['json_data'], true);
        $this->assertSame('object', gettype(json_decode($result['json_data'])->chordVoicings));
        $this->assertCount(1, $jsonData['sections']);
        $this->assertCount(8, $jsonData['sections'][0]['measures']);
        $this->assertEquals('Am7', $jsonData['sections'][0]['measures'][0]['chords'][0]['name']);
        $this->assertEquals('Am7', $jsonData['sections'][0]['measures'][1]['chords'][0]['name']);
        $this->assertEquals('Dm7', $jsonData['sections'][0]['measures'][2]['chords'][0]['name']);

    }

    /**
     * Test scaffolding from pipe-separated bars.
     */
    public function test_scaffolds_from_pipe_separated_bars(): void
    {
        $opts = [
            'title' => 'Bars Test',
            'song_key' => 'C',
            'tempo' => 120,
            'time_signature' => '4/4',
        ];

        $parsedSequence = [
            'mode' => 'bars',
            'items' => [
                ['Am7'],
                ['Dm7', 'G7'],
                ['Cmaj7'],
            ],
        ];

        $result = $this->scaffolder->scaffoldFromSequence($opts, $parsedSequence);

        $this->assertEquals(3, $result['measure_count']);
        $jsonData = json_decode($result['json_data'], true);
        $this->assertCount(3, $jsonData['sections'][0]['measures']);

        $this->assertCount(1, $jsonData['sections'][0]['measures'][0]['chords']);
        $this->assertEquals('Am7', $jsonData['sections'][0]['measures'][0]['chords'][0]['name']);

        $this->assertCount(2, $jsonData['sections'][0]['measures'][1]['chords']);
        $this->assertEquals('Dm7', $jsonData['sections'][0]['measures'][1]['chords'][0]['name']);
        $this->assertEquals('G7', $jsonData['sections'][0]['measures'][1]['chords'][1]['name']);
    }
}


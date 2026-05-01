<?php

namespace Tests\Unit;

use App\Services\ChordSequenceParser;
use App\Services\ProgressionDetector;
use App\Services\LeadsheetParser;
use PHPUnit\Framework\TestCase;

class ChordSequenceParserTest extends TestCase
{
    protected ChordSequenceParser $parser;

    protected function setUp(): void
    {
        $leadsheetParser = new LeadsheetParser();
        $detector = new ProgressionDetector($leadsheetParser);
        $context = new \App\Services\HarmonicContext($detector, $leadsheetParser);
        $resolver = new \App\Services\NumeralResolver($context);
        $this->parser = new ChordSequenceParser($detector, $resolver);


    }


    /**
     * Test parsing whitespace-separated sequence.
     */
    public function test_parses_whitespace_separated_sequence(): void
    {
        $input = "Am7  Dm7\nG7 Cmaj7";
        $result = $this->parser->parse($input);

        $this->assertEquals('sequence', $result['mode']);
        $this->assertEquals(['Am7', 'Dm7', 'G7', 'Cmaj7'], $result['items']);
        $this->assertEquals(0, $result['invalid_count']);
    }

    /**
     * Test parsing pipe-separated bars.
     */
    public function test_parses_pipe_separated_bars(): void
    {
        $input = "| Am7 | Dm7 G7 | Cmaj7 |";
        $result = $this->parser->parse($input);

        $this->assertEquals('bars', $result['mode']);
        $this->assertCount(3, $result['items']);
        $this->assertEquals(['Am7'], $result['items'][0]);
        $this->assertEquals(['Dm7', 'G7'], $result['items'][1]);
        $this->assertEquals(['Cmaj7'], $result['items'][2]);
        $this->assertEquals(0, $result['invalid_count']);
    }

    /**
     * Test parsing inline ChordPro.
     */
    public function test_parses_chordpro_inline(): void
    {
        $input = "[Am7]some lyric [Dm7]more [G7]words";
        $result = $this->parser->parse($input);

        $this->assertEquals('sequence', $result['mode']);
        $this->assertEquals(['Am7', 'Dm7', 'G7'], $result['items']);
        $this->assertEquals(0, $result['invalid_count']);
    }

    /**
     * Test mapping invalid tokens to question marks.
     */
    public function test_maps_invalid_tokens_to_question_marks(): void
    {
        $input = "Am7 InvalidChord G7";
        $result = $this->parser->parse($input);

        $this->assertEquals('sequence', $result['mode']);
        $this->assertEquals(['Am7', '?', 'G7'], $result['items']);
        $this->assertEquals(1, $result['invalid_count']);
    }

    /**
     * Test parsing roman numerals without concrete validation failure.
     */
    public function test_leaves_roman_numerals_unvalidated(): void
    {
        $input = "IIm7 V7 Imaj7";
        $result = $this->parser->parse($input);

        $this->assertEquals('sequence', $result['mode']);
        $this->assertEquals(['IIm7', 'V7', 'Imaj7'], $result['items']);
        $this->assertEquals(0, $result['invalid_count']);
    }
}

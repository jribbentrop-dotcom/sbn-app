<?php

namespace Tests\Unit;

use App\Services\RhythmHintMapper;
use Tests\TestCase;

class RhythmHintMapperTest extends TestCase
{
    public function test_keywords_map_to_slugs()
    {
        $mapper = new RhythmHintMapper();

        $this->assertEquals('joao-gilberto-bossa', $mapper->map('It is a bossa nova'));
        $this->assertEquals('blues-shuffle', $mapper->map('shuffle rhythm'));
        $this->assertEquals('waltz-strum', $mapper->map('A fast Waltz'));
    }

    public function test_unknown_or_null_hint_returns_null()
    {
        $mapper = new RhythmHintMapper();

        $this->assertNull($mapper->map('unknown weird style'));
        $this->assertNull($mapper->map(''));
        $this->assertNull($mapper->map(null));
    }

    public function test_multiple_keywords_returns_first_match_deterministically()
    {
        $mapper = new RhythmHintMapper();

        // 'bossa' is defined before 'pop' in the map array.
        $this->assertEquals('joao-gilberto-bossa', $mapper->map('bossa pop mix'));
    }
}

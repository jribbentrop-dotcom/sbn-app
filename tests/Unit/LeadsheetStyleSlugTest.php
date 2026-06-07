<?php

namespace Tests\Unit;

use App\Models\Leadsheet;
use Tests\TestCase;

class LeadsheetStyleSlugTest extends TestCase
{
    public function test_genre_takes_priority_over_rhythm_for_style_slug(): void
    {
        $leadsheet = new Leadsheet([
            'genre'  => 'classical',
            'rhythm' => 'jazz',
        ]);

        $this->assertSame('classical', $leadsheet->style_slug);
    }

    public function test_link_payload_uses_the_resolved_style_slug(): void
    {
        $leadsheet = new Leadsheet([
            'id'     => 42,
            'slug'   => 'test-song',
            'title'  => 'Test Song',
            'genre'  => 'pop',
            'rhythm' => 'jazz',
        ]);

        $link = $leadsheet->toLinkArray();

        $this->assertSame('pop', $link['styleSlug']);
    }

    public function test_rhythm_fallback_still_handles_legacy_rows(): void
    {
        $leadsheet = new Leadsheet([
            'rhythm' => 'bossa-nova-variation',
        ]);

        $this->assertSame('bossa-nova', $leadsheet->style_slug);
    }
}

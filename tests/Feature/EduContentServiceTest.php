<?php

namespace Tests\Feature;

use App\Services\Edu\EduTopic;
use App\Services\EduContentService;
use Tests\TestCase;

/**
 * Guards the markdown-backed EduContentService before Task 2 (widget registry
 * + mountSbnNodes extension) builds on top of it. A regression in parsing,
 * <sbn-widget> pass-through, or the legacy chord-quality API would otherwise
 * be silent.
 *
 * These read the real resources/edu/ files — a non-destructive filesystem
 * read, no fixtures needed.
 */
class EduContentServiceTest extends TestCase
{
    private EduContentService $edu;

    protected function setUp(): void
    {
        parent::setUp();
        $this->edu = $this->app->make(EduContentService::class);
    }

    public function test_parses_frontmatter_and_renders_markdown_body(): void
    {
        $triad = $this->edu->topic('concept', 'triad');

        $this->assertInstanceOf(EduTopic::class, $triad);
        $this->assertSame('triad', $triad->slug);
        $this->assertSame('concept', $triad->type);
        $this->assertSame('The Triad', $triad->title);
        $this->assertNotSame('', $triad->summary);

        // Markdown body rendered to HTML: **bold** -> <strong>.
        $this->assertStringContainsString('<strong>triad</strong>', $triad->bodyHtml);
        $this->assertStringContainsString('<p>', $triad->bodyHtml);

        // Frontmatter list fields decode to arrays.
        $this->assertContains('voice-leading', $triad->related);
    }

    public function test_sbn_widget_tags_survive_commonmark_rendering(): void
    {
        $triad = $this->edu->topic('concept', 'triad');

        // The raw custom tag must pass through unescaped — Task 2's
        // mountSbnNodes walker depends on finding it intact in body_html.
        $this->assertStringContainsString('<sbn-widget slug="triad-builder"', $triad->bodyHtml);
        $this->assertStringNotContainsString('&lt;sbn-widget', $triad->bodyHtml);
    }

    public function test_all_chord_qualities_returns_the_migrated_set(): void
    {
        $all = $this->edu->allChordQualities();

        // 18 qualities migrated from the retired config/edu/chord-qualities.php.
        // New content may grow this; it must never shrink below the migration.
        $this->assertGreaterThanOrEqual(18, count($all));

        foreach (['maj7', 'm7', 'dom7', 'm7b5'] as $slug) {
            $this->assertArrayHasKey($slug, $all);
            $this->assertArrayHasKey('title', $all[$slug]);
            $this->assertArrayHasKey('blurb', $all[$slug]);
        }

        // blurb is the plain-text summary EduPanel renders as a paragraph —
        // not the rendered HTML body.
        $this->assertStringNotContainsString('<', $all['maj7']['blurb']);
    }

    public function test_numeric_looking_slug_is_coerced_to_string(): void
    {
        // qualities/5.md has slug "5"; YAML would parse a bare 5 as int.
        // The power chord must remain reachable by its string key.
        $all = $this->edu->allChordQualities();
        $this->assertArrayHasKey('5', $all);
        $this->assertSame('Power chord', $all['5']['title']);

        $topic = $this->edu->topic('quality', '5');
        $this->assertInstanceOf(EduTopic::class, $topic);
        $this->assertSame('5', $topic->slug);
    }

    public function test_chord_quality_lookup_and_unknown_slug(): void
    {
        $maj7 = $this->edu->chordQuality('maj7');
        $this->assertSame('Major 7', $maj7['title']);

        // Unknown quality returns null — EduPanel handles its own fallback.
        $this->assertNull($this->edu->chordQuality('does-not-exist'));
    }

    public function test_topic_array_round_trips_through_cache_form(): void
    {
        // The non-local cache path stores arrays and rehydrates via fromArray.
        $original = $this->edu->topic('concept', 'triad');
        $rehydrated = EduTopic::fromArray($original->toArray());

        $this->assertEquals($original, $rehydrated);
    }
}

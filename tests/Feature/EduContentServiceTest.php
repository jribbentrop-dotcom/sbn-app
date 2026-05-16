<?php

namespace Tests\Feature;

use App\Models\ChordDiagram;
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

    // ── Task 3 (8.0): quality-only description/usage fields ──────────────────

    public function test_quality_topic_exposes_description_and_usage(): void
    {
        $maj7 = $this->edu->qualityTopic('maj7');

        $this->assertInstanceOf(EduTopic::class, $maj7);
        $this->assertNotNull($maj7->description);
        $this->assertNotNull($maj7->usage);
        $this->assertStringContainsString('major seventh', $maj7->description);

        // 7sus4 was authored fresh in 8.0 (no qualityEdu source) — still must
        // carry both fields like the 17 migrated qualities.
        $sevenSus = $this->edu->qualityTopic('7sus4');
        $this->assertNotNull($sevenSus->description);
        $this->assertNotNull($sevenSus->usage);
    }

    public function test_non_quality_topics_have_null_description_and_usage(): void
    {
        $concept = $this->edu->topic('concept', 'triad');
        $this->assertNull($concept->description);
        $this->assertNull($concept->usage);

        $glossary = $this->edu->topic('glossary', 'cadence');
        $this->assertNull($glossary->description);
        $this->assertNull($glossary->usage);
    }

    public function test_quality_topic_returns_null_for_a_non_quality_slug(): void
    {
        // A real concept slug is not a quality — qualityTopic() must not find it.
        $this->assertNull($this->edu->qualityTopic('triad'));
        $this->assertNull($this->edu->qualityTopic('does-not-exist'));
    }

    public function test_description_and_usage_survive_the_cache_round_trip(): void
    {
        // fromArray is the easiest place to silently drop the nullable fields.
        $original = $this->edu->qualityTopic('maj7');
        $rehydrated = EduTopic::fromArray($original->toArray());

        $this->assertSame($original->description, $rehydrated->description);
        $this->assertSame($original->usage, $rehydrated->usage);
        $this->assertEquals($original, $rehydrated);
    }

    // ── Task 3 (8.1): hasWidgets parse-time flag ─────────────────────────────

    public function test_body_has_widgets_matches_an_element_not_a_substring(): void
    {
        // The positive case: an actual <sbn-widget> element opening.
        $this->assertTrue(EduTopic::bodyHasWidgets('<p>x</p><sbn-widget slug="triad-builder" />'));
        $this->assertTrue(EduTopic::bodyHasWidgets('<sbn-widget slug="x"></sbn-widget>'));
        $this->assertTrue(EduTopic::bodyHasWidgets('<sbn-widget/>'));

        // The negative case: prose or code that merely mentions the words
        // "sbn-widget" must NOT trip the flag.
        $this->assertFalse(EduTopic::bodyHasWidgets('<p>Use the sbn-widget tag here.</p>'));
        $this->assertFalse(EduTopic::bodyHasWidgets('<code>sbn-widget</code>'));
        $this->assertFalse(EduTopic::bodyHasWidgets('<p>no widgets at all</p>'));
    }

    public function test_parse_sets_has_widgets_from_the_body(): void
    {
        // concepts/triad.md embeds a real <sbn-widget> — parsing must flag it.
        $triad = $this->edu->topic('concept', 'triad');
        $this->assertTrue($triad->hasWidgets);

        // No quality body has a widget today — all 18 report false. This is the
        // expected dormant state (plan §5.3): body_html parsed and carried, but
        // Chords/Show.vue renders it only when has_widgets is true.
        foreach ($this->edu->topics('quality') as $topic) {
            $this->assertFalse($topic->hasWidgets, "Quality '{$topic->slug}' unexpectedly reports hasWidgets");
        }
    }

    public function test_has_widgets_survives_the_cache_round_trip(): void
    {
        $widgetTopic = $this->edu->topic('concept', 'triad');       // hasWidgets true
        $plainTopic = $this->edu->qualityTopic('maj7');             // hasWidgets false

        $this->assertTrue(EduTopic::fromArray($widgetTopic->toArray())->hasWidgets);
        $this->assertFalse(EduTopic::fromArray($plainTopic->toArray())->hasWidgets);
    }

    public function test_every_canonical_chord_quality_has_a_topic(): void
    {
        // De-risks 8.1: Chords/Show.vue looks up qualityTopic($chord->quality),
        // and $chord->quality is constrained to ChordDiagram::CHORD_QUALITIES.
        // A missing qualities/*.md file would make qualityTopic() return null
        // and the chord page silently show no edu content. Catch the gap here.
        foreach (array_keys(ChordDiagram::CHORD_QUALITIES) as $quality) {
            $topic = $this->edu->qualityTopic((string) $quality);
            $this->assertNotNull($topic, "No edu topic for chord quality '{$quality}'");
            $this->assertNotNull($topic->description, "Quality '{$quality}' has no description");
            $this->assertNotNull($topic->usage, "Quality '{$quality}' has no usage");
        }
    }
}

<?php

namespace App\Services;

use App\Services\Edu\EduTopic;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;
use Symfony\Component\Yaml\Yaml;

/**
 * Source of truth for educational text content shown alongside chords,
 * progressions, and other study aids.
 *
 * Backed by Markdown files in `resources/edu/{type}/{slug}.md` — one file per
 * topic, YAML frontmatter + a Markdown body. Three types, one per subdirectory:
 *
 *   - concept   — a theory topic; may embed <sbn-widget> tags.
 *   - quality   — a chord-quality blurb, keyed by canonical quality slug.
 *   - glossary  — a short term definition.
 *
 * The parsed topic set is cached forever (busted via `php artisan edu:clear-cache`
 * or `cache:clear`). In the `local` env the cache is skipped so edits to the
 * markdown files surface immediately while authoring.
 *
 * Public method signatures are stable: swapping the markdown reader for an
 * Eloquent query later touches only this class.
 */
class EduContentService
{
    /** Cache key for the full parsed topic set. */
    private const CACHE_KEY = 'edu.topics';

    /**
     * Skill-node slug → concept slug. Deliberately partial: most skill nodes
     * have no natural 1:1 concept (e.g. ear-training/technique nodes), so only
     * the subset with an obvious theory-concept counterpart is listed. Used by
     * conceptsForLeadsheet() to surface "what this song teaches" in theory terms.
     */
    private const SKILL_NODE_CONCEPTS = [
        'triads'                  => 'triad',
        'chord-inversions'        => 'triad',
        'diatonic-harmony'        => 'chord-function',
        'cadences'                => 'circle-of-fifths',
        'ii-v-i-major'            => 'chord-function',
        'ii-v-i-minor'            => 'chord-function',
        'turnarounds'             => 'chord-function',
        'secondary-dominants'     => 'chord-function',
        'tritone-substitution'    => 'circle-of-fifths',
        'borrowed-chords'         => 'chord-quality-brightness',
        'drop2-voicings'          => 'drop2',
        'shell-voicings'          => 'chord-tones',
        'voice-leading'           => 'voice-leading',
        'the-basic-8'             => 'basic-chords',
        'pentatonic-scale'        => 'pentatonic-scales',
        'foundational-scales'     => 'scale-steps',
        'major-minor-scales'      => 'scale-steps',
        'chromatic-scale'         => 'scale-steps',
        'arpeggio-shapes'         => 'chord-tones',
        'scale-degrees'           => 'scale-steps',
        'rhythm-notation'         => 'note-durations',
        'tab-reading-basics'      => 'tab-diagram',
        'meter-basics'            => 'time-signature',
        'pulse-subdivision'       => 'note-durations',
        'swing-feel'              => 'triplets',
        'waltz-feel'              => 'time-signature',
        'brazilian-rhythm-styles' => 'repeat-signs',
        'barre-chords'            => 'caged-system',
        'caged-system'            => 'caged-system',
        'position-shifting'       => 'scale-positions',
    ];

    /**
     * Detected-progression slug substring → concept slug. Matched with
     * str_contains against the progression's own slug (e.g.
     * "secondary-dominant-ii-v" contains "secondary-dominant"), so one entry
     * covers every variant/name built on that harmonic device.
     */
    private const PROGRESSION_CONCEPTS = [
        'secondary-dominant' => 'chord-function',
        'tritone-sub'        => 'circle-of-fifths',
        'authentic-cadence'  => 'circle-of-fifths',
        'ii-v'               => 'chord-function',
        'turnaround'         => 'chord-function',
        'lydian'             => 'chord-quality-brightness',
        'borrowed'           => 'chord-quality-brightness',
        'tonic-dominant'     => 'circle-of-fifths',
    ];

    /**
     * Song genre → fallback concept(s), used when a song has no linked skill
     * nodes and no detected progression matches PROGRESSION_CONCEPTS. Keeps
     * "Related theory" from defaulting to the same one or two concepts for
     * every song regardless of style (the original complaint that chord
     * quality alone can't tell a classical piece from a jazz standard).
     */
    private const GENRE_CONCEPTS = [
        'classical'  => ['circle-of-fifths', 'caged-system'],
        'jazz'       => ['chord-function', 'chord-extensions'],
        'bossa-nova' => ['drop2', 'chord-tones'],
        'pop'        => ['basic-chords', 'triad'],
    ];

    /** Concepts gated to only surface once a song reaches this difficulty (1-5). */
    private const DIFFICULTY_GATED_CONCEPTS = [
        'chord-extensions'         => 3,
        'chord-quality-brightness' => 3,
    ];

    /** Hard cap on how many concepts conceptsForLeadsheet() returns. */
    private const MAX_RELATED_CONCEPTS = 4;

    /**
     * Recognised topic types mapped to their subdirectory under resources/edu/.
     * Type is singular (the lookup value); the directory is plural.
     */
    private const TYPE_DIRS = [
        'concept' => 'concepts',
        'quality' => 'qualities',
        'glossary' => 'glossary',
    ];

    /** Recognised topic type slugs. */
    public const TYPES = ['concept', 'quality', 'glossary'];

    // ── Legacy chord-quality API (file-backed, signatures unchanged) ─────────

    /**
     * Look up a chord quality blurb by canonical quality slug.
     *
     * Historically returns {title, blurb}. `blurb` is the topic's plain-text
     * summary — EduPanel renders it as a plain paragraph. The full rendered
     * body is available via topic('quality', $slug).
     *
     * @param  string  $qualitySlug  e.g. 'maj7', 'm7b5', 'dom7'
     * @return array{title:string,blurb:string}|null
     */
    public function chordQuality(string $qualitySlug): ?array
    {
        $topic = $this->topic('quality', $qualitySlug);
        if ($topic === null) {
            return null;
        }

        return ['title' => $topic->title, 'blurb' => $topic->summary];
    }

    /**
     * Bundle all chord-quality blurbs in one shot — used when the consumer
     * needs offline lookup over a known set (e.g. an Inertia payload that
     * surfaces blurbs for every chord on the page).
     *
     * Returns {title, blurb, related} — `related` is additive; existing
     * consumers that only read `title`/`blurb` are unaffected.
     *
     * @return array<string, array{title:string,blurb:string,related:string[]}>
     */
    public function allChordQualities(): array
    {
        $result = [];
        foreach ($this->topics('quality') as $topic) {
            $result[$topic->slug] = [
                'title'   => $topic->title,
                'blurb'   => $topic->summary,
                'related' => $topic->related,
            ];
        }

        return $result;
    }

    /**
     * Look up the full quality topic — title, summary, the `description` and
     * `usage` prose spans, and any body_html — by canonical quality slug.
     *
     * Unlike chordQuality() (legacy {title, blurb} shape), this returns the
     * whole EduTopic so Chords/Show.vue can render the structured fields.
     * Returns null for an unknown slug or a slug that is not a quality.
     */
    public function qualityTopic(string $slug): ?EduTopic
    {
        return $this->topic('quality', $slug);
    }

    /**
     * Look up multiple chord quality blurbs by their slugs.
     * Useful when you have a set of qualities used on a page.
     *
     * @param  string[]  $qualitySlugs
     * @return array<string, array{title:string,blurb:string}>
     */
    public function chordQualities(array $qualitySlugs): array
    {
        $all = $this->allChordQualities();
        $result = [];
        foreach ($qualitySlugs as $slug) {
            if (isset($all[$slug])) {
                $result[$slug] = $all[$slug];
            }
        }

        return $result;
    }

    // ── General topic API ───────────────────────────────────────────────────

    /**
     * Look up any topic by type + slug, with its full rendered body.
     */
    public function topic(string $type, string $slug): ?EduTopic
    {
        return $this->allTopics()[$type][$slug] ?? null;
    }

    /**
     * Resolve "Related theory" concepts for a song's sidebar (SBN-Leadsheet
     * Viewer EduPanel). Chord-quality alone can't tell a classical piece from
     * a jazz standard apart (both use dom7/maj7 chords), so this widens the
     * signal across four sources, in priority order:
     *
     *   1. skill nodes actually linked to the song (most specific — curated)
     *   2. detected progressions, matched by name/slug substring
     *   3. genre fallback, so a song with neither of the above still
     *      differentiates by style rather than falling through to nothing
     *   4. difficulty gate — advanced concepts (extensions, brightness) only
     *      surface once the song is difficulty >= their configured floor
     *
     * @param  \App\Models\Leadsheet  $leadsheet
     * @param  string[]  $progressionSlugs  slugs of progressions detected in this song
     * @return EduTopic[]  concept topics, most-relevant first, deduped, capped
     */
    public function conceptsForLeadsheet(\App\Models\Leadsheet $leadsheet, array $progressionSlugs = []): array
    {
        $slugs = [];

        foreach ($leadsheet->skillNodes as $node) {
            $concept = self::SKILL_NODE_CONCEPTS[$node->slug] ?? null;
            if ($concept) {
                $slugs[] = $concept;
            }
        }

        foreach ($progressionSlugs as $progSlug) {
            foreach (self::PROGRESSION_CONCEPTS as $needle => $concept) {
                if (str_contains($progSlug, $needle)) {
                    $slugs[] = $concept;
                }
            }
        }

        if (! $slugs && $leadsheet->genre) {
            $slugs = self::GENRE_CONCEPTS[$leadsheet->genre] ?? [];
        }

        $difficulty = (int) ($leadsheet->difficulty ?? 0);
        $slugs = array_values(array_filter($slugs, function (string $slug) use ($difficulty) {
            $floor = self::DIFFICULTY_GATED_CONCEPTS[$slug] ?? null;

            return $floor === null || $difficulty >= $floor;
        }));

        $slugs = array_slice(array_unique($slugs), 0, self::MAX_RELATED_CONCEPTS);

        return array_values(array_filter(array_map(
            fn (string $slug) => $this->topic('concept', $slug),
            $slugs,
        )));
    }

    /**
     * All topics of a given type, keyed by slug, sorted by title.
     *
     * @return array<string, EduTopic>
     */
    public function topics(string $type): array
    {
        $byType = $this->allTopics()[$type] ?? [];
        uasort($byType, fn (EduTopic $a, EduTopic $b) => strcasecmp($a->title, $b->title));

        return $byType;
    }

    /**
     * All glossary entries, sorted by title.
     *
     * @return array<string, EduTopic>
     */
    public function glossary(): array
    {
        return $this->topics('glossary');
    }

    /**
     * Drop the cached topic set. Called by `edu:clear-cache`.
     */
    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /**
     * The full parsed topic set: type => slug => EduTopic.
     *
     * Cached forever in non-local environments. In `local` the files are
     * re-parsed every call so authoring is live.
     *
     * @return array<string, array<string, EduTopic>>
     */
    private function allTopics(): array
    {
        if (app()->environment('local')) {
            return $this->parseAll();
        }

        $cached = Cache::rememberForever(self::CACHE_KEY, function () {
            // EduTopic objects are not guaranteed cache-safe across drivers,
            // so the cache holds plain arrays; rehydrate on read.
            return array_map(
                fn (array $byType) => array_map(fn (EduTopic $t) => $t->toArray(), $byType),
                $this->parseAll(),
            );
        });

        return array_map(
            fn (array $byType) => array_map([EduTopic::class, 'fromArray'], $byType),
            $cached,
        );
    }

    /**
     * Read and parse every markdown file under resources/edu/.
     *
     * @return array<string, array<string, EduTopic>>
     */
    private function parseAll(): array
    {
        $root = resource_path('edu');
        $result = [];

        foreach (self::TYPE_DIRS as $type => $subdir) {
            $result[$type] = [];
            $dir = $root.DIRECTORY_SEPARATOR.$subdir;
            if (! File::isDirectory($dir)) {
                continue;
            }

            foreach (File::files($dir) as $file) {
                if ($file->getExtension() !== 'md') {
                    continue;
                }
                $topic = $this->parseFile($file->getPathname(), $type);
                if ($topic !== null) {
                    $result[$type][$topic->slug] = $topic;
                }
            }
        }

        return $result;
    }

    /**
     * Parse one markdown file into an EduTopic. Returns null if the file has
     * no frontmatter or is missing a required field.
     */
    private function parseFile(string $path, string $type): ?EduTopic
    {
        $raw = File::get($path);

        // Split leading `---\n ... \n---` frontmatter from the body.
        if (! preg_match('/^---\s*\R(.*?)\R---\s*\R?(.*)$/s', $raw, $m)) {
            return null;
        }

        $meta = Yaml::parse($m[1]) ?: [];
        $body = $m[2];

        // YAML coerces bare scalars: `slug: 5` parses as int, `slug: 7sus4`
        // as string. Cast to string so numeric-looking slugs survive.
        $slug = isset($meta['slug']) && is_scalar($meta['slug']) ? (string) $meta['slug'] : null;
        $title = isset($meta['title']) && is_scalar($meta['title']) ? (string) $meta['title'] : null;
        $summary = isset($meta['summary']) && is_scalar($meta['summary']) ? (string) $meta['summary'] : null;
        if ($slug === null || $title === null || $summary === null) {
            return null;
        }

        // Optional quality-only prose. Authored as frontmatter strings because
        // Chords/Show.vue renders them as two distinct styled spans.
        $description = isset($meta['description']) && is_scalar($meta['description'])
            ? (string) $meta['description'] : null;
        $usage = isset($meta['usage']) && is_scalar($meta['usage'])
            ? (string) $meta['usage'] : null;

        $bodyHtml = $this->renderMarkdown($body);

        return new EduTopic(
            slug: $slug,
            type: $type,
            title: $title,
            summary: $summary,
            bodyHtml: $bodyHtml,
            related: array_map('strval', (array) ($meta['related'] ?? [])),
            seeAlso: array_map('strval', (array) ($meta['see_also'] ?? [])),
            description: $description,
            usage: $usage,
            hasWidgets: EduTopic::bodyHasWidgets($bodyHtml),
        );
    }

    /**
     * Render a Markdown body to HTML.
     *
     * `html_input: allow` is essential: topic bodies embed raw <sbn-widget>
     * tags that the client mounts as Vue components. The default `escape`
     * would turn them into inert text. Content is repo-authored, not user
     * input, so allowing raw HTML carries no injection risk.
     */
    private function renderMarkdown(string $markdown): string
    {
        $environment = new Environment([
            'html_input' => 'allow',
            'allow_unsafe_links' => false,
        ]);
        $environment->addExtension(new CommonMarkCoreExtension);

        return (string) (new MarkdownConverter($environment))->convert($markdown);
    }
}

<?php

namespace App\Services\Edu;

/**
 * A single educational topic parsed from a `resources/edu/{type}/{slug}.md`
 * file: YAML frontmatter + a rendered-to-HTML Markdown body.
 *
 * Immutable value object. Created only by EduContentService's parser.
 */
final class EduTopic
{
    /**
     * @param  string  $slug  Unique within its type; the lookup key.
     * @param  string  $type  One of: concept, quality, glossary.
     * @param  string  $title  Display heading.
     * @param  string  $summary  One-line plain-text hook.
     * @param  string  $bodyHtml  Markdown body rendered to HTML (sbn-widget tags preserved).
     * @param  string[]  $related  Slugs of related edu topics.
     * @param  string[]  $seeAlso  Glossary slugs cross-linked from this topic.
     * @param  string|null  $description  Quality-only: the "what it is" prose
     *                                    span on Chords/Show.vue. Null otherwise.
     * @param  string|null  $usage  Quality-only: the "where it's used" prose
     *                              span on Chords/Show.vue. Null otherwise.
     * @param  bool  $hasWidgets  True when $bodyHtml embeds at least one
     *                            <sbn-widget> element. Computed at parse time;
     *                            consumers render the body through mountSbnNodes
     *                            only when this is true. See bodyHasWidgets().
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $type,
        public readonly string $title,
        public readonly string $summary,
        public readonly string $bodyHtml,
        public readonly array $related = [],
        public readonly array $seeAlso = [],
        public readonly ?string $description = null,
        public readonly ?string $usage = null,
        public readonly bool $hasWidgets = false,
    ) {}

    /**
     * Whether a rendered Markdown body embeds at least one <sbn-widget>.
     *
     * Matches an actual element opening — `<sbn-widget` followed by
     * whitespace, `/`, or `>` — not a bare substring, so a body that merely
     * mentions the words "sbn-widget" in prose or a code fence does not trip
     * it. The single definition of "carries an interactive"; the parser uses
     * it to populate $hasWidgets, and all consumers read that flag rather
     * than re-deriving.
     */
    public static function bodyHasWidgets(string $bodyHtml): bool
    {
        return preg_match('/<sbn-widget[\s\/>]/', $bodyHtml) === 1;
    }

    /**
     * Plain associative form for Inertia / JSON payloads.
     *
     * @return array{slug:string,type:string,title:string,summary:string,body_html:string,related:string[],see_also:string[],description:string|null,usage:string|null,has_widgets:bool}
     */
    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'type' => $this->type,
            'title' => $this->title,
            'summary' => $this->summary,
            'body_html' => $this->bodyHtml,
            'related' => $this->related,
            'see_also' => $this->seeAlso,
            'description' => $this->description,
            'usage' => $this->usage,
            'has_widgets' => $this->hasWidgets,
        ];
    }

    /**
     * Rebuild a topic from its array form (used to rehydrate from cache).
     */
    public static function fromArray(array $data): self
    {
        return new self(
            slug: $data['slug'],
            type: $data['type'],
            title: $data['title'],
            summary: $data['summary'],
            bodyHtml: $data['body_html'],
            related: $data['related'] ?? [],
            seeAlso: $data['see_also'] ?? [],
            description: $data['description'] ?? null,
            usage: $data['usage'] ?? null,
            hasWidgets: $data['has_widgets'] ?? false,
        );
    }
}

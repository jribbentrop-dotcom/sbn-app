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
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $type,
        public readonly string $title,
        public readonly string $summary,
        public readonly string $bodyHtml,
        public readonly array $related = [],
        public readonly array $seeAlso = [],
    ) {}

    /**
     * Plain associative form for Inertia / JSON payloads.
     *
     * @return array{slug:string,type:string,title:string,summary:string,body_html:string,related:string[],see_also:string[]}
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
        );
    }
}

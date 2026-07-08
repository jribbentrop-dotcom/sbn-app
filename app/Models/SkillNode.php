<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * A skill node — an atomic teachable concept in the skill graph.
 *
 * Nodes form a directed graph via self-referencing prerequisite edges
 * (sbn_skill_node_prerequisites). They map many-to-many to courses, and may
 * optionally borrow the tag cloud (content_tag_slug) to discover associated
 * content. See docs/SBN-Skill-System-Reference.md.
 */
class SkillNode extends Model
{
    protected $table = 'sbn_skill_nodes';

    protected $guarded = ['id'];

    protected $casts = [
        'sort_order'         => 'integer',
        'grade'              => 'integer',
        'pos_x'              => 'integer',
        'pos_y'              => 'integer',
        'voicing_categories' => 'array',
    ];

    /** Canonical branches (see plan "Skill Taxonomy"). */
    public const BRANCHES = [
        'rhythm', 'harmony', 'melody', 'technique', 'ear-training', 'reading-theory',
    ];

    /**
     * Canonical style axis (vision pillar 4). Same controlled vocabulary courses
     * use in their `genres` JSON — NOT the freeform sbn_tags cloud. A node relates
     * to 0..N of these via sbn_skill_node_style, each with a weight (1–3). See
     * docs/SBN-Skill-System-Reference.md "Vision → Reality Reconciliation".
     */
    public const STYLES = ['bossa-nova', 'jazz', 'classical', 'pop'];

    /** Style weights: how characteristic the node is of a style. */
    public const STYLE_WEIGHT_WEAK = 1;          // touches the style, not defining
    public const STYLE_WEIGHT_MEDIUM = 2;        // clearly part of the style's toolkit
    public const STYLE_WEIGHT_DEFINITIONAL = 3;  // you can't separate the node from the style

    public const COMPLETION_SELF_REPORT = 'self_report';

    /** Max chips shown per content-type group in practiceLinks() before truncating. */
    private const PRACTICE_LINKS_CAP = 5;

    // =========================================================================
    // RELATIONS
    // =========================================================================

    /** Nodes this node directly depends on (its prerequisites). */
    public function prerequisites(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'sbn_skill_node_prerequisites',
            'skill_node_id',
            'requires_skill_node_id',
        );
    }

    /** Inverse edge: nodes that list this node as a prerequisite ("what this unlocks"). */
    public function unlocks(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'sbn_skill_node_prerequisites',
            'requires_skill_node_id',
            'skill_node_id',
        );
    }

    /** Courses that teach this node (many-to-many). */
    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'sbn_course_skill_node', 'skill_node_id', 'course_id');
    }

    /** Per-user progress rows for this node. */
    public function userProgress(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'sbn_user_skill_progress', 'skill_node_id', 'user_id')
            ->withPivot(['status', 'completed_at'])
            ->withTimestamps();
    }

    // =========================================================================
    // STYLE AXIS (vision pillar 4 — see docs plan)
    // =========================================================================

    /**
     * Style weights for this node, e.g. ['jazz' => 3, 'bossa-nova' => 2].
     * Empty array when the node carries no style tags (most foundational nodes).
     *
     * Kept as a query-backed accessor rather than a BelongsToMany because the
     * style "table" is a fixed string vocabulary (STYLES), not a model.
     *
     * @return array<string,int>
     */
    public function styleWeights(): array
    {
        return \DB::table('sbn_skill_node_style')
            ->where('skill_node_id', $this->id)
            ->pluck('weight', 'style')
            ->map(fn ($w) => (int) $w)
            ->all();
    }

    /** Replace this node's style tags with the given [style => weight] map. */
    public function syncStyles(array $weights): void
    {
        \DB::table('sbn_skill_node_style')->where('skill_node_id', $this->id)->delete();

        $rows = [];
        foreach ($weights as $style => $weight) {
            if (! in_array($style, self::STYLES, true)) {
                continue; // ignore anything outside the controlled vocabulary
            }
            $rows[] = [
                'skill_node_id' => $this->id,
                'style'         => $style,
                'weight'        => max(1, min(3, (int) $weight)),
            ];
        }

        if ($rows) {
            \DB::table('sbn_skill_node_style')->insert($rows);
        }
    }

    /** Tie-break order when 2+ styles share the top weight — see styleColor(). */
    private const STYLE_PRIORITY = ['bossa-nova', 'jazz', 'classical', 'pop'];

    /**
     * The dominant style for this node, for single-fill tile coloring on the
     * student skill tree. Highest weight wins; ties broken by STYLE_PRIORITY.
     * Returns null for nodes with no style tags (foundational/neutral).
     */
    public function styleColor(): ?string
    {
        $weights = $this->styleWeights();

        if (! $weights) {
            return null;
        }

        $maxWeight = max($weights);
        $tied = array_keys(array_filter($weights, fn ($w) => $w === $maxWeight));
        usort($tied, fn ($a, $b) => array_search($a, self::STYLE_PRIORITY, true) <=> array_search($b, self::STYLE_PRIORITY, true));

        return $tied[0];
    }

    // =========================================================================
    // DIRECT CONTENT LINKS (sbn_skill_node_content)
    // =========================================================================
    //
    // Precise node→content links (not the lossy tag bridge below). Each is a
    // morphedByMany over the shared sbn_skill_node_content pivot. Exercises are
    // intentionally absent — course-only content, see the migration docblock.

    public function rhythmPatterns(): MorphToMany
    {
        return $this->morphedByMany(RhythmPattern::class, 'content', 'sbn_skill_node_content', 'skill_node_id', 'content_id')
            ->withPivot('sort_order')->withTimestamps();
    }

    public function chordProgressions(): MorphToMany
    {
        return $this->morphedByMany(ChordProgression::class, 'content', 'sbn_skill_node_content', 'skill_node_id', 'content_id')
            ->withPivot('sort_order')->withTimestamps();
    }

    public function leadsheets(): MorphToMany
    {
        return $this->morphedByMany(Leadsheet::class, 'content', 'sbn_skill_node_content', 'skill_node_id', 'content_id')
            ->withPivot('sort_order')->withTimestamps();
    }

    /**
     * Lessons directly linked to this node via the same pivot. Despite the
     * "exercises excluded, course-only" note above (still true — exercises
     * really are absent), individual Lesson rows ARE present in
     * sbn_skill_node_content in practice (discovered 2026-07-07) — this
     * relation was missing even though the data existed. Used by
     * practiceLinks() to deep-link into the exact lesson, not just the course.
     */
    public function lessons(): MorphToMany
    {
        return $this->morphedByMany(Lesson::class, 'content', 'sbn_skill_node_content', 'skill_node_id', 'content_id')
            ->withPivot('sort_order')->withTimestamps();
    }

    /**
     * Chord voicings this node teaches, resolved from voicing_categories — NOT a
     * pivot. The node owns a category (e.g. "drop2"); this returns every diagram in
     * those categories, so new voicings are covered automatically as the library
     * grows. Empty collection when the node carries no categories.
     */
    public function chordDiagrams(): Collection
    {
        $cats = $this->voicing_categories ?: [];

        if (! $cats) {
            return new Collection;
        }

        return ChordDiagram::whereIn('voicing_category', $cats)
            ->orderBy('voicing_category')->orderBy('sort_order')->get();
    }

    /**
     * Directly-linked content grouped by type. Rhythms/progressions/songs are
     * specific-item pivot links; chordDiagrams is category-resolved (see above).
     * Leadsheets are the "songs as equipment" link — the repertoire a node unlocks.
     * Lessons are included too (see lessons() docblock — data existed, relation
     * was missing until 2026-07-07).
     *
     * @return array{rhythmPatterns:Collection,chordProgressions:Collection,chordDiagrams:Collection,leadsheets:Collection,lessons:Collection}
     */
    public function linkedContent(): array
    {
        return [
            'rhythmPatterns'    => $this->rhythmPatterns,
            'chordProgressions' => $this->chordProgressions,
            'chordDiagrams'     => $this->chordDiagrams(),
            'leadsheets'        => $this->leadsheets,
            'lessons'           => $this->lessons()->with('course:id,slug,title')->get(),
        ];
    }

    /**
     * Flat, Inertia-friendly "where to practice this" payload: courses that
     * teach the node plus every directly-linked content item, each reduced to
     * a slug + title + library route so a Vue "Practice this" panel can render
     * without knowing about each content model's own shape. Used by the public
     * glossary (/skills) and the "Recommended next" panel on /account/skills —
     * see docs/SBN-Skill-System-Reference.md "Node ↔ Content Links".
     *
     * Lessons deep-link into their own course/lesson pair and are appended to
     * the "courses" list alongside any plain node<->course mappings (a node can
     * have both — they're not mutually exclusive, and a node commonly links
     * several lessons within the same course, so lessons can't collapse into a
     * single per-course entry).
     *
     * Each group is capped at PRACTICE_LINKS_CAP items with a `more` overflow
     * count, not the raw list — a broadly-linked node (e.g. a voicing category
     * pulling 50 songs) was blowing out the recommended-card grid layout
     * (found + fixed 2026-07-07).
     *
     * @return array{courses:array{items:array,more:int},rhythmPatterns:array{items:array,more:int},chordProgressions:array{items:array,more:int},leadsheets:array{items:array,more:int},chordCategoryLabel:?string,chordLibraryUrl:?string}
     */
    public function practiceLinks(): array
    {
        $linked = $this->linkedContent();

        $courseLinks = $this->courses()->get(['sbn_courses.id', 'sbn_courses.slug', 'sbn_courses.title'])
            ->map(fn ($c) => ['slug' => $c->slug, 'title' => $c->title, 'url' => "/learn/{$c->slug}"]);

        $lessonLinks = $linked['lessons']
            ->filter(fn ($lesson) => $lesson->course) // skip orphaned rows (dangling course_id)
            ->map(fn ($lesson) => [
                // Lesson title alone, not "Course — Lesson": the course-level
                // chip (above) already names the course, and the long combined
                // string was overflowing the recommended-card grid (2026-07-07).
                'slug'  => $lesson->slug,
                'title' => $lesson->title,
                'url'   => "/learn/{$lesson->course->slug}/play/{$lesson->slug}",
            ]);

        $allCourseLinks = $courseLinks->concat($lessonLinks)->values();

        // Cap each group so a broadly-linked node (e.g. a voicing category
        // pulling 50 songs) can't blow out the card — see layout bug found
        // 2026-07-07. "more" carries the true count for a "+N more" affordance.
        $cap = fn ($items) => [
            'items' => $items->take(self::PRACTICE_LINKS_CAP)->values()->all(),
            'more'  => max(0, $items->count() - self::PRACTICE_LINKS_CAP),
        ];

        return [
            'courses' => $cap($allCourseLinks),

            'rhythmPatterns' => $cap($linked['rhythmPatterns']
                ->map(fn ($r) => ['slug' => $r->slug, 'title' => $r->name, 'url' => "/library/rhythms/{$r->slug}"])),

            'chordProgressions' => $cap($linked['chordProgressions']
                ->map(fn ($p) => ['slug' => $p->slug, 'title' => $p->name, 'url' => "/library/progressions/{$p->slug}"])),

            'leadsheets' => $cap($linked['leadsheets']
                ->map(fn ($l) => ['slug' => $l->slug, 'title' => $l->title, 'url' => "/library/songs/{$l->slug}"])),

            // Chord voicings are category-resolved, not individual items — link
            // to the chord library filtered by category rather than N diagrams.
            'chordCategoryLabel' => ($this->voicing_categories ?: null)
                ? implode(' / ', array_map(
                    fn ($cat) => ChordDiagram::VOICING_CATEGORIES[$cat] ?? $cat,
                    $this->voicing_categories,
                ))
                : null,
            'chordLibraryUrl' => $this->voicing_categories ? '/library/chords' : null,
        ];
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    public function scopeInBranch($query, string $branch)
    {
        return $query->where('branch', $branch);
    }

    // =========================================================================
    // TAG-BRIDGE CONTENT DISCOVERY
    // =========================================================================

    /**
     * The tag this node borrows for content discovery, if any.
     * Returns null when content_tag_slug is unset or the tag doesn't exist yet.
     */
    public function contentTag(): ?SbnTag
    {
        if (! $this->content_tag_slug) {
            return null;
        }

        return SbnTag::where('slug', $this->content_tag_slug)->first();
    }

    /**
     * Content associated with this node via the tag bridge, grouped by type.
     * Empty arrays when no content_tag_slug or no matching tag — callers should
     * not assume the tag bridge covers a node (see plan caveat: tag granularity
     * rarely matches skill granularity).
     *
     * @return array{rhythmPatterns:Collection,chordProgressions:Collection,leadsheets:Collection}
     */
    public function associatedContent(): array
    {
        $tag = $this->contentTag();

        if (! $tag) {
            return [
                'rhythmPatterns'   => new Collection,
                'chordProgressions' => new Collection,
                'leadsheets'       => new Collection,
            ];
        }

        return [
            'rhythmPatterns'   => $tag->rhythmPatterns,
            'chordProgressions' => $tag->chordProgressions,
            'leadsheets'       => $tag->leadsheets,
        ];
    }
}

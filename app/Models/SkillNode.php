<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A skill node — an atomic teachable concept in the skill graph.
 *
 * Nodes form a directed graph via self-referencing prerequisite edges
 * (sbn_skill_node_prerequisites). They map many-to-many to courses, and may
 * optionally borrow the tag cloud (content_tag_slug) to discover associated
 * content. See docs/SBN-Skill-System-Plan.md.
 */
class SkillNode extends Model
{
    protected $table = 'sbn_skill_nodes';

    protected $guarded = ['id'];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    /** Canonical branches (see plan "Skill Taxonomy"). */
    public const BRANCHES = [
        'rhythm', 'harmony', 'melody', 'technique', 'ear-training', 'reading-theory',
    ];

    /**
     * Canonical style axis (vision pillar 4). Same controlled vocabulary courses
     * use in their `genres` JSON — NOT the freeform sbn_tags cloud. A node relates
     * to 0..N of these via sbn_skill_node_style, each with a weight (1–3). See
     * docs/SBN-Skill-System-Plan.md "Vision → Reality Reconciliation".
     */
    public const STYLES = ['bossa-nova', 'jazz', 'classical', 'pop'];

    /** Style weights: how characteristic the node is of a style. */
    public const STYLE_WEIGHT_WEAK = 1;          // touches the style, not defining
    public const STYLE_WEIGHT_MEDIUM = 2;        // clearly part of the style's toolkit
    public const STYLE_WEIGHT_DEFINITIONAL = 3;  // you can't separate the node from the style

    public const COMPLETION_SELF_REPORT = 'self_report';

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

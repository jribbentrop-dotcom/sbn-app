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

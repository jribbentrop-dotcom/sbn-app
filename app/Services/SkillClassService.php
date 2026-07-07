<?php

namespace App\Services;

use App\Models\SkillNode;
use App\Models\User;

/**
 * Derives a student's style-class progress live from completed skill nodes —
 * vision pillar 5 (see docs/SBN-Skill-System-Plan.md "Vision → Reality
 * Reconciliation" and "Style Classes"). No persistence: exactly like
 * SkillGradeService, a class is recomputed on every request from
 * sbn_user_skill_progress + sbn_skill_node_style, so there's nothing to
 * migrate, backfill, or keep in sync when node/style data changes.
 *
 * Scope decision (2026-07-07): the plan's full vision is hand-curated TIERED
 * classes per style (Bossa Rhythm Player -> Bossa Comper -> Bossa Nova
 * Guitarist), each needing a deliberately-picked node set — real curatorial
 * work on the scale of the original 64-node graph. This ships the simpler
 * cut instead: ONE class per style (STYLES on SkillNode), auto-derived from
 * the style weights that already exist. Awarded, not tiered. If/when the
 * tiered version gets curated, this service is the natural place to add tier
 * thresholds without touching callers (they already consume "awarded" +
 * "pct").
 *
 * Award rule: weight-sum of completed nodes tagged that style, divided by
 * weight-sum of ALL nodes tagged that style (weight >= WEIGHT_FLOOR only —
 * weight-1 "touches the style, not defining" nodes are too incidental to
 * gate a class on), >= THRESHOLD.
 */
class SkillClassService
{
    /** Fraction of a style's qualifying weight that must be completed to award the class. */
    public const THRESHOLD = 0.70;

    /** Minimum node-style weight that counts toward a class (excludes "weak/touches" tags). */
    public const WEIGHT_FLOOR = SkillNode::STYLE_WEIGHT_MEDIUM;

    public const CLASS_TITLES = [
        'bossa-nova' => 'Bossa Nova Player',
        'jazz'       => 'Jazz Player',
        'classical'  => 'Classical Player',
        'pop'        => 'Pop Player',
    ];

    /**
     * Full style-class breakdown for a user.
     *
     * @return array<string,array{style:string,title:string,done:int,total:int,pct:int,awarded:bool}>
     */
    public function forUser(User $user): array
    {
        $completedNodeIds = $user->skillNodes()
            ->wherePivot('status', 'completed')
            ->pluck('sbn_skill_nodes.id')
            ->flip();

        return $this->compute($completedNodeIds->keys()->all());
    }

    /**
     * Same breakdown from an explicit set of completed node ids (mirrors
     * SkillGradeService::compute() — lets callers avoid a second query and
     * keeps this unit-testable without a User).
     *
     * @param  array<int>  $completedNodeIds
     * @return array<string,array{style:string,title:string,done:int,total:int,pct:int,awarded:bool}>
     */
    public function compute(array $completedNodeIds): array
    {
        $completed = array_flip($completedNodeIds);

        $rows = \DB::table('sbn_skill_node_style')
            ->where('weight', '>=', self::WEIGHT_FLOOR)
            ->get(['skill_node_id', 'style', 'weight']);

        // style => ['total' => weight-sum, 'done' => weight-sum of completed]
        $tally = [];
        foreach (SkillNode::STYLES as $style) {
            $tally[$style] = ['total' => 0, 'done' => 0];
        }
        foreach ($rows as $row) {
            $tally[$row->style]['total'] += (int) $row->weight;
            if (isset($completed[$row->skill_node_id])) {
                $tally[$row->style]['done'] += (int) $row->weight;
            }
        }

        $classes = [];
        foreach (SkillNode::STYLES as $style) {
            $total = $tally[$style]['total'];
            $done = $tally[$style]['done'];
            // A style with no qualifying nodes yet can never be awarded (not
            // vacuously true like an empty grade — there's nothing to have
            // learned, so "Player" would be a lie, not a milestone).
            $pct = $total === 0 ? 0 : (int) round(($done / $total) * 100);
            $classes[$style] = [
                'style'   => $style,
                'title'   => self::CLASS_TITLES[$style],
                'done'    => $done,
                'total'   => $total,
                'pct'     => $pct,
                'awarded' => $total > 0 && ($done / $total) >= self::THRESHOLD,
            ];
        }

        return $classes;
    }
}

<?php

namespace App\Services;

use App\Models\SkillNode;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Computes a student's difficulty grade from their completed skill nodes
 * (vision pillars 1 + 2 — see docs/SBN-Skill-System-Plan.md).
 *
 * Rule (chosen 2026-06-25, "% of each grade's nodes"):
 *   - A grade is CLEARED when completed/total of its nodes ≥ THRESHOLD.
 *   - The student's LEVEL is the highest grade G such that every grade 1..G is
 *     cleared (no skipping — you can't be "grade 3" with grade 2 unfinished,
 *     even if you happen to have done some grade-3 nodes).
 *   - Ungraded nodes (grade NULL) count toward nothing — neutral, like untagged
 *     styles. They never block a grade.
 *
 * The threshold lives here only, so tuning it is a one-line change. Grades with
 * zero nodes are treated as vacuously cleared (so an empty grade-5 doesn't cap
 * everyone at 4 forever — see capByDefinedGrades()).
 */
class SkillGradeService
{
    /** Fraction of a grade's nodes that must be done to "clear" it. */
    public const THRESHOLD = 0.70;

    /** Grade 1..MAX_GRADE. Matches the 5-grade difficulty system. */
    public const MAX_GRADE = 5;

    /**
     * Full grade breakdown for a user.
     *
     * @return array{
     *   level:int,
     *   levelLabel:string,
     *   threshold:float,
     *   grades:array<int,array{grade:int,label:string,done:int,total:int,pct:int,cleared:bool,current:bool}>
     * }
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
     * Same breakdown from an explicit set of completed node ids (lets callers
     * that already loaded progress avoid a second query, and keeps this unit-testable).
     *
     * @param  array<int>  $completedNodeIds
     */
    public function compute(array $completedNodeIds): array
    {
        $completed = array_flip($completedNodeIds);

        // [grade => [total, done]] for graded nodes only.
        $rows = SkillNode::whereNotNull('grade')
            ->get(['id', 'grade']);

        $tally = []; // grade => ['total'=>x,'done'=>y]
        foreach ($rows as $node) {
            $g = (int) $node->grade;
            $tally[$g] ??= ['total' => 0, 'done' => 0];
            $tally[$g]['total']++;
            if (isset($completed[$node->id])) {
                $tally[$g]['done']++;
            }
        }

        $maxDefinedGrade = $tally ? max(array_keys($tally)) : 0;

        $grades = [];
        for ($g = 1; $g <= self::MAX_GRADE; $g++) {
            $total = $tally[$g]['total'] ?? 0;
            $done  = $tally[$g]['done'] ?? 0;
            // A grade with no nodes is vacuously cleared (can't gate on it).
            $cleared = $total === 0 ? true : ($done / $total) >= self::THRESHOLD;
            $grades[$g] = [
                'grade'   => $g,
                'label'   => self::gradeLabel($g),
                'done'    => $done,
                'total'   => $total,
                'pct'     => $total === 0 ? 0 : (int) round(($done / $total) * 100),
                'cleared' => $cleared,
                'current' => false, // set below
            ];
        }

        // Level = highest G where every grade 1..G is cleared, capped at the
        // highest grade that actually has nodes defined (so an empty top grade
        // doesn't inflate level past what's been curated).
        $level = 0;
        for ($g = 1; $g <= self::MAX_GRADE; $g++) {
            if ($grades[$g]['cleared']) {
                $level = $g;
            } else {
                break;
            }
        }
        $level = min($level, $maxDefinedGrade);

        // "Current" grade = the one the student is working on (first uncleared,
        // among grades that have nodes). Null if everything defined is cleared.
        for ($g = 1; $g <= self::MAX_GRADE; $g++) {
            if ($grades[$g]['total'] > 0 && ! $grades[$g]['cleared']) {
                $grades[$g]['current'] = true;
                break;
            }
        }

        return [
            'level'      => $level,
            'levelLabel' => $level === 0 ? 'Getting started' : self::gradeLabel($level),
            'threshold'  => self::THRESHOLD,
            'grades'     => $grades,
        ];
    }

    public static function gradeLabel(int $grade): string
    {
        return [
            1 => 'Basic',
            2 => 'Early Intermediate',
            3 => 'Intermediate',
            4 => 'Late Intermediate',
            5 => 'Advanced',
        ][$grade] ?? "Grade {$grade}";
    }
}

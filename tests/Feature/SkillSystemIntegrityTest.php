<?php

namespace Tests\Feature;

use App\Models\SkillNode;
use App\Services\SkillClassService;
use App\Services\SkillGradeService;
use App\Services\SkillGraphService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Mechanical guardrails for the skill system (docs/SBN-Skill-System-Reference.md).
 *
 * These assert STRUCTURAL INVARIANTS that must hold no matter how the content
 * evolves — they deliberately never check node counts, specific slugs, or which
 * course maps to which node (all live content that churns). They run against the
 * real sbn.db (read-only), the same pattern as the other feature tests, so they
 * catch bad data the moment it lands: an edge that closes a cycle the admin form
 * didn't guard, a dangling prereq ref, a node with an out-of-range grade, or a
 * threshold-math regression in the grade/class services.
 */
class SkillSystemIntegrityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.sqlite.database' => database_path('sbn.db')]);
        DB::reconnect('sqlite');
    }

    // ---- Graph integrity ---------------------------------------------------

    public function test_prerequisite_graph_is_acyclic(): void
    {
        // topologicalOrder() throws RuntimeException if the graph has a cycle.
        $order = app(SkillGraphService::class)->topologicalOrder();

        $this->assertCount(
            SkillNode::count(),
            $order,
            'Topological order must contain every node exactly once.'
        );
    }

    public function test_no_node_is_its_own_prerequisite(): void
    {
        $selfEdges = DB::table('sbn_skill_node_prerequisites')
            ->whereColumn('skill_node_id', 'requires_skill_node_id')
            ->count();

        $this->assertSame(0, $selfEdges, 'A node must not require itself.');
    }

    public function test_prerequisite_edges_reference_real_nodes(): void
    {
        $ids = SkillNode::pluck('id')->flip();

        $dangling = DB::table('sbn_skill_node_prerequisites')
            ->get(['skill_node_id', 'requires_skill_node_id'])
            ->filter(fn ($e) => ! isset($ids[$e->skill_node_id]) || ! isset($ids[$e->requires_skill_node_id]))
            ->values();

        $this->assertCount(0, $dangling, 'Every prerequisite edge must point at an existing node on both ends.');
    }

    public function test_course_node_pivot_references_real_nodes(): void
    {
        $ids = SkillNode::pluck('id')->flip();

        $dangling = DB::table('sbn_course_skill_node')
            ->pluck('skill_node_id')
            ->filter(fn ($nid) => ! isset($ids[$nid]))
            ->values();

        $this->assertCount(0, $dangling, 'Every course→node pivot row must reference an existing node.');
    }

    // ---- Node field sanity -------------------------------------------------

    public function test_every_node_has_a_valid_branch(): void
    {
        $bad = SkillNode::whereNotIn('branch', SkillNode::BRANCHES)
            ->pluck('slug')
            ->all();

        $this->assertSame([], $bad, 'These nodes have a branch outside SkillNode::BRANCHES: ' . implode(', ', $bad));
    }

    public function test_every_graded_node_is_in_range_one_to_five(): void
    {
        $bad = SkillNode::whereNotNull('grade')
            ->where(fn ($q) => $q->where('grade', '<', 1)->orWhere('grade', '>', SkillGradeService::MAX_GRADE))
            ->pluck('slug')
            ->all();

        $this->assertSame([], $bad, 'These nodes have a grade outside 1..' . SkillGradeService::MAX_GRADE . ': ' . implode(', ', $bad));
    }

    public function test_style_pivot_uses_known_styles_and_weights(): void
    {
        $badStyle = DB::table('sbn_skill_node_style')
            ->whereNotIn('style', SkillNode::STYLES)
            ->count();
        $this->assertSame(0, $badStyle, 'sbn_skill_node_style has a style outside SkillNode::STYLES.');

        $badWeight = DB::table('sbn_skill_node_style')
            ->where(fn ($q) => $q->where('weight', '<', 1)->orWhere('weight', '>', 3))
            ->count();
        $this->assertSame(0, $badWeight, 'Style weights must be 1..3.');
    }

    public function test_slugs_are_unique(): void
    {
        $total = SkillNode::count();
        $distinct = SkillNode::distinct()->count('slug');

        $this->assertSame($total, $distinct, 'Skill node slugs must be unique.');
    }

    // ---- Grade service math (content-agnostic) -----------------------------

    public function test_grade_level_does_not_skip_an_uncleared_grade(): void
    {
        $grade = app(SkillGradeService::class);

        // Complete every grade-1 node but nothing else. If grade 1 has nodes,
        // the student clears it; they must NOT then be handed level 2+ just
        // because grade 2/3 happen to have some done nodes (they don't here).
        $g1Ids = SkillNode::where('grade', 1)->pluck('id')->all();
        $result = $grade->compute($g1Ids);

        if (! empty($g1Ids)) {
            $this->assertGreaterThanOrEqual(1, $result['level']);
        }

        // Whatever the level is, every grade at or below it must be cleared and
        // every grade above the first uncleared one must be unreached — i.e. the
        // "no skipping" contract holds for this input.
        $firstUncleared = null;
        for ($g = 1; $g <= SkillGradeService::MAX_GRADE; $g++) {
            if ($result['grades'][$g]['total'] > 0 && ! $result['grades'][$g]['cleared']) {
                $firstUncleared = $g;
                break;
            }
        }
        // The student's level can never reach or pass the first uncleared grade.
        if ($firstUncleared !== null) {
            $this->assertLessThan($firstUncleared, $result['level']);
        }
    }

    public function test_empty_completion_yields_level_zero(): void
    {
        $result = app(SkillGradeService::class)->compute([]);

        $this->assertSame(0, $result['level']);
        $this->assertSame('Getting started', $result['levelLabel']);
    }

    public function test_completing_everything_reaches_the_top_defined_grade(): void
    {
        $allIds = SkillNode::pluck('id')->all();
        $result = app(SkillGradeService::class)->compute($allIds);

        $maxDefined = (int) SkillNode::whereNotNull('grade')->max('grade');

        $this->assertSame($maxDefined, $result['level'], 'Completing every node should reach the highest defined grade.');
    }

    // ---- Class service math ------------------------------------------------

    public function test_no_class_is_awarded_with_zero_completion(): void
    {
        $classes = app(SkillClassService::class)->compute([]);

        foreach ($classes as $style => $c) {
            $this->assertFalse($c['awarded'], "Style class '{$style}' must not be awarded at 0% completion.");
            $this->assertSame(0, $c['done']);
        }

        // Every declared style must appear in the payload.
        $this->assertSame(SkillNode::STYLES, array_keys($classes));
    }

    public function test_completing_everything_awards_every_non_empty_class(): void
    {
        $allIds = SkillNode::pluck('id')->all();
        $classes = app(SkillClassService::class)->compute($allIds);

        foreach ($classes as $style => $c) {
            if ($c['total'] > 0) {
                $this->assertTrue($c['awarded'], "Style class '{$style}' should be awarded once every qualifying node is done.");
                $this->assertSame(100, $c['pct']);
            } else {
                // A style with no qualifying (weight>=2) nodes can never be awarded.
                $this->assertFalse($c['awarded']);
            }
        }
    }
}

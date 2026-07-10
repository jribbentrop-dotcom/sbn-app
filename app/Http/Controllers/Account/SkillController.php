<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\SkillNode;
use App\Services\SkillClassService;
use App\Services\SkillGradeService;
use App\Services\SkillGraphService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SkillController extends Controller
{
    public function index(Request $request, SkillGradeService $grades, SkillGraphService $graph, SkillClassService $classes)
    {
        $user = $request->user();

        $completedSlugs = $user->skillNodes()
            ->wherePivot('status', 'completed')
            ->pluck('sbn_skill_nodes.slug')
            ->flip(); // slug => index, for fast O(1) lookup in the map below

        // One query for all node style weights (avoid N+1 from calling
        // $n->styleWeights() per node) — id => [style => weight].
        $stylesByNodeId = \DB::table('sbn_skill_node_style')
            ->get(['skill_node_id', 'style', 'weight'])
            ->groupBy('skill_node_id')
            ->map(fn ($rows) => $rows->pluck('weight', 'style')->map(fn ($w) => (int) $w)->all());

        $nodes = SkillNode::orderBy('sort_order')
            ->get(['id', 'slug', 'title', 'branch', 'sub_branch', 'grade', 'icon_key', 'icon_path'])
            ->map(fn (SkillNode $n) => [
                'slug'         => $n->slug,
                'title'        => $n->title,
                'branch'       => $n->branch,
                'subBranch'    => $n->sub_branch,
                'grade'        => $n->grade,
                'iconKey'      => $n->icon_key,
                'iconPath'     => $n->icon_path,
                'done'         => isset($completedSlugs[$n->slug]),
                // Style weights let the page recompute class % live as the
                // student toggles nodes, mirroring liveGrades() below.
                'styleWeights' => $stylesByNodeId[$n->id] ?? (object) [],
            ]);

        $recommended = $graph->recommendedNext($user)
            ->map(fn (SkillNode $n) => [
                'slug'     => $n->slug,
                'title'    => $n->title,
                'branch'   => $n->branch,
                'grade'    => $n->grade,
                'iconKey'  => $n->icon_key,
                'iconPath' => $n->icon_path,
                'practice' => $n->practiceLinks(),
            ]);

        return Inertia::render('Account/Skills', [
            'nodes'        => $nodes,
            'gradeStats'   => $grades->forUser($user),
            'recommended'  => $recommended,
            'classStats'   => $classes->forUser($user),
        ]);
    }

    public function tree(Request $request, SkillGradeService $grades)
    {
        $user = $request->user();

        $completedIds = $user->skillNodes()
            ->wherePivot('status', 'completed')
            ->pluck('sbn_skill_nodes.id')
            ->flip();

        $allNodes = SkillNode::with('prerequisites:id')->orderBy('sort_order')->get();

        $nodes = $allNodes->map(function (SkillNode $n) use ($completedIds) {
            $done = isset($completedIds[$n->id]);
            $prereqIds = $n->prerequisites->pluck('id');
            $available = $prereqIds->isEmpty() || $prereqIds->every(fn ($id) => isset($completedIds[$id]));

            return [
                'id'         => $n->id,
                'slug'       => $n->slug,
                'title'      => $n->title,
                'branch'     => $n->branch,
                'subBranch'  => $n->sub_branch,
                'grade'      => $n->grade,
                'posX'       => $n->pos_x,
                'posY'       => $n->pos_y,
                'iconKey'    => $n->icon_key,
                'iconPath'   => $n->icon_path,
                'styleColor' => $n->styleColor(),
                'styles'     => array_keys($n->styleWeights()),
                'state'      => $done ? 'done' : ($available ? 'available' : 'locked'),
            ];
        });

        $edges = \DB::table('sbn_skill_node_prerequisites')
            ->join('sbn_skill_nodes as a', 'a.id', '=', 'sbn_skill_node_prerequisites.skill_node_id')
            ->join('sbn_skill_nodes as b', 'b.id', '=', 'sbn_skill_node_prerequisites.requires_skill_node_id')
            ->select(
                'sbn_skill_node_prerequisites.skill_node_id as from',
                'sbn_skill_node_prerequisites.requires_skill_node_id as to',
                \DB::raw('a.branch as from_branch'),
                \DB::raw('b.branch as to_branch'),
            )
            ->get()
            ->map(fn ($e) => [
                'from'        => (int) $e->from,
                'to'          => (int) $e->to,
                'crossBranch' => $e->from_branch !== $e->to_branch,
            ]);

        return Inertia::render('Account/SkillTree', [
            'nodes'      => $nodes,
            'edges'      => $edges,
            'gradeStats' => $grades->forUser($user),
        ]);
    }

    /**
     * Self-report a node complete (or un-complete it).
     *
     * Quiz-gated nodes are rejected: they're earned by passing the linked quiz,
     * which is the whole point of completion_type='quiz'. Because this is the
     * ONLY code path that detaches a progress row, blocking it here also makes
     * quiz-earned completions permanent. Nodes completed by self-report before
     * they became quiz-gated are grandfathered — the guard stops the toggle, so
     * their existing row is never removed.
     */
    public function toggle(Request $request, SkillNode $skillNode)
    {
        if ($skillNode->isQuizGated()) {
            abort(403, 'This skill is earned by passing its quiz.');
        }

        $user = $request->user();

        $progress = $user->skillNodes()->where('skill_node_id', $skillNode->id)->first();

        if ($progress && $progress->pivot->status === 'completed') {
            // Un-mark: remove the row entirely so "no row" = not started
            $user->skillNodes()->detach($skillNode->id);
            $done = false;
        } else {
            // Mark complete (upsert via sync — unique constraint handled by updateOrCreate semantics)
            $user->skillNodes()->syncWithoutDetaching([
                $skillNode->id => [
                    'status'       => 'completed',
                    'completed_at' => now(),
                ],
            ]);
            $done = true;
        }

        return response()->json(['done' => $done]);
    }
}

<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\SkillNode;
use App\Services\SkillGradeService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SkillController extends Controller
{
    public function index(Request $request, SkillGradeService $grades)
    {
        $user = $request->user();

        $completedSlugs = $user->skillNodes()
            ->wherePivot('status', 'completed')
            ->pluck('sbn_skill_nodes.slug')
            ->flip(); // slug => index, for fast O(1) lookup in the map below

        $nodes = SkillNode::orderBy('sort_order')
            ->get(['id', 'slug', 'title', 'branch', 'sub_branch', 'grade', 'icon_key', 'icon_path'])
            ->map(fn (SkillNode $n) => [
                'slug'      => $n->slug,
                'title'     => $n->title,
                'branch'    => $n->branch,
                'subBranch' => $n->sub_branch,
                'grade'     => $n->grade,
                'iconKey'   => $n->icon_key,
                'iconPath'  => $n->icon_path,
                'done'      => isset($completedSlugs[$n->slug]),
            ]);

        return Inertia::render('Account/Skills', [
            'nodes'      => $nodes,
            'gradeStats' => $grades->forUser($user),
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

    public function toggle(Request $request, SkillNode $skillNode)
    {
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

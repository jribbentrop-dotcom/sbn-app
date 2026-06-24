<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\SkillNode;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SkillController extends Controller
{
    public function index(Request $request)
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
            'nodes' => $nodes,
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

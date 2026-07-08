<?php

namespace App\Services;

use App\Models\SkillNode;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Graph algorithms over the skill-node prerequisite edges
 * (sbn_skill_node_prerequisites) — cycle detection and "recommended next"
 * traversal. See docs/SBN-Skill-System-Reference.md "Post-v1 Roadmap" #3 and
 * "v1 gaps" (no cycle detection existed before this).
 */
class SkillGraphService
{
    /**
     * Would adding the edge $nodeId -> requires $requiresId create a cycle?
     * True if $requiresId can already reach $nodeId via existing prerequisite
     * edges (i.e. $nodeId is already, directly or transitively, a prerequisite
     * of $requiresId) — adding the reverse edge would close a loop.
     *
     * Call this BEFORE persisting a new edge (single edge or a sync() batch).
     */
    public function wouldCreateCycle(int $nodeId, int $requiresId): bool
    {
        if ($nodeId === $requiresId) {
            return true; // self-prerequisite, already blocked elsewhere but cheap to catch here too
        }

        return $this->isReachable($requiresId, $nodeId);
    }

    /**
     * Validate a full desired prerequisite set for one node (as used by the
     * admin editor's sync() call) against the rest of the graph, excluding
     * that node's own current edges. Returns the subset of $requiresIds that
     * would each individually create a cycle — empty array means the whole
     * set is safe to sync().
     *
     * @param  array<int>  $requiresIds
     * @return array<int>
     */
    public function cyclicRequirements(int $nodeId, array $requiresIds): array
    {
        $bad = [];
        foreach ($requiresIds as $requiresId) {
            if ($this->wouldCreateCycle($nodeId, (int) $requiresId)) {
                $bad[] = (int) $requiresId;
            }
        }

        return $bad;
    }

    /**
     * Can $fromId reach $toId by following prerequisite edges forward
     * (from -> requires -> requires -> ...)? BFS over the whole edge table
     * loaded once; fine at this graph's size (dozens of nodes, ~70 edges).
     */
    private function isReachable(int $fromId, int $toId): bool
    {
        $adjacency = $this->adjacency();

        $visited = [$fromId => true];
        $queue = [$fromId];

        while ($queue) {
            $current = array_shift($queue);
            if ($current === $toId) {
                return true;
            }
            foreach ($adjacency[$current] ?? [] as $next) {
                if (! isset($visited[$next])) {
                    $visited[$next] = true;
                    $queue[] = $next;
                }
            }
        }

        return false;
    }

    /**
     * skill_node_id => [requires_skill_node_id, ...] for the whole graph, one query.
     *
     * @return array<int,array<int>>
     */
    private function adjacency(): array
    {
        $edges = DB::table('sbn_skill_node_prerequisites')
            ->get(['skill_node_id', 'requires_skill_node_id']);

        $map = [];
        foreach ($edges as $edge) {
            $map[(int) $edge->skill_node_id][] = (int) $edge->requires_skill_node_id;
        }

        return $map;
    }

    /**
     * Full topological order of all nodes (prerequisites before dependents).
     * Throws if the graph has a cycle — callers doing a one-off integrity
     * check (e.g. an artisan command) should catch this; the live app never
     * calls this on a path a student can trigger.
     *
     * @return array<int> node ids in dependency order
     *
     * @throws \RuntimeException
     */
    public function topologicalOrder(): array
    {
        $adjacency = $this->adjacency();
        $allIds = SkillNode::pluck('id')->all();

        $visited = [];   // fully processed
        $visiting = [];  // on the current DFS stack (cycle marker)
        $order = [];

        $visit = function (int $id) use (&$visit, &$visited, &$visiting, &$order, $adjacency) {
            if (isset($visited[$id])) {
                return;
            }
            if (isset($visiting[$id])) {
                throw new \RuntimeException("Cycle detected in skill node prerequisite graph at node id {$id}.");
            }
            $visiting[$id] = true;
            foreach ($adjacency[$id] ?? [] as $requiresId) {
                $visit($requiresId);
            }
            unset($visiting[$id]);
            $visited[$id] = true;
            $order[] = $id;
        };

        foreach ($allIds as $id) {
            $visit($id);
        }

        return $order;
    }

    /**
     * Recommended next nodes for a user: not yet completed, but every
     * prerequisite IS completed (i.e. "available" in the tree's own state
     * model — see Account\SkillController::tree()). Ranked by how much
     * progress completing the node would unlock (its `unlocks` out-degree),
     * then by grade ascending (finish the easier tier first), then title.
     *
     * @return \Illuminate\Support\Collection<int,SkillNode>
     */
    public function recommendedNext(User $user, int $limit = 5): \Illuminate\Support\Collection
    {
        $completedIds = $user->skillNodes()
            ->wherePivot('status', 'completed')
            ->pluck('sbn_skill_nodes.id')
            ->flip();

        $nodes = SkillNode::with('prerequisites:id')
            ->withCount('unlocks')
            ->orderBy('sort_order')
            ->get();

        return $nodes
            ->reject(fn (SkillNode $n) => isset($completedIds[$n->id]))
            ->filter(function (SkillNode $n) use ($completedIds) {
                $prereqIds = $n->prerequisites->pluck('id');

                return $prereqIds->isEmpty() || $prereqIds->every(fn ($id) => isset($completedIds[$id]));
            })
            ->sort(function (SkillNode $a, SkillNode $b) {
                // more unlocks first, then easier (lower) grade first, then title
                return [$b->unlocks_count, $a->grade ?? PHP_INT_MAX, $a->title]
                    <=> [$a->unlocks_count, $b->grade ?? PHP_INT_MAX, $b->title];
            })
            ->take($limit)
            ->values();
    }
}

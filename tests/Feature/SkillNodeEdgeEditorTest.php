<?php

namespace Tests\Feature;

use App\Models\SkillNode;
use App\Models\User;
use App\Services\SkillGraphService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Drives the layout editor's Ctrl+drag edge endpoints (addEdge / deleteEdge)
 * against the live sbn.db. Every test cleans up any edge it creates so the real
 * graph is left exactly as found — no fixture rows leak into sbn.db.
 */
class SkillNodeEdgeEditorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.sqlite.database' => database_path('sbn.db')]);
        DB::reconnect('sqlite');
    }

    private function admin(): User
    {
        // The admin routes sit behind auth + the `instructor` middleware
        // (EnsureIsInstructor → is_instructor). Grab an existing instructor so
        // we don't mutate the users table.
        return User::query()->where('is_instructor', true)->firstOrFail();
    }

    /** A pair with no existing edge in either direction, and not a cycle risk. */
    private function unlinkedPair(SkillGraphService $graph): array
    {
        $ids = SkillNode::pluck('id')->all();
        foreach ($ids as $req) {
            foreach ($ids as $dep) {
                if ($req === $dep) continue;
                $existing = DB::table('sbn_skill_node_prerequisites')
                    ->where('skill_node_id', $dep)->where('requires_skill_node_id', $req)->exists();
                if ($existing) continue;
                if ($graph->wouldCreateCycle($dep, $req)) continue;
                return [$req, $dep]; // [prerequisite, dependent]
            }
        }
        $this->fail('No unlinked, acyclic pair available to test with.');
    }

    public function test_layout_page_renders(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/skill-nodes/layout')
            ->assertOk()
            ->assertSee('Ctrl+drag', false);
    }

    public function test_add_and_delete_edge_round_trip(): void
    {
        $graph = app(SkillGraphService::class);
        [$req, $dep] = $this->unlinkedPair($graph);

        try {
            $this->actingAs($this->admin())
                ->postJson('/admin/skill-nodes/edges', ['from' => $dep, 'requires' => $req])
                ->assertOk()
                ->assertJson(['added' => true]);

            $this->assertTrue(
                DB::table('sbn_skill_node_prerequisites')
                    ->where('skill_node_id', $dep)->where('requires_skill_node_id', $req)->exists()
            );

            // Idempotent re-add is a no-op, still 200.
            $this->actingAs($this->admin())
                ->postJson('/admin/skill-nodes/edges', ['from' => $dep, 'requires' => $req])
                ->assertOk();

            $this->actingAs($this->admin())
                ->deleteJson('/admin/skill-nodes/edges', ['from' => $dep, 'requires' => $req])
                ->assertOk()
                ->assertJson(['deleted' => true]);

            $this->assertFalse(
                DB::table('sbn_skill_node_prerequisites')
                    ->where('skill_node_id', $dep)->where('requires_skill_node_id', $req)->exists()
            );
        } finally {
            DB::table('sbn_skill_node_prerequisites')
                ->where('skill_node_id', $dep)->where('requires_skill_node_id', $req)->delete();
        }
    }

    public function test_cycle_is_rejected(): void
    {
        // Find an existing edge dep -> req, then try to add the reverse (req -> dep),
        // which must close a 2-cycle and be rejected with 422.
        $edge = DB::table('sbn_skill_node_prerequisites')->first();
        $this->assertNotNull($edge, 'Need at least one existing edge for the cycle test.');

        $dep = (int) $edge->skill_node_id;
        $req = (int) $edge->requires_skill_node_id;

        $this->actingAs($this->admin())
            ->postJson('/admin/skill-nodes/edges', ['from' => $req, 'requires' => $dep])
            ->assertStatus(422)
            ->assertJsonStructure(['error']);

        // Ensure nothing was written.
        $this->assertFalse(
            DB::table('sbn_skill_node_prerequisites')
                ->where('skill_node_id', $req)->where('requires_skill_node_id', $dep)->exists()
        );
    }

    public function test_self_edge_is_rejected(): void
    {
        $id = SkillNode::value('id');

        $this->actingAs($this->admin())
            ->postJson('/admin/skill-nodes/edges', ['from' => $id, 'requires' => $id])
            ->assertStatus(422);
    }
}

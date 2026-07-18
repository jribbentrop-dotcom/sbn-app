<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SkillNodeDeleteEdgeRequest;
use App\Http\Requests\Admin\SkillNodeEdgeRequest;
use App\Http\Requests\Admin\SkillNodeLayoutRequest;
use App\Http\Requests\Admin\SkillNodeRequest;
use App\Models\ChordDiagram;
use App\Models\ChordProgression;
use App\Models\Course;
use App\Models\Leadsheet;
use App\Models\RhythmPattern;
use App\Models\SkillNode;
use App\Services\ContentHealthService;
use App\Services\SkillGraphService;
use Illuminate\Validation\ValidationException;

/**
 * Admin CRUD for skill nodes — the v1 table editor (no graph viz yet).
 * See docs/SBN-Skill-System-Reference.md "v1 Scope Lock" #4.
 */
class SkillNodeController extends Controller
{
    public function index()
    {
        $nodes = SkillNode::withCount(['prerequisites', 'unlocks', 'courses'])
            ->orderBy('branch')
            ->orderBy('sort_order')
            ->get()
            ->groupBy('branch');

        // Style tags for every node in one query (avoid N+1), as id => [style => weight].
        $stylesByNode = \DB::table('sbn_skill_node_style')
            ->orderByDesc('weight')
            ->get()
            ->groupBy('skill_node_id')
            ->map(fn ($rows) => $rows->pluck('weight', 'style')->all());

        return view('admin.skill-nodes.index', compact('nodes', 'stylesByNode'));
    }

    /**
     * Visual drag-to-position layout editor for the skill tree (pillar 6).
     * See docs/SBN-Skill-Tree-Design-Brief.md §7. Positions are 0..1000 design
     * units; auto-seeded by SkillNodePositionSeeder, fine-tuned here.
     */
    public function layout()
    {
        $nodes = SkillNode::orderBy('branch')->orderBy('sort_order')
            ->get(['id', 'slug', 'title', 'branch', 'grade', 'icon_key', 'pos_x', 'pos_y']);

        // Edges as [from id (the node) => requires id (prereq)] for drawing lines.
        $edges = \DB::table('sbn_skill_node_prerequisites')
            ->get(['skill_node_id', 'requires_skill_node_id'])
            ->map(fn ($e) => [
                'from' => (int) $e->skill_node_id,        // dependent node
                'to'   => (int) $e->requires_skill_node_id, // prerequisite
            ]);

        return view('admin.skill-nodes.layout', [
            'nodes'      => $nodes,
            'edges'      => $edges,
            'styleColors' => [
                'bossa-nova' => 'var(--clr-style-bossa)',
                'jazz'       => 'var(--clr-style-jazz)',
                'classical'  => 'var(--clr-style-classical)',
                'pop'        => 'var(--clr-style-pop)',
            ],
        ]);
    }

    /**
     * Bulk-save node positions from the layout editor. Accepts
     * positions: [ { id, x, y }, ... ] and clamps to 0..1000.
     */
    public function saveLayout(SkillNodeLayoutRequest $request)
    {
        $data = $request->validated();

        foreach ($data['positions'] as $p) {
            SkillNode::where('id', $p['id'])->update([
                'pos_x' => max(0, min(1000, (int) $p['x'])),
                'pos_y' => max(0, min(1000, (int) $p['y'])),
            ]);
        }

        return response()->json(['saved' => count($data['positions'])]);
    }

    /**
     * Add one prerequisite edge from the layout editor (Ctrl+drag prereq →
     * dependent). Payload: { from: dependent id, requires: prerequisite id }.
     * Routed through the SAME cycle guard as the edit form so a drawn edge can
     * never close a loop the form would have rejected. Idempotent — re-adding an
     * existing edge is a no-op, not an error.
     */
    public function addEdge(SkillNodeEdgeRequest $request, SkillGraphService $graph)
    {
        $data = $request->validated();

        if ($graph->wouldCreateCycle($data['from'], $data['requires'])) {
            $names = SkillNode::whereIn('id', [$data['from'], $data['requires']])
                ->pluck('title', 'id');

            return response()->json([
                'error' => "Can't connect these — \"{$names[$data['requires']]}\" already depends on "
                    . "\"{$names[$data['from']]}\", so this would create a cycle.",
            ], 422);
        }

        \DB::table('sbn_skill_node_prerequisites')->insertOrIgnore([
            'skill_node_id'          => $data['from'],
            'requires_skill_node_id' => $data['requires'],
        ]);

        return response()->json(['added' => true]);
    }

    /**
     * Remove one prerequisite edge from the layout editor (click an edge).
     * Payload mirrors addEdge. No-op if the edge is already gone.
     */
    public function deleteEdge(SkillNodeDeleteEdgeRequest $request)
    {
        $data = $request->validated();

        $deleted = \DB::table('sbn_skill_node_prerequisites')
            ->where('skill_node_id', $data['from'])
            ->where('requires_skill_node_id', $data['requires'])
            ->delete();

        return response()->json(['deleted' => (bool) $deleted]);
    }

    public function create()
    {
        $node = new SkillNode([
            'branch'          => 'harmony',
            'completion_type' => SkillNode::COMPLETION_SELF_REPORT,
        ]);
        $isNew = true;

        return view('admin.skill-nodes.edit', $this->editData($node, $isNew));
    }

    public function store(SkillNodeRequest $request)
    {
        $data = $request->payload();
        $node = SkillNode::create($data['attributes']);
        $this->syncRelations($node, $data);

        return redirect()->route('admin.skill-nodes.index')
            ->with('success', "Skill node “{$node->title}” created.");
    }

    public function edit(SkillNode $skillNode)
    {
        $isNew = false;

        return view('admin.skill-nodes.edit', $this->editData($skillNode, $isNew));
    }

    public function update(SkillNodeRequest $request, SkillNode $skillNode, SkillGraphService $graph)
    {
        $data = $request->payload();

        // A prereq edge can't close a loop back to this node — see
        // docs/SBN-Skill-System-Reference.md "v1 gaps" (no cycle detection existed
        // before this) and SkillGraphService for the traversal.
        $cyclic = $graph->cyclicRequirements($skillNode->id, $data['prereqs']);
        if ($cyclic) {
            $titles = SkillNode::whereIn('id', $cyclic)->pluck('title')->implode(', ');
            throw ValidationException::withMessages([
                'prereqs' => "Can't add {$titles} as a prerequisite — it already depends on \"{$skillNode->title}\", so this would create a cycle.",
            ]);
        }

        $skillNode->update($data['attributes']);
        $this->syncRelations($skillNode, $data);

        return redirect()->route('admin.skill-nodes.index')
            ->with('success', "Skill node “{$skillNode->title}” updated.");
    }

    public function destroy(SkillNode $skillNode)
    {
        $title = $skillNode->title;
        $skillNode->delete(); // prerequisite/course/progress pivots cascade via FK

        return redirect()->route('admin.skill-nodes.index')
            ->with('success', "Skill node “{$title}” deleted.");
    }

    // ─────────────────────────────────────────────────────────────

    /** Shared view payload for create/edit forms. */
    private function editData(SkillNode $node, bool $isNew): array
    {
        return [
            'node'           => $node,
            'isNew'          => $isNew,
            'branches'       => SkillNode::BRANCHES,
            'allNodes'       => SkillNode::orderBy('branch')->orderBy('title')
                ->get(['id', 'title', 'branch'])
                ->where('id', '!=', $node->id), // a node can't be its own prerequisite
            'allCourses'     => Course::orderBy('title')->get(['id', 'title']),
            'selectedPrereqs' => $isNew ? [] : $node->prerequisites()->pluck('sbn_skill_nodes.id')->all(),
            'selectedCourses' => $isNew ? [] : $node->courses()->pluck('sbn_courses.id')->all(),
            'styles'          => SkillNode::STYLES,
            'styleWeights'    => $isNew ? [] : $node->styleWeights(),

            // Direct content links (sbn_skill_node_content) — specific items.
            'allRhythmPatterns'       => RhythmPattern::orderBy('name')->get(['id', 'name']),
            'allChordProgressions'    => ChordProgression::orderBy('name')->get(['id', 'name']),
            'allLeadsheets'           => Leadsheet::orderBy('title')->get(['id', 'title']),
            'selectedRhythmPatterns'    => $isNew ? [] : $node->rhythmPatterns()->pluck('sbn_rhythm_patterns.id')->all(),
            'selectedChordProgressions' => $isNew ? [] : $node->chordProgressions()->pluck('sbn_chord_progressions.id')->all(),
            'selectedLeadsheets'        => $isNew ? [] : $node->leadsheets()->pluck('sbn_leadsheets.id')->all(),

            // Chord voicings link by CATEGORY, not individual diagram.
            'voicingCategories'         => ChordDiagram::VOICING_CATEGORIES,
            'selectedVoicingCategories' => $isNew ? [] : ($node->voicing_categories ?: []),
        ];
    }

    private function syncRelations(SkillNode $node, array $data): void
    {
        $node->prerequisites()->sync($data['prereqs']);
        $node->courses()->sync($data['courses']);
        $node->syncStyles($data['styles']);

        // Direct content links — each morphedByMany over sbn_skill_node_content.
        // (Chord voicings are NOT here — they're stored as voicing_categories on
        // the node itself, set in $attributes.)
        $node->rhythmPatterns()->sync($data['rhythmPatterns']);
        $node->chordProgressions()->sync($data['chordProgressions']);
        $node->leadsheets()->sync($data['leadsheets']);
    }

    public function coverage(ContentHealthService $health)
    {
        $details = $health->details();
        return view('admin.skill-nodes.coverage', compact('details'));
    }
}

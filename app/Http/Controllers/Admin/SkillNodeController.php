<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChordDiagram;
use App\Models\ChordProgression;
use App\Models\Course;
use App\Models\Leadsheet;
use App\Models\RhythmPattern;
use App\Models\SkillNode;
use App\Services\ContentHealthService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Admin CRUD for skill nodes — the v1 table editor (no graph viz yet).
 * See docs/SBN-Skill-System-Plan.md "v1 Scope Lock" #4.
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
    public function saveLayout(Request $request)
    {
        $data = $request->validate([
            'positions'     => 'required|array',
            'positions.*.id' => 'required|integer|exists:sbn_skill_nodes,id',
            'positions.*.x' => 'required|integer',
            'positions.*.y' => 'required|integer',
        ]);

        foreach ($data['positions'] as $p) {
            SkillNode::where('id', $p['id'])->update([
                'pos_x' => max(0, min(1000, (int) $p['x'])),
                'pos_y' => max(0, min(1000, (int) $p['y'])),
            ]);
        }

        return response()->json(['saved' => count($data['positions'])]);
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

    public function store(Request $request)
    {
        $data = $this->validated($request, null);
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

    public function update(Request $request, SkillNode $skillNode)
    {
        $data = $this->validated($request, $skillNode->id);
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

    /**
     * @return array{attributes:array,prereqs:array<int>,courses:array<int>}
     */
    private function validated(Request $request, ?int $exceptId): array
    {
        $raw = $request->validate([
            'title'            => 'required|string|max:255',
            'slug'             => 'nullable|string|max:120',
            'branch'           => 'required|in:' . implode(',', SkillNode::BRANCHES),
            'sub_branch'       => 'nullable|string|max:120',
            'description'      => 'nullable|string|max:2000',
            'content_tag_slug' => 'nullable|string|max:120',
            'grade'            => 'nullable|integer|min:1|max:5',
            'icon_key'         => 'nullable|string|max:120',
            'sort_order'       => 'nullable|integer|min:0',
            'prereqs'          => 'nullable|array',
            'prereqs.*'        => 'integer|exists:sbn_skill_nodes,id',
            'courses'          => 'nullable|array',
            'courses.*'        => 'integer|exists:sbn_courses,id',
            'styles'           => 'nullable|array',
            'styles.*'         => 'integer|min:0|max:3', // keyed by style slug; 0 = not tagged
            'rhythm_patterns'    => 'nullable|array',
            'rhythm_patterns.*'  => 'integer|exists:sbn_rhythm_patterns,id',
            'chord_progressions'   => 'nullable|array',
            'chord_progressions.*' => 'integer|exists:sbn_chord_progressions,id',
            'voicing_categories'   => 'nullable|array',
            'voicing_categories.*' => 'string|in:' . implode(',', array_keys(ChordDiagram::VOICING_CATEGORIES)),
            'leadsheets'         => 'nullable|array',
            'leadsheets.*'       => 'integer|exists:sbn_leadsheets,id',
        ]);

        $attributes = [
            'title'            => $raw['title'],
            'slug'             => $this->uniqueSlug($raw['slug'] ?: $raw['title'], $exceptId),
            'branch'           => $raw['branch'],
            'sub_branch'       => $raw['sub_branch'] ?? null,
            'description'      => $raw['description'] ?? null,
            'content_tag_slug' => $raw['content_tag_slug'] ?: null,
            'grade'            => $raw['grade'] ?? null,
            'icon_key'         => $raw['icon_key'] ?: null,
            'completion_type'  => SkillNode::COMPLETION_SELF_REPORT, // v1: fixed
            'sort_order'       => $raw['sort_order'] ?? 0,
            // Chord voicings link by category (stored on the node, not a pivot).
            'voicing_categories' => array_values($raw['voicing_categories'] ?? []) ?: null,
        ];

        // Style weights come in as { style-slug => weight }; drop 0s (untagged)
        // and anything outside the controlled vocabulary (syncStyles re-checks too).
        $styleWeights = [];
        foreach (($raw['styles'] ?? []) as $style => $weight) {
            if ((int) $weight > 0 && in_array($style, SkillNode::STYLES, true)) {
                $styleWeights[$style] = (int) $weight;
            }
        }

        // A node can never be its own prerequisite.
        $prereqs = array_values(array_filter(
            $raw['prereqs'] ?? [],
            fn ($id) => (int) $id !== (int) $exceptId,
        ));

        return [
            'attributes' => $attributes,
            'prereqs'    => $prereqs,
            'courses'    => $raw['courses'] ?? [],
            'styles'     => $styleWeights,
            'rhythmPatterns'    => $raw['rhythm_patterns'] ?? [],
            'chordProgressions' => $raw['chord_progressions'] ?? [],
            'leadsheets'        => $raw['leadsheets'] ?? [],
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

    private function uniqueSlug(string $slug, ?int $exceptId): string
    {
        $base = Str::slug($slug) ?: 'skill-node';
        $candidate = $base;
        $i = 2;
        while (
            SkillNode::where('slug', $candidate)
                ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
                ->exists()
        ) {
            $candidate = $base . '-' . $i++;
        }

        return $candidate;
    }

    public function coverage(ContentHealthService $health)
    {
        $details = $health->details();
        return view('admin.skill-nodes.coverage', compact('details'));
    }
}

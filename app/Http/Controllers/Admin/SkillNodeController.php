<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\SkillNode;
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

        return view('admin.skill-nodes.index', compact('nodes'));
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
            'sort_order'       => 'nullable|integer|min:0',
            'prereqs'          => 'nullable|array',
            'prereqs.*'        => 'integer|exists:sbn_skill_nodes,id',
            'courses'          => 'nullable|array',
            'courses.*'        => 'integer|exists:sbn_courses,id',
        ]);

        $attributes = [
            'title'            => $raw['title'],
            'slug'             => $this->uniqueSlug($raw['slug'] ?: $raw['title'], $exceptId),
            'branch'           => $raw['branch'],
            'sub_branch'       => $raw['sub_branch'] ?? null,
            'description'      => $raw['description'] ?? null,
            'content_tag_slug' => $raw['content_tag_slug'] ?: null,
            'completion_type'  => SkillNode::COMPLETION_SELF_REPORT, // v1: fixed
            'sort_order'       => $raw['sort_order'] ?? 0,
        ];

        // A node can never be its own prerequisite.
        $prereqs = array_values(array_filter(
            $raw['prereqs'] ?? [],
            fn ($id) => (int) $id !== (int) $exceptId,
        ));

        return [
            'attributes' => $attributes,
            'prereqs'    => $prereqs,
            'courses'    => $raw['courses'] ?? [],
        ];
    }

    private function syncRelations(SkillNode $node, array $data): void
    {
        $node->prerequisites()->sync($data['prereqs']);
        $node->courses()->sync($data['courses']);
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
}

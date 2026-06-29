<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class ContentHealthService
{
    /**
     * Headline counts for the dashboard summary card.
     * Each entry: ['key', 'label', 'count', 'anchor']
     */
    public function summary(): array
    {
        return [
            // Node-side gaps
            [
                'key'    => 'nodes-no-content',
                'label'  => 'Nodes with no linked content',
                'count'  => $this->countNodesNoContent(),
                'anchor' => 'nodes-no-content',
            ],
            [
                'key'    => 'nodes-no-course',
                'label'  => 'Nodes with no course',
                'count'  => $this->countNodesNoCourse(),
                'anchor' => 'nodes-no-course',
            ],
            [
                'key'    => 'nodes-no-style',
                'label'  => 'Nodes with no style (informational)',
                'count'  => $this->countNodesNoStyle(),
                'anchor' => 'nodes-no-style',
            ],
            [
                'key'    => 'nodes-no-icon',
                'label'  => 'Nodes with no icon',
                'count'  => $this->countNodesNoIcon(),
                'anchor' => 'nodes-no-icon',
            ],
            // Content-side gaps
            [
                'key'    => 'songs-unlinked',
                'label'  => 'Songs unlinked to any node',
                'count'  => $this->countUnlinked('sbn_leadsheets', 'App\\Models\\Leadsheet'),
                'anchor' => 'songs-unlinked',
            ],
            [
                'key'    => 'rhythms-unlinked',
                'label'  => 'Rhythms unlinked to any node',
                'count'  => $this->countUnlinked('sbn_rhythm_patterns', 'App\\Models\\RhythmPattern'),
                'anchor' => 'rhythms-unlinked',
            ],
            [
                'key'    => 'progressions-unlinked',
                'label'  => 'Progressions unlinked to any node',
                'count'  => $this->countUnlinked('sbn_chord_progressions', 'App\\Models\\ChordProgression'),
                'anchor' => 'progressions-unlinked',
            ],
            [
                'key'    => 'voicing-cats-no-node',
                'label'  => 'Voicing categories with no node',
                'count'  => count($this->orphanVoicingCategories()),
                'anchor' => 'voicing-cats-no-node',
            ],
            [
                'key'    => 'courses-no-node',
                'label'  => 'Courses with no node',
                'count'  => $this->countCoursesNoNode(),
                'anchor' => 'courses-no-node',
            ],
            [
                'key'    => 'pending-drafts',
                'label'  => 'Pending voicing drafts',
                'count'  => DB::table('sbn_voicing_drafts')->where('status', 'pending')->count(),
                'anchor' => 'pending-drafts',
            ],
        ];
    }

    /**
     * Full detail lists for the coverage drill-down page.
     */
    public function details(): array
    {
        return [
            'nodes_no_content'      => $this->nodesNoContent(),
            'nodes_no_course'       => $this->nodesNoCourse(),
            'nodes_no_style'        => $this->nodesNoStyle(),
            'nodes_no_icon'         => $this->nodesNoIcon(),
            'songs_unlinked'        => $this->unlinkedRows('sbn_leadsheets', 'App\\Models\\Leadsheet', 'title'),
            'rhythms_unlinked'      => $this->unlinkedRows('sbn_rhythm_patterns', 'App\\Models\\RhythmPattern', 'name'),
            'progressions_unlinked' => $this->unlinkedRows('sbn_chord_progressions', 'App\\Models\\ChordProgression', 'name'),
            'voicing_cats_no_node'  => $this->orphanVoicingCategories(),
            'courses_no_node'       => $this->coursesNoNode(),
            'pending_drafts'        => $this->pendingDrafts(),
        ];
    }

    // ── Node-side counts ────────────────────────────────────────────────────

    private function countNodesNoContent(): int
    {
        return DB::table('sbn_skill_nodes as n')
            ->whereNotIn('n.id', DB::table('sbn_skill_node_content')->select('skill_node_id'))
            ->where(function ($q) {
                $q->whereNull('n.voicing_categories')->orWhere('n.voicing_categories', '');
            })
            ->where(function ($q) {
                $q->whereNull('n.content_tag_slug')->orWhere('n.content_tag_slug', '');
            })
            ->count();
    }

    private function countNodesNoCourse(): int
    {
        return DB::table('sbn_skill_nodes')
            ->whereNotIn('id', DB::table('sbn_course_skill_node')->select('skill_node_id'))
            ->count();
    }

    private function countNodesNoStyle(): int
    {
        return DB::table('sbn_skill_nodes')
            ->whereNotIn('id', DB::table('sbn_skill_node_style')->select('skill_node_id'))
            ->count();
    }

    private function countNodesNoIcon(): int
    {
        return DB::table('sbn_skill_nodes')
            ->where(function ($q) {
                $q->whereNull('icon_key')->orWhere('icon_key', '');
            })
            ->count();
    }

    private function countUnlinked(string $table, string $type): int
    {
        return DB::table($table)
            ->whereNotIn('id', DB::table('sbn_skill_node_content')
                ->where('content_type', $type)
                ->select('content_id'))
            ->count();
    }

    private function countCoursesNoNode(): int
    {
        return DB::table('sbn_courses')
            ->whereNotIn('id', DB::table('sbn_course_skill_node')->select('course_id'))
            ->count();
    }

    // ── Node-side detail lists ──────────────────────────────────────────────

    private function nodesNoContent(): array
    {
        return DB::table('sbn_skill_nodes as n')
            ->select('n.id', 'n.title', 'n.slug', 'n.branch')
            ->whereNotIn('n.id', DB::table('sbn_skill_node_content')->select('skill_node_id'))
            ->where(function ($q) {
                $q->whereNull('n.voicing_categories')->orWhere('n.voicing_categories', '');
            })
            ->where(function ($q) {
                $q->whereNull('n.content_tag_slug')->orWhere('n.content_tag_slug', '');
            })
            ->orderBy('n.branch')->orderBy('n.title')
            ->get()->toArray();
    }

    private function nodesNoCourse(): array
    {
        return DB::table('sbn_skill_nodes')
            ->select('id', 'title', 'slug', 'branch')
            ->whereNotIn('id', DB::table('sbn_course_skill_node')->select('skill_node_id'))
            ->orderBy('branch')->orderBy('title')
            ->get()->toArray();
    }

    private function nodesNoStyle(): array
    {
        return DB::table('sbn_skill_nodes')
            ->select('id', 'title', 'slug', 'branch')
            ->whereNotIn('id', DB::table('sbn_skill_node_style')->select('skill_node_id'))
            ->orderBy('branch')->orderBy('title')
            ->get()->toArray();
    }

    private function nodesNoIcon(): array
    {
        return DB::table('sbn_skill_nodes')
            ->select('id', 'title', 'slug', 'branch')
            ->where(function ($q) {
                $q->whereNull('icon_key')->orWhere('icon_key', '');
            })
            ->orderBy('branch')->orderBy('title')
            ->get()->toArray();
    }

    // ── Content-side detail lists ───────────────────────────────────────────

    private function unlinkedRows(string $table, string $type, string $nameCol): array
    {
        return DB::table($table)
            ->select('id', DB::raw("$nameCol as title"), 'slug')
            ->whereNotIn('id', DB::table('sbn_skill_node_content')
                ->where('content_type', $type)
                ->select('content_id'))
            ->orderBy($nameCol)
            ->get()->toArray();
    }

    private function orphanVoicingCategories(): array
    {
        // All distinct voicing_category values in the chord diagrams table
        $allCats = DB::table('sbn_chord_diagrams')
            ->whereNotNull('voicing_category')
            ->where('voicing_category', '!=', '')
            ->distinct()
            ->pluck('voicing_category')
            ->toArray();

        // Union of all voicing_categories JSON arrays across skill nodes
        $nodeRows = DB::table('sbn_skill_nodes')
            ->whereNotNull('voicing_categories')
            ->where('voicing_categories', '!=', '')
            ->pluck('voicing_categories');

        $claimedCats = [];
        foreach ($nodeRows as $json) {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                foreach ($decoded as $cat) {
                    $claimedCats[] = $cat;
                }
            }
        }
        $claimedCats = array_unique($claimedCats);

        return array_values(array_diff($allCats, $claimedCats));
    }

    private function coursesNoNode(): array
    {
        return DB::table('sbn_courses')
            ->select('id', 'title', 'slug')
            ->whereNotIn('id', DB::table('sbn_course_skill_node')->select('course_id'))
            ->orderBy('title')
            ->get()->toArray();
    }

    private function pendingDrafts(): array
    {
        return DB::table('sbn_voicing_drafts')
            ->where('status', 'pending')
            ->select('id', 'root_note', 'chord_name', 'leadsheet_title', 'status', 'created_at')
            ->orderBy('created_at')
            ->get()->toArray();
    }
}

<?php

namespace Database\Seeders;

use App\Models\SkillNode;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds an initial slice of the skill graph: the Harmony and Rhythm branches
 * (the two best-specified in the taxonomy), plus their prerequisite edges.
 *
 * Idempotent — keyed on slug via updateOrCreate, edges via insertOrIgnore.
 * Re-running is safe. This is a STARTER set, not the full taxonomy; the rest is
 * curatorial work done in the admin editor. See docs/SBN-Skill-System-Plan.md.
 */
class SkillNodeSeeder extends Seeder
{
    /**
     * Node definitions: slug => [title, branch, sub_branch, content_tag_slug?, prerequisites[]]
     * Prerequisites reference other slugs in this list; wired in a second pass.
     */
    private const NODES = [
        // ── Harmony ──────────────────────────────────────────────────────────
        'intervals' => [
            'title' => 'Intervals', 'branch' => 'harmony', 'sub_branch' => 'Foundations',
            'prereqs' => [],
        ],
        'triads' => [
            'title' => 'Triads', 'branch' => 'harmony', 'sub_branch' => 'Foundations',
            'prereqs' => ['intervals'],
        ],
        'chord-inversions' => [
            'title' => 'Chord Inversions', 'branch' => 'harmony', 'sub_branch' => 'Foundations',
            'prereqs' => ['triads'],
        ],
        'shell-voicings' => [
            'title' => 'Shell Voicings (3+7)', 'branch' => 'harmony', 'sub_branch' => 'Voicings',
            'prereqs' => ['triads'],
        ],
        'drop2-voicings' => [
            'title' => 'Drop 2 Voicings', 'branch' => 'harmony', 'sub_branch' => 'Voicings',
            'prereqs' => ['shell-voicings', 'chord-inversions'],
        ],
        'drop3-voicings' => [
            'title' => 'Drop 3 Voicings', 'branch' => 'harmony', 'sub_branch' => 'Voicings',
            'prereqs' => ['drop2-voicings'],
        ],
        'ii-v-i-major' => [
            'title' => 'ii-V-I in Major', 'branch' => 'harmony', 'sub_branch' => 'Progressions',
            'prereqs' => ['shell-voicings'],
        ],
        'ii-v-i-minor' => [
            'title' => 'ii-V-I in Minor', 'branch' => 'harmony', 'sub_branch' => 'Progressions',
            'prereqs' => ['ii-v-i-major'],
        ],
        'tritone-substitution' => [
            'title' => 'Tritone Substitution', 'branch' => 'harmony', 'sub_branch' => 'Reharmonization',
            'prereqs' => ['ii-v-i-major'],
        ],
        'chord-melody' => [
            'title' => 'Chord Melody', 'branch' => 'harmony', 'sub_branch' => 'Reharmonization',
            'prereqs' => ['drop2-voicings', 'ii-v-i-major'],
        ],

        // ── Rhythm ───────────────────────────────────────────────────────────
        'pulse-subdivision' => [
            'title' => 'Pulse & Subdivision', 'branch' => 'rhythm', 'sub_branch' => 'Foundations',
            'prereqs' => [],
        ],
        'two-four-feel' => [
            'title' => '2/4 Feel (Bossa / Samba)', 'branch' => 'rhythm', 'sub_branch' => 'Feels',
            'content_tag_slug' => 'samba', 'prereqs' => ['pulse-subdivision'],
        ],
        'syncopation' => [
            'title' => 'Syncopation', 'branch' => 'rhythm', 'sub_branch' => 'Feels',
            'prereqs' => ['pulse-subdivision'],
        ],
        'comping-patterns' => [
            'title' => 'Comping Patterns', 'branch' => 'rhythm', 'sub_branch' => 'Application',
            'prereqs' => ['two-four-feel', 'syncopation'],
        ],
    ];

    public function run(): void
    {
        // Pass 1 — upsert all nodes so every slug exists before wiring edges.
        $idBySlug = [];
        $order = 0;
        foreach (self::NODES as $slug => $def) {
            $node = SkillNode::updateOrCreate(
                ['slug' => $slug],
                [
                    'title'            => $def['title'],
                    'branch'           => $def['branch'],
                    'sub_branch'       => $def['sub_branch'] ?? null,
                    'content_tag_slug' => $def['content_tag_slug'] ?? null,
                    'completion_type'  => SkillNode::COMPLETION_SELF_REPORT,
                    'sort_order'       => $order++,
                ],
            );
            $idBySlug[$slug] = $node->id;
        }

        // Pass 2 — wire prerequisite edges (insertOrIgnore = idempotent).
        $edges = [];
        foreach (self::NODES as $slug => $def) {
            foreach ($def['prereqs'] as $requiresSlug) {
                $edges[] = [
                    'skill_node_id'          => $idBySlug[$slug],
                    'requires_skill_node_id' => $idBySlug[$requiresSlug],
                ];
            }
        }

        if ($edges) {
            DB::table('sbn_skill_node_prerequisites')->insertOrIgnore($edges);
        }
    }
}

<?php

namespace Database\Seeders;

use App\Models\SkillNode;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Tags skill nodes with the styles they're characteristic of (vision pillar 4).
 * See docs/SBN-Skill-System-Plan.md "Vision → Reality Reconciliation".
 *
 * Weights: 1 = touches the style, 2 = clearly part of its toolkit,
 *          3 = definitional (you can't separate the node from the style).
 *
 * Curation principle: FOUNDATIONAL nodes that every style needs equally
 * (intervals, triads, meter, scales, notation, basic technique) are left
 * UNTAGGED. Untagged = neutral foundation, not "no style" — tagging them weak-
 * everything would just add noise to the player-class computation. Only nodes
 * that actually lean toward a style get tagged.
 *
 * Idempotent: clears + re-inserts each listed node's style rows (syncStyles).
 * Re-running is safe; hand edits in the admin editor to UNLISTED nodes survive,
 * but edits to LISTED nodes are overwritten by this seeder's canonical values.
 */
class SkillNodeStyleSeeder extends Seeder
{
    /**
     * node slug => [ style => weight ]
     * Only nodes with a genuine style lean are listed. See principle above.
     */
    private const STYLE_TAGS = [
        // ── Harmony ──────────────────────────────────────────────────────────
        'shell-voicings'       => ['jazz' => 3, 'bossa-nova' => 3],
        'drop2-voicings'       => ['jazz' => 3, 'bossa-nova' => 2],
        'drop3-voicings'       => ['jazz' => 3],
        'ii-v-i-major'         => ['jazz' => 3, 'bossa-nova' => 2],
        'ii-v-i-minor'         => ['jazz' => 3, 'bossa-nova' => 2],
        'tritone-substitution' => ['jazz' => 3, 'bossa-nova' => 2],
        'chord-melody'         => ['jazz' => 3, 'bossa-nova' => 1],
        'cadences'             => ['classical' => 3, 'pop' => 1],
        'pop-progressions'     => ['pop' => 3, 'classical' => 1],
        'turnarounds'          => ['jazz' => 3, 'bossa-nova' => 1],
        'secondary-dominants'  => ['jazz' => 2, 'classical' => 2, 'bossa-nova' => 1],
        'borrowed-chords'      => ['jazz' => 2, 'classical' => 2, 'pop' => 1],
        'voice-leading'        => ['classical' => 3, 'jazz' => 2, 'bossa-nova' => 1],
        'blues'                => ['jazz' => 2],

        // ── Rhythm ───────────────────────────────────────────────────────────
        'two-four-feel'           => ['bossa-nova' => 3],
        'swing-feel'              => ['jazz' => 3],
        'clave-systems'           => ['bossa-nova' => 3],          // Latin/Afro-Cuban core; bossa is the app's frame
        'brazilian-rhythm-styles' => ['bossa-nova' => 3],
        'comping-patterns'        => ['jazz' => 2, 'bossa-nova' => 2],
        'waltz-feel'              => ['classical' => 2, 'jazz' => 1],
        'polyrhythm'              => ['jazz' => 1, 'bossa-nova' => 1],

        // ── Melody ───────────────────────────────────────────────────────────
        'improvisation-over-changes' => ['jazz' => 3, 'bossa-nova' => 1],
        'motivic-development'        => ['jazz' => 2, 'classical' => 2],
        'arpeggio-shapes'            => ['jazz' => 2],
        'pentatonic-scale'           => ['pop' => 2, 'jazz' => 1],
        'blues-scale'                => ['jazz' => 2, 'pop' => 1],

        // ── Technique ────────────────────────────────────────────────────────
        'fingerpicking-basics'    => ['classical' => 3, 'bossa-nova' => 2],
        'thumb-independence'      => ['bossa-nova' => 3, 'classical' => 2],
        'right-hand-independence' => ['bossa-nova' => 2, 'classical' => 2],
        'tone-production'         => ['classical' => 2],
        'legato-slurs'            => ['classical' => 1, 'jazz' => 1],

        // ── Reading & Theory ──────────────────────────────────────────────────
        'nashville-number-system' => ['pop' => 3],
        'leadsheet-reading'       => ['jazz' => 2, 'bossa-nova' => 1, 'pop' => 1],
        'standard-notation-basics' => ['classical' => 2],
    ];

    public function run(): void
    {
        $nodesBySlug = SkillNode::whereIn('slug', array_keys(self::STYLE_TAGS))
            ->get()->keyBy('slug');

        $tagged = 0;
        $missing = [];
        foreach (self::STYLE_TAGS as $slug => $weights) {
            $node = $nodesBySlug->get($slug);
            if (! $node) { $missing[] = $slug; continue; }
            $node->syncStyles($weights);
            $tagged++;
        }

        $this->command?->info("SkillNodeStyleSeeder: tagged {$tagged} nodes with styles.");
        if ($missing) {
            $this->command?->warn('  missing node slugs (skipped): ' . implode(', ', $missing));
        }

        // Sanity: report coverage per style.
        foreach (SkillNode::STYLES as $style) {
            $n = DB::table('sbn_skill_node_style')->where('style', $style)->count();
            $this->command?->line("  {$style}: {$n} nodes");
        }
    }
}

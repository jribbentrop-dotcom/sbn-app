<?php

namespace Database\Seeders;

use App\Models\SkillNode;
use Illuminate\Database\Seeder;

/**
 * Auto-lays out skill-node positions for the tree viz (layout A).
 * See docs/SBN-Skill-Tree-Design-Brief.md §7.
 *
 * Scheme (matches the mockup): grade = vertical tier (grade 1 at the BOTTOM,
 * grade 5 at the TOP, climbing upward); within a tier, nodes spread across the
 * X axis grouped by branch so same-branch nodes cluster. Ungraded nodes (grade
 * NULL) park in a row below grade 1.
 *
 * This is a STARTING arrangement only — the admin drag editor fine-tunes from
 * here. Coordinates are 0..1000 design units (renderer-agnostic; see migration).
 *
 * Idempotent BUT destructive to hand-tuned positions: re-running OVERWRITES
 * pos_x/pos_y for every node back to the auto-layout. Only re-run when you want
 * to reset the layout. (It deliberately does not skip nodes that already have a
 * position — a partial run would leave the grid inconsistent.)
 */
class SkillNodePositionSeeder extends Seeder
{
    private const W = 1000;            // design width
    private const H = 1000;            // design height
    private const MARGIN_X = 60;
    private const TOP = 80;            // y of the top tier centre
    private const BOTTOM = 920;        // y of the bottom (grade 1 / ungraded) tier centre

    // Branch ordering left→right, so a branch keeps a consistent column band.
    private const BRANCH_ORDER = [
        'harmony', 'rhythm', 'technique', 'melody', 'reading-theory', 'ear-training',
    ];

    public function run(): void
    {
        $nodes = SkillNode::orderBy('branch')->orderBy('sort_order')->get();

        // Tier rows: grades 1..5 climb upward; ungraded sits as a row 0 below grade 1.
        // Row index 0 = ungraded (lowest), 1..5 = grades. Map row → y.
        $rows = [0, 1, 2, 3, 4, 5];
        $maxRow = max($rows);
        $yForRow = function (int $row) use ($maxRow): int {
            // row 0 (ungraded) lowest, row maxRow (grade 5) highest
            if ($maxRow === 0) return (int) ((self::TOP + self::BOTTOM) / 2);
            $t = $row / $maxRow; // 0..1
            return (int) round(self::BOTTOM - $t * (self::BOTTOM - self::TOP));
        };

        // Group nodes by their row.
        $byRow = [];
        foreach ($nodes as $n) {
            $row = $n->grade ? (int) $n->grade : 0;
            $byRow[$row][] = $n;
        }

        $branchRank = array_flip(self::BRANCH_ORDER);

        foreach ($byRow as $row => $rowNodes) {
            // Sort within the row by branch (column band), then title, so the
            // spread is stable and same-branch nodes sit together.
            usort($rowNodes, function (SkillNode $a, SkillNode $b) use ($branchRank) {
                $ra = $branchRank[$a->branch] ?? 99;
                $rb = $branchRank[$b->branch] ?? 99;
                return $ra <=> $rb ?: strcmp($a->title, $b->title);
            });

            $count = count($rowNodes);
            $usableW = self::W - 2 * self::MARGIN_X;
            $y = $yForRow($row);

            foreach ($rowNodes as $i => $node) {
                // Even horizontal spread across the usable width. Single node → centre.
                $x = $count === 1
                    ? (int) (self::W / 2)
                    : (int) round(self::MARGIN_X + ($i / ($count - 1)) * $usableW);

                $node->update(['pos_x' => $x, 'pos_y' => $y]);
            }
        }

        $this->command?->info("SkillNodePositionSeeder: laid out {$nodes->count()} nodes across "
            . count($byRow) . ' tiers.');
    }
}

<?php

namespace Tests\Unit;

use App\Services\Identifier\DbVoicingMatcher;
use Tests\TestCase;

/**
 * Pins the diagram_data fret convention: ABSOLUTE neck positions.
 *
 * `start_fret` is a derived render hint — ChordShapeCalculator::calculateStartFret()
 * computes it as min(fret) — and must never be added back into the fret values.
 * DbVoicingMatcher::positionsToAbsoluteMidi() once added `start_fret - 1`, which
 * sharpened 105 shapes by up to a major third (some to fret 27).
 *
 * The bug hid because lookup() compares pitch-class sets across all 12
 * transpositions, so a uniform offset cancels out. It surfaced only where an
 * absolute claim (root_note) meets a label-derived one: diagram 273
 * (Ebmaj7#11/Bb) elected G as its root.
 *
 * Runs against the real sbn.db.
 */
class DbVoicingMatcherFretConventionTest extends TestCase
{
    private DbVoicingMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.sqlite.database' => database_path('sbn.db')]);
        \Illuminate\Support\Facades\DB::reconnect('sqlite');
        $this->matcher = new DbVoicingMatcher();
    }

    /**
     * The Days of Wine and Roses voicing. 665785 is byte-identical to diagram
     * 273's stored frets, so it must match at transposition 0 and name itself
     * from the shape's own root — not land on a rotation.
     */
    public function test_days_of_wine_and_roses_voicing_hits_its_own_diagram(): void
    {
        $result = $this->matcher->lookup('665785', 'F');

        $ids = array_column($result['hits'], 'db_id');
        $this->assertContains(273, $ids, '665785 should hit diagram 273 (maj7-custom-roote-inv2-s11-overBb)');

        $hit = $result['hits'][array_search(273, $ids, true)];

        // Eb = pc 3, Bb = pc 10. The chord is Ebmaj7(#11) over Bb.
        $this->assertSame(3, $hit['root_pc'], 'root must be Eb, not the +start_fret-shifted G');
        $this->assertSame(10, $hit['bass_pc']);
        $this->assertTrue($hit['bass_match']);
    }

    /**
     * Structural invariant across the whole table: no diagram stores a fret
     * below its own start_fret. If any did, its frets would be window-local
     * (relative) and the absolute reading would be wrong.
     */
    public function test_no_diagram_stores_frets_below_its_start_fret(): void
    {
        $rows = \Illuminate\Support\Facades\DB::table('sbn_chord_diagrams')
            ->select('id', 'slug', 'start_fret', 'diagram_data')->get();

        $violations = [];
        foreach ($rows as $row) {
            $data = json_decode($row->diagram_data, true);
            if (!is_array($data)) continue;

            $frets = [];
            foreach ($data['positions'] ?? [] as $p) {
                if ((int)$p['fret'] > 0) $frets[] = (int)$p['fret'];
            }
            foreach ($data['barres'] ?? [] as $b) {
                if ((int)$b['fret'] > 0) $frets[] = (int)$b['fret'];
            }
            if (empty($frets)) continue;

            $startFret = (int)($row->start_fret ?: 1);
            if ($startFret > 1 && min($frets) < $startFret) {
                $violations[] = "#{$row->id} {$row->slug}: min(fret)=" . min($frets) . " < start_fret={$startFret}";
            }
        }

        $this->assertSame([], $violations,
            "diagram_data.fret is absolute; these rows look window-relative:\n" . implode("\n", $violations));
    }

    /**
     * No shape may decode to an unplayable neck position. The old +start_fret-1
     * offset produced frets up to 27.
     */
    public function test_no_shape_decodes_above_the_neck(): void
    {
        $m = new \ReflectionMethod(DbVoicingMatcher::class, 'shapes');
        $m->setAccessible(true);
        $shapes = $m->invoke($this->matcher);

        $this->assertNotEmpty($shapes);
        // shapes() returns pitch classes, so assert via the raw decoder instead.
        $decode = new \ReflectionMethod(DbVoicingMatcher::class, 'positionsToAbsoluteMidi');
        $decode->setAccessible(true);

        $rows = \Illuminate\Support\Facades\DB::table('sbn_chord_diagrams')
            ->select('id', 'slug', 'diagram_data', 'is_fixed_position', 'start_fret')->get();

        foreach ($rows as $row) {
            $absolute = $decode->invoke($this->matcher, $row);
            foreach ($absolute as $stringNum => $midi) {
                $fret = $midi - [1 => 40, 2 => 45, 3 => 50, 4 => 55, 5 => 59, 6 => 64][$stringNum];
                $this->assertLessThanOrEqual(24, $fret,
                    "diagram #{$row->id} ({$row->slug}) decodes string {$stringNum} to fret {$fret}");
            }
        }
    }
}

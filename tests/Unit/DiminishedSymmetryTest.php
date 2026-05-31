<?php

namespace Tests\Unit;

use App\Services\HarmonicContext\DiminishedSymmetry;
use Tests\TestCase;

class DiminishedSymmetryTest extends TestCase
{
    private DiminishedSymmetry $sym;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sym = new DiminishedSymmetry();
    }

    // ── Symmetry math ────────────────────────────────────────────────────────

    public function test_symmetric_roots_are_minor_thirds(): void
    {
        // C (0) → C, Eb(3), Gb(6), A(9)
        $this->assertSame([0, 3, 6, 9], $this->sym->symmetricRoots(0));
        // Wraps: A (9) → A, C, Eb, Gb (same set, rotated)
        $this->assertSame([9, 0, 3, 6], $this->sym->symmetricRoots(9));
    }

    public function test_dominant_readings_root_is_major_third_below_and_b9_is_dim_tone(): void
    {
        // C°7 {0,3,6,9} → doms Ab(8), B(11), D(2), F(5); b9 = the dim tone
        $readings = $this->sym->dominantReadings(0);
        $this->assertSame(
            [['domRootPc' => 8, 'b9Pc' => 0],
             ['domRootPc' => 11, 'b9Pc' => 3],
             ['domRootPc' => 2, 'b9Pc' => 6],
             ['domRootPc' => 5, 'b9Pc' => 9]],
            $readings
        );

        // Each dom root must be ABSENT from the dim voicing (true rootless).
        $dimPcs = $this->sym->symmetricRoots(0);
        foreach ($readings as $r) {
            $this->assertNotContains($r['domRootPc'], $dimPcs, 'dom root must be rootless');
        }
    }

    // ── Worked example: C°7 inversions (°7 tone pragmatic → natural) ──────────

    public function test_c_dim7_inversion_spellings(): void
    {
        $this->assertSame(['R' => 'C', 'b3' => 'Eb', 'b5' => 'Gb', 'bb7' => 'A'], $this->sym->spellDim7('C'));
        $this->assertSame(['R' => 'Eb', 'b3' => 'Gb', 'b5' => 'A', 'bb7' => 'C'], $this->sym->spellDim7('Eb'));
        $this->assertSame(['R' => 'Gb', 'b3' => 'A', 'b5' => 'C', 'bb7' => 'Eb'], $this->sym->spellDim7('Gb'));
        $this->assertSame(['R' => 'A', 'b3' => 'C', 'b5' => 'Eb', 'bb7' => 'Gb'], $this->sym->spellDim7('A'));
    }

    // ── Worked example: C°7's four dom7(b9) readings ──────────────────────────
    // Structural 3/5/b7 tight on the correct letter; b9 pragmatic natural.

    public function test_c_dim7_dominant_b9_spellings(): void
    {
        // Ab7(b9): 3=C 5=Eb b7=Gb b9=A
        $this->assertSame(['3' => 'C', '5' => 'Eb', 'b7' => 'Gb', 'b9' => 'A'], $this->sym->spellDom7b9('Ab'));
        // B7(b9): 3=D# (NEVER Eb) 5=F# b7=A b9=C
        $this->assertSame(['3' => 'D#', '5' => 'F#', 'b7' => 'A', 'b9' => 'C'], $this->sym->spellDom7b9('B'));
        // D7(b9): 3=F# 5=A b7=C b9=Eb
        $this->assertSame(['3' => 'F#', '5' => 'A', 'b7' => 'C', 'b9' => 'Eb'], $this->sym->spellDom7b9('D'));
        // F7(b9): 3=A 5=C b7=Eb b9=Gb
        $this->assertSame(['3' => 'A', '5' => 'C', 'b7' => 'Eb', 'b9' => 'Gb'], $this->sym->spellDom7b9('F'));
    }

    public function test_third_of_dominant_is_never_wrong_letter(): void
    {
        // The cardinal rule: a major 3rd above B is a D-something, never an E.
        $this->assertSame('D#', $this->sym->spellDom7b9('B')['3']);
        // ...and above G is a B-something, never a Cb.
        $this->assertSame('B', $this->sym->spellDom7b9('G')['3']);
    }

    public function test_structural_tones_carry_single_accidental_only(): void
    {
        // Sweep all 12 dominant roots: 3/5/b7 must never be a double accidental.
        foreach (range(0, 11) as $pc) {
            $tones = $this->sym->spellDom7b9($this->sym->spellRoot($pc));
            foreach (['3', '5', 'b7'] as $deg) {
                $this->assertDoesNotMatchRegularExpression(
                    '/(bb|##)/',
                    $tones[$deg],
                    "dom7b9 degree $deg for root pc $pc had a double accidental: {$tones[$deg]}"
                );
            }
        }
    }

    public function test_root_spelling_is_flat_biased(): void
    {
        $this->assertSame('Eb', $this->sym->spellRoot(3));
        $this->assertSame('Gb', $this->sym->spellRoot(6));
        $this->assertSame('Bb', $this->sym->spellRoot(10));
        $this->assertSame('C', $this->sym->spellRoot(0));
    }
}

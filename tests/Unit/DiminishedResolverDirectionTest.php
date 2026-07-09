<?php

namespace Tests\Unit;

use App\Services\HarmonicContext\DiminishedAsDominantResolver;
use App\Services\HarmonicContext\DiminishedResolver;
use Tests\TestCase;

/**
 * Directional spelling for passing diminished chords (2026-07-09).
 *
 * House rule: an ASCENDING passing dim (rising by semitone into the next chord —
 * an upward chromatic neighbour) spells SHARP (C#°7). A DESCENDING passing dim
 * (falling by semitone) and any non-directional/voice-leading-default dim spell
 * FLAT (Db°7) — the jazz lean. A dim7 reinterpreted as a rootless dominant 7(b9)
 * also spells its root flat (F7(b9), not E#7(b9)).
 *
 * Pure resolvers — no DB — so these run on the default connection.
 */
class DiminishedResolverDirectionTest extends TestCase
{
    private function slot(string $root, string $quality, ?string $name = null, array $pcs = []): array
    {
        return [
            'root' => $root,
            'quality' => $quality,
            'name' => $name ?? ($root . $quality),
            'pcs' => $pcs,
            'bass_note' => null,
        ];
    }

    /** The C#/E/G/Bb dim7 shape (pcs 1,4,7,10); Phase 1's arbitrary pick is Bb. */
    private const DIM_SHAPE_PCS = [1, 4, 7, 10];

    public function test_ascending_passing_dim_spells_sharp(): void
    {
        // C -> [dim7] -> Dm : prev C(0), next D(2) => ascending; expected root C#(1).
        $seq = [
            $this->slot('C', 'maj'),
            $this->slot('Bb', 'dim7', 'Bbdim7', self::DIM_SHAPE_PCS),
            $this->slot('D', 'm'),
        ];
        $out = (new DiminishedResolver())->resolve($seq);

        $this->assertSame('C#dim7', $out[1]['name']);
        $this->assertSame('dim_passing_ascending', $out[1]['reinterpret_reason']);
    }

    public function test_descending_passing_dim_spells_flat(): void
    {
        // Dm -> [dim7] -> C : prev D(2), next C(0) => descending; expected root Db(1).
        $seq = [
            $this->slot('D', 'm'),
            $this->slot('Bb', 'dim7', 'Bbdim7', self::DIM_SHAPE_PCS),
            $this->slot('C', 'maj'),
        ];
        $out = (new DiminishedResolver())->resolve($seq);

        $this->assertSame('Dbdim7', $out[1]['name']);
        $this->assertSame('dim_passing_descending', $out[1]['reinterpret_reason']);
    }

    public function test_dim_as_dominant_root_spells_flat(): void
    {
        // A dim7 (pcs 9,0,3,6) -> Bb : dom root F(5) resolves down P5 to Bb(10).
        // Root must be F, never E#.
        $seq = [
            $this->slot('A', 'dim7', 'Adim7', [9, 0, 3, 6]),
            $this->slot('Bb', 'maj'),
        ];
        $out = (new DiminishedAsDominantResolver())->resolve($seq);

        $this->assertSame('F7(b9)', $out[0]['name']);
        $this->assertStringNotContainsString('#', $out[0]['name']);
    }
}

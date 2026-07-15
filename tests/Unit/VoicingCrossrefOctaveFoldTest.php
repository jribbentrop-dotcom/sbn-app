<?php

namespace Tests\Unit;

use App\Services\VoicingCrossref;
use Tests\TestCase;

/**
 * Octave-folding of high-position voicings.
 *
 * Library shapes are always transposed into the lowest playable octave
 * (ChordShapeCalculator::calculateTargetFret returns 0-11), and every fret
 * comparison in the matcher is integer equality. So a voicing played above the
 * 12th fret could never match its own archetype — Easy Living's Dm7 "xxcedd"
 * is m7-drop2-rootd exactly twelve frets up, and sat unmatched in
 * sbn_voicing_drafts.
 *
 * Runs against the real sbn.db (matchVoicing does a diagram lookup).
 */
class VoicingCrossrefOctaveFoldTest extends TestCase
{
    private VoicingCrossref $crossref;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.sqlite.database' => database_path('sbn.db')]);
        \Illuminate\Support\Facades\DB::reconnect('sqlite');
        $this->crossref = new VoicingCrossref(app(\App\Services\ChordShapeCalculator::class));
    }

    private function voicing(string $name, string $frets, int $position, string $root, string $quality): array
    {
        return [
            'chord_name'  => $name,
            'fret_string' => $frets,
            'position'    => $position,
            'fingers'     => '',
            'root'        => $root,
            'quality'     => $quality,
            'extension'   => '',
            'bass_note'   => '',
        ];
    }

    /**
     * The case that motivated the fix. xxcedd = [x,x,12,14,13,13], which is
     * m7-drop2-rootd ([x,x,0,2,1,1] at root D) one octave up.
     */
    public function test_high_position_dm7_matches_drop2_archetype(): void
    {
        $result = $this->crossref->matchVoicing(
            $this->voicing('Dm7', 'xxcedd', 12, 'D', 'm7')
        );

        $this->assertNotFalse($result, 'Dm7 xxcedd should match a library shape after octave folding');
        $this->assertSame('exact', $result['match_type']);
        // m7-drop2-rootd — the archetype this voicing is an octave-up copy of.
        $this->assertSame(161, $result['diagram_id']);
    }

    /**
     * Same shape with the 11th added on the top string — still folds, still
     * resolves against an m7 archetype (as a superset rather than exact).
     */
    public function test_high_position_dm7_11_matches(): void
    {
        $result = $this->crossref->matchVoicing(
            $this->voicing('Dm7(11)', 'xxcedf', 12, 'D', 'm7')
        );

        $this->assertNotFalse($result, 'Dm7(11) xxcedf should match after octave folding');
    }

    /** Call the private foldOctaveDown() directly — it is the whole guard. */
    private function fold(array $frets): array
    {
        $m = new \ReflectionMethod(VoicingCrossref::class, 'foldOctaveDown');
        $m->setAccessible(true);

        return $m->invoke($this->crossref, $frets);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('foldCases')]
    public function test_fold_octave_down_guard(array $in, array $expected, string $why): void
    {
        $this->assertSame($expected, $this->fold($in), $why);
    }

    public static function foldCases(): array
    {
        return [
            // Every sounding string >= 12 → fold. (Easy Living Dm7 / Dm7(11).)
            'all high, folds' => [
                ['x', 'x', 12, 14, 13, 13], ['x', 'x', 0, 2, 1, 1],
                'xxcedd is m7-drop2-rootd an octave up',
            ],
            'all high with 15th fret, folds' => [
                ['x', 'x', 12, 14, 13, 15], ['x', 'x', 0, 2, 1, 3],
                'xxcedf folds to a low-register shape',
            ],
            // Lowest sounding string is 10 → folding would drive it to -2.
            'partially high, unchanged' => [
                ['x', 'x', 10, 10, 11, 11], ['x', 'x', 10, 10, 11, 11],
                'min fret 10 < 12 must not fold (would go negative)',
            ],
            // An open string is a real open string, not a foldable fret.
            'contains open string, unchanged' => [
                ['x', 0, 5, 7, 5, 'x'], ['x', 0, 5, 7, 5, 'x'],
                'an open string makes this a different voicing',
            ],
            'exactly at 12 boundary, folds' => [
                ['x', 12, 12, 12, 13, 'x'], ['x', 0, 0, 0, 1, 'x'],
                'fret 12 is the inclusive lower bound',
            ],
            'all muted, unchanged' => [
                ['x', 'x', 'x', 'x', 'x', 'x'], ['x', 'x', 'x', 'x', 'x', 'x'],
                'no sounding strings → no-op, no min() on empty array',
            ],
        ];
    }
}

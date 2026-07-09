<?php

namespace Tests\Unit;

use App\Services\ChordShapeCalculator;
use App\Services\VoicingCrossref;
use PHPUnit\Framework\TestCase;

/**
 * VoicingCrossref::identifyWithPinnedRoot() — the progression supplies the root,
 * Pass 1 supplies the spelling.
 *
 * The Ipanema B-part cases are the reason this exists: both voicings sound a
 * bass-alternation shell whose root is absent for that beat.
 */
final class PinnedRootIdentifyTest extends TestCase
{
    private VoicingCrossref $x;

    protected function setUp(): void
    {
        parent::setUp();
        $this->x = new VoicingCrossref(new ChordShapeCalculator());
    }

    /** pcs for 6x466x = {Bb,Gb,Db,F}; pinned root Eb; bass Bb. 5-b3-b7-9. */
    public function test_ipanema_slot1_pinned_to_eb_is_ebm7_9_over_bb(): void
    {
        $r = $this->x->identifyWithPinnedRoot([10, 6, 1, 5], 3, 10);

        $this->assertNotNull($r);
        $this->assertSame('Ebm7(9)/Bb', $r['name']);
        $this->assertSame('m7', $r['quality']);
        $this->assertSame('9', $r['extensions']);
        $this->assertTrue($r['rootless'], 'the pinned Eb is not sounding');
    }

    /** pcs for 6x566x = {Db,F,G,Bb}; pinned root Eb; bass Bb. 5-b7-9-3. */
    public function test_ipanema_slot0_pinned_to_eb_is_eb7_9_over_bb(): void
    {
        $r = $this->x->identifyWithPinnedRoot([1, 5, 7, 10], 3, 10);

        $this->assertNotNull($r);
        $this->assertSame('Eb7(9)/Bb', $r['name']);
        $this->assertSame('dom7', $r['quality']);
        $this->assertSame('9', $r['extensions']);
    }

    /**
     * Option tones are ALWAYS parenthesised and never absorbed into the base
     * quality. Guards the convention against drift.
     */
    public function test_extensions_are_parenthesised_never_absorbed(): void
    {
        $r = $this->x->identifyWithPinnedRoot([10, 6, 1, 5], 3, 10);

        $this->assertNotNull($r);
        $this->assertStringContainsString('(9)', $r['name']);
        $this->assertNotSame('Ebm9/Bb', $r['name'], 'bare-9 form must never be emitted');
        $this->assertStringNotContainsString('m9', $r['name']);
    }

    /** Multiple leftovers are comma-joined in ascending interval order. */
    public function test_multiple_extensions_are_interval_sorted(): void
    {
        // Ab7(b9,13): Ab C Eb Gb + A(b9) + F(13). Root Ab=8, bass A=9.
        $pcs = [8, 0, 3, 6, 9, 5];
        $r = $this->x->identifyWithPinnedRoot($pcs, 8, 9);

        $this->assertNotNull($r);
        $this->assertSame('b9,13', $r['extensions'], 'b9 (iv 1) precedes 13 (iv 9)');
    }

    /** A complete, root-present chord still resolves (no slash, not rootless). */
    public function test_root_present_complete_chord(): void
    {
        // {C,E,G,B} pinned to C, bass C.
        $r = $this->x->identifyWithPinnedRoot([0, 4, 7, 11], 0, 0);

        $this->assertNotNull($r);
        $this->assertSame('Cmaj7', $r['name']);
        $this->assertFalse($r['rootless']);
    }

    /** An unnameable leftover means the pinned root does not explain the voicing. */
    public function test_returns_null_when_pinned_root_does_not_fit(): void
    {
        // {Bb,Gb,Db,F} pinned to B(11): leftovers are not all nameable extensions.
        $this->assertNull($this->x->identifyWithPinnedRoot([10, 6, 1, 5], 11, 10));
    }

    /**
     * A single note is never a chord, whatever root you pin to it.
     */
    public function test_returns_null_for_a_single_note(): void
    {
        $this->assertNull($this->x->identifyWithPinnedRoot([10], 3, 10));
    }

    /**
     * Dyads are refused, mirroring identifyFromFrets()'s minimum-note guard.
     * Pinning a root must not launder a bare 3rd into a triad: {C,E} pinned to A
     * would otherwise return `Am/C`, hallucinating an A that is not sounding.
     * These are the same pitch sets the dyad-refusal regression cases forbid.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('dyadProvider')]
    public function test_dyads_are_refused_under_every_pinned_root(array $pcs, int $bassPc): void
    {
        for ($root = 0; $root < 12; $root++) {
            $this->assertNull(
                $this->x->identifyWithPinnedRoot($pcs, $root, $bassPc),
                'dyad ' . json_encode($pcs) . " must not resolve under pinned root $root"
            );
        }
    }

    public static function dyadProvider(): array
    {
        return [
            'C,E  bare major 3rd' => [[0, 4], 0],
            'C,Eb bare minor 3rd' => [[0, 3], 0],
            'A,C# root + M3'      => [[9, 1], 9],
            'C,G  power chord'    => [[0, 7], 0],
        ];
    }

    /**
     * A root-absent reading needs a genuine 3-5-7 shell — three sounding template
     * tones. Two would mean naming a triad whose root AND one tone are invented.
     */
    public function test_returns_null_when_root_absent_and_only_two_tones_sound(): void
    {
        // Pin root C. Sounding {E,B,D}: E(3rd) and B(7th) match 2 of Cmaj7's four
        // tones, D is a nameable 9th, and C itself is absent. Naming this
        // `Cmaj7(9)/E` would invent BOTH the root and the 5th, so it is refused.
        $this->assertNull($this->x->identifyWithPinnedRoot([4, 11, 2], 0, 4));
    }

    /**
     * The counterpart: a true 3-5-b7 shell IS accepted. {E,G,Bb} over root C
     * matches three of C7's four tones, omitting only the root — the one tone
     * jazz voicings legitimately drop.
     */
    public function test_a_genuine_three_five_seven_shell_is_accepted(): void
    {
        $r = $this->x->identifyWithPinnedRoot([4, 7, 10], 0, 4);

        $this->assertNotNull($r);
        $this->assertSame('C7/E', $r['name']);
        $this->assertTrue($r['rootless']);
    }

    /** Quality stays a string even for the numeric-looking '5' key. */
    public function test_quality_is_always_a_string(): void
    {
        $r = $this->x->identifyWithPinnedRoot([0, 4, 7], 0, 0);
        $this->assertNotNull($r);
        $this->assertIsString($r['quality']);
    }
}

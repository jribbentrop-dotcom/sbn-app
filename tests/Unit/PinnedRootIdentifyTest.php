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
     * A root-absent reading needs at least two sounding template tones. A lone
     * 5th otherwise satisfies the '5' template and yields a contentless
     * `Eb5/Bb` — never a name the sequence layer should be offered.
     */
    public function test_returns_null_when_root_absent_and_only_one_tone_sounds(): void
    {
        $this->assertNull($this->x->identifyWithPinnedRoot([10], 3, 10));
    }

    /** Quality stays a string even for the numeric-looking '5' key. */
    public function test_quality_is_always_a_string(): void
    {
        $r = $this->x->identifyWithPinnedRoot([0, 7], 0, 0);
        $this->assertNotNull($r);
        $this->assertIsString($r['quality']);
    }
}

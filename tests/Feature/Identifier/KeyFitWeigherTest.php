<?php

namespace Tests\Feature\Identifier;

use App\Services\Identifier\KeyFitWeigher;
use Tests\TestCase;

/**
 * Phase 3.2: KeyFitWeigher unit tests.
 *
 * Spec: docs/SBN-Identifier-Phase3-Plan.md §4.
 *
 * Core invariant: weights are always ≥ 1.00. Chromatic candidates are NEVER
 * penalized — only diatonic/functional candidates get promoted.
 */
class KeyFitWeigherTest extends TestCase
{
    private KeyFitWeigher $weigher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->weigher = new KeyFitWeigher();
    }

    public function test_tonic_iv_v_get_strongest_weight(): void
    {
        // Key of C: I=C(0), IV=F(5), V=G(7)
        $this->assertSame(1.20, $this->weigher->weight(0, 'maj7', 'C'));
        $this->assertSame(1.20, $this->weigher->weight(5, 'maj7', 'C'));
        $this->assertSame(1.20, $this->weigher->weight(7, 'dom7', 'C'));
    }

    public function test_diatonic_minor_degrees_get_diatonic_weight(): void
    {
        // Key of C: ii=D(2), iii=E(4), vi=A(9)
        $this->assertSame(1.15, $this->weigher->weight(2, 'm7', 'C'));
        $this->assertSame(1.15, $this->weigher->weight(4, 'm7', 'C'));
        $this->assertSame(1.15, $this->weigher->weight(9, 'm7', 'C'));
    }

    public function test_leading_tone_gets_leading_weight(): void
    {
        // Key of C: vii=B(11)
        $this->assertSame(1.10, $this->weigher->weight(11, 'm7b5', 'C'));
    }

    public function test_secondary_dominant_gets_secondary_weight(): void
    {
        // Key of C: V7/ii = A7 (A=9), targets D=2 which is diatonic
        $this->assertSame(1.10, $this->weigher->weight(9, 'dom7', 'C'));
        // V7/V = D7 (D=2), targets G=7 which is diatonic
        $this->assertSame(1.10, $this->weigher->weight(2, 'dom7', 'C'));
    }

    public function test_modal_mixture_gets_mixture_weight_in_major(): void
    {
        // Key of C major: bIII=Eb(3), bVI=Ab(8), bVII=Bb(10)
        $this->assertSame(1.05, $this->weigher->weight(3, 'maj7', 'C'));
        $this->assertSame(1.05, $this->weigher->weight(8, 'maj7', 'C'));
        $this->assertSame(1.05, $this->weigher->weight(10, 'maj7', 'C'));
    }

    public function test_chromatic_unrelated_root_gets_neutral_weight(): void
    {
        // Key of C: F# (6) — tritone away, no diatonic-target relationship as a non-dom7.
        // (F#m7 isn't a secondary dominant of anything in C, isn't mixture.)
        $this->assertSame(1.00, $this->weigher->weight(6, 'm7', 'C'));
    }

    /**
     * The bedrock invariant: weights never go below 1.00 for any combination.
     * Ensures chromatic readings (Garota's secondary moves, Coltrane changes, etc.)
     * never get penalized — they may not be promoted, but they're never demoted.
     */
    public function test_weight_is_never_below_one(): void
    {
        $qualities = ['maj', 'm', 'dom7', 'maj7', 'm7', 'm7b5', 'dim7', 'aug', 'sus4', 'add9', 'maj6', 'm6'];
        $keys = ['C', 'F#', 'Db', 'A', 'Eb', 'B', 'F', 'Bb minor', 'Em', 'C#m'];
        foreach ($keys as $key) {
            foreach (range(0, 11) as $pc) {
                foreach ($qualities as $q) {
                    $w = $this->weigher->weight($pc, $q, $key);
                    $this->assertGreaterThanOrEqual(1.00, $w, "Weight below 1.00 for pc=$pc q=$q key=$key");
                    $this->assertLessThanOrEqual(1.20, $w, "Weight above 1.20 for pc=$pc q=$q key=$key");
                }
            }
        }
    }

    public function test_null_or_empty_key_returns_neutral(): void
    {
        $this->assertSame(1.00, $this->weigher->weight(0, 'maj7', null));
        $this->assertSame(1.00, $this->weigher->weight(0, 'maj7', ''));
        $this->assertSame(1.00, $this->weigher->weight(0, 'maj7', '   '));
    }

    public function test_minor_key_diatonic_recognized(): void
    {
        // Key of A minor (relative of C major). Tonic = A(9). Diatonic root motion:
        // i=Am(9), ii°=B(11), III=C(0), iv=Dm(2), v=Em(4) or V=E7, VI=F(5), VII=G(7).
        // The minor-key shift treats this as the relative-major (C) frame internally.
        $this->assertSame(1.20, $this->weigher->weight(9, 'm7', 'Am'),  'i (Am)');
        $this->assertSame(1.20, $this->weigher->weight(2, 'm7', 'Am'),  'iv (Dm)');
        $this->assertSame(1.20, $this->weigher->weight(4, 'dom7', 'Am'),'V (E7)');
        // bIII = C (0): in minor that's actually III natural — diatonic.
        // Our impl folds via +3 rotation so this becomes IV of the relative frame? Confirm not <1.
        $this->assertGreaterThanOrEqual(1.00, $this->weigher->weight(0, 'maj7', 'Am'));
    }

    public function test_flat_key_signatures_parse(): void
    {
        // Db key: I=Db(1), IV=Gb(6), V=Ab(8)
        $this->assertSame(1.20, $this->weigher->weight(1, 'maj7', 'Db'));
        $this->assertSame(1.20, $this->weigher->weight(6, 'maj7', 'Db'));
        $this->assertSame(1.20, $this->weigher->weight(8, 'dom7', 'Db'));
        // F# key (same as Gb enharmonically): I=F#(6)
        $this->assertSame(1.20, $this->weigher->weight(6, 'maj7', 'F#'));
    }

    public function test_ipanema_chord_2_eb7_over_bb_in_db_is_secondary_dominant(): void
    {
        // The driver case from chord 2 of Ipanema. Eb7 in key of Db is V7/V
        // (Eb7 resolves to Ab7 which resolves to Db). Eb root = pc 3.
        // Target of Eb7 resolution = Ab = pc 8 = V in Db major (diatonic). Should be secondary_dom = 1.10.
        $this->assertSame(1.10, $this->weigher->weight(3, 'dom7', 'Db'));
        // By contrast Bbm7 (Bb=10) in key of Db is vi (diatonic minor) = 1.15.
        $this->assertSame(1.15, $this->weigher->weight(10, 'm7', 'Db'));
    }
}

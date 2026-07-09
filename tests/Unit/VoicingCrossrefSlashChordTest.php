<?php

namespace Tests\Unit;

use App\Services\VoicingCrossref;
use Tests\TestCase;

/**
 * Slash-chord output-formatting guards.
 *
 * SCOPE NARROWED (2026-07-09): the identification *expectations* that used to
 * live here had drifted from the identifier's behaviour — and several were
 * musically wrong for their input (0xx558 = {C,E} frozen as "Eaug"; a two-note
 * dyad cannot be any triad). Those cases moved to IdentifierRegressionTest /
 * IdentifierRegressionCases, where every expectation is computed and verified.
 *
 * What remains here is only what that suite doesn't cover: display/normalization
 * formatting of slash-chord names. See docs/SBN-Identifier-Reference.md App. B.
 *
 * Runs against the real sbn.db (identifyFromFrets does a diagram lookup).
 */
class VoicingCrossrefSlashChordTest extends TestCase
{
    private VoicingCrossref $crossref;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.sqlite.database' => 'C:/Users/info/sbn-app/database/sbn.db']);
        \Illuminate\Support\Facades\DB::reconnect('sqlite');
        $this->crossref = new VoicingCrossref(app(\App\Services\ChordShapeCalculator::class));
    }

    /**
     * Quality tokens normalize for display: 'min' → 'm' (not 'Gmin/D').
     * x5533x = {D,G,Bb} = complete Gm with the 5th (D) in the bass.
     */
    public function test_slash_chord_quality_normalizes_min_to_m(): void
    {
        $result = $this->crossref->identifyFromFrets('x5533x');
        $this->assertSame('Gm/D', $result['name']);
        $this->assertNotSame('Gmin/D', $result['name']);
        $this->assertStringNotContainsString('min', $result['name']);
    }

    /**
     * A slash chord keeps its bass suffix and does not regress to a non-slash
     * exotic reading. ax8aax = {D,F,A,Bb} = Bbmaj7 with the 3rd (D) in the bass.
     */
    public function test_slash_chord_keeps_bass_suffix(): void
    {
        $result = $this->crossref->identifyFromFrets('ax8aax');
        $this->assertSame('Bbmaj7/D', $result['name']);
        $this->assertNotSame('Dmin(b13)', $result['name']);
        $this->assertStringContainsString('/D', $result['name']);
    }
}

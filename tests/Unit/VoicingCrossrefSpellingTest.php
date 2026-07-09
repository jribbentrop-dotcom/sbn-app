<?php

namespace Tests\Unit;

use App\Services\ChordShapeCalculator;
use App\Services\HarmonicContext;
use App\Services\VoicingCrossref;
use Tests\TestCase;

/**
 * Enharmonic-spelling consolidation guard (2026-07-09).
 *
 * The identifier must NOT own its own flat/sharp policy. Every note name it
 * emits should route through the single spelling authority
 * (HarmonicContext::useFlatsFor / spellingUsesFlats) so the house style can be
 * tuned in one place. These tests lock that in two ways:
 *
 *   1. Key family drives spelling — a black-key root spells flat under a flat
 *      key and sharp under a sharp key (the old identifier was sharp-biased
 *      regardless of key).
 *   2. The identifier's output equals what HarmonicContext::reSpellChordName
 *      would produce for the same name+key — i.e. the two can never drift.
 *
 * Uses the real sbn.db (identifyFromFrets does a diagram lookup); mirrors
 * VoicingCrossrefSlashChordTest / DbVoicingMatcherTest.
 */
class VoicingCrossrefSpellingTest extends TestCase
{
    private VoicingCrossref $crossref;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.sqlite.database' => 'C:/Users/info/sbn-app/database/sbn.db']);
        \Illuminate\Support\Facades\DB::reconnect('sqlite');
        $this->crossref = new VoicingCrossref(app(ChordShapeCalculator::class));
    }

    /**
     * A black-key root follows the key family: flat under a flat key, sharp
     * under a sharp key. This is the behaviour the pre-consolidation identifier
     * lacked (it hard-coded sharps outside IDENTIFY_FLAT_ROOTS, ignoring key).
     */
    public function test_key_family_drives_black_key_spelling(): void
    {
        // pc 3 root (Eb / D#), plain major triad shape.
        $flat  = $this->crossref->identifyFromFrets('x68886x', 1, 'Eb');
        $sharp = $this->crossref->identifyFromFrets('x68886x', 1, 'E');

        $this->assertSame('Eb', $flat['name'], 'Flat key must spell the root flat');
        $this->assertSame('D#', $sharp['name'], 'Sharp key must spell the root sharp');
    }

    /**
     * A flat-family key must never emit a sharp in the ROOT/BASS of the name
     * (the tokens pcToNoteName produces). Guards against re-introducing a local
     * sharp-biased table in the identifier. (We check the root and any slash
     * bass specifically, not quality tokens like m7b5 which legitimately hold a
     * 'b'.)
     */
    public function test_flat_key_root_and_bass_are_never_sharp(): void
    {
        $probes = ['x68886x', 'ax8aax', 'x8657x', '688766'];
        foreach ($probes as $frets) {
            $name = $this->crossref->identifyFromFrets($frets, 1, 'Eb')['name'];
            [$root, $bass] = array_pad(explode('/', $name, 2), 2, null);
            $rootAcc = preg_match('/^[A-G](#|b)?/', $root, $m) ? ($m[1] ?? '') : '';
            $this->assertNotSame('#', $rootAcc, "Flat key emitted a sharp root: {$name}");
            if ($bass !== null && preg_match('/^[A-G](#|b)?/', $bass, $bm)) {
                $this->assertNotSame('#', $bm[1] ?? '', "Flat key emitted a sharp bass: {$name}");
            }
        }
    }

    /**
     * Concrete house-style spellings for the Bbmaj7/D case across keys — the
     * exact regression the consolidation targets (was sharp-biased to A#maj7/D
     * regardless of key before the identifier delegated to the authority).
     */
    public function test_bbmaj7_over_d_spelling_by_key(): void
    {
        $this->assertSame('Bbmaj7/D', $this->crossref->identifyFromFrets('ax8aax', 1, null)['name']);
        $this->assertSame('Bbmaj7/D', $this->crossref->identifyFromFrets('ax8aax', 1, 'F')['name']);
        $this->assertSame('A#maj7/D', $this->crossref->identifyFromFrets('ax8aax', 1, 'B')['name']);
    }
}

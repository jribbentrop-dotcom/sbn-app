<?php

namespace Tests\Unit;

use App\Services\ChordShapeCalculator;
use App\Services\VoicingCrossref;
use Tests\TestCase;

/**
 * End-to-end SEQUENCE regression suite (2026-07-09).
 *
 * Drives curated real-song progressions (IdentifierSequenceCases) through the
 * true pipeline — VoicingCrossref::identifyVoicingsBatch() = Phase 1 per slot +
 * Phase 2 contextual rerank — and asserts the resolved name sequence.
 *
 * This is deliberately NOT the fabricated-pool approach of ViterbiRescoreTest
 * (which hands the reranker invented candidate scores). Here the candidate
 * pools come from real fret identification, so a drift in Pass-1 scoring is
 * actually caught.
 *
 * HONESTY CONTRACT: a Tier-1 slot is asserted only when its `expected`
 * (Phase-2 resolved name) has been human-verified — run
 * `php artisan sbn:audit-identifier-sequences`, confirm the output by
 * ear/theory, then pin `expected` in IdentifierSequenceCases. Slots with
 * expected=null are printed-only (documented as pending), never asserted, so
 * the suite never freezes an unverified reading as ground truth.
 *
 * Runs against the real sbn.db (mirrors the other identifier suites).
 */
class IdentifierSequenceTest extends TestCase
{
    private VoicingCrossref $crossref;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.sqlite.database' => database_path('sbn.db')]);
        \Illuminate\Support\Facades\DB::reconnect('sqlite');
        $this->crossref = new VoicingCrossref(app(ChordShapeCalculator::class));
    }

    /** Run one fixture's slot list through the full Phase 1+2 pipeline. */
    private function runSequence(array $case): array
    {
        $voicings = [];
        foreach ($case['slots'] as $i => $slot) {
            $voicings["slot$i"] = ['frets' => $slot['frets'], 'position' => 1];
        }
        $ctx = ($case['song_key'] ?? null) ? ['song_key' => $case['song_key']] : null;
        return $this->crossref->identifyVoicingsBatch($voicings, $ctx);
    }

    /**
     * TIER 1 — sequences with a single correct functional resolution. Only
     * slots carrying a non-null, human-verified `expected` are asserted.
     */
    public function test_tier1_resolved_sequences(): void
    {
        foreach (IdentifierSequenceCases::TIER1_RESOLVED as $name => $case) {
            $results = $this->runSequence($case);

            $assertedAny = false;
            foreach ($case['slots'] as $i => $slot) {
                if (($slot['expected'] ?? null) === null) {
                    continue; // pending human verification — printed by the audit cmd, not frozen
                }
                $assertedAny = true;
                $r = $results["slot$i"] ?? [];
                $this->assertSame(
                    $slot['expected'],
                    $r['name'] ?? null,
                    "[$name] slot$i {$slot['frets']} should resolve to {$slot['expected']} ({$case['why']}), got '" . ($r['name'] ?? 'null') . "'"
                );
            }

            // Guard the guard: every Tier-1 fixture must eventually assert
            // something. If a fixture is entirely pending, mark it incomplete so
            // it can't silently contribute zero coverage.
            if (!$assertedAny) {
                $this->markTestIncomplete("[$name] has no verified `expected` slots yet — run sbn:audit-identifier-sequences and pin them.");
            }
        }
    }

    /**
     * TIER 1 TRIGRAM — voicings that are ambiguous as one chord but disambiguated
     * by the II7→V7→I trigram. We can't pin exact Phase-2 spelling without a
     * running engine, so we assert the falsifiable disambiguation CLAIM: the
     * forbidden alternative (the identical-PC reading the trigram must reject)
     * does not win, and — when want_root is given — the resolved root matches.
     */
    public function test_tier1_trigram_disambiguation(): void
    {
        $ran = 0;
        foreach (IdentifierSequenceCases::TIER1_TRIGRAM as $name => $case) {
            if (!empty($case['pending'])) {
                // Trigram machinery proven, but blocked upstream (see fixture note).
                continue;
            }
            $ran++;
            $results = $this->runSequence($case);

            foreach ($case['slots'] as $i => $slot) {
                $r = $results["slot$i"] ?? [];
                $resolved = $r['name'] ?? null;
                $this->assertNotNull($resolved, "[$name] slot$i must resolve");

                foreach ($slot['not'] ?? [] as $forbidden) {
                    if ($forbidden === '') continue; // '' is just a "non-empty" marker
                    $this->assertNotSame(
                        $forbidden, $resolved,
                        "[$name] slot$i {$slot['frets']} must NOT read as '$forbidden' — the trigram should reject it ({$case['why']})"
                    );
                }

                // Root check only where we've committed to it (the II7/V7 anchors).
                if (($slot['want_root'] ?? null) !== null) {
                    $this->assertSame(
                        $slot['want_root'], $r['root'] ?? null,
                        "[$name] slot$i {$slot['frets']} root should be {$slot['want_root']}, got '" . ($r['root'] ?? 'null') . "' (resolved '$resolved')"
                    );
                }

                // Exact spelling, where the sequence has been verified end-to-end.
                // Option tones are always parenthesised: `Ebm7(9)/Bb`, never `Ebm9/Bb`.
                if (($slot['want_name'] ?? null) !== null) {
                    $this->assertSame(
                        $slot['want_name'], $resolved,
                        "[$name] slot$i {$slot['frets']} should spell as {$slot['want_name']}, got '$resolved' ({$case['why']})"
                    );
                }
            }
        }

        if ($ran === 0) {
            $this->markTestIncomplete(
                'All TIER1_TRIGRAM cases are pending — un-skip as they resolve. '
                . 'See project_identifier_trigram memo.'
            );
        }
    }

    /**
     * TIER 3 — genuinely context-only / not-yet-locked. Assert only that the
     * sequence runs, each slot resolves to SOME non-empty name, and documented
     * negative guards hold. Never a pinned positive name.
     */
    public function test_tier3_ambiguous_sequences_are_stable(): void
    {
        foreach (IdentifierSequenceCases::TIER3_AMBIGUOUS as $name => $case) {
            $results = $this->runSequence($case);

            foreach ($case['slots'] as $i => $slot) {
                $resolved = $results["slot$i"]['name'] ?? null;

                $this->assertNotNull(
                    $resolved,
                    "[$name] slot$i {$slot['frets']} must resolve to some reading ({$case['why']})"
                );
                $this->assertNotSame('', $resolved, "[$name] slot$i must not be empty");

                foreach ($slot['not'] ?? [] as $forbidden) {
                    $this->assertNotSame(
                        $forbidden,
                        $resolved,
                        "[$name] slot$i must not read as '" . var_export($forbidden, true) . "'"
                    );
                }
            }
        }
    }
}

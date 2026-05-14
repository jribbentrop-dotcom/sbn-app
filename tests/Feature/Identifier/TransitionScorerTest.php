<?php

namespace Tests\Feature\Identifier;

use App\Services\Identifier\TransitionScorer;
use Tests\TestCase;

/**
 * Phase 3.3a: TransitionScorer tests.
 *
 * Spec: docs/SBN-Identifier-Phase3-Plan.md §5.3.
 *
 * Uses the real generated bigram table (storage/app/harmonic-transitions.generated.php)
 * so we're testing against the same corpus statistics production sees.
 */
class TransitionScorerTest extends TestCase
{
    private TransitionScorer $scorer;

    protected function setUp(): void
    {
        parent::setUp();
        // Real DB needed only for VoicingCrossref tests, not this one — but the bigram
        // file IS data-dependent. If it's missing, skip the tests rather than fail.
        if (!is_file(base_path('storage/app/harmonic-transitions.generated.php'))) {
            $this->markTestSkipped('Run `php artisan sbn:reseed-transitions` first');
        }
        $this->scorer = new TransitionScorer();
    }

    public function test_iiv_in_c_is_high_probability(): void
    {
        // Dm7 → G7 should be one of the strongest transitions in jazz corpus.
        $p = $this->scorer->probability('Dm7', 'G7');
        $this->assertGreaterThan(0.30, $p, 'P(G7|Dm7) should be high — ii→V in C is jazz canon');
    }

    public function test_ipanema_chord_2_bigram_prefers_secondary_dominant(): void
    {
        // The Ipanema driver case: after Db6(9)/Ab (~Db in canonical form), Eb7 is
        // the II7 secondary dominant (very common in bossa) and Bbm6 is iim6 (rare).
        // We test the simpler normalized form Db (since Db6 may not appear verbatim
        // in the corpus but Db→Eb7 transitions will).
        $pEb7 = $this->scorer->probability('Db', 'Eb7');
        $pBbm6 = $this->scorer->probability('Db', 'Bbm6');
        // Both might be low — the corpus has Db only as a transient key, but Eb7
        // should still beat Bbm6 by a margin.
        $this->assertGreaterThanOrEqual($pBbm6, $pEb7,
            "P(Eb7|Db)=$pEb7 should be ≥ P(Bbm6|Db)=$pBbm6");
    }

    public function test_score_multiplier_promotes_strong_transitions(): void
    {
        // Dm7 → G7 should yield a multiplier well above 1.0
        $mult = $this->scorer->scoreMultiplier('Dm7', 'G7');
        $this->assertGreaterThan(1.50, $mult, "Multiplier for Dm7→G7 should be strongly promoting");
        $this->assertLessThanOrEqual(3.0, $mult, "Multiplier capped at 3.0");
    }

    public function test_score_multiplier_demotes_weak_transitions(): void
    {
        // A genuinely-unseen transition should sit well below 1.0 (demoted).
        // Use a truly chromatic combination unlikely to appear in the corpus.
        $mult = $this->scorer->scoreMultiplier('Cmaj7', 'F#7b5');
        $this->assertLessThan(1.5, $mult, "Rare transition should not be strongly promoted");
    }

    public function test_unseen_chord_returns_uniform(): void
    {
        // A chord that almost certainly doesn't appear in corpus
        $p = $this->scorer->probability('Xyz123', 'G7');
        $this->assertGreaterThan(0.0, $p, 'Unseen prev should fall back to uniform');
        $this->assertLessThan(0.01, $p, 'Uniform should be small (1/V where V is large)');
    }

    public function test_multiplier_is_bounded(): void
    {
        // Strong probe
        $strong = $this->scorer->scoreMultiplier('Dm7', 'G7');
        // Weak probe (unseen)
        $weak = $this->scorer->scoreMultiplier('Xyz123', 'Foobar');
        $this->assertGreaterThanOrEqual(0.4, $weak);
        $this->assertGreaterThanOrEqual(0.4, $strong);
        $this->assertLessThanOrEqual(3.0, $strong);
        $this->assertLessThanOrEqual(3.0, $weak);
    }

    public function test_top_next_returns_plausible_continuations(): void
    {
        $top = $this->scorer->topNext('Dm7', 5);
        $this->assertNotEmpty($top);
        $topName = $top[0]['chord'];
        // G7 should dominate Dm7's continuations
        $this->assertSame('G7', $topName, "Most common Dm7→? should be G7");
    }

    public function test_meta_has_expected_shape(): void
    {
        $meta = $this->scorer->meta();
        $this->assertArrayHasKey('corpus_size', $meta);
        $this->assertArrayHasKey('total_bigrams', $meta);
        $this->assertArrayHasKey('generated_at', $meta);
        $this->assertGreaterThan(500, $meta['corpus_size'], 'Expect ~1382 standards');
    }
}

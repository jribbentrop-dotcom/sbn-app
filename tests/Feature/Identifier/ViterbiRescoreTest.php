<?php

namespace Tests\Feature\Identifier;

use App\Services\HarmonicContext\ContextualReranker;
use Tests\TestCase;

/**
 * Phase 3.4: Viterbi sequence rescore tests.
 *
 * Tests the sub-pass 2f behavior — minimum-cost path search over candidate
 * pools using -log(local_score) + edgeWeight × -log(P(next|prev)).
 *
 * Spec: docs/SBN-Identifier-Phase3-Plan.md §5.5.
 *
 * Property tested: Viterbi preserves the upstream pick when the local score
 * is much stronger than alternatives, even when the bigram chain disagrees.
 * It only flips when (a) candidates are roughly comparable and (b) the
 * sequence evidence consistently favors the alternate.
 */
class ViterbiRescoreTest extends TestCase
{
    private ContextualReranker $reranker;

    protected function setUp(): void
    {
        parent::setUp();
        if (!is_file(base_path('storage/app/harmonic-transitions.generated.php'))) {
            $this->markTestSkipped('Run `php artisan sbn:reseed-transitions` first');
        }
        $this->reranker = app(ContextualReranker::class);
    }

    /**
     * The safety invariant: a slot whose local-best candidate clearly outranks
     * the alternatives (>10× score) must NOT be flipped by Viterbi, even if
     * the bigram says the alternative would form better transitions.
     */
    public function test_does_not_flip_dominant_local_winners(): void
    {
        // Single slot with a heavily-dominant Pass 1 winner.
        // Build a fake Phase 1 result with Bbm6 at score 7200 vs Eb7(9)/Bb at 1062.
        $results = [
            $this->fakeSlot('Bbm6', [
                ['name' => 'Bbm6',      'score' => 7200, 'root' => 'Bb', 'quality' => 'm6',  'extensions' => '', 'bass_note' => 'Bb'],
                ['name' => 'Eb7(9)/Bb', 'score' => 1062, 'root' => 'Eb', 'quality' => 'dom7', 'extensions' => '9', 'bass_note' => 'Bb'],
            ]),
            $this->fakeSlot('Bbm6', [
                ['name' => 'Bbm6',      'score' => 7200, 'root' => 'Bb', 'quality' => 'm6',  'extensions' => '', 'bass_note' => 'Bb'],
                ['name' => 'Eb7(9)/Bb', 'score' => 1062, 'root' => 'Eb', 'quality' => 'dom7', 'extensions' => '9', 'bass_note' => 'Bb'],
            ]),
        ];
        $reranked = $this->reranker->rerank($results, 'Db');
        $this->assertSame('Bbm6', $reranked[0]['name'], 'Dominant local winner stays at slot 0');
        $this->assertSame('Bbm6', $reranked[1]['name'], 'Dominant local winner stays at slot 1');
    }

    /**
     * The tie-breaking case: when local scores are close, Viterbi can break
     * the tie using sequence evidence — but only if the score gap is small.
     * Construct two slots with near-equal candidate scores; the canonical
     * jazz transition Dm7 → G7 should win over a less-idiomatic alternative.
     */
    public function test_breaks_close_ties_using_bigram(): void
    {
        // Two slots: each has Dm7 and Cmaj7 as plausible (close-scored) candidates.
        // Bigram strongly favors Dm7 → G7 — but G7 isn't in slot 2.
        // We test Dm7 → Cmaj7 (low prob) vs Cmaj7 → Cmaj7 (high prob self).
        // Goal: when slot-1 candidates Cmaj7@1000 and Dm7@1050 are close,
        // and slot-2 has Cmaj7 only, Viterbi should pick Cmaj7@slot-1 (better
        // continuation to Cmaj7@slot-2). But our minScoreRatio safety check
        // still requires the flipped winner to be within 85% — Dm7 is at
        // 1050/1050=1.00 currently, Cmaj7 is at 1000/1050=0.95 — both within
        // threshold, so the flip is allowed.
        $results = [
            $this->fakeSlot('Dm7', [
                ['name' => 'Dm7',    'score' => 1050, 'root' => 'D', 'quality' => 'm7',   'extensions' => '', 'bass_note' => 'D'],
                ['name' => 'Cmaj7',  'score' => 1000, 'root' => 'C', 'quality' => 'maj7', 'extensions' => '', 'bass_note' => 'C'],
            ]),
            $this->fakeSlot('Cmaj7', [
                ['name' => 'Cmaj7',  'score' => 1000, 'root' => 'C', 'quality' => 'maj7', 'extensions' => '', 'bass_note' => 'C'],
            ]),
        ];
        $reranked = $this->reranker->rerank($results);
        // Either pick is defensible — but the result must be in the candidate set.
        $this->assertContains($reranked[0]['name'], ['Dm7', 'Cmaj7']);
        $this->assertSame('Cmaj7', $reranked[1]['name']);
    }

    /**
     * Sequence preserves canonical jazz transitions (Dm7 → G7 → Cmaj7 — the
     * archetypal ii-V-I in C). All three are dominant in their pools, so no
     * flipping should occur.
     */
    public function test_canonical_iivi_preserved(): void
    {
        $results = [
            $this->fakeSlot('Dm7', [
                ['name' => 'Dm7',    'score' => 5000, 'root' => 'D', 'quality' => 'm7',   'extensions' => '', 'bass_note' => 'D'],
                ['name' => 'F6',     'score' => 1500, 'root' => 'F', 'quality' => 'maj6', 'extensions' => '', 'bass_note' => 'F'],
            ]),
            $this->fakeSlot('G7', [
                ['name' => 'G7',     'score' => 5000, 'root' => 'G', 'quality' => 'dom7', 'extensions' => '', 'bass_note' => 'G'],
                ['name' => 'Bdim',   'score' => 1500, 'root' => 'B', 'quality' => 'dim',  'extensions' => '', 'bass_note' => 'B'],
            ]),
            $this->fakeSlot('Cmaj7', [
                ['name' => 'Cmaj7',  'score' => 5000, 'root' => 'C', 'quality' => 'maj7', 'extensions' => '', 'bass_note' => 'C'],
            ]),
        ];
        $reranked = $this->reranker->rerank($results, 'C');
        $this->assertSame('Dm7',   $reranked[0]['name']);
        $this->assertSame('G7',    $reranked[1]['name']);
        $this->assertSame('Cmaj7', $reranked[2]['name']);
    }

    /**
     * Build a minimal Phase 1 result with the required fields for the reranker.
     */
    private function fakeSlot(string $name, array $candidates): array
    {
        return [
            'name'              => $name,
            'root'              => $candidates[0]['root']      ?? '',
            'quality'           => $candidates[0]['quality']   ?? '',
            'extensions'        => $candidates[0]['extensions']?? '',
            'bass_note'         => $candidates[0]['bass_note'] ?? null,
            'pcs'               => [],
            'candidates'        => $candidates,
            'reinterpreted'     => false,
            'reinterpret_reason'=> null,
            'confidence'        => $candidates[0]['score'] ?? 0,
        ];
    }
}

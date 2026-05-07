<?php

namespace App\Services\HarmonicContext;

/**
 * Interface for contextual chord re-ranking (Phase 2).
 *
 * Takes Phase 1 results (top-K candidates) and applies harmonic context
 * to re-rank and reinterpret chords.
 */
interface IContextualReranker
{
    /**
     * Re-rank Phase 1 results using harmonic context.
     *
     * @param array<int, array> $phase1Results   Array of Phase 1 results with candidates
     * @param string|null       $songKey        e.g. 'F', 'Am', null
     * @param array<int, string>|null $expectedChords  Optional per-slot prior
     *                                                  (reserved for §1.7.2 audio path;
     *                                                  pass null for tab/XML)
     * @return array<int, array>  Re-ranked results with reinterpreted flags
     */
    public function rerank(
        array $phase1Results,
        ?string $songKey = null,
        ?array $expectedChords = null,
    ): array;
}

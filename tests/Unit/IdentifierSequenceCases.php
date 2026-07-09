<?php

namespace Tests\Unit;

/**
 * End-to-end SEQUENCE regression fixtures (2026-07-09).
 *
 * The single-chord suite (IdentifierRegressionCases) proves isolated
 * identification. This file proves the thing that only exists in sequence:
 * context resolution. Each case is a real progression pulled from a leadsheet
 * in sbn.db; the whole slot list is driven through
 * VoicingCrossref::identifyVoicingsBatch() (Phase 1 per slot + Phase 2
 * contextual rerank with the song key) and the resolved name sequence is
 * asserted.
 *
 * WHY sequence: several of these voicings are genuinely ambiguous or wrong as
 * ONE chord and only become determinate from their neighbours —
 *   • S'Wonderful: F#°7 = {C,Eb,Gb,A} is a symmetric dim (4 possible roots);
 *     only the ascending bass F→F#→G fixes it as a passing dim.
 *   • Easy Living: the C°7 pair IS the D7(b9) it decorates (dim-as-dominant);
 *     in isolation they're just dim7s.
 *   • Ipanema: 6x566x = {Db,F,G,Bb} is EXACTLY Bbm6 AND Eb7(9)/Bb — the same
 *     PC set. The song uses it both ways in different bars. No single-chord
 *     algorithm can tell them apart; only the progression can. (This is the
 *     canonical "unsolvable in isolation" case — docs §5.4.)
 *
 * Tiers mirror the single-chord suite:
 *   TIER1_RESOLVED  — the sequence has one correct functional reading; the
 *                     Phase-2 output MUST match it.
 *   TIER3_AMBIGUOUS — genuinely context-only or currently-unresolved; assert
 *                     stability + negative guards, never a pinned name.
 *
 * Each slot: ['frets','isolated'(the phase-1 read, for reference),'expected'].
 * `expected` is the FUNCTIONAL (Phase-2 resolved) name we want. Where a slot's
 * resolution is not yet reliable it carries expected=null and the case sits in
 * TIER3 (stability only). song_key drives the rerank.
 */
final class IdentifierSequenceCases
{
    /**
     * Sequences with a single correct functional resolution. Driven through
     * identifyVoicingsBatch in order; the resolved name at each slot with a
     * non-null `expected` must match.
     */
    public const TIER1_RESOLVED = [
        'swonderful_dim_ascent' => [
            'song_key' => 'F',
            'why' => 'chromatic passing dim: bass F→F#→G fixes F#°7 as a passing dim, not a rootless dom',
            'slots' => [
                // Identifier normalizes maj6 display as '6' (not 'maj6').
                ['frets' => '1x021x', 'isolated' => 'F6',   'expected' => 'F6'],
                ['frets' => '1x221x', 'isolated' => 'Fmaj7','expected' => 'Fmaj7'],
                // {C,Eb,Gb,A} symmetric dim. Currently resolves to Gbo7 (a valid
                // dim-family spelling); pinning the exact passing-dim spelling
                // needs the backward-context work, so leave unasserted for now.
                ['frets' => '2x121x', 'isolated' => null,   'expected' => null],
            ],
        ],
    ];

    /**
     * Genuinely context-only / not-yet-reliable. Assert only that the sequence
     * runs and each slot resolves to SOME name, plus documented negative guards.
     * Promote a slot to TIER1_RESOLVED only once its resolution is confirmed
     * against a running engine.
     */
    /**
     * Trigram-disambiguation targets (Phase 3.3b). The voicing is ambiguous as
     * ONE chord but the II7→V7→I trigram fixes it. We can't pin the exact
     * Phase-2 spelling without a running engine, so these assert the FALSIFIABLE
     * claim the trigram makes — the disambiguation direction — via `not`, plus a
     * `want_root`/`want_quality` the audit command prints for final verification.
     */
    public const TIER1_TRIGRAM = [
        'ipanema_bpart_II7_V7_I' => [
            'song_key' => 'Db',
            'why' => "6x566x = {Db,F,G,Bb} is Bbm6 AND Eb7(9)/Bb (identical PCs). In the B-part it's the II7 of II7→V7→I (Eb7→Ab7→Db). The trigram must pick the Eb7 (dominant) reading, NOT Bbm6.",
            // Real Ipanema B-part (bars 7→11), verified frets from the leadsheet:
            //   6x566x Eb7(9)/Bb [II7] → 6x466x Ebm7(9)/Bb → 5x456x Ab7(b9,13)/A [V7] → 4x334x Db6(9)/Ab [I]
            //
            // Resolved end-to-end once (a) the greedy sequential sub-passes stopped
            // vetoing Viterbi, (b) identifyWithPinnedRoot() let the progression
            // construct Ebm7(9)/Bb — a name Pass 1 never emits for these pcs — and
            // (c) the forward warrant let slot0's II7 beat the root-in-bass Bbm6,
            // whose 7200 Pass-1 score is a bass-position artifact, not evidence.
            'slots' => [
                ['frets' => '6x566x', 'want_root' => 'Eb', 'want_quality' => 'dom7',
                 'want_name' => 'Eb7(9)/Bb',
                 // THE disambiguation claim: resolves toward the II7 (Eb7…), never
                 // the vi/iim6 (Bbm6). Bbm6 is the identical-PC alternative the
                 // trigram must reject; the others guard against wrong-root reads.
                 'not' => ['Bbm6', 'Gm7', 'Gm', 'Bb', 'Bbmaj7']],
                // Bass-alternation shell (5–b3–b7–9): an exact, COMPLETE Gbmaj7/Bb
                // in isolation. Only the progression can supply the absent Eb.
                ['frets' => '6x466x', 'want_root' => 'Eb', 'want_quality' => 'm7',
                 'want_name' => 'Ebm7(9)/Bb', 'not' => ['Gbmaj7/Bb']],
                ['frets' => '5x456x', 'want_root' => 'Ab', 'want_quality' => 'dom7',
                 'want_name' => 'Ab7(b9,13)/A', 'not' => ['Ao7(b13)', 'Am6(b13)']],
                ['frets' => '4x334x', 'want_root' => 'Db', 'want_quality' => null,
                 'want_name' => 'Db6(9)/Ab', 'not' => ['Absus2(13)']],
            ],
        ],
    ];

    public const TIER3_AMBIGUOUS = [
        'easy_living_dim_as_dominant' => [
            'song_key' => 'F',
            'why' => 'C°7 pair are the upper structure of the D7(b9) they sit inside; dim-as-dominant resolution is the target but not yet locked as a pinned sequence',
            'slots' => [
                // These are dim7s or their dom resolution — never a plain triad.
                ['frets' => 'xx7898', 'isolated' => 'C°7(b13)/A',  'want' => 'D7(b9)-family', 'not' => ['A', 'Am', 'C', 'Cm', 'A/C']],
                ['frets' => 'xx4565', 'isolated' => 'C°7(b13)/Gb', 'want' => 'D7(b9)-family', 'not' => ['C', 'Cm', 'Gb', 'F']],
            ],
        ],
    ];
}

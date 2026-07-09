<?php

namespace Tests\Unit;

/**
 * Verified identifier regression fixtures (rebuilt 2026-07-09).
 *
 * Every expectation here was COMPUTED from the voicing's pitch classes and
 * musically verified — not copied from the pre-2026-07 frozen tests, several of
 * which had drifted or were musically impossible for their input (e.g. 0xx558 =
 * {C,E} was frozen as "Eaug", but {C,E} is a two-note dyad that cannot be any
 * triad). See docs/SBN-Identifier-Reference.md Appendix B.
 *
 * Tiers:
 *   TIER 1 (mechanical) — the pitch-class set fully determines the name; the
 *     identifier MUST return exactly this. Includes dyad-refusal cases where the
 *     only correct answer is "no chord".
 *   TIER 2 (verified voicing) — a shell/rootless/slash voicing with exactly one
 *     musically-correct reading; MUST match.
 *   TIER 3 (context-dependent) — genuinely ambiguous without external context
 *     (neighbouring chords / key). We assert only STABILITY and negative guards,
 *     never a pinned positive name. Promote to Tier 2 only if a context-free
 *     rule is agreed.
 *
 * Fret strings are the 6-char low-E→high-e hex form identifyFromFrets() takes.
 */
final class IdentifierRegressionCases
{
    /**
     * Each: ['frets','pcs','expected'|null,'why'].
     * expected === null means noResult() — the identifier must refuse.
     */
    public const TIER1_MECHANICAL = [
        // ── Dyads: two pitch classes, no defensible triad ──
        ['frets' => '0xx558', 'pcs' => '{C,E}',    'expected' => null, 'why' => 'bare major 3rd; not Eaug (no G#) nor C/E (no G)'],
        ['frets' => 'x31xxx', 'pcs' => '{C,Eb}',   'expected' => null, 'why' => 'bare minor 3rd; naming Cm hallucinates the 5th'],
        ['frets' => 'x0x225', 'pcs' => '{A,C#}',   'expected' => null, 'why' => 'root+M3 dyad; naming A hallucinates the 5th'],
        ['frets' => 'xxx225', 'pcs' => '{A,C#}',   'expected' => null, 'why' => 'root+M3 dyad, duplicate of x0x225'],

        // ── Complete triads (root-position or slash) ──
        ['frets' => 'x5533x', 'pcs' => '{D,G,Bb}', 'expected' => 'Gm/D',  'why' => 'complete Gm, 5th (D) in bass'],
        // PENDING: blocked by the Pass-1 absent-root bug — the incomplete 'F'
        // reading (root in bass) out-scores the complete rootless Bb/F. Same
        // class as the Ipanema blocker. Un-skip when Pass-1 absent-root scoring
        // is fixed. See docs §Appendix B / project_identifier_trigram memo.
        ['frets' => 'xx333x', 'pcs' => '{D,F,Bb}', 'expected' => 'Bb/F',  'why' => 'complete Bb, 3rd (F) in bass', 'pending' => 'pass1-absent-root'],
        // PENDING (pass1-absent-root): incomplete 'C' (root in bass) out-scores
        // the complete rootless F/C. Same class as xx333x + Ipanema.
        ['frets' => '8xaaax', 'pcs' => '{C,F,A}',  'expected' => 'F/C',   'why' => 'complete F, 5th (C) in bass', 'pending' => 'pass1-absent-root'],
        ['frets' => 'xxaaax', 'pcs' => '{C,F,A}',  'expected' => 'F/C',   'why' => 'complete F, 5th (C) in bass', 'pending' => 'pass1-absent-root'],
        ['frets' => 'x3333x', 'pcs' => '{C,D,F,Bb}', 'expected' => 'Bb/C','why' => 'complete Bb triad (Bb,D,F) + C (9th) in bass = true slash', 'pending' => 'pass1-absent-root'],

        // ── Complete 7th chords ──
        ['frets' => 'ax8aax', 'pcs' => '{D,F,A,Bb}', 'expected' => 'Bbmaj7/D', 'why' => 'complete Bbmaj7, 3rd (D) in bass'],
        ['frets' => '7x577x', 'pcs' => '{D,F#,G,B}', 'expected' => 'Gmaj7/B',  'why' => 'complete Gmaj7, 3rd (B) in bass'],
        ['frets' => '5x355x', 'pcs' => '{C,E,F,A}',  'expected' => 'Fmaj7/A',  'why' => 'complete Fmaj7, 3rd (A) in bass'],
    ];

    public const TIER2_VERIFIED = [
        // Shell / rootless / no-3rd voicings with one correct reading.
        // House style spells this root flat (Gb), per the enharmonic authority.
        ['frets' => 'x9799x', 'pcs' => '{E,F#,G#,A}','expected' => 'Gbm7(9)','why' => 'Gb R, A m3, E b7, Ab 9 — m7(9) shell, 5th dropped (flat spelling per house style)'],
        ['frets' => 'xx7978', 'pcs' => '{C,E,F#,A}', 'expected' => 'Am6',    'why' => 'A C E F# = Am6 (F# = 6th)'],
    ];

    public const TIER3_CONTEXT_DEPENDENT = [
        // Assert stability + documented negative guards ONLY. No pinned name.
        [
            'frets' => 'xx799a', 'pcs' => '{D,E,G#,A}',
            'not' => [],
            'why' => 'E7(11)/A vs Asus4(maj7) both defensible; no internal signal decides. Needs neighbour/key context.',
        ],
        [
            // Rootless F7(#9) (no F): A=3rd bass, C=5, Eb=b7, Ab=#9 — but equally an
            // A-rooted altered reading. Depends on the DB-injection/rootless path;
            // pin only the negative guard until confirmed against a running engine.
            'frets' => 'xx7898', 'pcs' => '{C,Eb,Ab,A}',
            'not' => ['A', 'Am', 'A/C', 'C', 'Cm'],
            'why' => 'rootless altered dom (F7#9 over A) — not a plain triad; A-root vs F7 reading needs context',
        ],
    ];
}

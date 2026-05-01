# SBN Chord Identification & Construction — Reference

**Status:** Living document.
**Last update:** 2026-05-01.
**Scope:** Both halves of SBN's chord-handling algorithm —
**chord identification** (frets → chord name) and **chord construction**
(chord progression → voicings). This document is the reference for what
each algorithm does, why it does it, what's working, what's broken, and
how to evolve it without breaking what works.

If you're touching either of these algorithms, **read this first**.

---

## TL;DR

- **Chord identification** (`App\Services\VoicingCrossref::identifyFromFrets`
  and friends) — fundamentally sound. One structural bug (Pattern 1) was
  shipped-fixed in Phase 1. Phase 2 (harmonic-pattern awareness) is the
  natural next step.
- **Chord construction** (`App\Services\ProgressionBuilder`) — fundamentally
  patched-up. Wants a clean rewrite, not another patch. Separate spec.
- **Audit infrastructure** exists for both, in `app/Console/Commands/`.
  Use it before and after every change. Specifically:
  `php artisan sbn:audit-identifier <file>` and `sbn:audit-progressions`.
- **Design principle that emerged from this work, write it on your wall:**
  every change to these algorithms must distinguish **Bucket 1** failures
  (algorithmic artifacts that no human would defend, e.g. `Dmin(b13)`)
  from **Bucket 2** failures (locally-defensible outputs that only a
  contextual reading would improve, e.g. `Am6` for what's really
  `D7(9)/A` over an A pedal in a ii–V–i in F). Fix Bucket 1. Leave
  Bucket 2 alone unless you're explicitly working on the harmonic layer.

---

## Part 1: Chord identification

### 1.1 What it does

Given a 6-character fret string (e.g. `x5756x`, `0` = open, `x` = muted,
hex `a-f` = frets 10–15) and a position marker, produce a chord name
plus structured metadata (root, quality, extensions, bass note,
inversion, confidence, diagram match if any).

Used during MusicXML import to label every chord stack the user pastes
in; also exposed via the admin "identify voicings" endpoint for ad-hoc
queries.

### 1.2 Underlying principle

The identifier is a **template-matching scoring algorithm with rescue
passes and contextual re-ranking**. In academic terms: MAP estimation
over `P(chord | pitch_classes)` with hand-coded likelihood, followed by
a CRF-style sequence pass when neighbor context is available.

The scoring step iterates every `(root, quality)` pair, scores how well
each fits the pitch-class set, and picks the highest. Rescue passes
catch known failure modes the main scorer mis-handles by design
(rootless voicings, plain triads with jazz interpretations,
tritone-implies-dominant). A separate context pass re-scores the result
against neighbor chords and the song key.

**This structure is the right structure for the problem. Do not
replace it.**

### 1.3 Pipeline

The full identification pipeline, in order:

| Pass | What it does | Where in code |
|---|---|---|
| 1 | Score all `(rootPc, quality)` pairs. Bass-boost ×4 when root = bass. Exact-bonus ×3 for 4+ tone exact matches. **Slash-bonus when a non-bass root passes the chord-tone test (Phase 1 fix — see §1.5).** | `identifyFromFrets`, lines ~1396–1451 |
| 3a | Rootless rescue: if Pass 1 found nothing, try interval sets *from an absent root* (e.g. `[3,7,10]` = m7 missing root) | lines ~1453–1460 |
| 3b | Rootless upgrade: if Pass 1 picked a plain triad from only 3 PCs and ≤3 fretted notes, also check rootless templates and prefer them when they match | lines ~1462–1470 |
| 3c | Tritone-dominant rescue: if Pass 1 winner is non-dominant but the PC set contains a tritone, re-score dominant interpretations with bonus ×5. Motivating example in code comment: `xx5676` in bossa | lines ~1472–1554 |
| 4 | Slash-chord post-detection: if `bestRoot ≠ bassPc`, append `/BassNote` and set `inversion` | lines ~1575–1592 |
| Context pass | When called via `identifyFromFretsWithContext` / `identifyVoicingsBatch`, re-rank against neighbor chord names + song key using `ROOT_MOTION_BONUS`, `FUNCTIONAL_FRAGMENTS`, diatonicity check | `identifyFromFretsWithContext`, line ~1634 |

### 1.4 What is load-bearing — do not change without strong reason

Future readers should treat the following as **deliberate and earned**,
not as scaffolding:

- The four-pass design (Pass 1 → 3a → 3b → 3c → context). Each pass
  exists for a documented failure mode.
- The `IDENTIFY_QUALITY_INTERVALS` table, sorted longest-first.
  Specificity matters — `maj7` beats `maj` on tied evidence.
- The `IDENTIFY_EXTENSION_INTERVALS` table mapping leftover pitch
  classes to named extensions. This is what lets the algorithm tolerate
  added 9s/11s/13s without losing the underlying chord identity.
- The `IDENTIFY_ROOTLESS_TEMPLATES` table for jazz voicings without a
  bass root. Crucial for `Cm7` over `Eb` cases.
- The tritone-dominant rescue at Pass 3c. Keep the `xx5676` comment.
- The context pass with `ROOT_MOTION_BONUS`, `ROOT_MOTION_PENALTY`,
  `FUNCTIONAL_FRAGMENTS`, `CTX_MAJOR_SCALE`. This is real harmonic
  knowledge refined against real songs.
- The slash-chord post-detection (Pass 4). It correctly adds `/BassNote`
  **once the right root is picked**.
- **The Phase 1 slash-bonus and its chord-tone restriction**
  (intervals `{3, 4, 7, 10, 11}`). See §1.5.
- **The two-tier slash-bonus values** (`slashBonus7th = 3.5`,
  `slashBonusTriad = 2.5`). See §1.5.

### 1.5 Phase 1 — Slash-chord first-class candidates (SHIPPED)

**Status:** Implemented and validated against the audit corpus (141
chord stacks across 3 representative MusicXML files). Pattern 1 cases
flip to correct outputs; no regressions on Bucket 2 or
currently-correct chords.

#### 1.5.1 The bug it fixed (Pattern 1)

Across the audit corpus, eight cases shared one signature: an inversion
of a major-or-maj7 chord — where the bass is the 3rd, 5th, or b7 of the
actual root — was being identified as an exotic interpretation rooted
on the bass note instead.

| Voicing | Frets | Notes | Was identified as | Should have been |
|---|---|---|---|---|
| `x5533x` | D-G-Bb-D | D, G, Bb | `Daug(11)` | `Gm/D` |
| `ax8aax` | D-Bb-F-A | D, Bb, F, A | `Dmin(b13)` | `Bbmaj7/D` |
| `xx333x` | F-Bb-D | F, Bb, D | `Fsus4(13)` | `Bb/F` |
| `8xaaax` | C-F-A | C, F, A | `Csus4(13)` | `F/C` |
| `7x577x` | B-G-D-F# | B, G, D, F# | `Bmin(b13)` | `Gmaj7/B` |
| `5x355x` | A-F-C-E | A, F, C, E | `Amin(b13)` | `Fmaj7/A` |
| `3x133x` | G-Eb-Bb-D | G, Eb, Bb, D | `Gmin(b13)` | `Ebmaj7/G` |
| `xxaaax` | C-F-A | C, F, A | `Csus4(13)` | `F/C` |

The signature is unmistakable: outputs of the form `Xmin(b13)`,
`Xsus4(13)`, or `Xaug(11)` are essentially never how a jazz reader
would write these chords. They were algorithmic artifacts.

#### 1.5.2 Mechanical cause (the score collision)

Traced on chord #9 of *Days of Wine and Roses* (`ax8aax`, PCs `{D, Bb,
F, A}`):

**D-min iteration (bass-boosted ×4):**
- `min` = `[0,3,7]` → expected `{D, F, A}`. Match: 3/3 exact. Leftover
  `{Bb}`; interval 8 from D = `b13` (in extension table) → unexplained = 0.
- `raw = 3 × 100 - 0 × 50 = 300`. Total < 4, no exact-bonus.
- Bass-boosted: `300 × 4 = 1200`.

**Bb-maj7 iteration (not bass):**
- `maj7` = `[0,4,7,11]` → expected `{Bb, D, F, A}`. Match: 4/4 exact.
  Leftover `{}` → unexplained = 0.
- `raw = 4 × 100 - 0 × 50 = 400`. Total ≥ 4, exact-bonus ×3 → **1200**.

**Both score exactly 1200.** The tie was broken by candidate-root
iteration order, which puts the bass first. D iterated first → reached
1200 → became `bestScore`. Bb reached 1200 later, but the comparison
`> bestScore` is strict, so it lost.

This was **structural, not a tuning issue.** The `bassBoost` (×4) for a
3-note partial match with all extensions explained produced *exactly*
the same score as the `exactBonus` (×3) for a 4-note perfect match. The
two reward structures collided at the same numerical value, and the
algorithm relied silently on enumeration order to break the tie.

The deeper issue: **slash-chord candidates were not enumerated as
first-class scored options.** The slash-chord pathway existed only as a
post-hoc relabeling (Pass 4) — by the time it ran, the algorithm had
already committed to a non-slash interpretation. So `Bbmaj7/D` never
got to compete in the scoring pass at all.

#### 1.5.3 The fix

Inside the Pass 1 scoring loop, after computing `raw`:

```
$bassIv = ($bassPc - $rootPc + 12) % 12;
$isSlashCandidate = !$isBass
    && in_array($bassIv, [3, 4, 7, 10, 11], true)
    && ($matched === $total || $unexplained === 0);

if ($isBass) {
    $score = $raw * $bassBoost;        // ×4
} elseif ($isSlashCandidate) {
    $score = $raw * ($total >= 4 ? $slashBonus7th : $slashBonusTriad);
                                       // ×3.5 for 7-chords, ×2.5 for triads
} else {
    $score = $raw;
}
```

Plus the constants:

```
$slashBonus7th   = 3.5;  // strong: "this is a clean 7-chord with bass-as-chord-tone"
$slashBonusTriad = 2.5;  // moderate: "this is a clean triad with bass-as-chord-tone"
```

#### 1.5.4 Why the chord-tone restriction matters

The interval set `{3, 4, 7, 10, 11}` is **the most important piece of
this fix**. It allows slash-bonus to fire only when the bass is a
**genuine chord tone** of the candidate root (m3, M3, P5, m7, M7). It
*excludes* cases where the bass is an extension (2 = 9, 5 = 11, 6 = #11,
8 = b13, 9 = 13).

This is the line that separates Bucket 1 from Bucket 2:

- `Bbmaj7/D` — bass D is the **3rd** of Bb. Slash-bonus fires. Bug fixed.
- `E7(11)/A` (Wine chord #6) — bass A is the **11th** of E. Slash-bonus
  does **not** fire. Output stays `E7(11)/A`. (An analyst, knowing the
  surrounding `Am7b5 → ... → Gm` cadence in F, would actually call this
  `D7(9#11)/A` — but reaching that conclusion requires harmonic-pattern
  reasoning, which is Phase 2 territory.)

Earlier drafts of the spec proposed a global triad exact-bonus (Option
B) and an explicit tiebreaker (Option C). Both were dropped because
they would have inflated bass-rooted exact triads enough to start
flipping Bucket 2 cases. The chord-tone restriction is more precise: it
encodes the *musical* concept of "the bass is the 3rd/5th/7th of the
real root" rather than relying on score-magnitude tuning.

#### 1.5.5 Why two tiers (the `xx5768` story)

The first implementation of the fix used a single `slashBonus = 3.5`.
This caused a regression on `xx5768` (PCs `{G, D, F, C}`, bass G,
appears 4× in *Wine and Roses*), which had been correctly identified as
`Gm7(11)`:

- **G-m7** (bass-boosted): expected `{G, Bb, D, F}`. Match 3/4
  near-exact, leftover `{C}` interval 5 = `11` ✓ in table. unexp = 0.
  raw = 250. ×4 = **1000**.
- **C-sus4** (slash, bass G = perfect 5th of C): expected `{C, F, G}`.
  Match 3/3 exact. Leftover `{D}` interval 2 = `9` ✓. unexp = 0.
  raw = 300. ×3.5 (slash) = **1050**.

`Csus4(9)/G` edged out `Gm7(11)` by 50. Both readings are technically
valid PC interpretations, but jazz convention strongly prefers the
m7-with-extension reading because:

1. `m7` is a more specific quality (4 expected notes) than `sus4` (3).
   When both are valid readings of the same PC set, the more-specific
   one is the better answer — this is already a principle elsewhere in
   the algorithm (`uasort` sorts qualities longest-first so `maj7`
   beats `maj`).
2. m7 chords commonly carry an 11 in jazz; sus4 chords don't have a
   strong convention around 9s. So "leftover note matches a known
   extension *of this chord type*" is stronger evidence for m7 than for
   sus4.

The fix to the fix: **slash-bonus is two-tiered**. A 7-chord slash
candidate (`total >= 4`) gets `slashBonus7th = 3.5`. A triad slash
candidate (`total <= 3`) gets `slashBonus7th = 2.5`. This encodes:

> "A clean 7-chord with bass-as-chord-tone is decisive evidence
> against a bass-rooted partial read.
> A clean triad with bass-as-chord-tone is suggestive but should not
> override a more-specific 4-tone rooted reading."

Re-traced with the two-tier rule:

- `xx5768`: G-m7 stays at 1000. C-sus4 slash with triad bonus 2.5: 300 ×
  2.5 = 750. **G-m7 wins. Gm7(11) preserved. ✓**
- `x5533x`: D-aug bass-boosted = 600. G-min slash with triad bonus 2.5:
  300 × 2.5 = 750. **G-min wins. Gm/D fix preserved. ✓**
- `ax8aax`: D-min bass-boosted = 1200. Bb-maj7 slash with 7th bonus 3.5:
  1200 × 3.5 = 4200. **Bb-maj7 wins. Bbmaj7/D fix preserved. ✓**

This refinement matters because **it makes the spec self-consistent**:
the existing `uasort` "longest quality wins ties" rule and the new
slash-bonus now cooperate rather than fight. They both encode the same
principle (specificity is preferred), just at different layers.

### 1.6 Phase 2 — Harmonic-pattern awareness (NEXT)

Phase 1 fixed the structural bug. Phase 2 is what elevates the
identifier from "locally correct on each chord" to "musically aware
across a passage." It is the natural next step but was deliberately
kept out of Phase 1.

#### 1.6.1 What Phase 2 fixes

Real edge cases observed in production after Phase 1 shipped, all of
which are **Bucket 2**:

| Voicing | Phase 1 says | Analyst says | Why |
|---|---|---|---|
| `ax8aax` (Wine, was Pattern 1) | `Bbmaj7/D` | `Gm7(9)/D` | The voicing sits between Gm chords; the Bb-as-root reading is locally clean but the *passage* is plainly Gm with the 9 (A) added |
| `xx799a` (Wine #6) | `E7(11)/A` | `D7(9#11)/A` | Sandwiched between `Am7b5` and `Gm` — a textbook ii–V–i in F, where the V slot must be D7 |
| `xx7898` (Wine #7) | `F7(#9)/A` | `D7(b9#11)/A` | Same passage as #6, more altered V7 voicing |
| `xx7978` (Wine #8) | `Am6` | `D7(9)/A` | `Am6` and `D9` are PC-equivalent (`{A,C,E,F#}`); only functional context disambiguates |

All four are "the local pitch content has multiple defensible readings,
and the analyst picks the one that fits the cadential pattern." Phase 1
has no mechanism for that.

#### 1.6.2 What Phase 2 adds (three capabilities)

**1. Cadential pattern recognition.**

After Phase 1 identifies each chord locally, run a second pass that
looks for known cadential fragments in the sequence and *reinterprets*
members when they fit a known pattern. Examples:

- **ii–V–i (minor) detection.** If a chord between `IIm7(b5)` and `Im`
  in the song's key reads locally as something whose PC set overlaps
  significantly with the expected `V7`, prefer the V7 reading.
- **ii–V–I (major) detection.** Same, between `IIm7` and `Imaj7`.
- **Tritone substitution.** `bII7 → I` with tritone-related root motion.
- **Backdoor dominant.** `bVII7 → I`.
- **Deceptive cadence.** `V7 → vi` instead of `V7 → I`.

This builds on the existing `FUNCTIONAL_FRAGMENTS` table — Phase 2
lifts those fragments from "scoring bonuses" to "interpretation rewrite
rules."

**2. Pedal-point detection.**

When the bass note is sustained across N consecutive chord stacks
(N ≥ 3, say) while upper voices change, treat the passage as a single
harmonic unit with a pedal:

- Identify the *upper* harmony of each stack independently (root +
  extensions computed from the non-bass notes alone).
- Display each chord as `UpperChord/PedalBass`.
- Optionally merge consecutive stacks with the same upper harmony into
  a single notation event.

This is what would flip the Wine m. 4–5 passage from `E7(11)/A | F7(#9)/A
| Am6` to `D7(9#11)/A | D7(b9#11)/A | D7(9)/A`. It also explains the
`Bbmaj7/D` → `Gm7(9)/D` case — D held in the bass across surrounding
Gm chords means the upper structure is Gm-with-9, not a separate
Bb-rooted chord.

**3. Key-context-aware enharmonic reinterpretation.**

When two PC-equivalent readings exist (`Am6` ↔ `D9`, `Cmaj7` ↔ rootless
`Em7add9`, etc.), pick the one whose root sits on a diatonic or
strong-functional position in the key. Requires:

- A solid key estimate (the existing `song_key` field is enough).
- A mapping from key + scale-degree to "expected functional role" —
  when do we expect a V7? a IV maj7? a ii m7b5?
- A scoring function that rewards interpretations whose root + quality
  fit the expected role at the current measure position.

#### 1.6.3 Implementation strategy — DB-seeded, hard-coded

The progression DB (`sbn_chord_progressions`, 43 entries) and the
occurrences table (`sbn_progression_occurrences`) together tell us
*which patterns actually matter* in this library. The `progression_id`
referenced most often by `leadsheet_id` is the most-pattern-matched
shape across user content.

**The right approach is a hybrid:**

1. **Once, at design time:** query the occurrences table to find the
   top N (say 8–12) cadential patterns by frequency. Likely candidates,
   based on what the DB seeds already include:
    - `IIm7, V7, Imaj7` — major ii–V–I
    - `IIm7b5, V7, Im` — minor ii–V–i
    - `Imaj7, VIm7, IIm7, V7` — major turnaround
    - `bVII7, Imaj7` — backdoor
    - `IIm7, bII7, Imaj7` — tritone sub
    - `IVm, Imaj7` — minor plagal
    - `I7, IV7, V7` (and 12-bar permutations) — blues
    - `V7, VIm` — deceptive
2. **At code level:** hard-code these patterns as a constant table in a
   new `HarmonicPatternMatcher` service (or extend the existing
   `FUNCTIONAL_FRAGMENTS`). Each pattern has:
    - The Roman-numeral sequence (transposable to any key).
    - A list of "rewrite rules" — given the local Phase 1 readings of
      slot N, what alternative reading should be preferred when the
      slots N±1, N±2 fit the pattern.
3. **Re-seed periodically:** every few months, re-query the occurrences
   table. If the user library has shifted (new genres, new students,
   new content) and a previously-uncommon pattern is now top-10,
   regenerate the constant table.

This avoids the cost of querying the DB at identification time, keeps
the algorithm fast and inspectable, and uses the DB as the source of
truth for *which* patterns matter without coupling the algorithm to
the DB's schema or availability.

**Reading from the DB at identification time is overkill** for the
reasons listed above (per-query overhead, transposition cost,
schema coupling). Don't do that.

#### 1.6.4 Why Phase 2 is separate work

- **Different state space.** Phase 1 operates on a single chord. Phase 2
  operates on a sequence with cross-chord dependencies. Different data
  structures, different search algorithms.
- **Different risk profile.** Phase 2 will sometimes make wrong
  reinterpretations (the analyst-vs-algorithm disagreement is
  irreducible in some cases). It needs an "advisory" UX where the user
  sees both the local and contextual reading and can override.
- **Different audit needs.** Phase 1's audit is "did the algorithm get
  the local chord right?" Phase 2's audit is "did the algorithm get the
  *narrative* right across the passage?" Needs different test fixtures
  — annotated lead sheets with the analyst's reading per chord.
- **Different quality bar.** Phase 1 ships when Pattern 1 is fixed and
  nothing regresses. Phase 2 needs explicit human evaluation against
  analyst-annotated examples before it can be trusted to rewrite
  Phase 1's output.

#### 1.6.5 Out-of-scope edge cases (catalogued, not fixed)

Things visible in the audits that are **explicitly not in Phase 2** (or
any current phase) — these are deferred until they actually matter to
users:

- Sparse 2-note voicings on Barquinho returning `no_identification`.
  These are usually intervals or single notes that aren't really
  chords. Probably fine to fail; could be improved by returning
  "interval" or "implied chord" rather than nothing.
- `aug` over-reach on 2-note voicings (Barquinho has 6 cases). The
  algorithm reaches for `aug = [0,4,8]` to explain things that don't
  have a real triadic interpretation. Not necessarily wrong, but
  visually weird.
- Single-note "stacks" — currently filtered out at audit time (≥2
  notes) but the underlying algorithm doesn't have a graceful
  single-note path.

---

## Part 2: Chord construction (`ProgressionBuilder`)

### 2.1 Status

**Patched-up. Wants a clean rewrite.** This is a different conclusion
from the identifier — please don't conflate them.

The audit (`php artisan sbn:audit-progressions --mode=all`) ran 43
progressions × 3 modes = 129 runs. Findings:

- `group_thrash` (voicing categories switching mid-progression): 50
  cases (39%). The dominant pathology.
- `position_thrash` (adjacent voicings >5 frets apart): 5 cases.
- `high_vl_score` (top-decile transitions): 28 cases.

The 12-Bar Blues run is illustrative: the same `C7` gets three
different voicings across 12 bars, the same `V7` two different
voicings. The forward/backward pass + cross-pool rescue is making the
algorithm *forget* what it already chose.

### 2.2 Why it wants a rewrite, not a patch

Looking at the code:

- The cross-pool rescue at threshold 4.0 is a band-aid over the 4-pool
  funnel. Either trust the lock or don't.
- The harmony filter and the score function penalize the same things in
  different units, with no shared source of truth.
- Magic constants without provenance: `bestDist = 999`, `rescueThreshold
  = 4.0`, `prefer fret 5`, `commonSameString * 1.5`, `commonAny * 0.3`,
  `min(fretDist * 0.4, 3.0)`, `setA === setB ? -1.5 : +3`. Each is an
  answer to a past failure, none documented, none normalized.
- Two entry points (`buildVoicings` vs. `selectVoicingsForSequence`)
  share nothing meaningful. One is sophisticated, one is "first of
  pool."
- Three sequential passes (forward, backward, gap-fill) plus rescue
  plus harmony filter. The actual algorithm is buried under scaffolding.

This file has the unmistakable shape of **incremental patches against
specific failure cases, never consolidated**. A fresh implementation
would be substantially smaller and clearer.

### 2.3 Sketch of the rewrite (separate spec required)

Not implementation-ready, but the design direction is:

- **Single normalized cost function.** Each term in `[0, 1]`, explicit
  weight vector. Add a *simplicity term* (penalize note count +
  extension count) which the current algorithm lacks entirely.
- **Single search algorithm: Viterbi** over the candidate lattice.
  Replaces forward-pass + backward-pass + gap-fill + cross-pool rescue.
  Provably optimal given the cost function.
- **Configuration via a small set of clear options**: `pool` (the
  existing `style` selector), `extensions`, `allowBarres` (new),
  `maxFrettedNotes` (new), `simplicityWeight` (new). No strategy
  hierarchy — just config.
- **A "simple mode" for beginner content** — flat lookup against an
  open-chord curated table. Bypasses the whole algorithm when the user
  asks for `Am, C, G, D` with no extensions and no barres.
- **Integrate with the identifier's harmonic knowledge.** When the
  builder considers extending a triad, it should consult the same
  harmonic-pattern data Phase 2 of the identifier uses, so e.g. the
  V chord in a ii–V–I gets the right alterations and the I chord
  doesn't get an out-of-key #11.

A separate `docs/Builder-Refactor-Spec.md` should be written before any
rewrite is attempted. The progression audit
(`storage/audits/progressions-*.json`) is the regression baseline — no
rewrite ships without showing measurable improvement against it.

---

## Part 3: Audit infrastructure

### 3.1 Why audits exist

Both algorithms are large, both have many edge cases, both have
constants that encode hard-won lessons. Audits are how we **measure
behavior change** across the corpus before and after every modification,
and how we **catalogue failure modes** systematically rather than by
reading bug reports.

**Discipline:** before changing either algorithm, run the relevant
audit. After changing, run it again and diff. If the diff doesn't
match what the change was supposed to do, **stop and investigate** —
either the change is wrong or there's a hidden interaction.

### 3.2 Identifier audit

```
php artisan sbn:audit-identifier "docs/WES MONTGOMERY - The Days Of Wine and Roses.musicxml"
php artisan sbn:audit-identifier "docs/BOSSA - O Barquinho.musicxml"
php artisan sbn:audit-identifier "docs/CHORD MELODY - Nature Boy.musicxml"
```

Each writes a JSON + Markdown pair to `storage/audits/`. The JSON is
diffable (commit hashes from before-and-after fix runs into git for
permanent comparison). The Markdown is human-readable.

The audit:

1. Parses MusicXML, groups notes into chord stacks (≥2 notes per onset).
2. Runs `identifyFromFrets` (Pass 1 only) on each stack.
3. Runs `identifyFromFretsWithContext` (full pipeline) on each stack
   with neighbor chord names.
4. Flags suspicious outputs: `rare_quality`, `exotic_extension` on
   non-dom chords, `context_changed_label` (informational), `no_identification`.

The current 3-file corpus covers jazz octaves (Wine, F major), bossa (G
major), and chord-melody (Nature Boy, D minor) — enough breadth for
most cases. Add new files when you encounter a domain the existing
files don't cover (modal, classical, folk, etc.).

### 3.3 Builder audit

```
php artisan sbn:audit-progressions --mode=all
```

Iterates every progression in `sbn_chord_progressions` × 3 modes
(`default` / `simple` / `jazz`). For each run, captures output voicings
and computes diagnostic flags:

- `position_thrash` — adjacent voicings >5 frets apart
- `group_thrash` — voicing_category changes within a progression
- `high_vl_score` — top decile of adjacent VL scores
- (additional flags planned but not yet active)

Use this as the regression baseline for any builder rewrite. The
acceptance bar for a rewrite: **fewer flags, same coverage, no new
failure categories.**

### 3.4 Where audits live

- Code: `app/Console/Commands/AuditChordIdentifier.php`,
  `app/Console/Commands/AuditProgressions.php`.
- Output: `storage/audits/*.{json,md}`. Timestamped filenames. Keep at
  least the last "known-good" baseline for each algorithm in git (or
  bundled with the relevant PR).

---

## Part 4: Design principles (lessons learned)

These principles emerged during Phase 1 work and apply to all future
work on these algorithms. **Read them before changing anything.**

### 4.1 Distinguish Bucket 1 from Bucket 2

When you find an output you think is "wrong," classify it:

- **Bucket 1: Algorithmic artifact.** No human would defend this output.
  Examples: `Dmin(b13)` for a Bbmaj7/D voicing, `Gm7(11)` morphing into
  three different shapes across 4 bars of the same chord. Fix.
- **Bucket 2: Locally defensible, contextually suboptimal.** The output
  is a valid reading of the local pitch content, but a contextual
  reading would be better. Examples: `Am6` for what's really `D7(9)/A`
  in a ii–V–i. **Don't fix this with a local change.** Either build the
  contextual layer (Phase 2) or leave it alone.

This distinction protects the algorithm from regressions caused by
"helpful" tuning. The chord-tone restriction in the slash-bonus fix is
the pattern: encode a *musical* rule that puts Bucket 1 inside the fix
and Bucket 2 outside it.

### 4.2 Specificity as a first-class principle

Both algorithms encode the principle "more specific interpretations
beat more general ones" but in different places:

- Identifier: `uasort` sorts qualities longest-first (`maj7` beats
  `maj` on tied evidence). The two-tier slash-bonus extends this: a
  4-tone slash candidate decisively beats a 3-tone one.
- Builder (when rewritten): the simplicity term will be the *opposite*
  pull — penalize over-specification when the input doesn't ask for it.
  These two pulls are not in conflict; they apply to different problems
  (identification = "what is this?" wants specificity; construction =
  "what should I play?" wants restraint).

When in doubt, prefer the *more specific* quality match for
identification, the *less specific* voicing for construction.

### 4.3 Tuning vs. structure

When a magic constant produces a wrong output, the temptation is to
nudge the constant. **Resist this** until you've ruled out a structural
fix. The Phase 1 trace showed the bug was a *score collision* (1200 =
1200), broken by silent iteration order — no constant tuning could fix
that without breaking other cases. The structural fix (slash-chord as
first-class candidate) was both more correct and more bounded.

A heuristic: if the bug requires "tune constant X up by Y%," ask "what
would happen at the boundary?" If the answer is "different bugs on the
other side," the structural fix is the right one.

### 4.4 Don't query DBs at hot paths

The progression DB is great as a *source of truth for which patterns
matter*, but reading it during identification or construction would be
slow and tightly couples the algorithm to schema. The DB-seeded
hard-coded approach (§1.6.3) is the pattern: DB at design/seed time,
constants at runtime.

### 4.5 Spec → audit → measure → ship

The cadence that works for these algorithms:

1. **Spec the change** — write down the principle, the proposed fix,
   and the acceptance criteria.
2. **Run the audit before** — capture the baseline.
3. **Implement the change.**
4. **Run the audit after** — diff against baseline.
5. **Verify the diff matches the spec's acceptance criteria.** If it
   doesn't, the spec is wrong, the implementation is wrong, or both.
6. **Ship.**

The Phase 1 work followed this cadence and caught the `xx5768` (Gm7 →
Csus4/G) regression at step 5, leading to the two-tier slash-bonus
refinement. Without the audit, that regression would have shipped.

---

## Appendix A: Phase 1 history

- **2026-04-30**: Three-file audit performed on chord identification.
  Pattern 1 identified across 8 cases. Trace of chord #9 (Wine,
  `ax8aax`) confirmed exact-1200 score collision between D-min
  (bass-boosted partial) and Bb-maj7 (exact 4-note).
- **2026-05-01 morning**: First spec draft proposed three-option fix
  (A: slash-chord candidates; B: global triad exact-bonus; C:
  tiebreaker rule).
- **2026-05-01 afternoon**: User feedback distinguished real bugs
  (Pattern 1, e.g. `Dmin(b13)`) from defensible-but-contextually-wrong
  cases (Wine middle chords, e.g. `Am6` for `D7(9)/A`). Spec scoped
  down to Option A only with chord-tone restriction. Phase 2 sketched.
- **2026-05-01 evening**: Implementation deployed. Validation against
  audit corpus revealed regression on `xx5768` (`Gm7(11)` → `Csus4(9)/G`).
  Two-tier slash-bonus introduced (`slashBonus7th = 3.5`,
  `slashBonusTriad = 2.5`). Re-validated; all Pattern 1 cases fixed,
  no regressions on Bucket 2 or correctly-identified chords.
- **2026-05-01 night**: Doc restructured as full reference for both
  identifier and builder. Phase 2 expanded with DB-seeded hard-coded
  pattern approach and additional motivating examples (`Bbmaj7/D` →
  `Gm7(9)/D` from surrounding Gm context).

## Appendix B: Files this document references

- `app/Services/VoicingCrossref.php` — identification subsystem (lines
  ~1114–2140 within the file). Forward voicing-lookup half is unrelated.
- `app/Services/ProgressionBuilder.php` — construction algorithm.
- `app/Services/HarmonicContext.php` — chord-sequence-from-numerals
  builder used by both audits.
- `app/Console/Commands/AuditChordIdentifier.php` — identifier audit
  command.
- `app/Console/Commands/AuditProgressions.php` — builder audit command.
- `storage/audits/` — audit output directory.
- `database/migrations/2026_03_23_000001_create_progression_occurrences_table.php`
  — schema for the data Phase 2 will seed from.
- `app/Models/ChordProgression.php` — model for `sbn_chord_progressions`.

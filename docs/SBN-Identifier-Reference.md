# SBN Chord Identification — Reference

**Status:** Living document. Phase 1 (slash chords) and Phase 2 (harmonic pattern awareness) shipped 2026-05-06. Phase 3 (evidence layers: DB lookup, key-fit, bigram transitions, Viterbi rescore) shipped 2026-05-14/15. Trigram viewport + second-order Viterbi shipped 2026-07-09; **sub-pass 2g (root-pinned injection) shipped 2026-07-09 and resolved the Ipanema case (§5.4)** — with it, the long-standing invariant *"the contextual layer never promotes a candidate that wasn't in Phase 1's top-K"* no longer holds (§4.1).
**Scope:** The SBN chord identification algorithm (`VoicingCrossref` and `HarmonicContext` services). For chord construction (building voicings from progressions), see `SBN-Builder-Reference.md`.

This document is the reference for what the identification algorithm does, why it does it, what's working, what's broken, and how to evolve it without breaking what works.

If you're touching this algorithm, **read this first**. For known issues and the queue of planned improvements, see **Appendix B**.

---

## 1. What it does

Given a 6-character fret string (e.g., `x5756x`, `0` = open, `x` = muted, hex `a-f` = frets 10–15) and a position marker, produce a chord name plus structured metadata (root, quality, extensions, bass note, inversion, confidence, diagram match if any).

It is used during MusicXML import to label every chord stack the user pastes in, and is exposed via the admin "identify voicings" endpoint for interactive use.

## 2. Underlying principle

The identifier is a **template-matching scoring algorithm with rescue passes and contextual re-ranking**. 

The scoring step iterates every `(root, quality)` pair, scores how well each fits the pitch-class set, and picks the highest. Rescue passes catch known failure modes the main scorer mis-handles by design (rootless voicings, plain triads with jazz interpretations, tritone-implies-dominant). A separate context pass re-scores the result against neighbor chords and the song key.

**This structure is the right structure for the problem. Do not replace it.**

### 2.1 The Pipeline

The full identification pipeline, in order:

| Pass | What it does | Where in code |
|---|---|---|
| 1 | Score all `(rootPc, quality)` pairs. Bass-boost ×4 when root = bass. Exact-bonus ×3 for 4+ tone exact matches. **Slash-bonus** applies when a non-bass root passes the chord-tone test. **DB evidence** multiplies the winning candidate's score (×1.5 bass+root confirmed, ×1.15 bass only, ×0.85 no-DB penalty). | `VoicingCrossref::identifyFromFrets` + `maybeApplyDbEvidence` |
| 3a | Rootless rescue: if Pass 1 found nothing, try interval sets *from an absent root*. | `VoicingCrossref::identifyFromFrets` |
| 3b | Rootless upgrade: if Pass 1 picked a plain triad from 3 PCs, check rootless templates. An **incomplete** triad (partial match) → return the rootless reading (genuine shell rescue). A **complete exact** triad → keep the triad as winner but add the rootless reading as a *candidate* for the context layer to weigh (e.g. `{D,F,A}` stays `Dm`, with `Bbmaj7` as a candidate). Never discards an exact triad — see §3.3. | `VoicingCrossref::identifyFromFrets` |
| 3c | Tritone-dominant rescue: if Pass 1 winner is non-dominant but the PC set contains a tritone, re-score dominant interpretations with bonus ×5. | `VoicingCrossref::identifyFromFrets` |
| 4 | Slash-chord post-detection: if `bestRoot ≠ bassPc`, append `/BassNote` and set `inversion`. | `VoicingCrossref::identifyFromFrets` |
| Context 2a | Pedal-point detector: N≥3 slots sharing bass note → upper-structure re-identified, labeled `Upper/PedalBass`. | `PedalDetector` |
| Context 2b | Diminished-as-rootless-V7(b9): dim7 resolving down a 5th renamed (e.g. `Adim7 → F7(b9)`). | `DiminishedAsDominantResolver` |
| Context 2c | Diminished-root resolver: inversionally symmetric dim7 spelling via step-wise motion to neighbors. | `DiminishedResolver` |

> **Shared dim7 primitive**: both resolvers' symmetric-root math (`[pc,+3,+6,+9]`), the dim7↔dom7(b9) mapping (`domRoot = dimTone − 4`; the dim tone is the dominant's ♭9), and note↔pitch-class conversion now live in `App\Services\HarmonicContext\DiminishedSymmetry` (pure, unit-tested). The identifier keeps its historical **sharp-biased** spelling (`spellSharp`); the chord library uses the same primitive's reading-aware spelling (tight 3/5/7, pragmatic °7+tensions) to generate dim7 pages. See `SBN-Chord-Library-Reference.md`.
| Context 2d | Cadence/fragment matcher: DB-seeded harmonic fragments (`IIm7→V7→Imaj7`, turnarounds). | `HarmonicPatternMatcher` |
| Context 2e | Key-aware enharmonic re-rank + **bigram transition prior** (Phase 3.3a): bumps candidates whose preceding winner predicts them via P(name\|prev) lookup. Greedy + first-order; a *sequential* commitment (§4.1). | `ContextualReranker` |
| Context 2f | **Viterbi sequence rescore** (Phase 3.4a/c): DP min-cost path over all slots simultaneously; second-order (trigram) edges (`edgeWeight=0.3`, `minScoreRatio=0.85` safety rail, plus the strong-trigram and forward warrants). | `ContextualReranker::applyViterbiRescore` |
| Context 2g | **Root-pinned injection** (2026-07-09): constructs a name Phase 1 never emitted, when the *functional* trigram decisively demands it. The only pass that adds to the candidate pool. `rerank()` runs `2f → 2g → 2f`. See §4.2. | `ContextualReranker::injectRootPinnedCandidates` + `VoicingCrossref::identifyWithPinnedRoot` |
| Context key-fit | **Key-fit weighting** (Phase 3.2): soft diatonic preference (×1.0–1.20), never penalises chromatic chords. | `KeyFitWeigher` via `maybeApplyDbEvidence` |

At import time, **key inference** (§5.5) runs *before* the pipeline: a keyless Pass 1 produces local names, `sbnInferKey` derives `song_key` from them, then the pipeline re-runs with the correct key. The MusicXML `<key>` is only a hint.

### 2.2 Load-bearing constants (Do not change lightly)
- The `IDENTIFY_QUALITY_INTERVALS` table, sorted longest-first (`maj7` beats `maj`).
- The `IDENTIFY_EXTENSION_INTERVALS` table mapping leftover pitch classes to named extensions.
- The `IDENTIFY_ROOTLESS_TEMPLATES` table for jazz voicings without a bass root. (In practice only reachable for 3-PC shapes — see Appendix B.1.)
- `ALTERED_TENSION_INTERVALS = [1, 3, 8]` (`b9`/`#9`/`b13`). Only a **dominant** may carry these. Stops a pinned root from laundering its misfit notes as fake alterations (`{Bb,G,Db,F}` pinned to Gb would otherwise yield a formally-legal `Gbmaj7(b9)`).
- The slash-chord chord-tone restriction (intervals `{3, 4, 7, 10, 11}`).
- The two-tier slash-bonus values (`slashBonus7th = 3.5`, `slashBonusTriad = 2.5`). **Note:** the `3.5` tier requires a *complete* match, which a rootless reading can never be — see Appendix B.1.
- Phase 3 tuning knobs: `dbBonus = 1.5` (bass+root confirmed), `dbBonusBassOnly = 1.15`, `noDbPenalty = 0.85`. Key-fit range `[1.00, 1.20]`. Viterbi `edgeWeight = 0.3`, `minScoreRatio = 0.85`, `FORWARD_WARRANT_MIN_EDGE = 2.0`.
- The minimum-note guard (`< 3` unique PCs → `noResult()`), on **both** `identifyFromFrets` and `identifyWithPinnedRoot`. A dyad is not a chord, and pinning a root does not make it one.

### 2.3 Enharmonic spelling — one authority (consolidated 2026-07-09)

The identifier does **not** own a flat/sharp policy. `IDENTIFY_NOTE_SHARP`/`_FLAT`
are lookup tables only; the flat-vs-sharp *decision* for every root/bass it emits
routes through the single spelling authority `HarmonicContext::spellingUsesFlats($songKey)`
(via `pcToNoteName`). `$songKey` — already threaded from the import blade →
`identify-voicings` → `identifyFromPcSetFull` — drives the key family; with no key,
the authority's **flats-by-default** house style applies. `DbVoicingMatcher::preferFlats`
delegates to the same authority. This means "sharps where flats should be" is tuned
in **one place** (`HarmonicContext`), not per-service. Guarded by
`tests/Unit/VoicingCrossrefSpellingTest.php`.

- `pcToNoteName` spells **roots and basses only**, never inner chord tones (the
  quality/extension strings are assembled separately), so it needs only the
  key-family decision, not the per-quality flat-lean.
- The dim7 symmetric-root path (`DiminishedSymmetry::spellRoot`) is a deliberate
  reading-aware carve-out and is **not** routed through `spellingUsesFlats` (§2.1).
- The Tab-import path re-spells the identifier's returned name once more through
  the JS twin `sbnSpellChordName` (belt-and-braces vs. PHP/JS drift), mirroring
  the `<harmony>` path.
- **Authority fix made here:** `spellingUsesFlats` now honours a written flat in
  the key root (`Db`, `Gb`, `Bbm`…) instead of normalising to its enharmonic sharp
  twin (`C#`, `F#`) and mis-reading it as a sharp key. Only sharp-written/natural
  roots consult the `SHARP_KEYS` allowlist. (Mirrored in JS `_keyUsesFlats`.)

**Flat-default is scoped to SYNTHESIS, not stored data.** The identifier builds
names from pitch classes with no author accidental to honour, so no-key → jazz
flats (`Bbm`, `Eb`). But the chord-rule fallback `useFlatsForQuality` (used by the
chord library / voicing serialization to spell *stored* chords) still keeps a
sharp-written root sharp — a genuine `F#m7`/`C#m7`/`D/F#` from a sharp key (D, E, G
major) must keep its DB spelling. **Respect the DB.** So "Bb all the way" holds for
generated names; deliberately sharp-named chords in the DB are preserved.

**Diminished chords spell by resolution DIRECTION** (2026-07-09). `DiminishedResolver`
already detects passing direction; the spelling now follows it:
- **Ascending** passing dim (rises a semitone into the next chord — upward chromatic
  neighbour) → **sharp** (`C#°7` in `C → C#°7 → Dm`). `dim_passing_ascending`.
- **Descending** passing dim (falls a semitone — downward neighbour) → **flat**
  (`Db°7` in `Dm → Db°7 → C`). `dim_passing_descending`.
- **Ambiguous** (common-tone D3, voice-leading-default D4) → **flat** (jazz lean).
- `DiminishedAsDominantResolver`: a dim7 renamed to a rootless dominant `7(b9)`
  spells its root **flat** (`F7(b9)`, never `E#7(b9)`).

Mechanism: `applyResolution(..., bool $useFlats)` picks `spellRoot` (flat) vs
`spellSharp` per direction; both from the shared `DiminishedSymmetry` primitive.
Guarded by `tests/Unit/DiminishedResolverDirectionTest.php`. PHP-only (no JS twin —
dim resolution runs server-side at identify time).

---

## 3. Phase 1: Local Scoring Mechanics

### 3.1 Slash-chord first-class candidates
A historical bug caused exact triads with bass roots (e.g., `Dmin(b13)` instead of `Bbmaj7/D`) to tie with exact slash-chord representations. Because of iteration order, the bass-rooted artifact won. 

To fix this, a **slash bonus** was introduced. It requires the bass to be a **genuine chord tone** of the candidate root (m3, M3, P5, m7, M7). This ensures `Bbmaj7/D` gets the bonus (D is the 3rd), but `E7(11)/A` does not (A is an extension, the 11th). 

### 3.2 Two-tier Slash Bonus
To prevent simpler partial triads (like `Csus4(9)/G`) from beating more specific 7th chords (like `Gm7(11)`), the slash bonus has two tiers:
- **7-chord slash candidate** (`total >= 4` notes) gets `3.5`.
- **Triad slash candidate** (`total <= 3` notes) gets `2.5`.

### 3.3 Pass 3b — rootless upgrade does not discard exact triads

A 3-PC plain triad is enharmonically ambiguous: `{D,F,A}` is both an exact `Dm` triad **and** the rootless 3-5-7 of `Bbmaj7`. Pass 3b previously always `return`ed the rootless reading, discarding the triad — which (a) violated §7 principle 1 (a Bucket 2 contextual choice made unilaterally in Phase 1) and (b) discarded the candidate list before the ContextualReranker ever saw it.

The fix splits on whether Pass 1's triad winner is **complete**:
- **Incomplete triad** (partial match — a tone or the root absent): a genuine rootless shell. Return the rootless reading. Established jazz-shell rescue, unchanged.
- **Complete exact triad** (all 3 tones present): the triad is a legitimate name and stays the local winner. The rootless reading is instead **added as a candidate** (scored `0.95 × winner` — ranks #2, below the exact triad, above Pass-1 partials), so the ContextualReranker can promote it when neighbours/key support it. The triad-vs-rootless call becomes a *contextual* decision, per §7.

**Known limitation**: the rootless candidate can only be injected when its quality exists in `IDENTIFY_QUALITY_INTERVALS` (`m7/7/maj7/m7b5/dim7/m6/mMaj7` — covers the common `Bbmaj7` case). The altered-dominant rootless qualities (`7(#9)`, `7(b9)`, `7(#11)`, `6`) are not representable in the `[rootPc, quality, …]` candidate tuple and are skipped for the complete-triad path. Extending `buildCandidateList` to carry explicit intervals for rootless candidates would lift this — deferred (see Appendix B).

### 3.4 `identifyWithPinnedRoot()` — the progression supplies the root (2026-07-09)

A narrow, well-posed re-entry into Phase 1: *"given these pitch classes and **this**
root, what is the chord?"* With the root fixed the scoring contest collapses and the
answer is forced. Called only by sub-pass 2g (§4.2); additive, no scoring changes.

Sibling of `identifyPhase1Only()`, whose docblock already blessed the idiom —
"used by contextual analyzers to re-identify chords with modified constraints."
`PedalDetector` does the same round-trip with a different constraint (kill the
bass-boost). Wired as a **callable**, not a service: `VoicingCrossref` resolves
`ContextualReranker`, so a direct dependency would be circular.

Accepts a quality when it is complete, **or** when the only missing template tone
is the pinned root, and every leftover is a nameable extension the quality can
actually carry (altered tensions are dominant-only, §2.2). Ranks by most base
tones matched → fewest leftovers → longest-first specificity. Returns `null` when
nothing fits; the caller then leaves the slot alone.

Two guards, both learned the hard way:
- **`< 3` unique PCs → `null`.** Without it, `{C,E}` pinned to A returns `Am/C`,
  hallucinating an A that is not sounding out of a bare 3rd.
- **A root-absent reading needs ≥ 3 sounding template tones** — a genuine `3-5-7`
  shell. Two would name a chord whose root *and* another tone are invented
  (`{E,B,D}` pinned to C ⇒ `Cmaj7(9)/E`, conjuring both the C and the G). The
  Ipanema shells match 3 of 4. Contrast `{E,G,Bb}` + C → `C7/E`, which is a real
  shell and must survive.

Spelling and the `(ext)` rendering delegate to the same helpers the main path uses,
so this cannot drift from the single spelling authority (§2.3). Option tones are
always parenthesised and interval-sorted: `Ebm7(9)/Bb`, never `Ebm9/Bb`;
`(b9,13)`, never `(13,b9)`.

Verified by `tests/Unit/PinnedRootIdentifyTest.php` (dyads refused across all 12
pinned roots × 4 pitch sets).

---

## 4. Phase 2: Contextual Re-ranking

The contextual layer sits entirely above Phase 1. It takes the top-K candidates from Phase 1 and re-ranks them based on neighbor sequence and song key.

**Sub-pass execution order in `ContextualReranker`:**
1. **Pedal-point detector (`PedalDetector`)**: Scans for runs of N≥3 slots with the same bass note, running Phase 1 on the upper structure alone and labeling as `Upper/PedalBass`.
2. **Diminished-as-rootless-V7(b9) (`DiminishedAsDominantResolver`)**: Flags dim7 chords that act as a rootless dominant resolving down a 5th, renaming them (e.g., `Adim7 -> F7(b9)`).
3. **Diminished-root resolver (`DiminishedResolver`)**: Resolves the spelling of inversionally symmetric dim7 chords based on step-wise motion to neighbors (e.g., `Ebdim7` ascending to `Em7`).
4. **Cadence/fragment matcher (`HarmonicPatternMatcher`)**: Uses DB-seeded harmonic fragments (like `IIm7 -> V7 -> Imaj7` or turnaround variants) to correct contextually weak readings.
5. **Key-aware enharmonic re-rank**: Bumps diatonic candidates within a 15% score delta to the top.
6. **Bigram transition rescore (2e)** — greedy, first-order: reweights by `P(curr | previous WINNER)`.
7. **Viterbi sequence search (2f)** — second-order, whole-path.
8. **Root-pinned injection (2g)**, then 2f again — see §4.1.

### 4.1 Commitment classes, and the one pass that constructs (2026-07-09)

**The old invariant — "the contextual layer never promotes a candidate that wasn't
already in Phase 1's top-K" — no longer holds.** It was the right rule for 2a–2f,
all of which *choose* among names Phase 1 emitted. Sub-pass **2g deliberately
breaks it**: it *constructs* a name Phase 1 never produced. See §4.2.

Two related fixes landed together:

**Sequential vs. structural commitments.** `applyViterbiRescore` collapsed the
candidate pool of any slot flagged `reinterpreted = true` to a single entry. But
2d (`cadence`) and 2e (`bigram`) are greedy and first-order — 2e reweights by
`P(curr | previous WINNER)` alone — so a pass seeing one predecessor was vetoing
the pass that sees the whole progression. Commitments are now classified by
`reinterpret_reason`:

| class | reasons | pool behaviour |
|---|---|---|
| **structural** | `pedal`, `dim_*` | collapses to the single winner — asserts a voicing-level fact Viterbi cannot re-derive from transition statistics |
| **sequential** | `cadence`, `bigram` | winner keeps index 0 (pole position for the `$pools[$i][0]` fallbacks) but the alternatives stay reachable |

Classified by reason rather than a per-sub-pass flag, so a future structural pass
is **locked by default** — the safe direction to fail. The promotion loop re-syncs
`name` to the chosen path, so the display/path consistency the collapse protected
is preserved anyway.

**Forward warrant.** The existing strong-trigram override needs two predecessors
(`$i >= 2`), so slots 0 and 1 can never earn it — and slot 0 is exactly where a
bass-alternation voicing hides. A forward-looking warrant now consults the pool
against the slot's two *successors*, on the strict functional tier, with the same
relative margin as 2g (`FORWARD_WARRANT_MIN_EDGE`).

### 4.2 Sub-pass 2g — root-pinned injection

`seed = -log(local_score)` treats a Phase-1 score as evidence *about the notes*.
Between rival readings of **identical pitch classes** it is not: `Bbm6` outscores
`Eb7(9)/Bb` 7200:1062 purely because its root sits in the bass (`bassBoost ×4 ·
exactBonus ×3`). That 1.91 of seed advantage swamps the 0.79 the trigram returns
through `edgeWeight = 0.3`, so the search never puts the II7 on the path — though
the functional trigram prefers it **sevenfold** (0.754 vs 0.054).

2g runs *after* 2f (it needs settled downstream context; before 2f the downstream
slots carry the greedy passes' mistakes, which mostly do not place as numerals at
all). For each of the 12 roots it asks `VoicingCrossref::identifyWithPinnedRoot()`
for the forced spelling, scores survivors against the slot's two **successors**
(the slot sits in the `prev2` position, so no settled predecessor is needed), and
injects the winner. `rerank()` then alternates `2f → inject → 2f`. Append-only,
hence monotone and terminating; capped regardless.

**The gate is relative, never absolute.** A "beats `k × uniform`" bar is *vacuous*
here: the functional vocabulary is ~115 tokens, so `5 × uniform ≈ 0.043` and even
a plainly wrong reading clears it (`P(Imaj7 | IVmaj7, V7) = 0.19`, i.e. 21×
uniform). What separates truth from plausibility is beating **the incumbent on the
same context**: `IIm7` 0.556 vs `IVmaj7` 0.188, a 2.95× edge.

**Functional tier only.** `TransitionScorer::functionalTrigramProbability()` exists
because `trigramProbability()` silently backs off to a plain bigram and hides that
it did so, which would make the guard meaningless. It returns `null` for an unseen
context — **`null` means "no evidence", never "weak evidence".**

---

## 5. Phase 3: Evidence Layers (shipped 2026-05-14/15)

Phase 3 extends the identifier from a local template-scoring algorithm into an **evidence-layered system** by wiring three additional signal sources into the score: DB-grounded shape matching (Layer 1), key-fit reasoning (Layer 2), and harmonic-transition probabilities (Layer 3).

### 5.1 Layer 1 — DB shape lookup (`DbVoicingMatcher`)

The 172+ filed shapes in `sbn_chord_diagrams` are treated as human-attested evidence. For each incoming voicing, the matcher transposes each candidate DB shape across all 12 roots and checks PC-set + bass-PC equivalence with the target.

**Scoring contribution** (soft contributor, not hard pre-empt):

| Condition | Score multiplier |
|---|---|
| Bass-confirmed match AND root = bass AND pop ≥ 1 | ×1.5 |
| Bass-confirmed match only | ×1.15 |
| Pass 1 winner has **no** DB match anywhere | ×0.85 |
| No DB evidence at all | ×1.0 (no change) |

The soft design was validated by a shadow dump across 423 baseline chords: 71% had a bass-confirmed DB match, but ~30% of those would have overridden Pass 1 toward a *less specific* name (the DB's cold-start gap). A hard pre-empt would have caused regressions.

**Two DB-schema traps** (legacy WordPress data):
1. `root_note` is not always the chord root — derive the true root from `interval_labels` by locating `'R'` and reading that string's pitch from `diagram_data`. Fall back to `root_note` only when `interval_labels` is empty.
2. The `inversion` column ('inv1'/'inv2'/'inv3') is not always consistent with the shape's actual lowest pitch. Always derive slash-bass from the transposed bass PC of the diagram, not from the nominal inversion label.

**String-numbering:** DB diagrams use **string 1 = low E, string 6 = high E** (index 0 = string 1 in the fret string). This matches the audio adapter convention but is easy to invert accidentally.

### 5.2 Layer 2 — Key-fit weighting (`KeyFitWeigher`)

A soft, jazz-tolerant preference: multiplies each candidate's score by a weight based on its root's scale-degree relationship to `$songKey`. Weights range 1.00–1.20 — never below 1.0, so chromatic chords are never penalised.

| Root relation | Weight |
|---|---|
| I, IV, V | 1.20 |
| ii, iii, vi | 1.15 |
| vii° | 1.10 |
| Secondary dominant (V/x, x diatonic) | 1.10 |
| bVII, bVI, bIII (modal mixture) | 1.05 |
| Tritone sub of diatonic dominant | 1.05 |
| Other chromatic | 1.00 |

Dominant 7 chords get the secondary-dominant reading first (functional precedence over interval-degree precedence). Minor keys use their own natural-minor diatonic set.

### 5.3 Layer 3 — Harmonic transition priors

#### Sub-pass 2e: surface bigram (`TransitionScorer`)

The transition tables are built from 1,382 jazz standards (oliphant/iReal Pro, 48,930 bigrams incl. self-transitions; 47,548 surface trigrams; 39,163 functional trigrams over the 1,149 keyed standards). Seeded via `php artisan sbn:reseed-transitions` to `storage/app/harmonic-transitions.generated.php`.

> **NB (2026-07-09):** the doc says this file "must be committed", but it is
> currently **gitignored** (same as `harmonic-fragments.generated.php`) — so it
> does NOT travel with a commit; a fresh checkout must run `sbn:reseed-transitions`.
> Reconcile the policy: either drop the ignore + force-add both generated files,
> or keep them local and document the reseed-on-checkout requirement. Unresolved.

Multiplier curve: `1 + 0.7 × log10(P / uniform)`, clamped to [0.4, 3.0]. A transition 5× more common than uniform yields ≈×2.0 promotion.

Backoff chain: `Db6(9)/Ab → Db6/Ab → Db/Ab → Db`. Searches all (prev, next) backoff combinations for the highest-probability hit before falling back to Laplace smoothing.

#### Sub-pass 2f: Viterbi sequence rescore (`applyViterbiRescore`)

Runs a min-cost Viterbi DP across all slots simultaneously:

```
cost[i][k] = min_j( cost[i-1][j] + edgeWeight × -log(P(cand_k | prev_j)) ) + -log(score_k)
```

- `edgeWeight = 0.3` — seed costs dominate; a weak-transition chain can't override strong local evidence.
- `minScoreRatio = 0.85` — Viterbi winner must score ≥85% of the current slot winner. Tie-breaking only, EXCEPT the strong-trigram override below.

#### Sub-pass 2f is second-order (Phase 3.4c): trigram viewport

`applyViterbiRescore` is a **second-order** Viterbi: the DP state is a pair
`(prev-slot cand, curr-slot cand)`, so each edge scores the **trigram**
`P(curr | prev2, prev1)` instead of a bigram. This widens the viewport enough to
see functional units like **II7→V7→I** as a whole — a bigram can only ever see
one predecessor per edge and cannot represent a three-chord shape.

`TransitionScorer::trigramProbability($prev2, $prev1, $curr, $songKey)` backs off:

1. **functional trigram** — key-relative numeral triple (e.g. `II7|V7|Imaj7`),
   so every II-V-I in any key reinforces the same entry. The functional token
   comes from `ProgressionDetector::chordToNumeral` with the **slash-bass degree
   stripped** (a pedal/inversion bass doesn't change a chord's function, and the
   iReal corpus is slash-less — keeping `/VI` makes every pedal voicing miss the
   table). Requires `song_key`.
2. **surface trigram** — exact chord-name triple.
3. **surface bigram** — the original first-order behaviour (§2e), the floor.

Both trigram tables are emitted by `sbn:reseed-transitions` alongside the bigram.

**Strong-trigram override:** because every candidate for one slot describes the
*same* pitch classes, a large Pass-1 score gap between two candidates is a
voicing-position artifact (root-in-bass bonus), not evidence about the notes.
When the promoted reading's in-context trigram is decisive (≥5× uniform), the
`minScoreRatio` and slash guards are bypassed for that slot — so a strong
functional trigram *can* override a locally-dominant but functionally-wrong
reading. This is the mechanism intended to resolve Ipanema chord 2 (§5.4).

### 5.4 Enharmonic PC ambiguity — RESOLVED (2026-07-09)

The Ipanema chord 2 case (`6x566x`): the PC set `{Bb, G, Db, F}` is genuinely
ambiguous between `Bbm6` and `Eb7(9)/Bb` — same pitches, both valid names. Long
documented as an architectural hard limit, then as a candidate-generation gap.
**It is neither. The full B-part now resolves end-to-end:**

```
6x566x Eb7(9)/Bb  →  6x466x Ebm7(9)/Bb  →  5x456x Ab7(b9,13)/A  →  4x334x Db6(9)/Ab
   [II7]                 [IIm7]                  [V7]                   [Imaj7]
```

Pinned by `IdentifierSequenceCases::TIER1_TRIGRAM` (`want_name`, exact spellings).

Three distinct defects had to fall, in order — and the fix was **not** where the
docs had predicted:

1. **The greedy sub-passes vetoed Viterbi** (§4.1). 2d/2e were collapsing the pool
   before the second-order search ran. Fixing this alone corrected slots 2 and 3.
2. **The name did not exist.** `6x466x` `{Bb,Gb,Db,F}` is an exact, *complete*
   `Gbmaj7/Bb` in isolation (Phase 1 scores it 6300, and is right to). Its
   `Ebm7(9)` reading scores 735 and is cut by the top-5 slice. Since every other
   Phase-2 mechanism only *chooses* among Phase-1 names, the reading the
   progression needs did not exist by the time the progression was understood.
   Hence 2g: **construct** it (§4.2).
3. **The seed artifact** blocked slot 0 even with the candidate present (§4.2).

**Why this voicing is not "rootless" in the jazz sense.** `6x466x` is
`5 – b3 – b7 – 9` over a `Bb` bass: a **shell with the 5th in the bass**. João
Gilberto alternates his bass between R and 5; on the 5 beat the root simply is not
sounding. Its identity is a property of the *bar*, not of the shape — unlike Jim
Hall's `xx b3 b7 9 x`, which omits R **and** 5 as a voicing choice. No amount of
widening `IDENTIFY_ROOTLESS_TEMPLATES` recovers it, because the information is not
in the pitch classes at all. This is why the progression must supply the root and
Phase 1 only supplies the spelling.

---

### 5.5 Key inference at import (shipped 2026-05-21)

Relative keys (C major / A minor) share a key signature, and most MusicXML
exports omit the `<mode>` element — so the notation alone cannot tell which
key a piece is in. Before this stage, `fifths=0` always mapped to `C`, and
every relative-minor import got the wrong `song_key`. Since `KeyFitWeigher`,
the bigram scorer, and Viterbi all consult `song_key`, a wrong key biased the
entire context layer toward the wrong tonal centre.

**`sbnInferKey`** (`public/js/sbn-key-inference.js`) recovers the key from
*functional* evidence and runs on **every import**, treating the MusicXML
`<key>` as a weak prior, not the source of truth.

**Two-pass import flow** (`identifyTabVoicings` in `admin/leadsheets/edit.blade.php`):

1. **Pass 1 — keyless.** `identify-voicings` is called with `songKey: null`,
   so the harmonic context cannot bias names toward the wrong centre. Pure
   local + Phase-1 scoring.
2. **`inferKeyFromChords()`** scores all 24 keys from the Pass-1 names:
   - **PC-profile correlation** — duration-weighted pitch-class histogram
     correlated against Krumhansl–Schmuckler major/minor profiles. Backbone score.
   - **Leading-tone bonus** — a minor key behaving tonally raises its 7th
     degree (G♯ in A minor). This is the decisive signal for relative-key
     disambiguation; profile correlation alone leaves C / Am / Em near-tied.
   - **Endpoint bonus** — first/last chord matching a candidate tonic.
   - **XML hint bonus** — a small prior for the `fifths`/`mode` reading, so
     genuinely ambiguous pieces defer to notation but strong functional
     evidence still wins.
3. **Pass 2 — keyed.** `identify-voicings` re-runs with the inferred key.

The inferred key pre-fills the editable Key field with an "inferred ·
{confidence}" hint (evidence in the tooltip). A human can override before save.

**Window-able by design.** `sbnInferKey(chords, opts)` takes a flat
`{name, pcs, durationBeats}` list and `useEndpoints`. A future
modulation-detection pass calls the same scorer over sliding windows and
segments the resulting key timeline — see Appendix B.2.

## 6. Audit Infrastructure

Always run audits before and after changing these algorithms to measure behavior change and catalog failure modes.

### 6.1 Commands
- `php artisan sbn:audit-identifier <file.musicxml>` (Runs local Pass 1)
- `php artisan sbn:audit-identifier-context <file.musicxml>` (Runs full Phase 2 pipeline)
- `php artisan sbn:audit-identifier-sequences` (Runs the curated `IdentifierSequenceCases` progressions through Phase 1+2 and prints the resolved name + `reinterpret_reason` per slot. **The verification loop for §4.1/§4.2** — confirm by ear/theory, then pin `expected`/`want_name` in the fixture.)
- `php artisan sbn:reseed-transitions` (Rebuilds the bigram/trigram tables. Run after corpus changes; 2g and the trigram warrants read `functional_trigrams`.)

Output goes to `storage/audits/`. **Do not commit these files** (except for frozen baselines in `tests/fixtures/identifier-context/baseline/`).

### 6.2 Test Fixtures
The core audit corpus resides in `docs/*.musicxml` and their expectations are encoded in `tests/fixtures/identifier-context/*.expected.json`:
- **Wine** (`WES MONTGOMERY...`): Covers pedal points, ii-V-i functional re-ranking.
- **Misty** (`BARNEY KESSEL...`): Covers ascending diminished passing chords.
- **Easy Living** (`GEORGE BENSON...`): Turnaround variants with dim subs, key-aware re-rank.
- **Shadow of Your Smile** (`JOE PASS...`): Descending diminished passing chords.

### 6.3 Regression suites (the guardrail)

Run these together — they are the contract for any change to Phase 1 or Phase 2:

```
vendor\bin\phpunit tests/Unit/IdentifierRegressionTest.php \
                   tests/Unit/IdentifierSequenceTest.php \
                   tests/Feature/Identifier \
                   tests/Unit/PinnedRootIdentifyTest.php
```

- `IdentifierRegressionCases` — single-chord identification, three tiers
  (mechanical / verified-voicing / context-dependent), each with a `why`.
- `IdentifierSequenceCases` — whole progressions through the real pipeline.
  `TIER1_TRIGRAM` pins Ipanema's exact spellings via `want_name`.
- `PinnedRootIdentifyTest` — §3.4, including dyad refusal across all 12 roots.

**Honesty contract:** a slot is asserted only once its resolution is human-verified
via `sbn:audit-identifier-sequences`. Unverified slots carry `expected = null` and
are printed, never frozen — the suite must never enshrine an unverified reading as
ground truth.

Current: **68 tests / 6015 assertions**, no skips.

> Some pre-existing failures elsewhere in the repo are unrelated to the identifier
> (beta auth-gate `302`s in `LibraryRelatedSongStyleSlugTest`/`ChordShowEduTest`, a
> missing `madd9` edu topic, `RhythmMaterializerTest`). Check against a clean tree
> before attributing them to an identifier change.

---

## 7. Design Principles

1. **Distinguish Bucket 1 from Bucket 2**:
   - *Bucket 1 (Algorithmic artifact)*: No human would defend this output (e.g., `Dmin(b13)`). Fix these with structural local logic.
   - *Bucket 2 (Locally defensible, contextually suboptimal)*: Output is a valid reading locally, but a contextual reading would be better (e.g., `Am6` instead of `D7(9)/A`). Fix these *only* in the ContextualReranker, never by hacking local Phase 1 thresholds.
2. **Specificity**: Prefer the *more specific* quality match for identification (`maj7` beats `maj`).
3. **Tuning vs. Structure**: Nudging magic constants often causes whack-a-mole bugs. Prefer structural solutions (like checking if a note is a true chord tone).
4. **DB at seed time, constants at runtime**: Do not query the database during identification. Harmonic fragments are compiled via `php artisan sbn:reseed-fragments` to `storage/app/harmonic-fragments.generated.php`. Bigram transitions are compiled via `php artisan sbn:reseed-transitions` to `storage/app/harmonic-transitions.generated.php`. Both files must be committed.
5. **Layer 3 is tie-breaking, not overriding — *except* where the local gap is an artifact.** The `minScoreRatio = 0.85` Viterbi guard remains load-bearing for the general case: a *bigram* must not override a large local-evidence gap, only break ties between roughly-equal candidates.

   But the gap itself must be interrogated. When two candidates describe the **same pitch classes**, a large Phase-1 spread is not evidence about the notes — it is a voicing-position artifact (`Bbm6` beats `Eb7(9)/Bb` 7200:1062 purely on `bassBoost ×4 · exactBonus ×3`). Overriding *that* is not a violation of this principle; it is the principle applied correctly. The escape hatches are deliberately narrow: a **decisive functional trigram** (never a bigram — `functionalTrigramProbability()` exists precisely so a backoff cannot masquerade as evidence), and a **relative** margin over the incumbent on the same context (an absolute `k × uniform` bar is vacuous — see §4.2).

6. **`null` is not a small number.** In the evidence layers, an unseen context returns `null`, meaning *no evidence*. Never coerce it to a low probability and compare it against a threshold; that silently converts ignorance into a weak vote.

7. **A pinned root must explain the voicing, not absorb it.** Altered tensions (`b9`/`#9`/`b13`) are dominant-function tones. Without that restriction a wrong root launders its misfit notes as fake alterations and produces formally-legal garbage (`Gbmaj7(b9)`, `Eo7(b9)`) that then competes with the true reading.

8. **A dyad is not a chord, and pinning a root does not make it one.** Both `identifyFromFrets` and `identifyWithPinnedRoot` refuse `< 3` unique PCs. A root-absent reading additionally needs ≥ 3 sounding template tones — a real `3-5-7` shell — or it invents more than the one tone jazz voicings legitimately omit.

---

## Appendix A: Workflow Gotchas

- **Fragment file is generated**: `storage/app/harmonic-fragments.generated.php` is generated by `php artisan sbn:reseed-fragments`. It **must be committed to git**. DB edits do not auto-update fragments.
- **Transitions file is generated**: `storage/app/harmonic-transitions.generated.php` is generated by `php artisan sbn:reseed-transitions`. It **must be committed to git**. The corpus is iReal Pro chord strings from `sbn_jazz_standards.chord_string`. Self-transitions are included (omitting them causes Viterbi to prefer non-repeating paths, a systematic bug for ostinato progressions).
- **Phase 1 `bass_note`**: `bass_note` must be populated on every slot. PedalDetector and DiminishedResolver both rely on it.
- **DB `root_note` is not always the chord root** (§5.1): derive root from `interval_labels` by locating `'R'`; fall back to `root_note` only when `interval_labels` is empty. Several legacy rows have `root_note = 'C'` (a positional tag) while the actual root is in the labels.
- **DB `inversion` column is not always reliable** (§5.1): always derive slash-bass from the transposed bass PC of the diagram data, not from the stored inversion label.
- **MusicXML Parsing**: The XML parsing runs in JavaScript (`resources/views/admin/leadsheets/edit.blade.php`), which then batches `Tab` voicings to `POST /api/admin/leadsheets/identify-voicings`.
- **Intra-bar fragment merge** (tab-path bars): `_mergeFragmentVoicings` runs per bar before identification. Consecutive voicing events whose fret strings overlay *without collision* are fragments of one held grip; such a run collapses to one voicing event. Conservative: within-bar only, adjacent only, gated to runs containing a thin (≤2-note) fragment, fret-span capped. A collision (a voice re-fretting a string) blocks the merge — the melody guard.
- **Harmony/notes mismatch correction** (`<harmony>`-path bars): a bar with an explicit `<harmony>` symbol takes the harmony path, *not* the tab path — its written chord name is often under-specified or wrong relative to the notated voicing (Maria Luisa bar 8: written `D5`, notes `{D,F,A}` = `Dm`; bar 14: written `Bm7`, notes `{B,D#,F#,A}` = `B7`). `_flagHarmonyNoteMismatches` collects a harmony slot's chord tones — but **not every note under a harmony is a chord tone**: a single-line scale/arpeggio passage is a melody, not a chord. A slot's notes qualify as a chord only via **(a) simultaneity** — a tick carrying ≥2 notes (a MusicXML `<chord/>` stack) — or **(b) arpeggiation** — a run where every adjacent interval is ≥3 semitones (a 3rd+; stepwise ≤2 = melodic line, disqualifies). A slot with neither is skipped. The qualifying notes' PC set + lowest pitch (bass) is rendered to a representative synthetic fret string (`_pcSetToFretString`, built against `VoicingCrossref::TUNING`, bass at index 0 — *not a playable diagram*, just a PC carrier). The async `detectHarmonyMismatches` identifies those and, on a **same-root quality mismatch**, either: (a) **auto-corrects** the name when the notes identify `exact` (ground truth, overrides the shorthand even on a 3rd conflict `Bm7`→`B7`); or (b) **flags** `chord._harmonyMismatch` when only `partial`. Reported in the persistent import-summary panel.
  - **String-numbering** (the documented footgun): reading MusicXML `<string>` uses 1 = high E … 6 = low E (`_OPEN_PC`). The synthetic fret string uses `TUNING = [4,9,2,7,11,4]`, index 0 = low E. These are *reverse* — keep them distinct.
- **Legacy Leadsheets**: Existing leadsheets identified before May 2026 are not automatically re-identified. You must use the editor's "identify voicings" button.

---

## Appendix B: Known Bugs & Future Improvements

### B.1 Known Bugs

- ~~**Pass-1 absent-root scoring**~~ — **FIXED 2026-07-09.** Absent-root candidates
  now score `×0.98` when the voicing is a legitimate rootless shape (only the root
  missing, every leftover a named extension) and `×0.35` otherwise, so a
  coincidental wrong-root map can no longer outrank the contiguous reading. The
  `pending => 'pass1-absent-root'` cases are un-skipped and passing. Ipanema is
  resolved (§5.4), though *not* by this fix alone — see §4.1/§4.2.

- **Rootless slash readings are stuck on the triad tier (MEDIUM, open):** the
  two-tier slash bonus (§3.2) grants `3.5` only when `total >= 4 && matched ==
  total`. A legitimate rootless reading is **by definition never complete**
  (`matched == total - 1`, the root being the omitted tone), so *every* rootless
  slash reading silently falls to the `2.5` triad tier. Both Ipanema shells score
  `300 × 0.98 × 2.5 = 735` and would be cut by the top-5 slice; `Eb7(9)/Bb` only
  survives because Pass 3c's tritone bonus (×5) happens to lift it — a rescue that
  applies to **dominants only**, so rootless `m9`/`maj9` readings still fall
  through. *Fix direction:* let a `$legitRootless` reading take the `3.5` tier.
  Deliberately not bundled with the 2g work — it shifts scores globally. 2g does
  not depend on it (it constructs rather than promotes), but fixing it would make
  more rootless readings reachable without injection.

- **`IDENTIFY_ROOTLESS_TEMPLATES` is dead for 4-note jazz voicings (LOW, open):**
  `identifyRootless()` hard-returns unless there are **exactly 3** pitch classes,
  every template in the table is a 3-note shape (no `m9`/`9`/`maj9`), Pass 3a only
  fires when Pass 1 found *nothing*, and Pass 3b only on **plain triads** with
  `<= 3` notes. So the dedicated rootless subsystem never sees a real 4-note jazz
  voicing; it is in practice a 3-note-shell rescue. Left as-is on purpose: the
  Ipanema voicing is *not* rootless (§5.4), so widening this would encode the
  wrong concept. Revisit only if a genuine Jim-Hall-style `xx b3 b7 9 x` (root
  **and** 5th omitted) needs identifying.
- **Regression suite rebuilt (2026-07-09):** the old `VoicingCrossrefSlashChordTest` had frozen expectations from 2026-05 that had drifted — and several were **musically wrong for their input**, discovered by computing the pitch classes rather than trusting the frozen name. Canonical example: `0xx558` = **{C,E}** (a two-note dyad) was frozen as `Eaug`, but {C,E} contains no G#, so it cannot be `Eaug` (nor `C/E` — no G). The "expected `Eaug`" was the bug, not the identifier. Fixes shipped:
  - **Dyad guard on `identifyFromFrets`**: a fret voicing with < 3 unique pitch classes now returns `noResult()` (symmetric with `identifyFromMidi`). Refuses to hallucinate a missing third tone into a triad. Retires the contradictory `x0x225`/`xxx225` (`{A,C#}`→`A`) and `x31xxx` (`{C,Eb}`→`Cm`) expectations — those also over-named dyads.
  - **New verified suite** `IdentifierRegressionTest` + `IdentifierRegressionCases` (three tiers: mechanical / verified-voicing / context-dependent). Every expectation is computed + human-verified with a `why`. This is now the source of truth; `VoicingCrossrefSlashChordTest` is narrowed to slash-name display formatting only.
  - `xx333x` `{D,F,Bb}`→`Bb/F` and `x9799x` `{E,F#,G#,A}`→`F#m7(9)` are Tier-1/Tier-2 verified. `x3338x` `{C,F,G,Bb}`→`C7sus4` is Tier-2 (see the `7sus4` bug below — currently returns `Cm7(11)`, so this Tier-2 case fails until that Pass-1 fix lands).
  - `xx799a` `{D,E,G#,A}` is **genuinely unsolvable without context** (`E7(11)/A` vs `Asus4(maj7)` both defensible) → Tier-3, tested for stability only, never a pinned name.
- **Slash chord over-eagerness on `7sus4` shapes**: Voicings like `x3338x` = {C,F,G,Bb}/C name as `Cm7(11)` (or slash chords) instead of `C7sus4`. *Attempted & reverted (2026-07-02):* adding `'7sus4' => [0,5,7,10]` to `IDENTIFY_QUALITY_INTERVALS` (+ extending the sus 3rd-guard) did NOT fix it — a partial `Cm7` match (4th read as `11`) still out-scores the exact `7sus4` in Pass 1, for reasons not fully traced without a running PHP env. The interval-table entry alone is insufficient; the real fix needs a Pass 1 scoring change (why does a partial-3-of-4 minor beat an exact-4-of-4 sus?). Guarded by `IdentifierRegressionCases::TIER2_VERIFIED` (`x3338x`).
- **`noteNameToPc` maps unknown to 0**: `PedalDetector` and the diminished resolvers treat unrecognized note strings as C (0). This is a latent bug if Phase 1 ever emits non-standard names. *Fix shape:* Return `null` and skip gracefully. (Note: the two diminished resolvers now delegate `noteNameToPc`/`pcToNoteName`/symmetric-root math to the shared `App\Services\HarmonicContext\DiminishedSymmetry` primitive — fixing it there fixes both at once.)

### B.2 Planned Improvements

**Phase 2 follow-ups:**
- **Add `IIIm7b5 → VI7 → IIm` as Tier 1 fragment**: A common secondary ii-V to ii (used in *Wine and Roses*). Add it to `sbn_chord_progressions` and reseed fragments.
- **Bass-as-third heuristic for `DiminishedAsDominantResolver`**: Pick the dominant root whose major third matches the original dim7 bass note.
- **Dim7 disambiguation in isolated slots**: When a dim7 has no useful neighbors, prefer the spelling whose root matches the bass (e.g., `Adim7` over `Cdim7/A` when bass is A).

> **Note — dim7 in `ProgressionDetector` vs. identifier:** The identifier's dim7 pipeline (resolvers above) renames dim7 chords *for display* (e.g. `Adim7 → F7(b9)`). `ProgressionDetector` runs its own independent `resolveDominantDim7s` pass for progression matching and deliberately keeps a **pre-resolution stream** so dim7-containing progression patterns still find their raw tokens. The two pipelines are parallel — a fix in the identifier's `DiminishedAsDominantResolver` does not automatically affect the detector. See `SBN-Progression-Library-Reference.md §8` for the detector's dim7 matching design (pre-resolution stream routing, enharmonic degree equivalence, quality-family matching).
- **Tonicization detection**: Detect temporary tonicizations and lift the local key for fragment matching.
- **Cross-pedal pattern matching**: Handle isolated pedal slots where `PedalDetector`'s N≥3 rule doesn't fire.

**Phase 3 follow-ups:**
- **Functional bigram (Phase 3.3b)**: Extend `sbn:reseed-transitions` to also emit numeral-keyed bigrams. `TransitionScorer` consults functional bigram first (stronger generalization across keys), falls back to surface bigram. Requires reliable `song_key` per standard.
- **HMM with latent key state (Phase 3.4b)**: Viterbi over `(chord_label × hidden_key)` states to handle modulations and tonicizations natively. Structurally identical to `ProgressionBuilder::viterbiSelect` (Phase D builder) — see `SBN-Builder-Reference.md` §10 for the proven inference pattern. A shared `App\Services\Inference\ViterbiSearch<TState, TObservation>` is the right abstraction once both call-sites exist.
- **Expected-chord priors (B.2 original)**: Feed `expectedChords` from a reference leadsheet into `ContextualReranker` to resolve enharmonically ambiguous PC sets (e.g., Ipanema chord 2: `{Bb, G, Db, F}` is valid as both `Bbm6` and `Eb7(9)/Bb` — only an external expected-chord anchor can break the tie, see §5.4).
- **Modulation detection**: Extend §5.5 key inference from one whole-piece key to a *key timeline*. `sbnInferKey` is already window-able (`useEndpoints: false` for windows); the pass calls it over sliding windows of the chord list, then segments the timeline (cost-based, à la Viterbi) into key regions. Per-region keys then feed `KeyFitWeigher`/bigram per slot instead of one global key. Structurally this is the import-time precursor to the HMM-with-latent-key idea (Phase 3.4b).
- **`mappingsByPosition` tests for bigram corpus**: AABA fixture, "more marks than passes", mark for a gi not in the sequence.
- **Diatonicity check deduplication**: The diatonic-candidate test in `ContextualReranker` and `ProgressionBuilder` share the same logic; extract to a shared utility.
- **Rootless candidates with explicit intervals**: `buildCandidateList` derives a candidate's `basePcs` from `IDENTIFY_QUALITY_INTERVALS[$quality]`, so it can only represent rootless qualities present in that table (§3.3). To also surface `7(#9)`/`7(b9)`/`7(#11)`/`6` rootless readings as candidates, let a candidate optionally carry an explicit interval set + `rootless` flag, and have `buildCandidateList` use it instead of the table lookup. Touches the candidate-tuple model.

**Open tasks — partial voicings & slash chords (raised 2026-07-09):**

- **One-tone-omission rule for partial voicings (MEDIUM, open).** Classical/fingerstyle
  music routinely picks 3 of 6 strings, so a 4-tone quality arrives with only 3 pitch
  classes. `matchVoicing`'s `isSubsetMatch` already handles "leadsheet omits strings the
  library shape has" — but it is *name-driven*, so it only fires once the chord name has
  retrieved the right shapes. The gap is in the **identifier**, where a fragment must be
  scored without a name.

  Measured over the 53 pending `sbn_voicing_drafts` (2026-07-09): **37 are complete chords**
  — they carry every tone their quality requires and fail for shape-lookup or
  missing-library reasons (`F xx333x`, `F# xx444x` are closed triads on inner strings: a
  `closed_triads` library gap, not a subset problem). Only **12 are true fragments**
  (`Gm7/D x5533x` = {D,G,Bb}, 7th dropped; `C#m7 x4245x`; `Bbm7b5 xx899c`; `G6 xx5450`).
  Scope the feature accordingly — it is much narrower than "match partial chords".

  *Proposed rule:* allow a candidate whose **only** missing template tone is the root **or**
  the 5th, on 7th-chords, with `$unexplained === 0`. This is a direct widening of the
  existing `$legitRootless` gate in Pass 1 (§3.1, `VoicingCrossref` ~L1720) — which already
  encodes "the root is the one tone jazz voicings legitimately omit" — to the 5th, the other
  tone players actually drop. It can never hallucinate a 3rd (the quality) or a 7th (the
  extension), which is where fragment ambiguity lives. Score near `0.95`: a dropped 5th is
  *more* ordinary than a dropped root (`0.98`). Non-legit root-absent readings keep the hard
  `0.35` penalty.

  *Why the identifier, not `matchVoicing`:* a 3-note fragment is a subset of many shapes, so
  choosing among them is a **ranking** problem. The identifier owns the ranker (key fit, DB
  evidence, bigram/Viterbi context, pinned root); `matchVoicing` has only a name and an
  extra-string count. Since `matchByIdentification()` now backstops both of `matchVoicing`'s
  failure exits, any identifier improvement reaches the crossref engine for free.

  *Do NOT lower the `count($pcSet) < 3` floor.* Two pitch classes cannot determine a chord
  (`Bbsus2 xx85xx` = {Bb,F} is Bb5 / Bbsus2 / F5sus4 / the shell of ~9 others). Leaving
  dyads in drafts is correct; the existing dyad guard (§B.1) says so and is right.

  *Risk:* widening the gate expands the candidate space for **every** voicing, not just
  fragments — a complete `Cmaj7` also matches "Em with missing 5th + extensions" more easily.
  The `0.35` penalty and `$unexplained` counter are what hold that line, and whether they
  hold at the new width is a **measurement, not an argument**. Land behind
  `sbn:audit-identifier-sequences` + `IdentifierRegressionCases` and look at what moves.

- **Slash chords as a generated primitive, not stored shapes (MEDIUM, open).** Triad-over-bass
  voicings should not each be filed as a `sbn_chord_diagrams` row (`maj-slash-rootd-inv2` and
  friends) — a triad shape plus a bass string is *derivable*, and
  `ChordShapeCalculator::calculateFretsWithBass()` already derives it.

  *Proposed:* a shared `SlashVoicing::generate(triadShape, bassPc)` primitive, structurally
  identical to `HarmonicContext\DiminishedSymmetry` — one primitive consumed by identifier,
  matcher, builder and library, the architecture already locked for dim7. Feed the generated
  combinations into `DbVoicingMatcher::shapes()` as **virtual** shapes so `lookup()` sees
  triad-over-bass without any of them being stored.

  *Explicitly rejected: a "12 chromatic basses × triad ⇒ chord name" lookup table.* Naming a
  triad-over-bass is a **derivation with a context-dependent choice**, not a fact, so it
  cannot live in a table cell. `C/D` is `D9sus4` in a ii–V but plain `C/D` over a Jobim pedal;
  `C/Bb` is `Bb∆#11` modally but a passing bass line otherwise; `C/A` is not a slash chord at
  all — it is a complete `Am7` (4 tones, nothing omitted). Baking one answer per cell would
  re-create exactly the rigidity that froze `665785` as `Gmadd9(b13)` (§B.1, Days of Wine and
  Roses). Naming already belongs to `identifyFromPcSetFull`, which scores all 12 candidate
  roots against the full PC set and has `KeyFitWeigher` + DB evidence to break ties. It does
  not need a table; it needs the **shapes** to exist so it can match them.

  Note `ChordShapeCalculator::SLASH_CHORD_TONES` already covers the 3 chord-tone intervals per
  quality (→ `inv1`/`inv2`/`inv3`); the other 9 fall through to `type => 'slash'`. That table
  is about **inversion identity**, not naming, and should stay as-is.

  *Risk / unmeasured:* 12 basses × every triad shape multiplies the shape cache, and `lookup()`
  is already an `O(shapes × 12)` scan. Measure before committing. Also unverified: the claim
  that `Am/E 0xx5x5` and `C6/E 0xx0x5` fail for *shape-lookup* rather than *naming* reasons —
  trace one through `matchVoicing` first, since it decides whether this work is really about
  shapes at all.

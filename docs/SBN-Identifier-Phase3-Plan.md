# SBN Identifier — Phase 3 Plan: Evidence-Layered Identification

**Status:** Spec, not yet implemented. Authored 2026-05-14.
**Reference doc:** `SBN-Identifier-Reference.md` (Phases 1 & 2 shipped).
**Predecessor in spirit:** Phase D Builder Refactor (Viterbi/HMM-style sequence inference, applied to construction rather than identification).

This plan extends the identifier from a local template-scoring algorithm with rescue passes (Phases 1 & 2) into an **evidence-layered system** that combines DB-grounded shape matching, key-fit reasoning, and harmonic-transition probabilities.

---

## 1. The problem this solves

The current identifier produces names that are PC-correct but functionally implausible — e.g., `Absus2(13)` for a voicing that is unambiguously `Db6(9)/Ab` in context. It also fails to produce certain valid names at all when the canonical reading involves an absent root (e.g., `4x334x` in key of Db has no `Db` PC, so no Db-rooted candidate is enumerated).

The root cause is that the algorithm reasons purely from intervals against templates, with no model of:

1. **What real-world voicings look like** (the DB of 172 filed shapes is consulted only for `diagram_id` enrichment, not for naming).
2. **Whether the candidate name is plausible in the song's key.**
3. **Whether the candidate fits the harmonic flow** of neighboring chords.

## 2. The three-layer architectural model

```
For each chord slot S_n in a leadsheet:

  candidates(S_n) = local_pass1(frets, bass)
                  ∪ db_lookup(frets, bass)        ← Layer 1 (DB shape match)

  for each c in candidates(S_n):
    score(c) = local_score(c)
             × db_match_bonus(c)                  ← Layer 1 weight
             × key_fit(c, song_key)               ← Layer 2 (soft, jazz-tolerant)
             × transition_prior(c | S_{n-1}, S_{n+1})  ← Layer 3 (sequence)

  pick(S_n) = argmax score
```

Each layer is independently testable, observable, and revertable.

## 3. Layer 1: DB shape lookup (Phase 3.1)

### 3.1 Goal

Treat the 172 (and growing) filed shapes in `sbn_chord_diagrams` as **evidence of human-attested chord names**. Before running the local scorer, ask the DB: *"have you seen this shape before, and what did you call it?"*

### 3.2 Mechanism

1. For an incoming voicing (fret string + bass PC), compute the **shape signature**: the set of (string, fret-offset) tuples relative to the lowest fretted position, plus which string carries the bass.
2. Query DB shapes by transposing each candidate shape across all 12 roots and checking PC-set + bass-PC equivalence with the target voicing.
3. Return ranked hits sorted by:
   - **(a)** Bass match: candidate's transposed bass PC = target bass PC → preferred over PC-only matches.
   - **(b)** Inversion field agreement: `inversion='root'` when bass is the root; `inv2/inv3` when bass is the 5th/3rd respectively.
   - **(c)** Popularity: higher `popularity` wins ties.

### 3.3 Role: soft contributor, not prefix pre-empt

**Revised after 2026-05-14 shadow dump (`storage/audits/db-lookup-shadow-report.txt`).** Initial design called for Layer 1 to run as a prefix pass that short-circuits Pass 1 when a high-confidence hit exists. Shadow-mode evidence on 423 baseline chords killed that approach:

- 71% of baseline slots have a bass-confirmed DB match.
- Of those, ~50% would **agree** with Pass 1's current output (good — supports the evidence).
- But ~30% would **override** Pass 1 toward a *less specific* name — `Bbmaj7/F` → `Bb/F`, `Gadd9/A` → `G/A` — because the DB doesn't have the more specific shape filed. This is the cold-start problem in action: an empty corner of the DB makes "the best DB match" worse than the algorithmic reading.

So Layer 1 is wired as a **soft contributor to the final score**, not a hard pre-empt:

- **DB hit with bass agreement + root-equals-bass + pop ≥ 1** → multiply that candidate's Pass 1 score by `dbBonus = 1.5`.
- **DB hit with bass agreement only** → multiply by `dbBonus = 1.15`.
- **Pass 1 winner has no DB match anywhere** → multiply by `noDbPenalty = 0.85` (the `Absus2(13)` filter — never seen in any filed shape, downweight).
- **No DB evidence at all** → no adjustment, pure Pass 1 behavior.

Final pick is then resolved by Layer 2 and Layer 3 scoring on top.

### 3.4 Cold-start safety

A new shape not yet filed simply produces "no DB evidence" → no adjustment → Pass 1 behavior. **Layer 1 cannot regress an existing correct identification** because it only adjusts scores, never replaces candidate names. Pass 1 still has to produce the right name; Layer 1 just nudges the ranking when DB evidence is available.

### 3.4a Two DB-schema traps confirmed during shadow dump

1. **`root_note` column is not always the chord root.** For some rows (e.g. id 104 `Maj7 Drop 3 1st Inv (Root E)`) the column holds an archetype/positional tag (`'C'`), while the actual chord root is encoded in `interval_labels` (`'3,x,R,5,7,x'` → the string labeled `R` is the true root). The true root must be derived by locating `'R'` (or `'1'`) in `interval_labels` and reading that string's pitch from `diagram_data`. Fallback to `root_note` is only safe when `interval_labels` is empty.
2. **`inversion` column ('inv1'/'inv2'/'inv3') is not always consistent with the shape's actual lowest pitch.** Slash-bass labels must be derived from the **actual transposed bass PC** of the shape (lowest-pitch string in `diagram_data`), not by adding the nominal interval to the root. Several rows have inversion labels that contradict their diagram_data.

Both traps come from legacy WordPress-era data that was hand-curated with looser invariants than the schema implies. Cleaning the rows is out of scope for Phase 3 — implementation must work around these inconsistencies.

### 3.5 String-numbering convention

DB diagrams use legacy WordPress numbering: **string 1 = low E, string 6 = high E** (per `SBN-Admin-Reference.md`). Fret strings (e.g., `4x334x`) use the same convention: **index 0 = string 1 (low E)** (per `SBN-Audio-Reference.md`). Any implementer of Layer 1 must handle this — the inverted convention is a known footgun.

### 3.6 Validation plan

- Build the lookup as a standalone service (`App\Services\Identifier\DbVoicingMatcher`) with no integration into `VoicingCrossref`.
- Run it against the four existing baseline audits (Wine, Misty, Easy Living, Shadow of Your Smile) and the Ipanema exercise.
- Dump for every chord: `(target_pcs, target_bass, db_hits, agreement_with_current_identifier)`.
- Inspect the dump with the maintainer. Sign off requires: zero regressions on baselines, ≥1 demonstrable improvement on Ipanema.
- Only then wire it into `VoicingCrossref::identifyFromFrets` as a prefix pass.

### 3.7 What this fixes (confirmed by shadow dump)

- **Ipanema chord 1 `Db6(9)/Ab`** — DB shape id 123 (`Maj69 Custom 2nd Inv (Root B)`) matches PC set + bass exactly at +2 semitone transposition, ranks first with bass+root-aligned signal. After enharmonic re-spelling (B+2 = C# → Db in flat-key context), produces `Db6(9)/Ab`.
- Names like `Absus2(13)` that appear nowhere in the filed shape corpus get downweighted via `noDbPenalty`, allowing better-supported alternatives to win.

### 3.8 What this does NOT fix (also confirmed)

- **Ipanema chord 2 `Eb7(9)/Bb`** — DB has the voicing filed as `Eb7(9)/G` (bass=G, inv1) only, not with bass=Bb (inv2). The four bass-confirmed matches the DB returns for `6x566x` are all `Bbm6` shapes (ids 280, 177, 272, 206). Layer 1 alone would *agree* with the current `Bbm6` reading — correct as a local pick, wrong as a contextual one. Resolution requires Layer 3 transition priors (`I → II7` is high-probability bossa; `I → iim6` is rare).
- Underspecified DB cases — chords whose more-specific voicing isn't filed (~40 out of 88 overrides in the shadow dump). Layer 1's score bonuses are calibrated so this doesn't push a less-specific reading past Pass 1's more-specific one; the cold-start gap is absorbed by leaving Pass 1 in charge.

### 3.9 Shadow-dump baseline metrics (2026-05-14)

Across 423 chord slots from Wine, Misty, Easy Living, Shadow of Your Smile:

| Metric | Value |
|---|---|
| Slots with any DB hit | 327 (77%) |
| Slots with bass-confirmed DB hit | 300 (71%) |
| Layer 1 would AGREE with current identifier | 212 |
| Layer 1 would OVERRIDE current | 88 |
| — pure enharmonic disagreements (Dbo7 ↔ C#o7) | ~25 |
| — underspecified DB names (cold-start) | ~40 |
| — mid-confidence genuine alternates | ~15 |
| — problematic re-readings | ~8 |

These numbers justify the soft-contributor design (§3.3). Full report: `storage/audits/db-lookup-shadow-report.txt` (kept for reference, not committed).

---

## 4. Layer 2: Key-fit (Phase 3.2)

### 4.1 Goal

A **soft, jazz-tolerant** preference for candidates whose root is functionally related to the song key. Critically NOT a hard diatonic filter — that would regress chromatic jazz (secondary dominants, tritone subs, modal interchange).

### 4.2 Mechanism

Define key-fit weight per candidate root scale-degree:

| Root relation to song key | Weight |
|---|---|
| I, IV, V (primary functions) | 1.20 |
| ii, iii, vi (diatonic minor) | 1.15 |
| vii° | 1.10 |
| Secondary dominant (V/x where x is diatonic) | 1.10 |
| bVII, bVI, bIII (modal mixture) | 1.05 |
| Tritone sub of any diatonic dominant | 1.05 |
| Other chromatic | 1.00 (no penalty) |

**No candidate is ever multiplied below 1.0.** The mechanism *promotes* diatonic readings, it never *demotes* chromatic ones.

### 4.3 Required input

Reliable `song_key` per leadsheet. Already populated on Ipanema (`Db`) and on 83% of the jazz-standards corpus.

### 4.4 Tonicization gotcha

A secondary ii-V (e.g., `Am7-D7` inside a Bb song, tonicizing G minor briefly) should be evaluated against the **local tonal area**, not just the song key. Detecting tonicization is itself nontrivial — for Phase 3.2, defer this to Layer 3 (transition priors handle it naturally because `Am7→D7→Gm7` is a high-probability sequence regardless of song key). Revisit only if Layer 3 doesn't suffice.

---

## 5. Layer 3: Harmonic transition priors (Phase 3.3+)

### 5.1 Goal

Rank candidates by **how plausible the resulting chord sequence is**, using transition probabilities learned from the corpus of 1,382 jazz standards (oliphant/iReal Pro source — pure human-curated, ~52,000 bigram observations).

### 5.2 Upgrade path

Designed for the destination (HMM with latent key state), built incrementally so each milestone ships value.

| Level | Model | Captures | Doesn't capture |
|---|---|---|---|
| 3.3a | **Surface bigram** P(c_n \| c_{n-1}) | Common literal transitions (`Dm7→G7`) | Transpositional generalization, key context |
| 3.3b | **Functional bigram** P(numeral_n \| numeral_{n-1}, key) | Same transitions across all keys; ii-V is one entry | Modulations, ambiguous-key passages |
| 3.4 | **HMM with latent key state** | Modulations, B-section key changes, secondary ii-V chains | Phrase-level structure |
| 3.5+ | Phrase/section-aware, neural | Form-level priors | (not currently needed) |

Levels 3.3a → 3.3b → 3.4 **share the same data pipeline** (count chord pairs from the corpus, optionally with functional preprocessing). Level 3.4 swaps the inference algorithm to Viterbi over (chord × hidden-key) states; the data prep is reusable.

### 5.3 Data preparation

- **Input:** `sbn_jazz_standards.chord_string` — pipe-bar / comma-intra-bar format (e.g., `D9|Fm6|D9|Fm6|C|C7,B7,Bb7,A7`).
- **Parse:** split into a flat chord sequence per standard.
- **Normalize:** map chord names to canonical labels via `App\Helpers\ChordName::normalize` (already exists).
- **Annotate:** for each chord, attach root PC + quality bucket. For functional bigrams, also attach scale-degree (computed from `song_key`).
- **Count:** emit bigram (and later trigram) tables.
- **Smooth:** apply Laplace add-1 smoothing so unseen transitions get nonzero probability (rare ≠ impossible).
- **Persist:** seed into a `harmonic_transitions.generated.php` file alongside `harmonic-fragments.generated.php`. Reseed via `php artisan sbn:reseed-transitions`. **Commit to git.**

### 5.4 Functional bigram example (Level 3.3b)

Computed from corpus, hypothetical numbers for illustration:

| From numeral | To numeral | P |
|---|---|---|
| IIm7 | V7 | 0.62 |
| V7 | Imaj7 | 0.58 |
| Imaj7 | IIm7 | 0.21 |
| Imaj7 | bIIImaj7 | 0.03 |
| Imaj7 | iim6 | 0.01 |
| Imaj7 | II7 | 0.18 |

So at chord 2 of Ipanema, given chord 1's strong DB pick of `Db6(9)/Ab` (= I in Db), the bigram says `Bbm6` (= iim6, P=0.01) vs `Eb7(9)/Bb` (= II7, P=0.18) — **18× preference for the II7 reading**. That's exactly the disambiguation we need.

### 5.5 HMM-with-key-state (Level 3.4)

The inference task becomes: given an observed chord sequence and the local PC evidence per slot, find the Viterbi-optimal path through (chord_label × hidden_key) states. Transition costs combine:
- P(chord_{n} | chord_{n-1}) for non-modulating transitions
- A small modulation penalty when hidden_key shifts
- The candidate-confidence priors from Layers 1 & 2

This is structurally **the same algorithm as `ProgressionBuilder::viterbiSelect`** (Phase D builder), applied to identification rather than voicing selection. Implementer should read `Builder-Refactor-Spec.md` §10 first for the proven inference pattern.

### 5.6 Bootstrap risk

**None for layer 3.3/3.4 from the chosen corpus.** All 1,382 standards are oliphant-sourced and pre-date the identifier's existence — no positive-feedback contamination. Going forward, if identifier output ever flows back into the bigram corpus, we'd need to mark `identified_vs_curated` per chord. For now, the corpus boundary is clean.

---

## 6. Phased delivery

### Phase 3.1 — DB lookup (Layer 1 only) ✅ SHIPPED 2026-05-14

**Goal:** Fix Ipanema chord 1 (`Db6(9)/Ab`). No changes to Layer 2 or 3.

**Tasks:**
- **3.1.1** ✅ Build `App\Services\Identifier\DbVoicingMatcher` as a standalone service.
- **3.1.2** ✅ Write `DbVoicingMatcherTest` with the four baseline audit voicings + Ipanema chord 1. (8 tests, all green)
- **3.1.3** ✅ Run a "shadow mode" dump across baselines, inspect. (See `storage/audits/db-lookup-shadow-report.txt`)
- **3.1.4** ✅ Wire into `VoicingCrossref::identifyFromFrets` as score contributor per §3.3.
- **3.1.5** ✅ Re-run baseline audits, diff against frozen baselines, sign off.

**Outcome:**
- Ipanema chord 1 identifies as `Db6(9)/Ab` (was `Absus2(13)`).
- 423 chords across the 4 baseline audits: 402 identical, 20 cosmetic improvements (`Fmaj6` → `F6`, leadsheet convention), 1 ambiguity flip (`Gb7/Db` ↔ `Dbm6` on a 3-note voicing where both are valid; resolution deferred to Layer 2/3).
- Baselines refrozen 2026-05-14 to capture the new behavior.
- Display normalization added: `maj6` → `6` in chord names (mirrors existing `dom7` → `7`).
- Side fix: `IDENTIFY_QUALITY_INTERVALS[$bestQuality]` lookup now uses `?? []` fallback so a DB-injected winner with an unusual quality doesn't crash the inversion derivation.

### Phase 3.2 — Key-fit weighting (Layer 2) ✅ SHIPPED 2026-05-14

**Goal:** Add soft diatonic preference without breaking chromatic jazz.

**Tasks:**
- **3.2.1** ✅ Add `App\Services\Identifier\KeyFitWeigher` (stateless, returns weight 1.00–1.20).
- **3.2.2** ✅ Integrated into `VoicingCrossref::maybeApplyDbEvidence` (alongside Layer 1 reweighting) rather than `ContextualReranker` — same effect, simpler call path. Triggered when `$songKey` is passed to `identifyFromFrets`.
- **3.2.3** ✅ 11 unit tests in `KeyFitWeigherTest` cover primary/diatonic/secondary-dominant/tritone-sub/mixture roles plus the bedrock invariant (weight ≥ 1.00 across all 12 PCs × 12 qualities × 10 keys = 5781 assertions).
- **3.2.4** ✅ Baseline audits unchanged (0 diffs) because audit command passes `--key` only to the context pass, not isolation.

**Outcome:**
- Eb7 in key of Db correctly classified as `secondary_dom` (V7/V) at weight 1.10, not as ii at 1.15.
- Dominant 7 chords whose root happens to be diatonic get the secondary-dominant reading first (functional precedence over interval-degree precedence).
- Minor keys handled with their own natural-minor diatonic set, no rotation tricks.
- Modal mixture (bIII, bVI, bVII) recognized in major keys at weight 1.05.
- The eventual fix to Ipanema chord 2 (`Eb7(9)/Bb` over `Bbm6`) needs Phase 3.3 (transitions) — key fit alone gives Bbm6 ×1.15 (vi) and Eb7 ×1.10 (V/V), not enough to flip the rank with Bbm6's much higher Pass 1 score.

### Phase 3.3a — Surface bigram (Layer 3, first slice)

**Goal:** Add transition priors with the cheapest possible model.

**Tasks:**
- **3.3a.1** Build `php artisan sbn:reseed-transitions` to parse jazz-standards corpus, emit bigram table.
- **3.3a.2** Persist as `storage/app/harmonic-transitions.generated.php`. Commit.
- **3.3a.3** Add `App\Services\Identifier\TransitionScorer` reading the seeded table. Apply Laplace smoothing.
- **3.3a.4** Integrate into `ContextualReranker` as a sub-pass after `HarmonicPatternMatcher`.
- **3.3a.5** Re-run baseline audits + Ipanema. Verify chord 2 of Ipanema picks `Eb7(9)/Bb`.

**Definition of done:** Ipanema chord 2 identifies as `Eb7(9)/Bb`. Zero regressions on the four baseline audits.

### Phase 3.3b — Functional bigram (Layer 3, generalization)

**Goal:** Generalize across keys so the model has correct priors even for standards/keys underrepresented in the corpus.

**Tasks:**
- **3.3b.1** Extend the reseed command to also emit numeral-keyed bigrams (requires song_key column).
- **3.3b.2** `TransitionScorer` consults functional bigram first, falls back to surface bigram.
- **3.3b.3** Add 5-progression regression suite covering keys that are sparse in the corpus.

**Definition of done:** Functional bigram dominates for slots with reliable key; surface bigram serves the rest.

### Phase 3.4 — HMM with latent key state

**Goal:** Handle modulations and tonicizations natively.

**Tasks:**
- **3.4.1** Spec out the (chord × hidden-key) state space and transition matrix.
- **3.4.2** Implement Viterbi inference (template the existing `ProgressionBuilder::viterbiSelect`).
- **3.4.3** Replace `TransitionScorer`'s per-slot scoring with sequence-level Viterbi.
- **3.4.4** Build a modulation-focused regression suite (B-sections, key-shifting standards).

**Definition of done:** A standard with a clear modulation (e.g., *All The Things You Are*) identifies chords correctly across the modulation boundary.

---

## 7. Risk register

| Risk | Mitigation |
|---|---|
| DB lookup over-confidently picks wrong name when bass disagrees subtly | Strict bass check in §3.3 branching; PC-only matches drop to medium confidence, do not bypass Layer 2/3 |
| Phase 3.2 inadvertently penalizes chromatic chords | Weights bounded ≥1.0; explicit regression suite for chromatic jazz |
| Bigram trained from biased corpus distribution skews predictions | Corpus is uniform-source (oliphant); reseed is reproducible; spot-check top-30 transitions against music theory |
| Phase 3.4 HMM picks plausible but wrong modulation | Modulation penalty tunable; regression suite must include non-modulating standards too |
| Implementation drift between identifier (Phase 3.4) and builder (Phase D) Viterbi | Both should reference a single internal `ViterbiSearch` helper. Refactor Phase D's inline implementation in Phase 3.4 if needed |

## 8. What is explicitly out of scope

- Audio-transcription expected-chord input (already in Appendix B.2 of the reference doc, distinct stream of work).
- Tonicization detection as a standalone pass (Layer 3 should absorb this naturally; revisit only if it doesn't).
- Re-identifying historical leadsheets identified before Phase 3 ships (per Appendix A of the reference doc, this remains a manual operation via the "identify voicings" button).

---

## 9. Architectural symmetry note

Phase 3.4 mirrors Phase D Builder Refactor structurally:

- **Builder Phase D**: Viterbi over (voicing) states, minimizing total voice-leading cost across a fixed chord sequence.
- **Identifier Phase 3.4**: Viterbi over (chord_label × hidden_key) states, maximizing posterior over the observed PC sequence.

Both decompose the same NP-hard search via dynamic programming over per-slot evidence + pairwise transition cost. Both are warranted by the same data property: musical sequences have **strong local dependency, weak long-range dependency**. The cost function differs (voice-leading penalty vs negative-log-likelihood of transition), but the algorithm is the same.

This symmetry is worth preserving in code. A shared `App\Services\Inference\ViterbiSearch<TState, TObservation>` would be the right abstraction once both call-sites exist.

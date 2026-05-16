# SBN Chord Identification — Reference

**Status:** Living document. Phase 1 (slash chords) and Phase 2 (harmonic pattern awareness) shipped 2026-05-06. Phase 3 (evidence layers: DB lookup, key-fit, bigram transitions, Viterbi rescore) shipped 2026-05-14/15.
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
| 3b | Rootless upgrade: if Pass 1 picked a plain triad from only 3 PCs, also check rootless templates and prefer them when they match. | `VoicingCrossref::identifyFromFrets` |
| 3c | Tritone-dominant rescue: if Pass 1 winner is non-dominant but the PC set contains a tritone, re-score dominant interpretations with bonus ×5. | `VoicingCrossref::identifyFromFrets` |
| 4 | Slash-chord post-detection: if `bestRoot ≠ bassPc`, append `/BassNote` and set `inversion`. | `VoicingCrossref::identifyFromFrets` |
| Context 2a | Pedal-point detector: N≥3 slots sharing bass note → upper-structure re-identified, labeled `Upper/PedalBass`. | `PedalDetector` |
| Context 2b | Diminished-as-rootless-V7(b9): dim7 resolving down a 5th renamed (e.g. `Adim7 → F7(b9)`). | `DiminishedAsDominantResolver` |
| Context 2c | Diminished-root resolver: inversionally symmetric dim7 spelling via step-wise motion to neighbors. | `DiminishedResolver` |
| Context 2d | Cadence/fragment matcher: DB-seeded harmonic fragments (`IIm7→V7→Imaj7`, turnarounds). | `HarmonicPatternMatcher` |
| Context 2e | Key-aware enharmonic re-rank + **bigram transition prior** (Phase 3.3a): bumps candidates whose preceding winner predicts them via P(name\|prev) lookup. | `ContextualReranker` |
| Context 2f | **Viterbi sequence rescore** (Phase 3.4a): DP min-cost path over all slots simultaneously using bigram edge weights (`edgeWeight=0.3`, `minScoreRatio=0.85` safety rail). | `ContextualReranker::applyViterbiRescore` |
| Context key-fit | **Key-fit weighting** (Phase 3.2): soft diatonic preference (×1.0–1.20), never penalises chromatic chords. | `KeyFitWeigher` via `maybeApplyDbEvidence` |

### 2.2 Load-bearing constants (Do not change lightly)
- The `IDENTIFY_QUALITY_INTERVALS` table, sorted longest-first (`maj7` beats `maj`).
- The `IDENTIFY_EXTENSION_INTERVALS` table mapping leftover pitch classes to named extensions.
- The `IDENTIFY_ROOTLESS_TEMPLATES` table for jazz voicings without a bass root.
- The slash-chord chord-tone restriction (intervals `{3, 4, 7, 10, 11}`).
- The two-tier slash-bonus values (`slashBonus7th = 3.5`, `slashBonusTriad = 2.5`).
- Phase 3 tuning knobs: `dbBonus = 1.5` (bass+root confirmed), `dbBonusBassOnly = 1.15`, `noDbPenalty = 0.85`. Key-fit range `[1.00, 1.20]`. Viterbi `edgeWeight = 0.3`, `minScoreRatio = 0.85`.

---

## 3. Phase 1: Local Scoring Mechanics

### 3.1 Slash-chord first-class candidates
A historical bug caused exact triads with bass roots (e.g., `Dmin(b13)` instead of `Bbmaj7/D`) to tie with exact slash-chord representations. Because of iteration order, the bass-rooted artifact won. 

To fix this, a **slash bonus** was introduced. It requires the bass to be a **genuine chord tone** of the candidate root (m3, M3, P5, m7, M7). This ensures `Bbmaj7/D` gets the bonus (D is the 3rd), but `E7(11)/A` does not (A is an extension, the 11th). 

### 3.2 Two-tier Slash Bonus
To prevent simpler partial triads (like `Csus4(9)/G`) from beating more specific 7th chords (like `Gm7(11)`), the slash bonus has two tiers:
- **7-chord slash candidate** (`total >= 4` notes) gets `3.5`.
- **Triad slash candidate** (`total <= 3` notes) gets `2.5`.

---

## 4. Phase 2: Contextual Re-ranking

The contextual layer sits entirely above Phase 1. It takes the top-K candidates from Phase 1 and re-ranks them based on neighbor sequence and song key.

**Sub-pass execution order in `ContextualReranker`:**
1. **Pedal-point detector (`PedalDetector`)**: Scans for runs of N≥3 slots with the same bass note, running Phase 1 on the upper structure alone and labeling as `Upper/PedalBass`.
2. **Diminished-as-rootless-V7(b9) (`DiminishedAsDominantResolver`)**: Flags dim7 chords that act as a rootless dominant resolving down a 5th, renaming them (e.g., `Adim7 -> F7(b9)`).
3. **Diminished-root resolver (`DiminishedResolver`)**: Resolves the spelling of inversionally symmetric dim7 chords based on step-wise motion to neighbors (e.g., `Ebdim7` ascending to `Em7`).
4. **Cadence/fragment matcher (`HarmonicPatternMatcher`)**: Uses DB-seeded harmonic fragments (like `IIm7 -> V7 -> Imaj7` or turnaround variants) to correct contextually weak readings.
5. **Key-aware enharmonic re-rank**: Bumps diatonic candidates within a 15% score delta to the top.

*Crucial safety mechanism*: The contextual layer never promotes a candidate that wasn't already in Phase 1's top-K.

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

The bigram table is built from 1,382 jazz standards (oliphant/iReal Pro, 48,930 bigrams including self-transitions). Seeded via `php artisan sbn:reseed-transitions` to `storage/app/harmonic-transitions.generated.php`. **This file must be committed to git.**

Multiplier curve: `1 + 0.7 × log10(P / uniform)`, clamped to [0.4, 3.0]. A transition 5× more common than uniform yields ≈×2.0 promotion.

Backoff chain: `Db6(9)/Ab → Db6/Ab → Db/Ab → Db`. Searches all (prev, next) backoff combinations for the highest-probability hit before falling back to Laplace smoothing.

#### Sub-pass 2f: Viterbi sequence rescore (`applyViterbiRescore`)

Runs a min-cost Viterbi DP across all slots simultaneously:

```
cost[i][k] = min_j( cost[i-1][j] + edgeWeight × -log(P(cand_k | prev_j)) ) + -log(score_k)
```

- `edgeWeight = 0.3` — seed costs dominate; a weak-bigram chain can't override strong local evidence.
- `minScoreRatio = 0.85` — Viterbi winner must score ≥85% of the current slot winner. Tie-breaking only, not dominant-winner overrides.

### 5.4 Architectural limit: enharmonic PC ambiguity

The Ipanema chord 2 case (`6x566x`) demonstrates a hard limit: the PC set `{Bb, G, Db, F}` is genuinely ambiguous between `Bbm6` and `Eb7(9)/Bb`. Both names are correct representations of the same pitches. No combination of DB lookup, key-fit, bigram, or Viterbi can resolve this without an external reference to the *expected* chord name.

**Pass 1+Layer1** scores `Bbm6` at 7200 vs `Eb7(9)/Bb` at 1062 (6.8× gap). Viterbi's `minScoreRatio = 0.85` correctly blocks the flip — the bigram is working as designed, it just can't overcome a 6.8× local-evidence gap. Resolution requires Appendix B.2 (expected-chord priors from a reference leadsheet).

---

## 6. Audit Infrastructure

Always run audits before and after changing these algorithms to measure behavior change and catalog failure modes.

### 5.1 Commands
- `php artisan sbn:audit-identifier <file.musicxml>` (Runs local Pass 1)
- `php artisan sbn:audit-identifier-context <file.musicxml>` (Runs full Phase 2 pipeline)

Output goes to `storage/audits/`. **Do not commit these files** (except for frozen baselines in `tests/fixtures/identifier-context/baseline/`).

### 5.2 Test Fixtures
The core audit corpus resides in `docs/*.musicxml` and their expectations are encoded in `tests/fixtures/identifier-context/*.expected.json`:
- **Wine** (`WES MONTGOMERY...`): Covers pedal points, ii-V-i functional re-ranking.
- **Misty** (`BARNEY KESSEL...`): Covers ascending diminished passing chords.
- **Easy Living** (`GEORGE BENSON...`): Turnaround variants with dim subs, key-aware re-rank.
- **Shadow of Your Smile** (`JOE PASS...`): Descending diminished passing chords.

---

## 7. Design Principles

1. **Distinguish Bucket 1 from Bucket 2**:
   - *Bucket 1 (Algorithmic artifact)*: No human would defend this output (e.g., `Dmin(b13)`). Fix these with structural local logic.
   - *Bucket 2 (Locally defensible, contextually suboptimal)*: Output is a valid reading locally, but a contextual reading would be better (e.g., `Am6` instead of `D7(9)/A`). Fix these *only* in the ContextualReranker, never by hacking local Phase 1 thresholds.
2. **Specificity**: Prefer the *more specific* quality match for identification (`maj7` beats `maj`).
3. **Tuning vs. Structure**: Nudging magic constants often causes whack-a-mole bugs. Prefer structural solutions (like checking if a note is a true chord tone).
4. **DB at seed time, constants at runtime**: Do not query the database during identification. Harmonic fragments are compiled via `php artisan sbn:reseed-fragments` to `storage/app/harmonic-fragments.generated.php`. Bigram transitions are compiled via `php artisan sbn:reseed-transitions` to `storage/app/harmonic-transitions.generated.php`. Both files must be committed.
5. **Layer 3 is tie-breaking, not overriding**: The `minScoreRatio = 0.85` Viterbi guard is load-bearing. A bigram that correctly prefers `Eb7` over `Bbm6` should not override a 6.8× local-evidence gap — it should break ties between roughly-equal candidates.

---

## Appendix A: Workflow Gotchas

- **Fragment file is generated**: `storage/app/harmonic-fragments.generated.php` is generated by `php artisan sbn:reseed-fragments`. It **must be committed to git**. DB edits do not auto-update fragments.
- **Transitions file is generated**: `storage/app/harmonic-transitions.generated.php` is generated by `php artisan sbn:reseed-transitions`. It **must be committed to git**. The corpus is iReal Pro chord strings from `sbn_jazz_standards.chord_string`. Self-transitions are included (omitting them causes Viterbi to prefer non-repeating paths, a systematic bug for ostinato progressions).
- **Phase 1 `bass_note`**: `bass_note` must be populated on every slot. PedalDetector and DiminishedResolver both rely on it.
- **DB `root_note` is not always the chord root** (§5.1): derive root from `interval_labels` by locating `'R'`; fall back to `root_note` only when `interval_labels` is empty. Several legacy rows have `root_note = 'C'` (a positional tag) while the actual root is in the labels.
- **DB `inversion` column is not always reliable** (§5.1): always derive slash-bass from the transposed bass PC of the diagram data, not from the stored inversion label.
- **MusicXML Parsing**: The XML parsing runs in JavaScript (`resources/views/admin/leadsheets/edit.blade.php`), which then batches `Tab` voicings to `POST /api/admin/leadsheets/identify-voicings`.
- **Legacy Leadsheets**: Existing leadsheets identified before May 2026 are not automatically re-identified. You must use the editor's "identify voicings" button.

---

## Appendix B: Known Bugs & Future Improvements

### B.1 Known Bugs

- **Slash chord over-eagerness on `7sus4` shapes**: Voicings like `x3333x` output as `Bbadd9/C` instead of `C7sus4`. *Fix shape:* Add a `7sus4` recognition gate that fires *before* the slash-bonus is applied (requires a new `IDENTIFY_SUS_DOM_TEMPLATES` entry).
- **`noteNameToPc` maps unknown to 0**: Both `PedalDetector` and `DiminishedResolver` treat unrecognized note strings as C (0). This is a latent bug if Phase 1 ever emits non-standard names. *Fix shape:* Return `null` and skip gracefully.

### B.2 Planned Improvements

**Phase 2 follow-ups:**
- **Add `IIIm7b5 → VI7 → IIm` as Tier 1 fragment**: A common secondary ii-V to ii (used in *Wine and Roses*). Add it to `sbn_chord_progressions` and reseed fragments.
- **Bass-as-third heuristic for `DiminishedAsDominantResolver`**: Pick the dominant root whose major third matches the original dim7 bass note.
- **Dim7 disambiguation in isolated slots**: When a dim7 has no useful neighbors, prefer the spelling whose root matches the bass (e.g., `Adim7` over `Cdim7/A` when bass is A).
- **Tonicization detection**: Detect temporary tonicizations and lift the local key for fragment matching.
- **Cross-pedal pattern matching**: Handle isolated pedal slots where `PedalDetector`'s N≥3 rule doesn't fire.

**Phase 3 follow-ups:**
- **Functional bigram (Phase 3.3b)**: Extend `sbn:reseed-transitions` to also emit numeral-keyed bigrams. `TransitionScorer` consults functional bigram first (stronger generalization across keys), falls back to surface bigram. Requires reliable `song_key` per standard.
- **HMM with latent key state (Phase 3.4b)**: Viterbi over `(chord_label × hidden_key)` states to handle modulations and tonicizations natively. Structurally identical to `ProgressionBuilder::viterbiSelect` (Phase D builder) — see `SBN-Builder-Reference.md` §10 for the proven inference pattern. A shared `App\Services\Inference\ViterbiSearch<TState, TObservation>` is the right abstraction once both call-sites exist.
- **Expected-chord priors (B.2 original)**: Feed `expectedChords` from a reference leadsheet into `ContextualReranker` to resolve enharmonically ambiguous PC sets (e.g., Ipanema chord 2: `{Bb, G, Db, F}` is valid as both `Bbm6` and `Eb7(9)/Bb` — only an external expected-chord anchor can break the tie, see §5.4).
- **`mappingsByPosition` tests for bigram corpus**: AABA fixture, "more marks than passes", mark for a gi not in the sequence.
- **Diatonicity check deduplication**: The diatonic-candidate test in `ContextualReranker` and `ProgressionBuilder` share the same logic; extract to a shared utility.

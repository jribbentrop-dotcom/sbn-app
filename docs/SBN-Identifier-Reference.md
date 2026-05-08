# SBN Chord Identification — Reference

**Status:** Living document. Phase 1 (slash chords) and Phase 2 (harmonic pattern awareness) shipped 2026-05-06.
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
| 1 | Score all `(rootPc, quality)` pairs. Bass-boost ×4 when root = bass. Exact-bonus ×3 for 4+ tone exact matches. **Slash-bonus** applies when a non-bass root passes the chord-tone test. | `VoicingCrossref::identifyFromFrets` |
| 3a | Rootless rescue: if Pass 1 found nothing, try interval sets *from an absent root*. | `VoicingCrossref::identifyFromFrets` |
| 3b | Rootless upgrade: if Pass 1 picked a plain triad from only 3 PCs, also check rootless templates and prefer them when they match. | `VoicingCrossref::identifyFromFrets` |
| 3c | Tritone-dominant rescue: if Pass 1 winner is non-dominant but the PC set contains a tritone, re-score dominant interpretations with bonus ×5. | `VoicingCrossref::identifyFromFrets` |
| 4 | Slash-chord post-detection: if `bestRoot ≠ bassPc`, append `/BassNote` and set `inversion`. | `VoicingCrossref::identifyFromFrets` |
| Context Pass | Key-aware re-ranking, pedal detection, cadence matching, and diminished resolution across sequences. | `ContextualReranker::rerank` |

### 2.2 Load-bearing constants (Do not change lightly)
- The `IDENTIFY_QUALITY_INTERVALS` table, sorted longest-first (`maj7` beats `maj`).
- The `IDENTIFY_EXTENSION_INTERVALS` table mapping leftover pitch classes to named extensions.
- The `IDENTIFY_ROOTLESS_TEMPLATES` table for jazz voicings without a bass root.
- The slash-chord chord-tone restriction (intervals `{3, 4, 7, 10, 11}`).
- The two-tier slash-bonus values (`slashBonus7th = 3.5`, `slashBonusTriad = 2.5`).

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

## 5. Audit Infrastructure

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

## 6. Design Principles

1. **Distinguish Bucket 1 from Bucket 2**:
   - *Bucket 1 (Algorithmic artifact)*: No human would defend this output (e.g., `Dmin(b13)`). Fix these with structural local logic.
   - *Bucket 2 (Locally defensible, contextually suboptimal)*: Output is a valid reading locally, but a contextual reading would be better (e.g., `Am6` instead of `D7(9)/A`). Fix these *only* in the ContextualReranker, never by hacking local Phase 1 thresholds.
2. **Specificity**: Prefer the *more specific* quality match for identification (`maj7` beats `maj`).
3. **Tuning vs. Structure**: Nudging magic constants often causes whack-a-mole bugs. Prefer structural solutions (like checking if a note is a true chord tone).
4. **DB at seed time, constants at runtime**: Do not query the database during identification. Harmonic fragments are compiled via `php artisan sbn:reseed-fragments` to `storage/app/harmonic-fragments.generated.php`.

---

## Appendix A: Workflow Gotchas

- **Fragment file is generated**: `storage/app/harmonic-fragments.generated.php` is generated by `php artisan sbn:reseed-fragments`. It **must be committed to git**. DB edits do not auto-update fragments.
- **Phase 1 `bass_note`**: `bass_note` must be populated on every slot. PedalDetector and DiminishedResolver both rely on it.
- **MusicXML Parsing**: The XML parsing runs in JavaScript (`resources/views/admin/leadsheets/edit.blade.php`), which then batches `Tab` voicings to `POST /api/admin/leadsheets/identify-voicings`.
- **Legacy Leadsheets**: Existing leadsheets identified before May 2026 are not automatically re-identified. You must use the editor's "identify voicings" button.

---

## Appendix B: Known Bugs & Future Improvements

### B.1 Known Bugs

- **Slash chord over-eagerness on `7sus4` shapes**: Voicings like `x3333x` output as `Bbadd9/C` instead of `C7sus4`. *Fix shape:* Add a `7sus4` recognition gate that fires *before* the slash-bonus is applied (requires a new `IDENTIFY_SUS_DOM_TEMPLATES` entry).
- **`noteNameToPc` maps unknown to 0**: Both `PedalDetector` and `DiminishedResolver` treat unrecognized note strings as C (0). This is a latent bug if Phase 1 ever emits non-standard names. *Fix shape:* Return `null` and skip gracefully.

### B.2 Planned Improvements

- **Add `IIIm7b5 → VI7 → IIm` as Tier 1 fragment**: A common secondary ii-V to ii (used in *Wine and Roses*). Add it to `sbn_chord_progressions` and reseed fragments to catch it contextually.
- **Bass-as-third heuristic for `DiminishedAsDominantResolver`**: Rather than picking the dominant root that isn't in the PC set, pick the one where the original dim7 bass note acts as the major third of the dominant.
- **Dim7 disambiguation in isolated slots**: When a dim7 has no useful neighbors, prefer the symmetric root that matches the bass note (e.g., `A-C-Eb-Gb` over an A bass should prefer `Adim7` over `Cdim7/A`).
- **Tonicization detection**: A system to detect temporary tonicizations (e.g., a secondary ii-V) and lift the local key for fragment matching.
- **Cross-pedal pattern matching**: Handling an isolated pedal point slot (e.g., a single `Bbmaj7/D` surrounded by `Gm9` slots) where `PedalDetector`'s N≥3 rule doesn't catch it.
- **Audio-transcription expected chords**: Implementing the populator that feeds `expectedChords` from a reference leadsheet into the `ContextualReranker` to guide noisy audio PC sets.

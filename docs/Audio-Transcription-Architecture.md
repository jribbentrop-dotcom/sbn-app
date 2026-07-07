# Audio-Transcription Architecture & Quality Refinement

This document outlines the unified architecture and technical debt analysis for the AI-driven audio-to-leadsheet transcription pipeline. It merges the high-level implementation details with the deep-dive quality audits, code examples, and roadmap from the OPUS refinement session.

---

## 1. High-Level Workflow (v2 Pipeline)

The transcription process follows a six-stage pipeline designed for structural accuracy and harmonic clarity:

1.  **Acquisition:** YouTube audio is downloaded via `yt-dlp.exe` in the best available format.
2.  **Standardization:** Raw audio is converted to a standard 16-bit PCM WAV (22050Hz, Mono) using `ffmpeg.exe`. This ensures reliability across Windows environments and codecs.
3.  **AI Inference (Python):** A dedicated Python 3.11 environment executes `scripts/transcribe.py`.
    *   **Beat Tracking:** `librosa` identifies the tempo and beat frames.
    *   **Pitch Tracking:** `basic-pitch` (Spotify's ICASSP 2022 model) performs polyphonic transcription.
    *   **Bucketing:** Notes starting within each beat interval are grouped into pitch sets (PC-Sets). No minimum duration or note-count filtering is applied at the Python layer.
4.  **Melody Reconstruction (PHP):**
    *   **Range Filtering:** Limits pitches to guitar range (MIDI 40–88).
    *   **Beat-Boundary Clamping:** Notes are strictly prevented from crossing beat boundaries (480-tick grid).
    *   **Rhythmic Standardization:** Snap-to-grid (no dotted notes) and gap-closing to eliminate 16th-note jitter.
    *   **Silence Trimming:** Automatic removal of leading empty measures to align the score with music onset.
5.  **Assembly:** The final data is assembled into the Leadsheet model, including video sync mappings and quantized melody/tab data.

> **No AI refinement pass.** The pipeline is fully deterministic. An optional
> Gemini "musicalize" step (rhythm quantization → then, after T4, section
> grouping + enharmonic relabel + key correction) existed historically but was
> **removed 2026-07-07** — see §T8. Rhythm is quantized deterministically (§5),
> spelling is owned by the app's deterministic spelling authority, and
> section-from-chord-list inference proved too weak to justify an LLM round-trip.

---

## 2. Technical Stack & Bridge

*   **PHP/Laravel:** Orchestration and Harmonic Recognition.
*   **Python 3.11.9:** AI Inference — pitch/beat detection, stem separation (located in `/python_env`).
*   **FFmpeg & yt-dlp:** Audio conversion and acquisition.
*   *(No LLM in the transcription path — removed 2026-07-07, see §T8. Gemini/LLM
    clients remain in use for the separate **AI Song Search** leadsheet path.)*
*   **Handshake Pattern:** The PHP service manually injects the system `PATH`, `TEMP`, and `TMP` variables into the `proc_open` environment. Python output is prefixed with `JSON_START` to allow the PHP service to ignore diagnostic noise (TensorFlow warnings, etc.).

---

## 3. Problem 1 — Structural Fit / Beat Grid Misalignment

### Symptom
Bars start on the wrong beat. The "1" (downbeat) is often placed on what is actually beat 2 or beat 3 of the real musical measure. This cascades downstream: chord changes land on wrong beats and video sync drifts.

### Root Cause
`librosa.beat_track()` detects *pulse* locations, not *downbeat* positions. The PHP controller blindly groups them into bars of 4:
```php
if ($i % $beatsPerBar === 0) { ... }
```
If the first detected beat is an anacrusis or beat 2, every bar boundary is wrong by that offset forever.

### Implemented Fixes (Session 2026-05-04)
- **Leading Silence Removal:** The engine scans for the first busy measure and trims empty bars from the start, realigning the `videoSync` mappings. ✅

### Implemented: User Downbeat Offset Tool (Session 2026-05-18, reworked 2026-05-21) ✅
The "Set downbeat" panel in the Video sidebar (`VideoSyncEditor.vue`) lets the
user fix a within-bar phase offset *after* import, without re-downloading or
re-transcribing.

- **Raw cache.** `assembleTranscription()` stores the raw Python output
  (`beats`, `beat_times`, `notes`, `tempo`, `downbeatOffset`) in
  `json_data.transcriptionRaw`. `AnalysisToLeadsheet` passes it through;
  `musicalizeTranscription` preserves it across the Gemini pass.
- **Pick by note.** The panel has one button — *🎯 Set downbeat from a note*.
  Arming it puts the editor in `downbeatPickMode` (a ref `provide`d by
  `TabEditor`); the next note clicked in the tab grid becomes the true "1".
  This replaced the original abstract dot-strip picker, which was unintuitive
  and silently clamped selections.
- **Tick resolution.** Transcription quantizes note starts to an 8th/16th grid,
  so the chosen "1" can sit anywhere in the bar. `reshiftDownbeat`'s `offset`
  is a **tick** value (`0..1919`, 480 = 1 quarter), not a whole-beat index.
  `TabEditor` hands the clicked note's `tickInMeasure` to
  `VideoSyncEditor.pickDownbeatFromTick()`, which computes
  `newOffset = (currentOffsetTicks + tickInBar) mod 1920`.
- **Pickup, not drop.** Content *before* the chosen "1" is kept as a leading
  pickup bar (a full measure with leading rests). In `assembleTranscription()`
  the padding splits: `$padBeats` (whole pickup beats — drives bar grouping &
  chord-region detection) + `$padFrac` (0–479 tick remainder).
- **Sub-beat picks re-phase, they don't de-quantize.** After the (possibly
  fractional) shift, note start/end ticks are re-snapped to the 120-tick
  lattice. A raw fractional shift would push every note off the grid and break
  duration quantization; re-snapping keeps the rhythm coherent, just re-phased.
- **Video sync stays correct.** `videoSync` mappings are rebuilt from raw beat
  `start` times every re-shift. With a fractional shift the bar line no longer
  coincides with a raw beat, so the mapping's `videoTime` is interpolated
  backward toward the previous beat by `$padFrac/480`.
- **Re-assembly.** `POST /api/admin/leadsheets/{id}/reshift-downbeat` re-runs
  `assembleTranscription()` with the new offset, rebuilds sections / melody /
  `videoSync`, persists, and the editor reloads. Idempotent — always re-shifts
  from the original cached beats.
- **Migration note.** `transcriptionRaw.downbeatOffset` now holds *ticks*;
  sheets last assembled by the older whole-beat code stored `0..3`, which reads
  as a near-zero tick shift — harmless, self-corrects on the next re-shift.
- **Caveat.** Full re-assembly discards manual chord/voicing edits made after
  import. The panel warns; it is meant as a "do this first" step.

### Implemented: Bass-Snap Beat Correction (Session 2026-05-18) ✅
For jazz solo guitar the time is flexible (rubato), so `beat_track()`'s steady
grid drifts away from the actual playing. The bass note (thumb), however, keeps
far steadier time than the melody above it — so bass onsets are used as metric
anchors to rebuild a tempo-following `beat_times`.

All in `LeadsheetController`:
- **`bassSnapBeatTimes($notes, $beatTimes)`** — clusters note onsets (70 ms
  window = one attack), takes the *lowest pitch per cluster* as the bass note,
  drops clusters whose lowest note is implausibly high (median + 7 semitones —
  a melody-only moment), then snaps each bass onset to the nearest 8th-note
  subdivision of `beat_track`'s provisional grid. Yields `(audioTime → gridPos)`
  anchor pairs. `beat_track` only needs to be locally accurate to within an 8th
  for the snap to pick the right subdivision; anchoring then resets the
  accumulated drift at every bass note.
- **`buildCorrectedBeatTimes($anchors, $fallback)`** — rebuilds `beat_times` by
  piecewise-linear interpolation between anchors. Melody-only stretches ride
  smoothly across the gap between the nearest anchored bass notes. Front-edge
  extrapolation is clamped to ≥ 0; output is guaranteed monotonic.
- **`rebucketBeats($notes, $beatTimes)`** — re-groups notes into per-beat pitch
  buckets against the corrected grid (mirrors Python's bucketing), so chord
  region detection stays aligned after the grid moves.

Wiring: `assembleTranscription()` takes a `bass_snap` opt — **on by default**
for new audio imports. The cached `transcriptionRaw` always stores the pristine
Python grid plus a `bassSnap` flag, so re-runs are reproducible. The
`reshift-downbeat` endpoint accepts `bass_snap`, defaulting to whatever produced
the current assembly.

**Caveat — depends on detection.** The snap is only as good as `basic-pitch`'s
bass detection; muddy low register can be missed, leaving sparse anchors (it
then falls back to `beat_track`). And `beat_track` must be locally accurate to
within an 8th or an anchor snaps to the wrong subdivision. Best results on
recordings with a clear bass-vs-melody separation.

**Dev tool:** `php artisan sbn:bass-snap-debug {leadsheetId}` prints the
`beat_track` vs bass-snap times per beat with deltas — for spot-checking how
much (and where) the correction moved the grid.

### Proposed Future Improvements
#### A. PLP for beat tracking — tried, rejected
`librosa.beat.plp()` follows local tempo, but it returns a *pulse curve* —
sparse, unevenly-spaced peaks, not a quarter-note grid. The PHP pipeline hard-
assumes "1 `beat_times` entry = 1 quarter note (480 ticks)", so swapping in PLP
drops notes (fewer beats → fewer bars → shortened timeline). Rubato is corrected
by **bass-snap** (and, where that fails, manual re-shift) instead. See the
explanatory comment in `transcribe.py`.
#### B. Tap-correct re-time UI
A manual escape hatch for recordings where bass-snap can't find good anchors:
the user taps downbeats against the synced video; tapped times become
`downbeatOverrides` feeding `assembleTranscription()`. Designed, not built —
bass-snap covers the common case.
#### C. Reference Anchoring
If the song matches a standard leadsheet from the **Mike Oliphant JazzStandards JSON**, use the standard bar count and section labels to force-align the grid. Could also bias bass-snap toward expected bass pitches.

---

## 3b. Note-Detection Tuning (Session 2026-05-22) ✅

### Symptom
A whole class of recordings transcribed with **far too few notes** — e.g. *Stardust*
came back at ~1.8 notes/sec where bossa-nova imports sit at 4.7–6.0. The shortfall
was uniform across the whole track, not a truncated download.

### Root Cause
`predict()` ran with **basic-pitch's default thresholds** (`onset 0.5`,
`frame 0.3`, `minimum_note_length ≈ 128 ms`). Those defaults are tuned for
clearly-articulated input; soft / legato solo jazz guitar and orchestral mixes
have weak onsets that fall below 0.5, so most notes are simply never detected.
The PHP melody assembly was faithfully passing through every note it received —
the loss was entirely upstream, in inference.

### Implemented: Detection Sensitivity levers
The import modal (`_lookup-modal.blade.php`, audio mode) now exposes:

- **Note Detection Sensitivity** preset — `balanced` (defaults), `sensitive`
  (`onset 0.3 / frame 0.18 / minLen 58 ms`), `strict` (`0.7 / 0.45 / 160 ms`),
  or `custom`.
- **Advanced knobs** (collapsible) — onset threshold, frame threshold and
  minimum-note-length sliders, plus a "restrict to guitar range" checkbox
  (clamps detection to ~80–1320 Hz). Touching any slider flips the preset to
  `custom`.

Measured effect on a real recording: **default 719 notes → sensitive 2793**
(3.9×). `sensitive` can over-detect on already-dense material — nudge onset
back toward ~0.4 via `custom` if so.

### Threading
`_lookup-modal.blade.php` → `createFromLookup` validation →
`LeadsheetController::resolveDetectionParams()` (preset baseline + slider
overrides; drops keys still equal to basic-pitch defaults so an untouched modal
sends nothing) → `MidiTranscriptionService::transcribe($id, $params)` →
**temp JSON file** → `transcribe.py` arg 2 → keyword args on `predict()`.
The params used are echoed back by Python and cached in
`transcriptionRaw.detectionParams`.

### Gotcha — params MUST go via a file, not an inline arg
Two stacked bugs made the first cut a silent no-op (every transcription used
defaults regardless of the modal):
1. Passing `{"k":v}` as a **bare CLI argument** lets the shell strip the double
   quotes → `json.loads` fails.
2. `transcribe.py`'s `except: params = None` fallback **swallowed the error**,
   so it just used defaults with no signal.

Fix: `MidiTranscriptionService` writes a temp `*.params.json` next to the audio
and passes its **path**; `transcribe.py` reads it with `encoding='utf-8-sig'`
(tolerates a BOM). Detection params only apply at **import time** —
`reshiftDownbeat` works from cached notes and never re-runs Python, so a
bad-detection transcription must be **re-imported** to fix, not re-shifted.

---

## 4. Problem 2 — Repeated Notes & Voice Separation

### Symptom
The tab output shows many repeated notes, especially on lower strings. Bass lines or sustained chord voicings appear as individual melody events, cluttering the tab.

### Root Cause
No separation between melody and accompaniment. Every note from `basic-pitch` is treated as a melody event.

### Implemented Fixes (Session 2026-05-04)
- **Guitar Range Filter:** Clamps the pitch range to standard guitar (MIDI 40–88). This eliminates sub-bass noise and overtone artifacts. ✅
- **Polyphony Restoration:** Moved away from "Top-1" filtering to support full polyphonic transcription (chords/dyads) while maintaining range constraints. ✅

### Proposed Future Improvements
#### A. Duplicate Suppression
Skip identical pitches if they appear within a proximity (e.g., < 480 ticks) of the previous occurrence:
```php
if (isset($lastPitchTick[$key]) && abs($tick - $lastPitchTick[$key]) < 480) {
    continue; // skip repeated note jitter
}
```
#### B. Voice Assignment
Implement a voice-leading tracker:
- **Voice 1 (Melody):** Highest sounding pitch.
- **Voice 2 (Bass):** Lowest sounding pitch (optional toggle).

---

## 5. Problem 3 — Over-Complex Rhythms

### Symptom
Tab output contains 32nd notes, dotted 16ths, and notes overflowing bar boundaries. Unplayable and visually cluttered.

### Root Cause
No beat-aware quantization. Notes were rounded to the nearest 16th independently without awareness of measure boundaries.

### Implemented Fixes (Session 2026-05-04)
- **Beat-Boundary Clamping:** Notes are strictly truncated at every 480-tick beat boundary. Notes can no longer "bleed" into the next beat. ✅
- **No-Dots Policy:** strictly whole, half, quarter, 8th, and 16th notes. Quantization favors the **larger duration** to fill gaps and align with beats. ✅
- **Explicit Rest Insertion:** Fills all timeline gaps with explicit rest objects (`isRest => true`) to ensure every measure is mathematically complete. ✅
- **Gap Closing:** 120-tick (16th) gaps are absorbed into preceding notes to clean up beaming. ✅

### Proposed Future Improvements
#### A. AI Polish Pass
Update the Gemini prompt to enforce that every beat sums to exactly one quarter note:
```
4. **RHYTHMIC SIMPLIFICATION**: Ensure every note stays within its beat. 
   Every beat (1, 2, 3, or 4) must be mathematically filled with exactly 480 ticks of content.
```

---

## 6. Problem 4 — Chord Symbol Mis-Identification

### Symptom
Chord symbols are wrong (e.g., Dm7 labeled as F6).

### Root Cause Summary
1. Identification ran **pre-quantization** on noisy beat buckets.
2. No durational weighting (a 50ms ghost note had same weight as a 2s bass note).
3. `identifyFromMidi()` used a stripped-down re-implementation of the engine.

### Implemented Fixes (Session 2026-05-04)
- **Duration-Weighted Aggregation:** Only notes lasting 100ms+ are included in the harmonic region grouping. ✅
- **Post-Quantization ID:** Identification now runs *after* rhythmic cleanup, using cleaner pitch sets. ✅
- **Shared Core Algorithm:** `VoicingCrossref` now uses a unified core for both fret-based and MIDI-based identification. ✅

### Feature Parity: Full Engine vs. Old MIDI Path
| Feature | Full Engine | Old MIDI Path |
|---|---|---|
| Rootless voicing detection | ✅ | ❌ |
| Tritone-dominant upgrade | ✅ | ❌ |
| Full slash/inversion bonuses | ✅ | ❌ |
| Context-aware re-scoring | ✅ | ❌ |
| DB diagram lookup | ✅ | ❌ |

---

## 7. Connecting Reference Leadsheets to Harmonic Awareness

Integrating a "Standard Reference" database (like the Mike Oliphant Jazz Standards JSON) provides a powerful feedback loop for the `VoicingCrossref` engine.

### Reference Anchoring Mechanism
1.  **Parsing:** Standard progressions (Title, Key, Chords-per-bar) are parsed into an `IntermediateAnalysis` template.
2.  **Harmonic Bias:** During the `identifyFromPcSetFull` pass, the engine receives an `expectedChord` from the template.
3.  **Scoring Bonus:** If the audio pitch data matches the reference chord (Jaccard similarity > threshold), the system applies a scoring bonus to the standard chord name, ensuring professional consistency.

---

## 8. Implementation Roadmap

| Priority | Feature | Status | Impact |
|----------|---------|--------|--------|
| **P0** | Guitar Range Filter | ✅ Implemented | Removes sub-guitar noise from tab |
| **P1** | Beat-Boundary Clamping | ✅ Implemented | Strictly regularizes the rhythmic pulse |
| **P1** | Leading Silence Removal | ✅ Implemented | Score starts at music onset |
| **P1** | No-Dots Rhythmic Grid | ✅ Implemented | Maximizes tab readability |
| **P1** | Duration-Weighted ID | ✅ Implemented | Better chord recognition input |
| **P2** | User Downbeat Offset UI | ✅ Implemented | User clicks the true "1" note in the tab; tick-resolution re-shift via pickup bar |
| **P2** | Bass-Snap Beat Correction | ✅ Implemented | Rebuilds beat_times from bass-note anchors so the grid follows rubato |
| **P1** | T4 AI Job Re-division | ✅ Implemented | Deterministic chord ID for all paths; AI narrowed to structuralisation (later removed — see T8) |
| **P2** | T8 Remove AI refinement | ✅ Implemented | Transcription path now 100% deterministic; Gemini "musicalize" pass dropped |
| **P1** | T1 Fretboard Optimisation | ✅ Implemented | Viterbi over (string,fret) — playable tab instead of absurd fret leaps |
| **P1** | T1b Melody→Voicing Position | ✅ Implemented | Chord voicings tracked to the melody's hand position per bar |
| **P1** | T1 Bar-Locked Positions | ✅ Implemented | One compact 4-fret hand position per bar — no fret-1/fret-8 mixing |
| **P2** | T2 Voice Labelling | ⏸ Shelved | Position win folded into T1; chord-ID win smaller than T3 |
| **P2** | T3 Phase 3 Context Awareness | ✅ Implemented | Sequence rerank (key-fit+bigram+Viterbi) over per-region audio chord IDs |
| **P3** | Tap-Correct Re-time UI | ⏳ Designed | Manual downbeat tapping for recordings bass-snap can't anchor |
| **P3** | Reference Leadsheet Sync | ⏳ Pending | Anchoring transcription to standard progressions |

---

## 9. Maintenance & Debugging

*   **Testing:** `php artisan sbn:transcribe-test path/to/audio.mp3`
*   **Bass-snap inspection:** `php artisan sbn:bass-snap-debug {leadsheetId}` — prints `beat_track` vs bass-snapped grid per beat with deltas.
*   **yt-dlp Update:** `.\yt-dlp.exe -U`
*   **Harmonic Engine:** Core logic in `VoicingCrossref.php` (Pass 1–3c).
*   **Assembly:** `LeadsheetController::assembleTranscription()` owns beat→bar grouping, bass-snap, chord region detection, melody reconstruction, context reranking (T3) and videoSync mapping — the entire deterministic pipeline. No LLM call.

> **History:** This doc supersedes the 2026-05-04 `Audio-Transcription-OPUSAnalysis` audit, whose proposed fixes (range filter, beat clamping, no-dots grid, duration-weighted ID) are all now implemented above. The chord mis-ID deep-dive it called out lives in [SBN-Identifier-Reference.md](SBN-Identifier-Reference.md).

---

## 10. Next Phases — Roadmap & Design Notes

> Captured 2026-05-18 from a planning session. The pipeline's remaining
> weaknesses are **music-theory problems, not acoustic ones** — and every item
> below reuses an engine that already exists in the codebase. Nothing here is a
> from-scratch research effort. Listed roughly in value order.

### Fixed — `transcriptionRaw` leaked into the AI prompt ✅

(Session 2026-05-20, fixed as part of T4.) `musicalizeTranscription()` used to
build the user prompt as `json_encode($rawAnalysis)` — the **entire** analysis
array, including the `transcriptionRaw` blob (cached `beats`, `beat_times`, all
raw `notes`) and `raw_beats` / `melody_data`. The AI payload now contains only
the title, key/tempo/time-sig, and a compact one-line-per-bar chord listing;
`transcriptionRaw`, `raw_beats`, `melody_data` and pitch integers are never
encoded into it. See T4 below. *(Moot after T8 — there is no AI prompt in the
transcription path anymore; retained as history.)*

### Phase T1 — Fretboard position optimisation ✅ IMPLEMENTED (Session 2026-05-20, bar-locked rewrite 2026-05-21)

**Problem (as was):** `midiToTab()` mapped each MIDI pitch to *a* string/fret
greedily and in isolation (first string with fret 0–15). Even a perfectly
correct transcription produced physically absurd tab — a line could leap
fret 2 → 14 → 5 for notes any guitarist plays in one hand position.

**First version (2026-05-20)** ran a per-note Viterbi minimising hand *travel*
against a running anchor. It removed the absurd leaps but still let the hand
*creep* — a 5→6→7→8 line within a bar paid almost nothing because each hop was
small and measured against the previous note. It minimised travel, not
*position changes*.

**As built — bar-locked rewrite (2026-05-21):** the Viterbi now runs over
**bars**, not notes. Each bar commits to one hand position.

- **State:** a candidate hand position — the index-finger fret, 0–17. A
  position anchors a **4-fret window** `pos..pos+3`, because a real fretting
  hand covers ~4 frets, not the whole neck. One state per bar (`tick / 1920`).
- **Node cost:** sum over the bar's notes of the cost to play each from that
  window. Each note picks the `(string,fret)` that keeps it *inside* the
  window; a note that can't be is pulled to its nearest fret and pays a stiff
  `1.5 ×` per-fret out-of-window penalty (plus a tiny `0.05 × fret` height
  tiebreak). Open strings are nearly free and don't constrain the window.
- **Why the window matters:** the first (2026-05-20) version used a 9-fret
  span, so a 1st-position note and a 5th-position note *both* looked free in
  one bar — the optimiser had no reason to pull them together and produced
  bars mixing fret 1 and fret 8. The 4-fret window forces the bar to commit:
  comp notes get pulled onto lower strings at higher frets to sit *with* the
  melody's hand position instead of dropping to open frets. Verified on
  *Manhã de Carnaval* — a bar that read `f1 f3 f3 f1 f8 f7 f5` (8-fret span)
  now reads as a single 5th–8th-position grip (3-fret span).
- **Transition:** between consecutive bars, `0` if the position is unchanged,
  else `0.6` flat shift cost + fret distance. The hand only relocates at a bar
  line, and only when the next bar needs it.
- **Open-string handling — `tab_position_style`.** An open string's cost
  depends on the user's per-piece choice from the import modal:
  - *fretted* (default, jazz chord-melody): open string costs `pos × 0.12` —
    free at the nut, dearer the higher the hand sits. It stays cheaper than an
    out-of-window stretch but dearer than an in-window fretted alternative, so
    a hand at the 5th position frets E4 at B-string 5 instead of reaching for
    the open e. Verified on *Manhã* — a chord that read `x075x0` (two scattered
    open strings) becomes `5x755x` (one 5th-position grip).
  - *open* (classical / fingerstyle): open string is a flat `0.05`, used
    freely regardless of hand position.
  The chosen style is cached in `transcriptionRaw.tabPositionStyle` so a
  `reshift-downbeat` re-assembly reuses it (or accepts an override).
- **Chord-position bias.** Each bar's identified chord suggests a neck
  position — `chordPositionHints()` maps the chord root to the low fret where
  it sits on the E or A string (G→3, F→1, C→3, A→5, D→5). A *soft* node-cost
  term (`chordBiasWeight = 0.18` per fret) nudges a bar toward that position.
  Deliberately soft: measured on *Manhã*, forcing the G7 bar from open to 3rd
  position costs 3.6× more for that bar's actual melody — the melody there is
  genuinely a low, open-string phrase. The bias only flips bars that are
  *already close*; it will not (and should not) steamroll a melody that
  honestly wants open position. It is a tiebreak, not an override.
- **Assignment:** once each bar's position is fixed, every note in it takes its
  chosen `(string,fret)` for that position — so a whole bar sits in one region.
- A bar entirely out of range breaks the chain (notes keep `midiToTab`'s
  fallback). `midiToTab()` still does the *initial* assignment; absolute MIDI
  is stashed as `_midi` and stripped by the pass.

**Net effect:** the tab reads as a guitarist plays — one compact position per
bar, relocating only at bar lines. Also tightens `melodyPositionHints()` (the
chord-voicing position coupling), since every note in a bar now shares a fret
region, so the mean-fret hint is sharp instead of blurred.

**Caveat:** a bar whose notes genuinely span more than four frets gets the
least-bad single position (the out-of-window penalty is finite, not infinite)
rather than thrashing — the remaining notes pay the stretch. This is the right
behaviour: a guitarist *does* stretch within a bar rather than re-anchor twice.
T1 still optimises every detected note (bass + comp + melody) as one stream;
true per-voice layout would need T2 voice labelling, which is shelved.

**Known limitation — "playable" ≠ "stylistically nicest" (noted 2026-05-21).**
The cost function has a built-in low-fret preference (`fret × 0.05`, open
strings near-free). All else equal, lower on the neck *is* easier — and this is
correct most of the time. But it diverges from style: a jazz phrase that a
player would finger as a compact closed-position grip up the neck (e.g. a bar
of D4/F4/G4 played in 4th position on strings 2–4) will instead be laid out in
1st position, because 1st position is literally cheaper note-for-note. The
output is *playable and correct*, just not the fingering a jazz guitarist would
choose. T1 sees only fret height and hand travel — it has no concept of
"closed-position voicing" as a stylistic target, and pitch data alone does not
carry that intent. Pushing the cost model to chase this (rewarding tight
adjacent-string clustering, or penalising low positions) risks regressing the
clear wins above — deliberately **not pursued**. Accepted as a limitation.

#### T1b — Melody-position hints for chord voicings (Session 2026-05-20)

`optimizeTabPositions()` only laid out the single-note **melody**. The **chord
voicings** are picked separately by `ProgressionBuilder::buildVoicings()`, whose
Viterbi anchored the whole progression on the *first chord's* `seedCost()` and
then only minimised *relative* fret motion between chords. It had no idea where
the melody was — so an A-minor piece with the melody at the 5th–8th fret would
still get an open `x0221x` Am instead of `5775xx`.

**Fix — melody → voicing position coupling:**
- `LeadsheetController::melodyPositionHints()` computes a `measureIndex →
  meanFret` map from the post-T1 melody (mean of *fretted* notes per bar; open
  strings/rests excluded). Passed into `buildVoicings()` as `position_hints`.
- `ProgressionBuilder::positionHintCost()` penalises a voicing whose fretted-
  note centroid is far from its measure's hint. Threaded into every Viterbi
  context (`POSITION_HINT_WEIGHT = 0.45` — a strong pull, the user's choice for
  chord-melody material). Fully-open voicings get only a soft pull.
- `seedCost()` now takes a measure index: when a hint exists for slot 0, the
  hint **replaces** the fixed category seed bias (`jazz → fret 5`), so the
  first chord anchors to the melody, not a generic register.
- Per-slot node cost applied in `viterbiSearch()` for slots 1+.
- Inert when `position_hints` is absent — the standalone progression builder is
  unaffected.

**Caveat:** the hint is mean melody fret per *bar*; a bar where the melody
leaps registers gets a blurred target. Fine in practice — chord-melody melodies
mostly stay in a position within a bar.

### Phase T2 — Voice labelling (analysis aid) — NOT BUILT

**Idea:** tag every detected note as bass / harmony / melody — *without
dropping any* — and use the labels to improve position detection, chord ID and
rhythm. Everything still collapses to a single-voice tab; the labels are an
internal analysis aid, not an output format.

**Status — deliberately not built (Session 2026-05-21).** An attempt at a
*melody-extraction* version (keep top note per tick, drop the rest) was
prototyped and **reverted** — dropping detected notes was never the intent. The
genuine win of voice labelling is concentrated in *position detection*, and
that turned out to be fixable directly in T1 without a full voice classifier
(see T1 below — narrowing the hand-position window). The chord-ID benefit is
smaller than T3 would give, and the rhythm benefit is doubtful (bass-snap
already uses bass onsets for the grid). So T2 as a standalone phase is shelved;
revisit only if T1's position fix proves insufficient on more material.

**Constraint:** the tab editor renders a **single voice** and there is no plan
to extend it — so multi-staff separation is out of scope regardless.

### Phase T3 — Wire the context-aware identifier into the audio path ✅ IMPLEMENTED (Session 2026-07-07)

**Problem (as was):** doc §6 — the audio path identified every chord *in
isolation* via `identifyFromMidi()`. The context-aware Phase 3 engine (Viterbi +
bigram + key-fit, see [SBN-Identifier-Reference.md](SBN-Identifier-Reference.md)
§5) was built but **only connected to the fret path** (`identifyVoicingsBatch`),
never to transcription.

**As built — sequence rerank after per-region ID:**
- `assembleTranscription()` still runs `identifyFromMidi()` per harmonic region
  as the first pass. Each emitted chord entry now carries a transient `_seq` id
  and the full `identifyFromMidi()` result (which already includes `candidates`,
  `pcs`, `bass_note` — exactly the per-slot shape the reranker consumes) is
  collected into an ordered `$chordSeq`. Emission is funnelled through one
  `$emitChord` closure so all three flush sites (mid-region / end-of-bar /
  trailing) record into the sequence identically.
- After the grid is assembled, `applyContextualChordReranking()` feeds the
  ordered slots through `ContextualReranker::rerank($slots, $songKey)` — the
  same engine the fret path uses (2d diatonicity → 2e bigram → 2f Viterbi).
  Reinterpreted names are written back into the matching chord entries by
  `_seq`, and the scratch key is stripped so `_seq` never reaches `json_data`.
- **Conservative by construction.** The reranker only shifts a *near-tied* local
  reading (its `minScoreRatio` guards keep a dominant `identifyFromMidi()` winner
  intact) and can never introduce a name no candidate produced. So a clean
  ii-V-I stays untouched; the win is on the borderline regions (rootless
  voicings, tritone ambiguity, enharmonic spelling) doc §6 called out.
- **Non-fatal.** Any exception logs a warning and leaves the deterministic
  per-region labels; `_seq` is stripped on every path.
- **Key source.** `$analysis['key']` (audio imports default to `'C'` when none
  was inferred). The key-dependent sub-passes are internally inert on a neutral
  key; bigram + Viterbi don't use the key at all — so a wrong/absent key
  degrades gracefully rather than mis-ranking.

Tests: `tests/Feature/Identifier/AudioContextRerankTest.php` drives
`assembleTranscription()` on a synthetic ii-V-I rawResult and asserts labels +
no `_seq` leakage (multi-chord and single-chord/short-circuit paths).

**Caveat:** the reranker's ceiling is the candidate list `identifyFromMidi()`
produced for each region — if the right reading isn't in a slot's top-K
candidates, sequence context can't summon it. T5 (reference anchoring) is the
lever for cases where even the candidate set is wrong.

### Phase T4 — Redivide the AI's job ✅ IMPLEMENTED (Session 2026-05-20)

**Problem (as was):** the old `musicalizeTranscription()` prompt asked Gemini to
do **chord identification from raw MIDI pitch-class integers** and **rhythmic
quantization** — both things the deterministic pipeline already does *better*
(`VoicingCrossref`, the beat-clamp/no-dots quantizer). LLMs are weak at
mechanical pitch-set→chord arithmetic and could *overwrite* good deterministic
results. The prompt also dumped tens of thousands of tokens of redundant JSON.

**As built — re-division of labour:**
- **`assembleTranscription()`** now runs the deterministic harmonic-region
  chord ID (`VoicingCrossref::identifyFromMidi()`) for **both** the AI and
  non-AI paths. The old AI-only branch that stuffed `label => '?'` + `pitches`
  slots and attached `raw_beats` is gone — chords are identified deterministically
  before the AI ever sees them. The `ai_cleanup` opt no longer affects assembly.
- **`musicalizeTranscription()`** is now structuralisation-only:
  - *Input:* title, detected key, tempo, time signature, `"solo jazz guitar"`,
    and a compact **one-line-per-bar chord listing** (`"3: Dm7 G7"`). No pitch
    integers, no melody, no `raw_beats`, no `transcriptionRaw`.
  - *Output schema:* `key`, `sections` as `[{name, barCount}]`, and an optional
    `relabel` map (`[{from, to}]`) for enharmonic spelling fixes only.
  - *Merge:* the deterministic flat bar list is **re-sliced** into the AI's
    named sections by `barCount` — bars/chords/beats are never rewritten. If the
    `barCount`s don't sum to the total, it falls back to one section and logs a
    warning. The `relabel` map is applied to chord labels (spelling only).
    `melody_data`, `videoSync`, `transcriptionRaw` pass through untouched.
- **Removed:** `resolveUnidentifiedChords()` (dead — no `'?'` slots exist now).
- AI failure is non-fatal: the deterministic analysis is already complete.

**Net effect:** cheaper, faster, *better* — the AI does the one thing it's good
at (pattern recognition over chord *names*) on clean input, and can no longer
corrupt deterministic chord/rhythm results.

> **Superseded by T8.** T4 kept a *narrowed* AI pass (structuralisation +
> relabel). T8 removed even that — the transcription path is now 100%
> deterministic. Reference anchoring (T5) is now a purely deterministic idea:
> force-align the grid + bias chord ID from the matched standard, no LLM.

### Phase T5 — Reference anchoring (known standards)

When the tune matches an entry in the ~1,400-row **Mike Oliphant Jazz Standards
DB**, use its bar count / section labels / changes to force-align the grid and
bias chord ID (doc §7). Especially powerful for known standards (e.g. *Body and
Soul*). Composes with bass-snap (bias anchors toward expected bass pitches).
Fully deterministic — a matched standard is a *reference*, not a prompt.

### Phase T6 — Tap-correct re-time UI (manual fallback)

Designed, not built (see §3 Future Improvements B). Manual downbeat tapping
against the synced video for recordings where bass-snap can't find good
anchors. Tapped times become `downbeatOverrides` feeding `assembleTranscription()`.
Lower priority — bass-snap covers the common case.

### Phase T7 — Cross-ref detected voicings against the chord DB (proposed)

**Idea:** attach a real catalogued voicing (a `diagram_id` from
`sbn_chord_diagrams`) to each transcribed chord — the actual shape the player
used — instead of only a bare chord *name*. Today `identifyFromMidi()` answers
"what chord *name* is this pitch-set?" and always returns `diagram_id = null`
(no fret string to look up). Now that T1 lays out real per-bar `(string,fret)`
positions, a region *does* have playable frets — so we can ask the fret-based
twin instead: "is this specific shape one of our catalogued voicings?"

**Reuse, not new algorithm.** `VoicingCrossref::identifyFromFrets()` /
`findDiagramByFrets()` already do DB diagram lookup from a 6-char fret string —
they're the fret path's engine. The work is plumbing:
1. For each harmonic region, assemble a 6-char fret string from the T1-assigned
   positions of the notes that fall in that region (bass + comp; the melody note
   is the top voice). Regions where the notes don't form a single grippable
   shape (span > ~4 frets, or too few voices) simply don't cross-ref — leave
   `diagram_id` null, keep the name from the context-reranked `identifyFromMidi`.
2. Call `identifyFromFrets($frets, $position, $songKey)` → take its
   `diagram_id` when the name agrees with the region's already-chosen label
   (guard against the fret shape naming a *different* chord than the reranked
   sequence settled on — trust the sequence for the name, the shape only for the
   diagram link).
3. Store the `diagram_id` on the chord entry so the leadsheet links to the real
   voicing (fingering diagram, chord-library "level up" page).

**Why it's the *right* replacement for the removed Build Voicings block.** The
old block *synthesized* voicings from labels via `ProgressionBuilder` — inventing
fingerings the recording never contained, keyed on the wrong default key. This
instead *recognises* the voicing that was actually played. It's additive
(diagram links), never overwrites the transcribed melody/tab, and needs no key.

**Ceiling:** only as good as T1's fret assignment and the DB's voicing coverage;
a shape not in `sbn_chord_diagrams` just stays unlinked. No downside — it's a
pure enrichment pass.

#### Import-modal cleanup (Session 2026-07-07)

The **Build Voicings** block (checkbox + Voicing Style / Extension Mode / Rhythm
Override) is now **hidden in Audio Transcription mode** — it only shows for AI
Song Search. Rationale: the block runs `ProgressionBuilder` to *synthesize*
voicings/comping/melody from chord labels, which is exactly what the AI-search
path needs (no performance data) but actively wrong for a transcription (which
already carries melody + tab from the recording, and whose `analysis['key']`
defaults to `'C'` in this path, so builder voicings would be keyed wrong and
clobber the transcribed data).

- Modal (`_lookup-modal.blade.php`): the four form-groups gate on
  `mode !== 'audio'`; switching to audio sets `buildVoicings = false` (so the
  still-in-DOM hidden inputs submit nothing), switching back to search restores
  the AI-search default `true`.
- Backend backstop (`createFromLookup`): `$wantVoicings` additionally requires
  `mode !== 'audio'`, so even a hand-crafted request can't run the builder on an
  audio import.
- T7 (above) is the intended *replacement* enrichment for the audio path —
  recognise the played voicing in the DB, don't synthesize a new one.

### Phase T8 — Remove the AI refinement pass ✅ IMPLEMENTED (Session 2026-07-07)

The Gemini "musicalize" pass is **gone**. The transcription path is now fully
deterministic end-to-end.

**History.** Originally the AI quantized *rhythm*. T4 (2026-05-20) took rhythm
and chord-ID away from it (deterministic quantizer + `VoicingCrossref` do both
better) and narrowed it to **section grouping + enharmonic relabel + key
correction**. T8 removes that remnant too.

**Why drop the narrowed pass:**
- **Rhythm** — already quantized deterministically upstream (beat-clamp / no-dots
  grid / gap-close, §5) before the AI ever saw a bar. The checkbox still *said*
  "AI Rhythmic Cleanup" but hadn't done that since T4.
- **Enharmonic spelling** — owned by the app's deterministic spelling authority
  (`HarmonicContext::useFlatsFor` + the JS twin). The AI relabel was redundant
  with a system already trusted more.
- **Section grouping** — the AI inferred form from a bare chord-per-bar list (no
  melody, no repeats, no rhythm). Too weak to be reliable; in practice labels
  rarely surfaced, and on a barCount mismatch it silently collapsed to one
  section anyway. Not worth an LLM round-trip.
- **Key correction** — silently *overwrote* the deterministic key with no guard,
  a real downside for ~zero upside.
- **Unevaluable.** Success was never logged (only the failure path warned), so
  the tool's contribution was impossible to measure — the deciding factor.

**Removed:** the `ai_cleanup` checkbox (`_lookup-modal.blade.php`), its validation
key + branch in `createFromLookup`, and the entire `musicalizeTranscription()`
method. `LLM\LookupClient` stays — it's still used by the separate **AI Song
Search** leadsheet path (`SongLookup`), which is untouched.

**Verdict — no AI in transcription.** Revisit only if a genuinely more
sophisticated capability is on the table (form detection from melody + repeat
structure, or rhythmic *interpretation* that rewrites note positions) — and that
would need the evaluation harness this pass never had, plus the "AI can't
corrupt good deterministic data" guard T4 established.

### Smaller fixes

- **90-second cap — ✅ lifted.** `transcribe.py` now loads full duration
  (`librosa.load(audio_path, sr=22050)`, no `duration=` arg) and hands the full
  audio path straight to `predict()`. The old `.clip.wav` round-trip (a
  full-length `soundfile.write` copy that only existed to clip to 90 s) is
  removed — basic-pitch reads the converted WAV directly.

### Suggested order

~~T4~~ ✅ · ~~T1~~ ✅ (Session 2026-05-20; bar-locked rewrite + 4-fret window
2026-05-21) · ~~T2~~ ⏸ shelved (the position win it promised was achieved
inside T1; revisit only if more material shows T1 is insufficient) · ~~T3~~ ✅
(Session 2026-07-07 — context-aware sequence rerank wired into the audio path) ·
~~T8~~ ✅ (Session 2026-07-07 — AI refinement pass removed; path now fully
deterministic)
→ **T5 next** (reference anchoring to the Jazz Standards DB — force-align grid +
bias chord ID; also lifts T3's candidate-set ceiling; now a deterministic pass,
no LLM) → T7 (cross-ref detected voicings to the chord DB — the replacement for
the removed Build Voicings block) → T6. The 90 s cap can be lifted any time.

---

## 11. Stem Separation (Session 2026-07-02, two-phase audition rebuild 2026-07-06)

### What it does
Recordings with vocals or a full band badly confuse `basic-pitch` — it's a
polyphonic *pitch* detector, not a source separator, so a lead vocal or piano
comping over the guitar shows up as spurious notes. **Demucs** (`htdemucs_6s`,
already installed in `python_env` alongside torch 2.5.1+cu121) runs a 6-stem
separation (guitar / bass / vocals / drums / piano / other). On the dev GPU
(GTX 1070, CUDA) a ~152s recording separates in **~7s**.

### Two-phase workflow (audition + selection) — 2026-07-06
Separation is now an explicit **opt-in step** in the import modal, split from
transcription so the admin can **listen to each stem and pick which to
transcribe** (not just guitar):

```
Phase 1  [Separate stems]  ──POST /admin/leadsheets/separate-stems──▶
           download/convert → Demucs (all 6 stems) → persist to
           storage/app/stems/{session}/ → return {session, stems[]}
         ▶ audition each stem (GET /admin/leadsheets/stems/{session}/{stem})
         ☑ tick stems to transcribe (guitar+bass default)
Phase 2  [Look Up & Generate]  ──POST create-from-lookup {stem_session, stems[]}──▶
           sum ticked stems (separate_stem.py --sum, no Demucs re-run) →
           downconvert 22050Hz mono → basic-pitch → assemble → sweep session
```

- **Selection covers the low-note caveat.** Summing `guitar` + `bass` is the
  documented fix for htdemucs_6s attributing low guitar notes to the bass stem
  — it's now a checkbox, not a code change.
- **Sum is peak-normalised** to −1 dBFS so adding stems can't clip.
- **Sessions are ephemeral.** A completed transcription sweeps its own session;
  orphaned sessions (separated, never transcribed) are removed by
  `php artisan sbn:sweep-stem-sessions --hours=6` (scheduled hourly in
  `routes/console.php`, also runnable manually).
- **Skipping separation transcribes the raw audio.** The old auto guitar-isolate
  default is gone — `resolveDetectionParams()`'s `separate_stem` now defaults
  **false**. If you don't run the separate step, basic-pitch sees the full mix.

### Legacy one-shot path (still present)
`separateStem()` (guitar-only, Demucs → copy `guitar.wav`) and
`separate_stem.py`'s single-stem mode remain for back-compat, driven by the
`separate_stem` boolean flag on the non-session path. `separate_stem.py` also
gained `--all-stems <dir>` (phase 1) and `--sum <a,b,c> --stems-dir <dir>
<out.wav>` (phase 2) modes.

### Where it sits in the pipeline
Separation runs **between** `convertToWav()` and `runPythonTranscription()` —
strictly *before* the 22050Hz/mono downsample basic-pitch expects, not after:

```
download/upload → convertToWav() (22050Hz mono)
                → separateStem() (Demucs, 44.1kHz stereo guitar.wav out)
                → convertToWav() again (re-downconvert the stem to 22050Hz mono)
                → runPythonTranscription()
```

The re-downconvert is necessary because Demucs's stem output is full-length
44.1kHz **stereo** — reusing `convertToWav()` for the second pass (rather than
writing a bespoke downsampler) keeps exactly one code path responsible for
"whatever basic-pitch receives is 22050Hz mono."

### The modal UI (two-phase)
`_lookup-modal.blade.php` (audio mode) replaces the old single "Separate guitar
from vocals first" checkbox with a **[Separate stems]** button + audition grid.
Alpine (`lookupModal()`) state: `stemSession`, `availableStems`, `chosenStems`,
`stemSeparating`, `stemError`. The button `fetch`es phase 1 (so the modal stays
open), renders one `<audio>` + checkbox per returned stem, and stashes
`stem_session` + `stems[]` in hidden inputs the main form submits. Picking a
different source (video/file) calls `resetStems()` to drop stale stems.

### Endpoint / flag threading
```
Phase 1: [Separate stems] fetch → POST admin.leadsheets.separate-stems
  → LeadsheetController::separateStems() (validates youtube_id | local_audio)
  → MidiTranscriptionService::separateStemsToSession()
      download/convert → runSeparateAll() → separate_stem.py --all-stems
      → persist 6 stems to storage/app/stems/{session}/
  → JSON {session, stems[]}
Audition: GET admin.leadsheets.stream-stem {session}/{stem}
  → streamStem() (whitelists stem name; response()->file the WAV)

Phase 2: [Look Up & Generate] main form → POST create-from-lookup
  (stem_session + stems[] + detection params)
  → createFromLookup(): if stem_session present →
      transcribeFromSession(session, stems, params)
        runSumStems() → separate_stem.py --sum → downconvert → basic-pitch
  → finally: removeStemSession(session)  (sweep)
```
`separate_stem` (bool) is now only the **legacy** non-session flag, defaulting
FALSE (`resolveDetectionParams()`). It's still stripped before transcribe.py's
`*.params.json` (basic-pitch keyword args only).

### `MidiTranscriptionService::separateStem()`
Shells to `python_env/python.exe scripts/separate_stem.py <in.wav> <out.wav>
--device cuda`, mirroring the `proc_open` + `PATH`/`TEMP`/`TF_CPP_MIN_LOG_LEVEL`
env-injection pattern used by `runPythonTranscription()` and `downloadAudio()`,
and the same `JSON_START` handshake. **Graceful fallback on any failure** —
non-zero exit code, a `{"success": false}` payload, invalid JSON, or a missing
output file all log a warning and return the **original `$wavPath` unchanged**;
a bad separation must never block an import.

`scripts/separate_stem.py` contract:
```
separate_stem.py <input.wav> <output.wav> [--device cuda|cpu]
```
Falls back to CPU internally if `torch.cuda.is_available()` is False. Invokes
`demucs.separate.main([...])` in-process (no nested `-m demucs` subprocess),
writes Demucs's full 6-stem output tree to `<input>.demucs_out/` (a scratch dir
next to the input, so cleanup is one `shutil.rmtree`), copies
`htdemucs_6s/<basename>/guitar.wav` to the requested output path, then deletes
the scratch tree. Same handshake as `transcribe.py`: warnings suppressed,
`JSON_START` then one line of JSON — `{"success": true, "stem_path": "..."}` or
`{"success": false, "error": "...", "traceback": "..."}`.

### Cleanup
Every intermediate file is tracked and unlinked in the caller
(`transcribe()`'s step 4 / `transcribeLocalFile()`'s `finally` block): the raw
download, the first-pass wav, the guitar-stem wav, and the re-downconverted
stem wav. The Demucs output tree itself is deleted by `separate_stem.py`
before it returns, so nothing accumulates under `storage/app/temp_audio`.

### `transcriptionRaw.separateStem` caching
Whether separation ran is cached in `transcriptionRaw.separateStem` alongside
`bassSnap` / `tabPositionStyle`, so a later `reshiftDownbeat` re-assembly (which
never re-runs Python) can record what actually produced the transcription
rather than silently defaulting. Reshift doesn't accept a `separate_stem`
override — there's nothing to re-run — it just carries the cached value
through.

### Caveat — `htdemucs_6s` also strips bass
The 6-stem model separates guitar from bass, vocals, drums, piano, and other.
If a recording's **low guitar notes** get clipped because Demucs attributed
them to the `bass` stem rather than `guitar`, the documented (not yet built)
fix is to **sum the `guitar.wav` and `bass.wav` stems** before the
re-downconvert step, rather than using `guitar.wav` alone. Left as a knob on
`separate_stem.py` for a future session if this shows up on real material —
not implemented now since it wasn't observed as a problem on the verification
pass.

---

## 12. Editor Playback Aids (Session 2026-07-06)

Two transcription-workflow features in the tab editor (`TabEditor.vue`), both
riding the existing `AudioEngine` singleton.

### 12a. Note preview on input
Hear a note the moment you type or click it — the fastest way to catch a wrong
fret against the original.

- `AudioEngine.previewNote(midi, dur=0.6, vel=0.85)` fires one note off the
  transport: prefers the **nylon sampler** (`NylonSampler.ready` getter added),
  falls back to `PitchedSynth`. Best-effort — never throws into note input.
- `useNoteInput(cursor, model, wrapCommand, reposition, { previewNote })` calls
  it from `commitFret()` and `commitGraceFret()` via `stringFretToMidi`.
- Clicking a note (`TabEditor.onCursorMousedownEvent` → `previewClickedNote`)
  reads the actual note on the clicked string and sounds it.
- **🔊/🔇 Notes** toggle in the editor tab bar (`notePreviewEnabled`). Engine
  inits lazily on first keypress/click (a valid audio-unlock gesture).

### 12b. Synth-vs-original A/B blend
Play the synth transcription against the **real recording**, together, with
on/off toggles + a balance slider — direct A/B while transcribing.

- **Persisted source audio.** Audio imports now keep the full original: each
  transcription method returns `source_audio_path` (a copy that survives its own
  cleanup, via `preserveSourceAudio()`); `createFromLookup()` moves it to
  `public/audio/source/{leadsheetId}/original.wav` and records
  `json_data.sourceAudio = { url, kind }`. For a stem-session import the full
  original is reconstructed from `_original.wav` kept in the session dir (not the
  chosen stems). Gitignored (`/public/audio/source/`), scp'd like backing tracks.
- **Blend path.** `edit.blade.php` exposes `window.__sbnLeadsheet.sourceAudio`.
  `loadAllEvents()` passes `{ demoUrl: sourceAudio.url }` to `engine.load()`; the
  engine decodes it onto the **demo bus** and `setBlend()` crossfades synth↔demo.
  `applyMix()` maps the UI: synth-only → blend 0, original-only → blend 1, both →
  `mixBalance`. Toggling a source reloads events (synth off ⇒ empty timeline so
  only the demo sounds; `Scheduler` treats 0 events as never-ending, so the demo
  isn't cut off).
- **UI.** A source-mix bar under the transport (`.sbn-source-mix`), shown only
  when `hasSourceAudio`: 🎹 Synth / 🎙️ Original toggles + a balance slider when
  both are on.

**Known limitation — alignment.** The demo track starts at transport beat 0,
matching the silence-trimmed transcription. A pickup bar or residual offset can
put the original slightly ahead/behind the synth. Drift-correction (reuse the
videoSync downbeat offset to delay the demo) is a follow-up, not built.

**Not built this pass:** #3 (local audio as a first-class syncable source) and
#4 (stem separate/audition UI inside the video-sync sidebar). The persisted
`sourceAudio` is the foundation both will build on.

### 12c. Local/original audio as a syncable source (feature #3)
Any hosted audio URL now drives the video-sync path, not just video. A hosted
source whose URL ends in an audio extension renders as an `<audio controls>`
element in `VideoEmbed.vue` (`isAudioSource`) instead of a black `<video>` box;
it shares the same `videoEl` ref + handlers, so `getCurrentTime`/`seekTo`/
`play`/`pause` and the 60fps `timeupdate` poll all work identically — the media
element is its own clock. The `MediaElementClock` stub stays unused; sync runs
through the existing rAF loop reading `currentTime`.

**One-click:** the video-sync sidebar shows **🎙️ Sync to original recording**
when `sourceAudio` exists — emits `set-video-id { id: sourceAudio.url,
type: 'hosted' }`. No re-upload; it reuses the file persisted in 12b.

### 12d. Stem separation + audition in the video-sync sidebar (feature #4)
The sidebar can separate the **persisted original** into stems and sync to one
(e.g. the isolated guitar is easier to follow than the full mix):

- `POST admin.leadsheets.separate-stems` now also accepts `leadsheet_id` —
  resolves the leadsheet's `json_data.sourceAudio.url` to a public_path and runs
  the same `separateStemsToSession()` (as an 'upload' source, so the persisted
  original is never unlinked). Returns a session + the six stems.
- Audition via the existing `stream-stem` route.
- **Sync to this** → `POST admin.leadsheets.{id}.persist-stem-sync {session,
  stem}` copies the chosen session stem into `public/audio/source/{id}/
  stem-{name}.wav` (survives session sweep) and returns its URL; the sidebar
  points videoSync at it as a hosted source.

---

## 13. Transcription Lifecycle: "Fix transcription" latch ✅ IMPLEMENTED (Session 2026-07-07)

### The problem it solves
Every re-derive tool (the downbeat re-shift; the proposed detection tuning, §14)
rebuilds the grid from cached `transcriptionRaw` via `assembleTranscription()`,
which **discards manual chord/voicing/note edits** made after import. Today the
"raw" and the "worked-on" data are the same mutable `json_data` blob, so there is
no boundary between *still tuning the transcription* and *editing a finished
sheet*. The downbeat tool warns about this; it doesn't prevent it.

### The model (latch)
An audio-transcribed sheet has two stages, tracked by a single flag
`json_data.transcriptionFixed` (boolean, absent ⇒ false ⇒ still tuning):

- **Tuning** (default after import): re-derive tools (downbeat / detection /
  bass-snap) are live and re-assemble freely. Manual edits are *disposable* —
  you're hunting for the best raw transcription.
- **Fixed** (after pressing **Fix transcription**): the sheet is the source of
  truth. Re-derive tools **lock**; re-opening tuning requires an explicit
  "this discards your edits" confirm. `transcriptionRaw` is **kept** (not
  discarded), so re-tuning stays *possible*, just gated.

### Why a dedicated endpoint (not the editor save)
`update()` writes `json_data` **verbatim from the client payload** — and the
editor's tab-save posts a serialized *model*, not the whole `json_data`. Relying
on the generic save to carry a new flag is fragile. Instead the flag is written
by purpose-built endpoints that merge into `parsed_data` server-side — the exact
idiom used for `sourceAudio` persistence (read `parsed_data` → set key →
`json_encode` → `update(['json_data' => ...])`), which preserves every other key
including `transcriptionRaw`.

### Endpoints (as built)
- `POST /api/admin/leadsheets/{id}/fix-transcription`
  (`LeadsheetController::fixTranscription`) — set `transcriptionFixed = true`,
  persist, return `{success, transcriptionFixed}`. No grid re-assembly — just the
  flag. Idempotent. 422 if the sheet has no `transcriptionRaw`.
- `POST /api/admin/leadsheets/{id}/reopen-tuning` (`reopenTuning`) — set it
  false, called only after the client's discard-confirm.
- Both share `setTranscriptionFixed()`, which uses the `sourceAudio`-persistence
  idiom (read `parsed_data` → set key → `json_encode` → `update`), so every other
  `json_data` key (incl. `transcriptionRaw`) survives.

### Backend guard
`reshiftDownbeat` (and the future §14 retune/redetect endpoints) **refuse with
409** (`{success:false, fixed:true}`) when `transcriptionFixed === true`, unless
the request carries `force: true` (the client sends it right after
`reopen-tuning`). A stale client can't silently clobber a fixed sheet.

### Editor UI (`VideoSyncEditor.vue`)
- Blade exposes `transcriptionFixed` on `window.__sbnLeadsheet` (mirrors
  `transcriptionRaw`); seeded into a `transcriptionFixed` ref.
- **Tuning stage** (`!fixed`): the Set-downbeat tool + a green **✓ Fix
  transcription** button (calls `fix-transcription`, flips the ref, disarms the
  picker).
- **Fixed stage:** the tool is replaced by a "🔒 Transcription fixed" panel with
  a **Re-open tuning** button — a `window.confirm` warns it discards edits, then
  calls `reopen-tuning` and flips the ref back. `applyDownbeat` is only reachable
  in the tuning stage, so no `force` is needed from the picker itself.
- Older sheets (no flag) ⇒ treated as un-fixed (tuning available) — safe default.

### Tests
`tests/Feature/TranscriptionFixLatchTest.php` — fix → reshift 409 → forced
reshift OK → reopen → reshift OK; `transcriptionRaw` preserved across fix;
non-audio sheet rejected. (Runs against real `sbn.db`; row deleted in tearDown.)

### Sequencing
This latch is a **prerequisite** for §14 (live detection tuning): without it,
more re-derive surfaces = more ways to silently clobber. Built first; §14's
re-derive endpoints inherit the guard for free.

---

## 14. Live detection tuning in the editor (T9 — Tier 1 ✅ IMPLEMENTED, Tier 2 PENDING; Session 2026-07-07)

### Goal
Adjust basic-pitch detection knobs and **watch the tab re-render**, instead of
the current blind flow (set knobs in the import modal → full import → discover
it's wrong → re-import from scratch). UI lives in the tab editor, post-import,
tuning against the resident `sourceAudio` (§12b).

**Status:** Tier 1 (post-filter sliders) shipped — P1/P2/P3. Tier 2 (onset/frame
re-inference button) pending — P4/P5.

### The governing constraint
The knobs split by *where basic-pitch consumes them* (`transcribe.py`):

| Knob | Consumed | Re-tunable without Python? |
|---|---|---|
| `minimum_note_length` | post-filter on `note_events` | **Yes** |
| `MIN_NOTE_DURATION` (50 ms floor) | post-filter | **Yes** |
| MIDI/guitar-range clamp | inside `predict()` (freq) | **Yes** as a MIDI post-filter proxy |
| `onset_threshold` | **inside** `predict()` | **No** — needs re-inference |
| `frame_threshold` | **inside** `predict()` | **No** — needs re-inference |

So there is no single "all live" path — hence a two-tier design. Both tiers
reuse the `reshiftDownbeat` machinery (cached raw → assemble → convert → persist
→ return fresh leadsheet).

### Tier 1 — live post-filter sliders ✅ (P1–P3, as built)
Min-note-length + MIDI range clamp. **Key simplification found during build:**
no `transcribe.py` change was needed. The cached `transcriptionRaw.notes` is
*already* the full unfiltered note set (Python's 50 ms floor only applies to
`beats`, the chord-region buckets — `notes` is untouched). So the chord regions
can be **re-bucketed from the cached `notes`** post-hoc.

- **`rebucketBeats($notes, $beatTimes, $filter=null)`** was parameterized: the
  filter overrides the 50 ms floor and adds an optional MIDI min/max clamp. Null
  filter ⇒ exactly the original behaviour (regression-safe, `notes` was already
  the superset — no floor lowering required).
- **`assembleTranscription()`** gained a `detection_filter` opt: when present it
  re-buckets `beats` from `notes` with those values (after any bass-snap
  re-bucket), and narrows the melody range clamp within the hard guitar bound
  (MIDI 40–88 floor/ceiling — a filter can only tighten it). Cached as
  `transcriptionRaw.detectionFilter` for reproducibility.
- **`retuneDetection`** endpoint (`POST .../retune-detection`) — a clone of
  `reshiftDownbeat`: reads cached raw, re-assembles with the new filter (reusing
  cached downbeat offset / bass-snap / tab-position style), persists, returns the
  fresh leadsheet. Inherits the §13 fixed-latch guard (409 unless `force`).
- **UI** (`VideoSyncEditor.vue`, tuning stage only): a "Clean up detection" panel
  — min-note-length slider + optional pitch-range clamp. `@change` fires a
  **debounced (350 ms)** POST → full re-assembly → `window.location.reload()`.
  Single source of truth (server assembly); rejected a JS mirror of the bucketing.
- **UI-state restore across the reload.** Because retune re-assembles the whole
  leadsheet, it still reloads — but before reloading it stashes
  `sbn_retune_restore` in `sessionStorage`, and `TabEditor.onMounted` reads it
  (fresh-intent guard, 15 s) to come back in the **Tab view with the video
  sidebar open + synced**, instead of resetting to the default Grid view. Keeps
  slider tuning fast and in place. *Limitation:* still a full reload (flashes) —
  true in-place re-hydration of grid+melody+videoSync was judged too risky for
  the payoff; deferred.
- Tests: `DetectionFilterTest` (rebucket unit + filter-in-assembly) and the
  retune case in `TranscriptionFixLatchTest`.

### Tier 2 — "Re-run detection" button (seconds)
Onset + frame thresholds (+ preset). A `redetect` endpoint re-invokes
`transcribe.py` on the resident `sourceAudio` (no re-download / re-YouTube /
re-separate — reuses cached `separateStem`/`tabPositionStyle`), then flows
through the same assemble→persist→reload path. One inference; seconds.

### Phases
1. **P1** ✅ — parameterize `rebucketBeats` + `detection_filter` opt on
   `assembleTranscription`. Regression-guarded (null filter = unchanged).
2. **P2** ✅ — `retuneDetection` endpoint (Tier-1 server re-assembly + latch guard).
3. **P3** ✅ — Tier-1 UI (sliders + debounced POST + reload). The "watch it change" win.
4. **P4** ⏳ — `redetect` endpoint (Tier-2 re-inference on resident audio).
5. **P5** ⏳ — Tier-2 UI (onset/frame threshold sliders + Re-run button).
Stopped after P3 for now — over-detection / clutter is the common failure and
Tier 1 covers it. P4/P5 add the onset/frame re-inference when a recording needs
*more* notes surfaced (below the original detection floor), which Tier 1 can't do.

### Risks
- **P1 regression** is the real one — the filter relocation must not shift
  default-value assembly. Guard with a fixture test.
- **`sourceAudio` presence** — Tier 2 needs the persisted original; pre-§12b
  sheets lack it ⇒ Tier-2 button disabled (Tier 1 still works from cache).
- Inherits §13's `transcriptionFixed` guard — a fixed sheet's tuning is locked.

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
5.  **Musicology Pass (AI Refinement):** If enabled, the raw analysis is sent to Gemini.
    *   **Quantization:** Raw timestamps are "musicalized" into standard rhythms.
    *   **Structuralization:** Logical song sections (Intro, Verse, Chorus, Outro) are identified.
    *   **Harmonic Correction:** Chord labels are refined based on functional harmony.
6.  **Assembly:** The final data is assembled into the Leadsheet model, including video sync mappings and quantized melody/tab data.

---

## 2. Technical Stack & Bridge

*   **PHP/Laravel:** Orchestration and Harmonic Recognition.
*   **Python 3.11.9:** AI Inference (located in `/python_env`).
*   **Gemini (LLM):** Rhythmic and structural refinement.
*   **FFmpeg & yt-dlp:** Audio conversion and acquisition.
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
| **P1** | T4 AI Job Re-division | ✅ Implemented | Deterministic chord ID for all paths; AI does structuralisation only |
| **P1** | T1 Fretboard Optimisation | ✅ Implemented | Viterbi over (string,fret) — playable tab instead of absurd fret leaps |
| **P1** | T1b Melody→Voicing Position | ✅ Implemented | Chord voicings tracked to the melody's hand position per bar |
| **P1** | T1 Bar-Locked Positions | ✅ Implemented | One compact 4-fret hand position per bar — no fret-1/fret-8 mixing |
| **P2** | T2 Voice Labelling | ⏸ Shelved | Position win folded into T1; chord-ID win smaller than T3 |
| **P2** | Phase 3 Context Awareness | ⏳ Pending | Context-aware chord disambiguation |
| **P3** | Tap-Correct Re-time UI | ⏳ Designed | Manual downbeat tapping for recordings bass-snap can't anchor |
| **P3** | Reference Leadsheet Sync | ⏳ Pending | Anchoring transcription to standard progressions |

---

## 9. Maintenance & Debugging

*   **Testing:** `php artisan sbn:transcribe-test path/to/audio.mp3`
*   **Bass-snap inspection:** `php artisan sbn:bass-snap-debug {leadsheetId}` — prints `beat_track` vs bass-snapped grid per beat with deltas.
*   **yt-dlp Update:** `.\yt-dlp.exe -U`
*   **Harmonic Engine:** Core logic in `VoicingCrossref.php` (Pass 1–3c).
*   **Assembly:** `LeadsheetController::assembleTranscription()` owns beat→bar grouping, bass-snap, chord region detection, melody reconstruction and videoSync mapping.
*   **Melody Pipeline:** `LeadsheetController::musicalizeTranscription` orchestration.

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
encoded into it. See T4 below.

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

### Phase T3 — Wire the context-aware identifier into the audio path

**Problem:** doc §6 — the audio path identifies every chord *in isolation* via
`identifyFromMidi()`. The context-aware Phase 3 engine (Viterbi + bigram +
key-fit, see [SBN-Identifier-Reference.md](SBN-Identifier-Reference.md) §5) was
built but **never connected to transcription**.

**Approach:** after first-pass ID, re-identify with key + neighbour context.
Mostly plumbing of an existing engine, not new algorithm.

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

**Not yet done (folds into T5):** feeding the matched Jazz Standard's changes
into the AI payload — `musicalizeTranscription()` no longer takes the
`SongLookup` it would need; T5 re-introduces it.

### Phase T5 — Reference anchoring (known standards)

When the tune matches an entry in the ~1,400-row **Mike Oliphant Jazz Standards
DB**, use its bar count / section labels / changes to force-align the grid and
bias chord ID (doc §7). Especially powerful for known standards (e.g. *Body and
Soul*). Composes with T4 (feed the reference into the AI payload) and with
bass-snap (bias anchors toward expected bass pitches).

### Phase T6 — Tap-correct re-time UI (manual fallback)

Designed, not built (see §3 Future Improvements B). Manual downbeat tapping
against the synced video for recordings where bass-snap can't find good
anchors. Tapped times become `downbeatOverrides` feeding `assembleTranscription()`.
Lower priority — bass-snap covers the common case.

### Smaller fixes

- **90-second cap.** `transcribe.py` — `librosa.load(..., duration=90)` truncates
  every transcription at 90 s (≈ one chorus of a jazz tune). Likely a leftover
  dev limit; lifting it removes a hard ceiling on usefulness.

### Suggested order

~~T4~~ ✅ · ~~T1~~ ✅ (Session 2026-05-20; bar-locked rewrite + 4-fret window
2026-05-21) · ~~T2~~ ⏸ shelved (the position win it promised was achieved
inside T1; revisit only if more material shows T1 is insufficient) → **T3
next** (wire the context-aware identifier into the audio path — plumbing of an
existing engine) → T5 → T6. The 90 s cap can be lifted any time.

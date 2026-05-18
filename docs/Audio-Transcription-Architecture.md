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

### Implemented: User Downbeat Offset Tool (Session 2026-05-18) ✅
The "Set downbeat" panel in the Video sidebar (`VideoSyncEditor.vue`) lets the
user fix a within-bar phase offset *after* import, without re-downloading or
re-transcribing.

- **Raw cache.** `assembleTranscription()` stores the raw Python output
  (`beats`, `beat_times`, `notes`, `tempo`, `downbeatOffset`) in
  `json_data.transcriptionRaw`. `AnalysisToLeadsheet` passes it through;
  `musicalizeTranscription` preserves it across the Gemini pass.
- **Picker.** The panel renders the first ~12 beats from the cached `beats` as
  columns with per-beat pitch dots. The user clicks the beat that is the true
  musical "1".
- **Pickup, not drop.** Beats *before* the chosen "1" are not discarded — they
  become a leading pickup bar (a full 4-beat measure with leading rests). The
  offset is `chosenOneIndex − firstBusyBeatIndex`, clamped 0–3.
- **Re-assembly.** `POST /api/admin/leadsheets/{id}/reshift-downbeat` re-runs
  `assembleTranscription()` with the new offset, rebuilds sections / melody /
  `videoSync`, persists, and the editor reloads. Idempotent — always re-shifts
  from the original cached beats.
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
| **P2** | User Downbeat Offset UI | ✅ Implemented | User picks the true "1" from an onset strip; re-shifts via pickup bar |
| **P2** | Bass-Snap Beat Correction | ✅ Implemented | Rebuilds beat_times from bass-note anchors so the grid follows rubato |
| **P2** | Phase 3 Context Awareness | ⏳ Pending | Context-aware chord disambiguation |
| **P3** | Tap-Correct Re-time UI | ⏳ Designed | Manual downbeat tapping for recordings bass-snap can't anchor |
| **P3** | Reference Leadsheet Sync | ⏳ Pending | Anchoring transcription to standard progressions |
| **P3** | Multi-Voice Support | ⏳ Pending | Clean separation of melody/bass/inner |

---

## 9. Maintenance & Debugging

*   **Testing:** `php artisan sbn:transcribe-test path/to/audio.mp3`
*   **Bass-snap inspection:** `php artisan sbn:bass-snap-debug {leadsheetId}` — prints `beat_track` vs bass-snapped grid per beat with deltas.
*   **yt-dlp Update:** `.\yt-dlp.exe -U`
*   **Harmonic Engine:** Core logic in `VoicingCrossref.php` (Pass 1–3c).
*   **Assembly:** `LeadsheetController::assembleTranscription()` owns beat→bar grouping, bass-snap, chord region detection, melody reconstruction and videoSync mapping.
*   **Melody Pipeline:** `LeadsheetController::musicalizeTranscription` orchestration.

> **History:** This doc supersedes the 2026-05-04 `Audio-Transcription-OPUSAnalysis` audit, whose proposed fixes (range filter, beat clamping, no-dots grid, duration-weighted ID) are all now implemented above. The chord mis-ID deep-dive it called out lives in [SBN-Identifier-Reference.md](SBN-Identifier-Reference.md).

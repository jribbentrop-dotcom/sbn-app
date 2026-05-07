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

### Proposed Future Improvements
#### A. Python: Use `librosa.beat.plp()` for downbeat estimation
```python
onset_env = librosa.onset.onset_strength(y=y, sr=sr)
pulse = librosa.beat.plp(onset_envelope=onset_env, sr=sr)
# The phase of the strongest pulse cycle hints at the downbeat offset
```
#### B. UI: User Downbeat Offset Selector
Add a control in the transcription modal or Video Sidebar:
- Users see the first 4–8 beats and tap which one is "1".
- Stores a `beat_offset` (0–3) to re-assemble the leadsheet with shifted bar grouping.
#### C. Reference Anchoring
If the song matches a standard leadsheet from the **Mike Oliphant JazzStandards JSON**, use the standard bar count and section labels to force-align the grid.

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
| **P2** | User Downbeat Offset UI | ⏳ Pending | User can fix grid alignment |
| **P2** | Phase 3 Context Awareness | ⏳ Pending | Context-aware chord disambiguation |
| **P3** | Reference Leadsheet Sync | ⏳ Pending | Anchoring transcription to standard progressions |
| **P3** | Multi-Voice Support | ⏳ Pending | Clean separation of melody/bass/inner |

---

## 9. Maintenance & Debugging

*   **Testing:** `php artisan sbn:transcribe-test path/to/audio.mp3`
*   **yt-dlp Update:** `.\yt-dlp.exe -U`
*   **Harmonic Engine:** Core logic in `VoicingCrossref.php` (Pass 1–3c).
*   **Melody Pipeline:** `LeadsheetController::musicalizeTranscription` orchestration.

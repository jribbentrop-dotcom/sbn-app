# Audio Transcription Architecture

This document outlines the architecture and implementation details of the AI-driven audio-to-leadsheet transcription pipeline in the SBN application.

## 1. High-Level Workflow

The transcription process follows a four-stage pipeline:
The transcription process follows a six-stage pipeline:

1.  **Acquisition:** YouTube audio is downloaded via `yt-dlp.exe` in the best available audio format (usually `.webm` or `.m4a`).
2.  **Standardization (The Universal Translator):** Raw audio is converted to a standard 16-bit PCM WAV (22050Hz, Mono) using `ffmpeg.exe`. This step is critical for ensuring that the Python `soundfile` library can read the file regardless of the source codec or Windows environment settings.
3.  **AI Inference:** A dedicated Python 3.11 environment executes `scripts/transcribe.py`.
    *   **Beat Tracking:** `librosa` identifies the tempo and beat frames.
    *   **Pitch Tracking:** `basic-pitch` (Spotify's ICASSP 2022 model) performs polyphonic transcription.
    *   **Bucketing:** Notes are grouped by beat interval to form pitch-class sets (PC-Sets).
4.  **Harmonic Recognition:** The resulting JSON is parsed by Laravel and passed to `VoicingCrossref::identifyFromMidi()`, which maps pitch sets to canonical chord names (e.g., `[60, 64, 67, 70]` -> `C7`).
5.  **Musicology Pass (AI Refinement):** If the `AI Rhythmic Cleanup` toggle is enabled, the raw analysis is sent to Gemini.
    *   **Quantization:** Raw timestamps are "musicalized" into standard 8th/16th note phrases.
    *   **Structuralization:** Logical song sections (Intro, Verse, Chorus, etc.) are identified.
    *   **Harmonic Correction:** Obvious chord misidentifications are corrected based on functional harmony.
6.  **Assembly:** The final data is assembled into the Leadsheet model, including:
    *   **Video Sync:** Automatic measure-by-measure mappings between the leadsheet and YouTube video.
    *   **Melody/Tab:** Quantized MIDI notes mapped to standard guitar string/fret positions.

## 2. Technical Stack

*   **PHP/Laravel:** Orchestration and Harmonic Recognition.
*   **Python 3.11.9:** AI Inference (located in `/python_env`).
*   **Gemini (LLM):** Rhythmic and harmonic refinement.
*   **FFmpeg:** Audio conversion and decoding (located in `/ffmpeg.exe`).
*   **yt-dlp:** YouTube acquisition (located in `/yt-dlp.exe`).
*   **Key Python Libraries:** `basic-pitch`, `librosa`, `numpy`, `soundfile`.

## 3. Communication Bridge

Communication between PHP and Python uses a "Handshake" pattern to ensure reliability:

*   **Tagged Output:** The Python script prefixes the final JSON result with `JSON_START`. This allows the PHP service to ignore any library-level diagnostic noise (TensorFlow warnings, etc.) and only parse the actual data.
*   **Environment Injection:** The PHP service manually injects the system `PATH`, `TEMP`, and `TMP` variables into the `proc_open` environment to ensure binaries like FFmpeg are discoverable on Windows.
*   **Handshake Protocol:** Raw note events and beat timestamps are exported to allow high-fidelity reconstruction in PHP.
*   **Time Limits:** The web request is granted a 10-minute timeout (`set_time_limit(600)`) to account for the heavy computation required for AI inference.

## 4. User Interface Integration

*   **Lookup Modal:** Users select "Audio Transcription" and can search YouTube directly.
*   **AI Cleanup Toggle:** An optional checkbox to enable the Gemini-driven refinement pass.
*   **Auto-Editor Transition:** Upon completion, the Backend Leadsheet Editor opens automatically.
*   **Video Master Sync:** The YouTube player is automatically set as the master clock, with the Video Sidebar active by default.

## 5. Known Constraints & Roadmap

*   **Duration Limit:** Audio processing is currently capped at the first 90 seconds.
*   **Quantization Grid:** The AI currently prefers a 16th-note subdivision for the "Musicology Pass."
*   **Voicing Integration:** When "Build Voicings" is enabled, the system prioritizes transcribed melody notes over auto-generated comping patterns.

## 6. Stability & Performance Features

*   **Windows Path Normalization:** All file paths are normalized to forward slashes (`/`) before being passed to Python, preventing backslash escape bugs (e.g., `\u` or `\i` being misinterpreted).
*   **Automatic Cleanup:** Temporary files (`.webm`, `.wav`) are automatically purged from `storage/app/temp_audio/` immediately after processing.

## 5. Maintenance & Debugging

### Testing the Pipeline
You can run a full end-to-end test on any local file using the Artisan command:
```powershell
php artisan sbn:transcribe-test path/to/audio.mp3
```

### Updating yt-dlp
If YouTube changes their architecture and downloads start failing, update the binary:
```powershell
.\yt-dlp.exe -U
```

### Modifying Identification Logic
The logic for converting pitches to chord names is located at the bottom of `app/Services/VoicingCrossref.php` in the `identifyFromMidi()` and `identifyFromPcSet()` methods.

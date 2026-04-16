# Coding AI Handoff Briefs — Phase 3

One brief per task. Give the AI exactly what is listed under "Provide these files" — nothing more, nothing less.

**Global rule for all tasks**: do not add TypeScript, do not change file extensions to `.ts`, do not install new npm packages unless the brief says to. The project uses plain JS + JSDoc.

---

## Task 5 — Port `PercussionSampler`

**Target file:** `resources/js/audio/engine/voices/PercussionSampler.js`
**WP source:** `sbn-percussion.js` lines 59-191

### Prompt

```
You are porting a legacy JavaScript module to a new audio engine. Write `resources/js/audio/engine/voices/PercussionSampler.js`.

## What it does
Loads percussion WAV samples from a base URL and plays them on demand. Falls back gracefully when files are missing.

## Loading (init)
Implement `async init(samplesBaseUrl)`. Load these 10 files in parallel:
  shaker_soft.wav, shaker_accent.wav
  tamborim_soft.wav, tamborim_accent.wav
  kick_soft.wav, kick_accent.wav
  hihat_brush_soft.wav, hihat_brush_accent.wav
  brush_snare_soft.wav, brush_snare_accent.wav

Rules:
- Use `Promise.allSettled()` — NOT `Promise.all()`. A single missing file must not abort the rest.
- Fetch each file with `fetch()`, decode with `audioContext.decodeAudioData()`.
- Store decoded buffers in a Map keyed `{instrument}_{variant}` (e.g. `"shaker_soft"`).
- If a file fails, log `console.warn('[SBN] PercussionSampler: failed to load <filename>')` and leave that bucket as null. Do NOT throw.
- Set `this.ready = true` after allSettled resolves (even if some files failed).

## AudioContext rule (CRITICAL)
ALWAYS obtain the AudioContext via `Tone.getContext().rawContext`. NEVER call `new AudioContext()`. This is an architectural constraint — see the contract.

## Playback (trigger)
Implement `trigger(instrument, variant, when, velocity)`:
- Look up `{instrument}_{variant}` in the buffer map.
- If that bucket is null, try `{instrument}_soft` as fallback.
- If both are null, return silently (the Mixer will route to FallbackSynths).
- If a buffer is found: create a `BufferSourceNode`, connect to a `GainNode` (gain = velocity), connect to destination, schedule `start(when)`.

## Interface
Export a class `PercussionSampler` with:
  - `async init(samplesBaseUrl: string): Promise<void>`
  - `trigger(instrument: string, variant: 'soft'|'accent', when: number, velocity: number): void`
  - `releaseAll(): void`  (no-op for one-shot samples — required by Scheduler interface)
  - `dispose(): void`
  - `ready: boolean`  (false until init resolves)

## Do NOT
- Do not import Vue, Alpine, or any DOM framework.
- Do not create a second AudioContext.
- Do not use Tone.js nodes (ToneAudioBuffer etc) — use raw WebAudio API for buffer playback.
- Do not add a blend slider — that is handled by Mixer.js.
```

### Provide these files
1. `docs/audio-engine-contract.md` — §12.1 and §12.4 are the authoritative spec
2. `scratch-sample-synth-findings.md` — paste lines 1-40 (the `_getAudioContext` block and init logic)
3. `resources/js/audio/engine/voices/PitchedSynth.js` — shows the class shape and Tone import pattern to follow

---

## Task 6 — Verify and enhance `PitchedSynth`

**Target file:** `resources/js/audio/engine/voices/PitchedSynth.js` (already exists — enhance, do not rewrite)
**WP source:** `sbn-audio.js` lines 84-120

### Prompt

```
The file `resources/js/audio/engine/voices/PitchedSynth.js` is a port of the legacy WP guitar synth. It already exists and mostly works. Your job is to verify it against the WP source and add any missing capability.

## Verify these things
1. Signal chain is: PolySynth → EQ3 → Reverb → Limiter → Destination. ✓ (already done)
2. Oscillator config matches WP exactly: type 'custom', partials [1.0, 0.4, 0.15, 0.05], attack 0.002, decay 0.08, sustain 0.25, release 0.6, volume -16. Verify and correct if different.
3. EQ3 settings: low -2, mid 0, high -4.
4. Reverb settings: decay 1.6, wet 0.18.
5. Limiter: -3 dB.

## Add if missing
- `get gainNode()` — expose the gain of the PolySynth so Mixer can adjust per-voice volume
- A `setVolume(db)` method that sets `this.synth.volume.value = db`
- Ensure `releaseAll()` exists and calls `this.synth.releaseAll()`

## Do NOT
- Do not change the oscillator config values — they were tuned by ear in the WP version.
- Do not add samples. This voice is pure synthesis only.
- Do not rewrite the file — make targeted additions only.
```

### Provide these files
1. `resources/js/audio/engine/voices/PitchedSynth.js` — the file to enhance
2. `scratch-sample-synth-findings.md` — section 4 (SbnAudio signal chain, lines 84-120)

---

## Task 7 — Port `FallbackSynths`

**Target file:** `resources/js/audio/engine/voices/FallbackSynths.js`
**WP source:** `course-player.js` lines 1037-1162

### Prompt

```
Write `resources/js/audio/engine/voices/FallbackSynths.js`. This module synthesizes percussion sounds using raw WebAudio API for use when WAV samples are unavailable.

## Methods to implement (port from WP source — exact frequency and envelope values must be preserved)

### playClave(time, register)
- register = 'high': triangle wave, 2500Hz → 1800Hz exponential decay, bandpass filter
- register = 'low':  triangle wave, 1800Hz → 1200Hz exponential decay, bandpass filter
- Duration: ~0.05s decay

### playMuted(time, register)
- register = 'high': square wave, 330Hz, lowpass filter
- register = 'low':  square wave, 110Hz, lowpass filter
- Short decay envelope

### playHiHat(time)
- White noise buffer, bandpass 8000Hz, highpass 7000Hz
- Short decay

### playNote(time, freq, durationSec, volume)
- Sine wave for pitched fallback notes
- Used for frequencies 493.88 (B4) and 220 (A3) in the WP code

## Interface
Export a class `FallbackSynths`:
  - constructor takes no arguments
  - All four methods above
  - `releaseAll(): void` (no-op — these are one-shot)
  - `dispose(): void`
  - `get audioContext()` must call `Tone.getContext().rawContext` — NEVER `new AudioContext()`

## Dispatch table for the Mixer
The Mixer routes percussion events to FallbackSynths using this map (already in the contract):
  sample='shaker'      → playHiHat(time)
  sample='tamborim'    → playClave(time, 'high')
  sample='kick'        → playClave(time, 'low')
  sample='hihat-brush' → playHiHat(time)
  sample='brush-snare' → playMuted(time, 'high')

## Do NOT
- Do not import Tone.js nodes for sound generation — use raw WebAudio API only.
- Do not import Vue or any DOM framework.
- Do not create a second AudioContext.
- Do not change the frequency values — they were tuned in the WP version.
```

### Provide these files
1. `docs/audio-engine-contract.md` — §12.2 for the fallback map
2. `scratch-sample-synth-findings.md` — section 2 (Synth Fallback Sounds, lines 66-103 of the findings)
3. Paste the raw WP source for `course-player.js` lines 1037-1162 directly into the brief

---

## Task 8 — `rhythmPatternToEvents` adapter

**Target file:** `resources/js/audio/adapters/rhythmPatternToEvents.js`

### Prompt

```
Write `resources/js/audio/adapters/rhythmPatternToEvents.js`.

## What it does
Converts a RhythmPattern model (from the Laravel backend) into an array of EngineEvents for the audio engine scheduler.

## Input shape
See the provided `model.json`. Key fields:
- `thumb` (string): bass/thumb hits — each char is a step. 'x' = soft hit, 'X' = accent, '.' = rest.
- `fingers` (string): treble/finger hits — same encoding.
- `percTop` (string): instrument name for finger hits (e.g. 'shaker'), or 'none'.
- `percBass` (string): instrument name for thumb hits (e.g. 'kick'), or 'none'.
- `beats` (number): total number of steps in the pattern.
- `gridType` (string): 'eighth' | 'sixteenth' | 'triplet' — determines step duration in beats.
  - eighth:    stepBeats = 0.5
  - sixteenth:  stepBeats = 0.25
  - triplet:    stepBeats = 1/3

## Output shape
See `events.json`. Each non-'.' character produces one EngineEvent:
```json
{
  "time":     <stepIndex * stepBeats>,
  "voice":    "percussion",
  "sample":   <percTop or percBass>,
  "variant":  "accent" if char === 'X', else "soft",
  "velocity": 0.85 for thumb hits, 0.78 for finger hits (1.0 for accents),
  "duration": stepBeats,
  "sourceId": "step-<stepIndex>"
}
```

## Rules
- If percTop === 'none', skip all finger events (do not emit them).
- If percBass === 'none', skip all thumb events.
- Events must be sorted by time ascending.
- This is a pure function: (model, ctx) => EngineEvent[]. No side effects, no imports from Tone.js, no DOM access.
- ctx.startBeat offsets all event times (add it to every time value).

## Export
```js
export function rhythmPatternToEvents(model, ctx = {}) { ... }
```

## Test
Your output for the provided model.json + context.json must exactly match events.json.
Tolerance: times must match to 6 decimal places. velocity and duration must be exact.
```

### Provide these files
1. `docs/audio-engine-contract.md` — §3 (EngineEvent schema) and §13.3 (RhythmPattern shape)
2. `resources/js/audio/adapters/__tests__/rhythmPatternToEvents/model.json`
3. `resources/js/audio/adapters/__tests__/rhythmPatternToEvents/context.json`
4. `resources/js/audio/adapters/__tests__/rhythmPatternToEvents/events.json`
5. `resources/js/audio/adapters/tabMeasureToEvents.js` — shows the adapter pattern and file shape to follow

---

## Task 9 — `chordDiagramToEvents` adapter

**Target file:** `resources/js/audio/adapters/chordDiagramToEvents.js`

### Prompt

```
Write `resources/js/audio/adapters/chordDiagramToEvents.js`.

## What it does
Converts a ChordDiagram model into an array of EngineEvents representing a single chord strum.

## Input shape
See `model.json`. Key fields:
- `id` (number): used to build sourceId.
- `diagram_data.positions` (array): [{string, fret}, ...] — one entry per fretted string.
- `diagram_data.open` (array): string numbers that are played open (fret 0).
- `diagram_data.muted` (array): string numbers that are muted — skip these, do not emit events.
- `diagram_data.barres` (array): [{fret, fromString, toString}, ...] — a barre frets all strings in range.

## MIDI calculation (CRITICAL)
Do NOT use the `notes` column — it contains pitch-class names only (no octave) and cannot produce correct MIDI.
Derive MIDI from fret position + standard tuning:

  String 1 (Low E)  = MIDI 40 (open E2)
  String 2 (A)      = MIDI 45 (open A2)
  String 3 (D)      = MIDI 50 (open D3)
  String 4 (G)      = MIDI 55 (open G3)
  String 5 (B)      = MIDI 59 (open B3)
  String 6 (Hi E)   = MIDI 64 (open E4)

  MIDI for string S at fret F = tuning[S] + F

## Barre handling
A barre [{fret, fromString, toString}] frets all strings from fromString to toString at that fret,
UNLESS a position entry already exists for that string (explicit position overrides barre).

## Output shape
See `events.json`. One EngineEvent per sounding string (muted strings skipped):
```json
{
  "time":     ctx.startBeat ?? 0,
  "voice":    "pitched",
  "pitch":    <MIDI number>,
  "duration": ctx.durationBeats ?? 2,
  "velocity": 0.8,
  "sourceId": "diagram-<id>"
}
```
All notes in one strum share the same sourceId — the UI highlights the whole chord, not individual strings.

## Rules
- Pure function: (model, ctx) => EngineEvent[]. No Tone.js, no DOM access.
- Sort events by pitch ascending (lowest string first).

## Export
```js
export function chordDiagramToEvents(model, ctx = {}) { ... }
```

## Test
Your output for model.json + context.json must match events.json exactly.
```

### Provide these files
1. `docs/audio-engine-contract.md` — §3 and §13.3 (ChordDiagram shape + MIDI gap note)
2. `resources/js/audio/adapters/__tests__/chordDiagramToEvents/model.json`
3. `resources/js/audio/adapters/__tests__/chordDiagramToEvents/context.json`
4. `resources/js/audio/adapters/__tests__/chordDiagramToEvents/events.json`
5. `resources/js/audio/adapters/tabMeasureToEvents.js` — adapter pattern reference

---

## Task 10 — `chordProgressionToEvents` adapter

**Target file:** `resources/js/audio/adapters/chordProgressionToEvents.js`

### Prompt

```
Write `resources/js/audio/adapters/chordProgressionToEvents.js`.

## What it does
Converts a resolved chord progression (Roman numerals + key + quality + duration per chord) into EngineEvents.
The model is assembled by the Vue composable — it is NOT the raw database model.

## Input shape
See `model.json`:
```json
{
  "id": 7,
  "name": "ii–V–I",
  "numerals": "ii,V,I",
  "key": "C",
  "qualityPerChord": ["m7", "dom7", "maj7"],
  "beatsPerChord": [4, 4, 8]
}
```

## Numeral → root resolution
Parse each numeral to a scale degree (case-insensitive):
  I/i=0, II/ii=2, III/iii=4, IV/iv=5, V/v=7, VI/vi=9, VII/vii=11
  bII=1, bIII=3, bVI=8, bVII=10  (flat numerals)
  #IV=6, #I=1 (sharp numerals — less common, include for completeness)

Root MIDI = rootOfKey + scaleDegree (use octave 3 as default — C3=48, so rootOfKey for 'C'=48).

## Quality → intervals (semitones above root)
  maj:   [0, 4, 7]
  min:   [0, 3, 7]
  maj7:  [0, 4, 7, 11]
  m7:    [0, 3, 7, 10]
  dom7:  [0, 4, 7, 10]
  m7b5:  [0, 3, 6, 10]
  o7:    [0, 3, 6, 9]
  maj6:  [0, 4, 7, 9]
  m6:    [0, 3, 7, 9]
  sus4:  [0, 5, 7]
  sus2:  [0, 2, 7]
  add9:  [0, 2, 4, 7]
  aug:   [0, 4, 8]
  dim:   [0, 3, 6]
  5:     [0, 7]

## Output
See `events.json`. For each chord, emit one EngineEvent per chord tone:
```json
{
  "time":     <cumulative beat offset>,
  "voice":    "pitched",
  "pitch":    <root MIDI + interval>,
  "duration": <beatsPerChord[i]>,
  "velocity": 0.8,
  "sourceId": "chord-<index>"
}
```
All notes of one chord share the same sourceId.
Beat offset accumulates: chord 0 starts at startBeat, chord 1 at startBeat + beatsPerChord[0], etc.

## Rules
- Pure function: (model, ctx) => EngineEvent[]. No Tone.js, no DOM access.
- ctx.startBeat offsets the entire progression.
- Within each chord, sort events by pitch ascending.

## Export
```js
export function chordProgressionToEvents(model, ctx = {}) { ... }
```

## Test
Your output for model.json + context.json must match events.json exactly.
MIDI values: D3=50, F3=53, A3=57, C4=60, G3=55, B3=59, D4=62, F4=65, C3=48, E3=52, G3=55, B3=59.
```

### Provide these files
1. `docs/audio-engine-contract.md` — §3 and §13.3 (ChordProgression shape)
2. `resources/js/audio/adapters/__tests__/chordProgressionToEvents/model.json`
3. `resources/js/audio/adapters/__tests__/chordProgressionToEvents/context.json`
4. `resources/js/audio/adapters/__tests__/chordProgressionToEvents/events.json`
5. `resources/js/audio/adapters/tabMeasureToEvents.js` — adapter pattern reference

---

## Task 11 — Vue composables for rhythm and leadsheet views

**Target files:**
- `resources/js/rhythm/composables/useAudioEngine.js`
- `resources/js/leadsheet/composables/useAudioEngine.js`

**Depends on:** Tasks 8, 9, 10 complete.

### Prompt

```
Write two Vue 3 composables that wire the audio engine to their respective views.
Model the implementation exactly on the reference composable provided.

## File 1: resources/js/rhythm/composables/useAudioEngine.js

Differences from the tab-editor reference:
- Calls `rhythmPatternToEvents(model.value, { startBeat: 0 })` instead of `tabModelToEvents`.
- Passes `samplesBaseUrl: window.sbnConfig?.samplesBaseUrl ?? ''` to `engine.init()`.
- Exposes a `blend` ref (default 1.0) in addition to the standard return values.
- Watches `blend` and calls `engine.setBlend(blend.value)` on change.
- Otherwise identical to the reference composable.

## File 2: resources/js/leadsheet/composables/useAudioEngine.js

Differences from the tab-editor reference:
- Calls `chordProgressionToEvents(model.value, { startBeat: 0 })` for chord playback.
- Does NOT pass samplesBaseUrl (percussion not used in leadsheet view).
- Does NOT expose a blend ref.
- Otherwise identical to the reference composable.

## Rules for both
- Call `engine.isInited` (NOT `engine._inited`) to check initialization state.
- Call `engine.stop()` in `onBeforeUnmount`, NOT `engine.dispose()`.
- Do not add any CSS or visual styling.
- Player controls (play button, etc.) are NOT part of this task — the composable only manages state.
- Export the composable as a named export: `export function useAudioEngine(model) { ... }`
```

### Provide these files
1. `resources/js/tab-editor/composables/useAudioEngine.js` — the reference to follow exactly
2. `resources/js/audio/engine/AudioEngine.js` — so the AI can see the facade API
3. `docs/audio-engine-contract.md` — §13.4 for the composable template
4. The completed `rhythmPatternToEvents.js` (task 8 output)
5. The completed `chordProgressionToEvents.js` (task 10 output)

---

## Task 12 — Unit tests for adapters

**Target files:**
- `resources/js/audio/adapters/__tests__/rhythmPatternToEvents.test.js`
- `resources/js/audio/adapters/__tests__/chordDiagramToEvents.test.js`
- `resources/js/audio/adapters/__tests__/chordProgressionToEvents.test.js`

**Depends on:** Tasks 8, 9, 10 complete.

### Prompt

```
Write Vitest unit tests for three audio adapters.
First install Vitest: add `"vitest": "^1.0.0"` to devDependencies and add `"test": "vitest"` to scripts in package.json.

## Pattern for each test file

```js
import { describe, it, expect } from 'vitest';
import { rhythmPatternToEvents } from '../rhythmPatternToEvents.js';
import model   from './rhythmPatternToEvents/model.json' assert { type: 'json' };
import context from './rhythmPatternToEvents/context.json' assert { type: 'json' };
import expected from './rhythmPatternToEvents/events.json' assert { type: 'json' };

describe('rhythmPatternToEvents', () => {
  it('produces the golden fixture output', () => {
    const result = rhythmPatternToEvents(model, context);
    // Strip _comment fields before comparing
    const clean = result.map(e => { const c = {...e}; delete c._comment; return c; });
    const exp   = expected.map(e => { const c = {...e}; delete c._comment; return c; });
    expect(clean).toEqual(exp);
  });

  it('returns empty array for all-rest pattern', () => {
    const m = { ...model, thumb: '................', fingers: '................', percTop: 'shaker', percBass: 'kick' };
    expect(rhythmPatternToEvents(m, context)).toEqual([]);
  });

  it('skips percussion events when percTop is none', () => {
    const m = { ...model, percTop: 'none' };
    const result = rhythmPatternToEvents(m, context);
    expect(result.every(e => e.sample !== model.percTop)).toBe(true);
  });
});
```

Write the same three-test pattern for chordDiagramToEvents and chordProgressionToEvents.
For chordProgressionToEvents, add a test that ctx.startBeat offsets all event times correctly.

## Rules
- Do not mock the adapters. Import and call them directly.
- Do not add Tone.js or any audio context — these are pure functions.
- Keep tests short. Three meaningful tests per adapter is enough.
```

### Provide these files
1. All three completed adapter files (tasks 8, 9, 10 output)
2. All nine fixture files in `__tests__/` (model, context, events for each adapter)
3. `package.json` — so the AI knows the current scripts and can add vitest correctly

---

## Quick reference — file map

| Task | Give the AI | Do NOT give |
|------|-------------|-------------|
| 5 PercussionSampler | contract §12, findings §1, PitchedSynth.js | TabEditor.vue, Blade views |
| 6 PitchedSynth enhance | PitchedSynth.js, findings §4 | Anything else |
| 7 FallbackSynths | contract §12.2, findings §2, raw WP lines 1037-1162 | Vue files |
| 8 rhythmPattern adapter | contract §3+§13.3, 3 fixture files, tabMeasureToEvents.js | Backend PHP files |
| 9 chordDiagram adapter | contract §3+§13.3, 3 fixture files, tabMeasureToEvents.js | Backend PHP files |
| 10 chordProgression adapter | contract §3+§13.3, 3 fixture files, tabMeasureToEvents.js | Backend PHP files |
| 11 Composables | reference composable, AudioEngine.js, contract §13.4, completed adapters | Blade views, CSS |
| 12 Tests | 3 adapter files, 9 fixture files, package.json | Engine internals |

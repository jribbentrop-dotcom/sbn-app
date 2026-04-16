---
status: DRAFT v0.3
owners: architecture (Opus/Sonnet)
consumers: rhythm patterns, chord diagrams, chord view, tab editor, (future) video-synced lessons
---

# SBN Audio Engine Contract

This document defines the interface between the SBN audio playback engine and its consumers. It is the load-bearing artifact for Phase 7C. Coding-focused models (Gemini/Windsurf/Copilot/Cursor) should treat this as authoritative: adapters, ports, and Vue composables are written **against this contract**, not against each other.

Lessons baked in come from the legacy WP code surveyed in `scratch-sample-synth-findings.md` — shared Tone.js context, sample/synth blend, iOS unlock, non-fatal sample loads.

---

## 1. Goals & non-goals

**Goals**
- One engine, four consumers today (rhythm / chord diagrams / chord view / tab editor), extensible to video-synced lessons tomorrow.
- Single master clock shared across all consumers — no drift between a rhythm pattern and a chord view playing simultaneously.
- Sample-first playback with graceful synth fallback, preserving the blend behavior from the WP `RhythmPlayer`.
- Adapters (model → event stream) are pure functions, trivially testable, trivially parallelizable across coding AIs.
- Tempo and transport changes do not require re-emitting events.
- Backend stays audio-ignorant. It serves the same models it already serves; all audio logic is frontend.

**Non-goals (for Phase 7C)**
- Full DAW features (recording, multitrack mixdown, MIDI export).
- Per-note automation curves beyond `velocity` and `bend`.
- Offline rendering / WAV export.
- Server-side audio synthesis.

---

## 2. Module layout

```
resources/js/audio/                        # framework-agnostic
  engine/
    AudioEngine.ts            # facade — the public surface
    PlaybackClock.ts          # interface: now(), start(), stop(), seek(), onTick()
    ToneClock.ts              # impl: wraps Tone.Transport (default)
    MediaElementClock.ts      # impl: wraps <video>.currentTime (future)
    Scheduler.ts              # lookahead scheduler; accepts event streams
    voices/
      PitchedSynth.ts         # ports sbn-audio.js PolySynth+EQ3+Reverb+Limiter
      PercussionSampler.ts    # ports sbn-percussion.js WAV loading + variant fallback
      FallbackSynths.ts       # ports course-player.js clave/muted/hihat/guitar
    mixer/
      Mixer.ts                # per-voice gain, sample/synth blend, master out
  adapters/
    rhythmPatternToEvents.ts  # pure: RhythmPattern model → EngineEvent[]
    chordDiagramToEvents.ts   # pure: ChordDiagram   → EngineEvent[]
    chordProgressionToEvents.ts
    tabMeasureToEvents.ts     # pure: Tab measures   → EngineEvent[]
  types.ts                    # EngineEvent, Voice, Variant, etc.

resources/js/tab-editor/composables/
  useAudioEngine.js           # thin Vue wrapper, lifecycle + play/stop/seek
```

Other views (`leadsheet/`, `rhythm/`, `chord-diagrams/`) each get their own `useAudioEngine.js` wrapper that reuses the same singleton engine instance.

**Singleton rationale:** one Tone.js context per page. The legacy `SbnPercussion._getAudioContext()` already enforced this; we're promoting it from a workaround to an architectural rule.

---

## 3. Event schema

The universal unit passed from adapters to the engine.

```ts
type Beats = number;          // musical time, not seconds
type MIDINote = number;       // 0–127
type Voice =
  | 'pitched'                 // chords, tab notes, leadsheet
  | 'percussion'              // shaker, tamborim, kick, hihat, snare
  | 'muted'                   // muted string (rhythm)
  | 'clave'
  | 'noise';                  // hihat-as-noise fallback

type Variant = 'soft' | 'accent';  // matches sbn-percussion variants

interface EngineEvent {
  time: Beats;                // when, in beats from transport 0
  voice: Voice;
  pitch?: MIDINote;           // required for pitched/muted/clave
  duration: Beats;            // note length
  velocity: number;           // 0..1
  variant?: Variant;          // percussion accent/soft
  sample?: string;            // e.g. "shaker" | "kick" — picks PercussionSampler bucket
  // tab-specific (optional, ignored by other voices)
  bend?: { fromSemitones: number; toSemitones: number; atBeat: Beats };
  tieNext?: boolean;          // hammer-on / pull-off / legato glue
  articulation?: 'palm-mute' | 'ghost' | 'staccato';
  // metadata (optional, used for highlights / UI sync)
  sourceId?: string;          // stable id from the source model (measure, step, note)
}
```

**Why beats, not seconds:** a tempo change mid-playback must not invalidate queued events. The scheduler converts beats → seconds at dispatch time via `PlaybackClock`.

**Why `sourceId` is first-class:** UI needs to highlight the currently-playing note / beat / measure. The engine emits `onSourceActive(sourceId)` ticks so views can light up without maintaining parallel state.

---

## 4. The `AudioEngine` facade

```ts
interface AudioEngine {
  // lifecycle
  init(opts: { samplesBaseUrl: string; clock?: PlaybackClock }): Promise<void>;
  dispose(): void;

  // content
  load(events: EngineEvent[], opts?: { loop?: { from: Beats; to: Beats } }): void;
  clear(): void;

  // transport
  play(): Promise<void>;      // resolves once audio context is unlocked + playing
  pause(): void;
  stop(): void;
  seek(beat: Beats): void;
  setTempo(bpm: number): void;

  // mixing
  setBlend(ratio: number): void;       // 0 = pure synth, 1 = pure sample (rhythm use)
  setVoiceVolume(voice: Voice, db: number): void;
  setMasterVolume(db: number): void;

  // observability (for UI highlighting, visualizers, and video sync debugging)
  on(event: 'tick', cb: (beat: Beats) => void): Unsubscribe;
  on(event: 'sourceActive', cb: (id: string) => void): Unsubscribe;
  on(event: 'ended', cb: () => void): Unsubscribe;
  on(event: 'unlock', cb: () => void): Unsubscribe;
}
```

**Key discipline:** the facade never accepts voice-specific options (no `playClave` method). Everything flows through `load(events)`. This is what makes adapters parallelizable — they can be written and tested in isolation.

---

## 5. The `PlaybackClock` interface — the video-readiness hook

```ts
interface PlaybackClock {
  now(): number;                          // current time in seconds (audio-context time)
  currentBeat(): Beats;
  start(): Promise<void>;
  pause(): void;
  stop(): void;
  seek(beat: Beats): void;
  setTempo(bpm: number): void;
  onTick(cb: (beat: Beats) => void): Unsubscribe;
  readonly isExternal: boolean;           // true if an external element (video) drives time
}
```

Two implementations ship:

- **`ToneClock`** (default, Phase 7C): wraps `Tone.Transport`. Audio is the time authority.
- **`MediaElementClock`** (later, when video arrives): wraps an `HTMLMediaElement`. The video's `currentTime` is the authority; the scheduler treats `video.currentTime` as truth and schedules audio events against `Tone.now() + (videoBeat - currentBeat) * beatDuration`. Drift correction every ~250ms. `isExternal = true`.

Adapters and the `AudioEngine` facade are **identical** against either clock. The only difference is who owns the timeline. This is the single most important forward-looking decision in this doc — everything else can be revised cheaply, but if the clock isn't pluggable, video sync becomes a rewrite.

---

## 6. The `Scheduler` — lookahead loop

Standard web audio lookahead pattern:

- Tick every 25ms.
- Schedule any events whose `time` falls within `[currentBeat, currentBeat + 100ms lookahead]`.
- Dispatch to the appropriate voice via the mixer.
- Emit `sourceActive` when a scheduled event's `sourceId` becomes audible (i.e., at its exact `audioContext.currentTime`).

Lookahead values tuned to match what the WP `RhythmPlayer` used successfully. We don't reinvent these numbers.

---

## 7. Sample vs synth policy (ported from `RhythmPlayer`)

1. On `init`, `PercussionSampler` tries to load WAVs from `samplesBaseUrl`. Failures are non-fatal (per `sbn-percussion.js:101-104`).
2. When an event has `voice: 'percussion'`:
   - If the requested sample bucket is loaded → play sample.
   - Else → synthesize via `FallbackSynths` (clave/muted/hihat rules from `course-player.js:1037-1162`).
3. **Blend slider** (rhythm view only) crossfades between the two paths via the `Mixer`:
   - `synthGain = (1 - blend) * 0.8`
   - `sampleGain = blend * 0.8`
   - Matches WP formula exactly to preserve user-tuned feel.
4. Pitched voices (chord view, tab) currently have no sample bank — they always use `PitchedSynth`. The facade is designed so a sampled guitar could slot in later without API change.

---

## 8. iOS / autoplay unlock

- First `play()` call awaits `Tone.start()` before scheduling.
- The facade emits `unlock` so UI can hide a "tap to enable sound" overlay.
- `PercussionSampler` uses `Tone.getContext().rawContext` (legacy lesson — never create a second AudioContext).

---

## 9. Adapter contract

Every adapter is a **pure function** with this signature:

```ts
type Adapter<Model> = (model: Model, ctx: AdapterContext) => EngineEvent[];

interface AdapterContext {
  tempoBpm: number;         // for adapters that need seconds internally (rare)
  startBeat?: Beats;        // offset when concatenating multiple models
  repeat?: number;          // n times
}
```

Rules:
- No side effects, no Tone.js imports, no DOM access.
- Deterministic: same model + context → identical event list.
- Unit-testable against golden-file fixtures. Each adapter ships with a `__tests__/` folder containing `model.json` + `events.json` pairs.

This is what lets you fan out tasks 5–11 across coding AIs in parallel: each adapter is a pure translator with an input, an output, and a reference file from the WP code.

---

## 10. Video readiness — explicit checklist

These are the concrete things that keep video integration cheap:

- [x] Clock is pluggable (§5).
- [x] Events are in beats, not seconds, so tempo-mapped video still works.
- [x] `sourceId` lets video UI (e.g., scrolling notation, highlighted measure) sync without extra state.
- [x] Facade has no hidden assumptions about who owns `requestAnimationFrame` or the timeline.
- [x] `on('tick')` gives video layer a frame-accurate beat cursor.
- [x] `MediaElementClock` stub can land in Phase 7C as an unused-but-compiled type — forces us to keep the interface honest.

---

## 11. Phase 7C scope — what shipped vs what stubs

**Shipped (v0.2, working end-to-end in tab editor):**
- [x] `AudioEngine` facade — `resources/js/audio/engine/AudioEngine.js`
- [x] `PlaybackClock` interface + `ToneClock` impl
- [x] `Scheduler` (25ms lookahead loop, scheduler-owned)
- [x] `PitchedSynth` (port of WP `sbn-audio.js` — PolySynth+EQ3+Reverb+Limiter)
- [x] `tabMeasureToEvents` adapter (pitch, duration, velocity; tie/bend can land iteratively)
- [x] `pitchToMidi` utility (note-name+octave and string+fret→MIDI)
- [x] `useAudioEngine` composable wired into `TabEditor.vue`
- [x] Play/Stop button (tab bar, right-aligned) + Space/Escape shortcuts
- [x] Tone.js installed (`npm install tone`)

**Stubs / typed but not implemented:**
- `MediaElementClock` — throws on use. Keeps interface honest for video phase.
- `PercussionSampler`, `FallbackSynths`, rhythm/chord adapters — Phase 7D+ tasks.

**Deferred entirely:**
- Recording, export, multi-track.

**Resolved decisions:**
- JS + JSDoc (not TS) — matches house style.
- Scheduler owns its own 25ms loop; clock only reports time.
- `samplesBaseUrl` will come from a Blade-injected `window.sbnConfig` when percussion lands.
- Tab model shape locked: `model.sections[].measures[].events[].notes[]` with `{string, fret, pitch, octave}`, ticks at 480 PPQ.

---

## 12. Sample/Synth Hybrid Policy — Detailed Design

This section is the task-2 design deliverable. It expands §7 and §8 from summaries into specifications precise enough for a coding AI to implement `PercussionSampler`, `FallbackSynths`, and `Mixer` without further design questions.

### 12.1 PercussionSampler — WAV loading strategy

Port the `SbnPercussion.init()` pattern from `sbn-percussion.js:59-115`. On `engine.init()` for any page that uses percussion (rhythm view only — see §12.5), `PercussionSampler` fires parallel `fetch()` + `decodeAudioData()` calls for all ten WAV files:

```
{samplesBaseUrl}/shaker_soft.wav        shaker_accent.wav
{samplesBaseUrl}/tamborim_soft.wav      tamborim_accent.wav
{samplesBaseUrl}/kick_soft.wav          kick_accent.wav
{samplesBaseUrl}/hihat_brush_soft.wav   hihat_brush_accent.wav
{samplesBaseUrl}/brush_snare_soft.wav   brush_snare_accent.wav
```

Each decoded buffer is stored in an internal map keyed `{instrument}_{variant}` (e.g., `shaker_soft`). All ten `fetch()` calls are issued simultaneously via `Promise.allSettled()` — not `Promise.all()` — so a single missing file does not abort the rest.

**Non-fatal rule**: if a file fails to fetch or `decodeAudioData()` throws, that bucket stays `null`. A `console.warn('[SBN] PercussionSampler: failed to load …')` is emitted and the engine silently falls back to `FallbackSynths` for events that target that bucket. No error is thrown; no `Promise` rejection propagates to the caller.

**Variant fallback within a loaded bucket** (`sbn-percussion.js:131`): if `shaker_accent` is missing but `shaker_soft` is present, use `shaker_soft` rather than synthesizing. Never invoke `FallbackSynths` when *any* variant of the requested bucket is available.

### 12.2 Fallback map — bucket missing → FallbackSynths

When `PercussionSampler` cannot serve an event (bucket `null`), the `Mixer` routes it to `FallbackSynths` instead. The mapping preserves the WP `course-player.js` behavior as closely as possible:

| `EngineEvent.sample` | FallbackSynths method | WP source |
|---|---|---|
| `shaker` | `playHiHat(time)` — noise burst | `course-player.js:1138` |
| `tamborim` | `playClave(time, 'high')` — 2500→1800 Hz | `course-player.js:1074` |
| `kick` | `playClave(time, 'low')` — 1800→1200 Hz | `course-player.js:1074` |
| `hihat-brush` | `playHiHat(time)` — noise burst | `course-player.js:1138` |
| `brush-snare` | `playMuted(time, 'high')` — 330 Hz square | `course-player.js:1116` |

The mapping is intentionally approximate — the goal is *something rhythmically usable*, not sonic accuracy. The blend slider (§12.3) lets users maximize samples once they have loaded.

Pitched voices (`voice: 'pitched'`) are always handled by `PitchedSynth`; `FallbackSynths` is never invoked for them. The `voice: 'muted'` and `voice: 'clave'` paths route directly to `FallbackSynths.playMuted()` and `FallbackSynths.playClave()` regardless of blend.

### 12.3 Blend slider — formula and view policy

The `Mixer` maintains two parallel gain nodes per percussion path: `synthGainNode` and `sampleGainNode`. `engine.setBlend(ratio)` (0..1, where 0 = pure synth, 1 = pure sample) updates both simultaneously:

```js
// Preserve WP formula verbatim (course-player.js:815-838).
// WP used blend 0–100; ratio here is 0..1 — formula adapted accordingly.
// Do NOT change the 0.8 headroom factor — it was tuned by ear in the WP version.
synthGainNode.gain.value  = (1 - ratio) * 0.8;
sampleGainNode.gain.value = ratio       * 0.8;
```

Both paths are always active (neither is gated off completely) so there is no click artifact when adjusting the slider mid-playback.

**View-specific blend policy:**

| View | Blend slider | Default ratio | Notes |
|---|---|---|---|
| Rhythm | Yes — exposed to user | `1.0` | Slider wired to `engine.setBlend()` |
| Chord view | No | N/A | `PitchedSynth` only; `PercussionSampler` never initialized |
| Tab editor | No | N/A | `PitchedSynth` only; `PercussionSampler` never initialized |
| Leadsheet | No | N/A | `PitchedSynth` only; `PercussionSampler` never initialized |

The blend slider is a rhythm-view concern only. Chord, tab, and leadsheet views do not call `engine.setBlend()` at all — they initialize the engine without `PercussionSampler`, so there is nothing to crossfade.

### 12.4 iOS / autoplay unlock — implementation rule

`AudioEngine.play()` already awaits `Tone.start()` before scheduling (shipped code). One additional rule governs `PercussionSampler` specifically:

> **`PercussionSampler` MUST obtain its `AudioContext` via `Tone.getContext().rawContext`, never via `new AudioContext()`.**

This is the direct lesson from `SbnPercussion._getAudioContext()` (sbn-percussion.js:178-191). The legacy standalone fallback context (`this._standaloneCtx = new AudioContext()`) caused clock drift on iOS because it ran on a separate timeline from Tone.js. In the new engine the rule is architectural: **exactly one `AudioContext` per page, always owned by Tone.js.** Any module needing a raw context reference calls `Tone.getContext().rawContext` and does nothing else.

The `on('unlock')` event emitted by `AudioEngine.play()` is the correct hook for hiding a "tap to enable sound" overlay in the UI. Composables should listen to this event rather than implementing their own unlock detection.

### 12.5 Pre-load vs lazy-load strategy

| Page type | PercussionSampler init timing | Rationale |
|---|---|---|
| Rhythm view | Pre-load during `engine.init()` (page mount) | User expects immediate playback; page-load idle time should fetch WAVs |
| Chord view | Not initialized | Percussion never used |
| Tab editor | Not initialized | Percussion never used |
| Leadsheet (future, Phase 7D+) | Lazy — on first `play()` attempt | Page may embed a rhythm block or may not |

For the rhythm view: `engine.init({ samplesBaseUrl })` is called as soon as the rhythm Vue component mounts (not deferred to first play press). This ensures `PercussionSampler` has the full page-load window to fetch and decode WAVs before the user interacts.

For tab and chord views: pass `samplesBaseUrl: ''` (or omit it). `PercussionSampler` is never constructed; there is no wasted fetch.

---

## 13. Laravel / Vue Integration — Detailed Design

This section is the task-4 design deliverable. It specifies how backend data reaches the frontend engine, where the singleton lives, how models serialize to adapter inputs, and how the composable pattern extends to new views.

### 13.1 How `samplesBaseUrl` reaches the frontend

Inject via an inline `<script>` block in the Blade layout **before** the `@stack('vite')` call so that `window.sbnConfig` is synchronously available when the Vue entry-point module executes:

```blade
{{-- resources/views/layouts/admin.blade.php, just before @stack('vite') --}}
<script>
window.sbnConfig = {
    samplesBaseUrl: "{{ rtrim(asset('audio/samples'), '/') }}",
    csrfToken: "{{ csrf_token() }}",
};
</script>
@stack('vite')
```

`asset('audio/samples')` resolves to `public/audio/samples/` — the directory that holds the WAV files. `rtrim(..., '/')` ensures no trailing slash, because `PercussionSampler` appends `/shaker_soft.wav` itself.

**Do not** use a separate `/api/config` AJAX call. The config must be synchronously present when `engine.init()` is called, which may happen at Vue `mounted()` before any async operations complete.

Views that do not use percussion (chord, tab, leadsheet) do not need `samplesBaseUrl` in `window.sbnConfig`, but it is harmless to include it globally. A per-view `@push('scripts')` override can supply a different value if needed.

### 13.2 Engine singleton scope

`getAudioEngine()` (exported from `AudioEngine.js`) returns a module-level singleton — one instance per page load. This is the correct scope because Tone.js enforces one `AudioContext` per page.

**Multi-app pages**: the leadsheet edit view currently mounts two Vue apps (`TabEditor` + `TabSidebarApp` via `tab-editor.js`). Both call `getAudioEngine()` and receive the same singleton. Neither app should call `engine.dispose()` on `onBeforeUnmount`, because the other app may still be active. Instead:

- Call `engine.stop()` on unmount — this is safe and idempotent.
- Call `engine.dispose()` only when the page is fully unloading (i.e., wire it to `window.beforeunload` if needed, or simply let the page GC handle it).

**Contradiction flagged**: the shipped `useAudioEngine.js` (tab-editor) reads `engine._inited` (a private field) to gate the lazy `init()` call inside `play()`. This is a contract violation — the public facade exposes no `isInited` property.

**Resolution**: add a read-only getter to `AudioEngine`:
```js
get isInited() { return this._inited; }
```
Update the existing `tab-editor/composables/useAudioEngine.js` to use `engine.isInited` instead of `engine._inited`. All future composables (rhythm, leadsheet) must use the public getter only.

### 13.3 Backend model → adapter JSON shapes

The backend serves existing model data unchanged; no new API endpoints are required for Phase 7C/7D audio features. Each adapter receives a plain JS object, serialized from either a Blade `@json()` prop injection or a JSON API response from an existing controller.

**RhythmPattern → `rhythmPatternToEvents`**

The `RhythmPattern::toPlayerData()` method (`app/Models/RhythmPattern.php:106-118`) already emits the shape the adapter needs:

```json
{
  "name":          "Bossa Nova",
  "beats":         16,
  "gridType":      "sixteenth",
  "thumb":         "x...x...x...x...",
  "fingers":       "..x...x...x...x.",
  "bpm":           130,
  "timeSignature": "4/4",
  "percTop":       "shaker",
  "percBass":      "kick"
}
```

`percTop` and `percBass` map directly to the `sample` field on `EngineEvent`. A value of `"none"` means no percussion event is emitted for that row. `thumb` encodes the bass-string (thumb) hits; `fingers` encodes the treble-string (finger) hits. Characters: `x`/`X` = hit, `.` = rest. `X` (uppercase) = accent → `variant: 'accent'`; `x` (lowercase) = normal → `variant: 'soft'`.

The adapter's job is to walk both strings in parallel, converting each non-`.` character to an `EngineEvent` at the appropriate beat offset. The `gridType` field determines the beat subdivision (e.g., `"sixteenth"` = 1/4 beat per step at 4/4).

**ChordDiagram → `chordDiagramToEvents`**

**Gap noted**: the `notes` column in `sbn_chord_diagrams` stores pitch-class names only (e.g., `"E,B,E,G#,B,E"`) without octave information. The adapter requires absolute MIDI numbers. The `notes` column is suitable for display only and must not be used to generate MIDI.

**Resolution**: the adapter must compute MIDI from `diagram_data.positions` (fret numbers per string) plus standard guitar tuning (Low E = MIDI 40). The model's `getDiagramAttribute()` already decodes `diagram_data` from JSON. The adapter brief must specify this explicitly and include the standard tuning table:

```
String 1 (Low E)  = MIDI 40    String 4 (G) = MIDI 55
String 2 (A)      = MIDI 45    String 5 (B) = MIDI 59
String 3 (D)      = MIDI 50    String 6 (Hi E) = MIDI 64
```

Muted strings (`diagram_data.muted[]`) are skipped — no event emitted. Open strings (`diagram_data.open[]`) use fret 0.

Expected adapter input shape:

```json
{
  "id":           42,
  "slug":         "e-maj-roote",
  "root_note":    "E",
  "quality":      "maj",
  "diagram_data": {
    "positions": [{"string": 1, "fret": 0}, {"string": 2, "fret": 2}, ...],
    "barres":    [],
    "muted":     [],
    "open":      [1]
  }
}
```

**ChordProgression → `chordProgressionToEvents`**

The `ChordProgression` model stores Roman numeral strings (`numerals`, e.g., `"ii,V,I"`) and category metadata, but not concrete chord roots or durations. The adapter cannot operate on the raw model alone — it requires a resolved context from the leadsheet. The composable or Blade layer is responsible for assembling:

```json
{
  "id":               7,
  "name":             "ii–V–I",
  "numerals":         "ii,V,I",
  "key":              "C",
  "qualityPerChord":  ["m7", "dom7", "maj7"],
  "beatsPerChord":    [4, 4, 8]
}
```

`key` comes from `Leadsheet.song_key`. `qualityPerChord` and `beatsPerChord` are editorial choices (either stored on the leadsheet or defaulted by the adapter). The adapter brief must document these fields explicitly. The coding AI implementing task 10 must not infer these from the progression model alone.

### 13.4 `useAudioEngine` composable pattern per view

The shipped `tab-editor/composables/useAudioEngine.js` is the canonical reference. New views follow the same structure at their own composable paths:

```
resources/js/rhythm/composables/useAudioEngine.js
resources/js/leadsheet/composables/useAudioEngine.js
```

All composables:
1. Call `getAudioEngine()` to get the singleton.
2. Call the view-specific adapter to convert the reactive model to `EngineEvent[]`.
3. Expose `{ isPlaying, currentBeat, activeSourceId, play, stop, toggle }`.
4. Clean up event listeners in `onBeforeUnmount`.

View-specific differences are limited to three things:

| Concern | Tab editor | Rhythm | Leadsheet |
|---|---|---|---|
| Adapter called | `tabModelToEvents` | `rhythmPatternToEvents` | `chordProgressionToEvents` or `tabModelToEvents` |
| `samplesBaseUrl` in init | No | Yes — from `window.sbnConfig` | No |
| Exposes `blend` ref | No | Yes | No |

The rhythm composable is the only one that passes `samplesBaseUrl` to `engine.init()` and exposes a `blend` ref wired to `engine.setBlend()`. Template (interface-only — implementation is a Phase 7D coding task):

```js
// resources/js/rhythm/composables/useAudioEngine.js — interface sketch
export function useAudioEngine(model) {
    const engine  = getAudioEngine();
    const isPlaying      = ref(false);
    const currentBeat    = ref(0);
    const activeSourceId = ref(null);
    const blend          = ref(1.0);   // rhythm-only

    async function init() {
        await engine.init({
            samplesBaseUrl: window.sbnConfig?.samplesBaseUrl ?? '',
            bpm: model.value?.bpm ?? 120,
        });
        engine.setBlend(blend.value);
        // wire tick / sourceActive / ended listeners ...
    }

    // play(), stop(), toggle() follow tab-editor pattern exactly.
    // blend watcher: watch(blend, v => engine.setBlend(v))

    return { isPlaying, currentBeat, activeSourceId, blend, play, stop, toggle };
}
```

---

## 14. Task distribution across AI models

### Architecture tasks (Opus / Sonnet)

These require judgment, design decisions, or complex integration:

| # | Task | Model | Status | Notes |
|---|------|-------|--------|-------|
| 1 | Engine contract & module layout | Opus | Done | This document |
| 2 | Sample/synth hybrid policy | Sonnet | **Done** | §12 — WAV loading, fallback map, blend formula, iOS rule, pre-load |
| 3 | Tab editor polyphony spike | Opus | Done | Validated contract against the hardest consumer |
| 4 | Laravel/Vue integration design | Sonnet | **Done** | §13 — sbnConfig injection, singleton scope, model shapes, composable template |

### Coding tasks (Gemini / Windsurf / Copilot / Cursor)

These are parallelizable once architecture is locked. Each task gets a self-contained brief (see §15 handoff contract).

| # | Task | Input (WP source) | Output (target path) | Deps |
|---|------|--------------------|----------------------|------|
| 5 | Port `SbnPercussion` → `PercussionSampler` | `assets/js/sbn-percussion.js` (lines 59-191) | `audio/engine/voices/PercussionSampler.js` | None |
| 6 | Port `SbnAudio` pitched synth refinements | `assets/js/sbn-audio.js` (lines 84-120) | `audio/engine/voices/PitchedSynth.js` (enhance) | None |
| 7 | Port synth fallback sounds (clave/muted/hihat/guitar) | `assets/js/course-player.js` (lines 1037-1162) | `audio/engine/voices/FallbackSynths.js` | None |
| 8 | Rhythm pattern → event stream adapter | Rhythm model (backend) | `audio/adapters/rhythmPatternToEvents.js` | §13.3 + fixtures ✓ |
| 9 | Chord diagram → event stream adapter | ChordDiagram model | `audio/adapters/chordDiagramToEvents.js` | §13.3 + fixtures ✓ |
| 10 | Chord progression → event stream adapter | Chord view model | `audio/adapters/chordProgressionToEvents.js` | §13.3 + fixtures ✓ |
| 11 | Vue composable `useAudioEngine()` per view | — | `rhythm/composables/`, `leadsheet/composables/` | Tasks 8-10 + §13.4 |
| 12 | Unit tests for each adapter | Golden fixtures | `audio/adapters/__tests__/` | Tasks 8-10 |

### Sequencing

```
Phase 1 (done)  ─ Opus:    tasks 1 + 3 (contract + tab spike)
Phase 2 (done)  ─ Sonnet:  tasks 2 + 4 (hybrid policy + Laravel integration + golden fixtures)
Phase 3 (ready) ─ Coding AIs: tasks 5-7 now; tasks 8-10 now (fixtures shipped); tasks 11-12 after 8-10
Phase 4         ─ Sonnet:  integrate adapters → engine, run tests, fix integration bugs
Phase 5         ─ Sonnet:  visual design session — player controls, playback highlighting, blend slider UI
```

Critical rule: **do not fan out Phase 3 until the event contract is validated by the tab spike (done) and the hybrid policy (task 2) is locked.** Otherwise adapters get written against an API that changes.

---

## 15. Handoff contract for coding AIs

When a coding model is given adapter task N, it receives:

1. **This document** as authoritative reference.
2. **The WP source file** it's porting from (with line ranges).
3. **The target path** in `resources/js/audio/`.
4. **Golden fixtures**: one input model, one expected event list, produced by Opus/Sonnet before handoff.
5. **Explicit non-requirements**: what NOT to do (e.g., "do not import Tone", "do not read from the DOM").

Without all five, do not hand off — the task will come back wrong and waste a round trip.

### Visual design boundary

Coding AI tasks are **technical only**. They must not make visual design decisions. Specifically:

- Player controls: ship a plain `<button>` with a functional click handler. No styling opinions.
- Playback highlighting: apply a single `is-active` CSS class to the currently-active element (keyed by `activeSourceId`). Do not invent animation, color, or transition rules.
- Blend slider: a plain `<input type="range" min="0" max="1" step="0.01">` wired to the `blend` ref. No custom slider UI.
- Do not add new CSS files, design tokens, or component styling beyond what already exists in the view.

A dedicated Sonnet visual design session (Phase 5) will handle all player UI styling and ensure consistency across views. Coding AIs that design visuals create rework.

---

## Changelog

- v0.4 — golden fixtures shipped for adapters 8-10 (`audio/adapters/__tests__/`). Updated sequencing: Phase 3 fan-out now unblocked. Added visual design boundary rule to §15: coding AIs use plain HTML + `is-active` class only; Phase 5 Sonnet session handles all player UI styling. Architecture tasks 2 + 4 marked Done in task table.
- v0.3 — tasks 2 + 4 complete. Added §12 (Sample/Synth Hybrid Policy — detailed design: WAV loading strategy, fallback map, blend formula, iOS lock rule, pre-load strategy) and §13 (Laravel/Vue Integration — `window.sbnConfig` injection, singleton scope, backend model JSON shapes for all three adapters, composable pattern template). Renumbered former §12→§14, §13→§15. Flagged one contract violation in shipped code: `engine._inited` accessed directly in `useAudioEngine.js`; resolution specified (add `get isInited()` getter). Flagged one data gap: `ChordDiagram.notes` column stores pitch-class only, not octave; resolution specified (read `diagram_data.positions` + tuning table instead).
- v0.2 — tab spike shipped and working. Resolved open questions (JS, scheduler ownership, tab model shape). Added task distribution plan (§12).
- v0.1 — initial draft.

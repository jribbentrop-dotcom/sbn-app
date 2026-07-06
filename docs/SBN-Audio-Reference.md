# SBN Audio System

> Last updated: 2026-07-04  
> Status: chord view and tab editor fully wired. Percussion voices wired. Video sync shipped (YouTube-as-clock, play-position model, AABA repeats). Adapters (`tabMeasureToEvents`, `chordVoicingsToEvents`) are repeat/volta-aware via `expandMeasureSequence`. Leadsheet Viewer plays real nylon-guitar samples (`NylonSampler`, `voice: 'nylon'`) instead of the synth voice, and loads only the active mode's events (Tab mode ≠ Chords mode) instead of combining both — see SBN-Leadsheet-Reference §6.3.

---

## 1. Architecture overview

All audio runs in the browser. The backend serves data models unchanged; it has no knowledge of audio. The system has three layers:

```
┌─────────────────────────────────────────────┐
│  Vue composables  (per-view wrappers)        │
│  useAudioEngine.js  /  useChordAudio.js      │
└───────────────────┬─────────────────────────┘
                    │ play / pause / reset / seek
┌───────────────────▼─────────────────────────┐
│  AudioEngine  (singleton facade)             │
│    ToneClock  ←  Tone.Transport              │
│    Scheduler  (25ms lookahead loop)          │
│    Voices:  PitchedSynth                     │
│             PercussionSampler  [wired]       │
│             FallbackSynths    [wired]        │
└───────────────────▲─────────────────────────┘
                    │ EngineEvent[]
┌───────────────────┴─────────────────────────┐
│  Adapters  (pure functions, no side effects) │
│    tabMeasureToEvents        ← tab model     │
│    chordVoicingsToEvents     ← chord model   │
│    chordDiagramToEvents      ← diagram model │
│    chordProgressionToEvents  ← progression   │
│    rhythmPatternToEvents     ← rhythm model  │
└─────────────────────────────────────────────┘
```

**One engine per page.** `getAudioEngine()` returns a module-level singleton. Tone.js enforces one `AudioContext` per page — this is the architectural rule, not a convenience.

**One shared clock.** Both `useAudioEngine` (tab) and `useChordAudio` (chord) share the same `ToneClock` → `Tone.Transport`. Seeking or changing tempo in one composable immediately affects the other.

---

## 2. File map

```
resources/js/audio/
  engine/
    AudioEngine.js          — public facade
    ToneClock.js            — wraps Tone.Transport (active clock)
    MediaElementClock.js    — stub; throws on use (video phase)
    PlaybackClock.js        — interface definition (JSDoc)
    Scheduler.js            — 25ms lookahead, walks EngineEvent[]
    voices/
      PitchedSynth.js       — PolySynth + EQ3 + Reverb + Limiter [WIRED]
      PercussionSampler.js  — WAV loading + playback              [WIRED]
      FallbackSynths.js     — raw WebAudio synth fallbacks         [WIRED]
  adapters/
    tabMeasureToEvents.js             [WIRED — tab view]
    chordVoicingsToEvents.js          [WIRED — chord view]
    chordDiagramToEvents.js           [implemented, not wired]
    chordProgressionToEvents.js       [implemented, not wired]
    rhythmPatternToEvents.js          [implemented, not wired]
    pitchToMidi.js                    — utility (note-name + fret → MIDI)
    __tests__/                        — Vitest golden-fixture tests (9 passing)
  types.js                            — EngineEvent, Voice, Beats JSDoc typedefs

resources/js/tab-editor/composables/
  useAudioEngine.js     — tab playback wrapper
  useChordAudio.js      — chord view playback wrapper

resources/js/tab-editor/components/
  TransportBar.vue      — unified play/pause/stop/seek/tempo UI

resources/js/rhythm/composables/
  useAudioEngine.js     — rhythm wrapper (written, awaiting rhythm Vue migration)
resources/js/leadsheet/composables/
  useAudioEngine.js     — leadsheet wrapper (written, awaiting leadsheet Vue migration)
```

---

## 3. Engine API

```js
const engine = getAudioEngine();

// Lifecycle
await engine.init({ bpm: 120 });  // idempotent; safe to call multiple times
engine.dispose();                 // full teardown; call only on page unload

// Content
engine.load(events);   // EngineEvent[] — replaces current queue
engine.clear();

// Transport
await engine.play();   // resumes from current clock position
engine.pause();        // stops audio, keeps clock position
engine.stop();         // stops audio + seeks to beat 0
engine.seek(beat);     // move playhead; safe while paused or playing
engine.setTempo(bpm);

// Mixing
engine.setMasterVolume(db);

// Events
const unsub = engine.on('tick',         (beat) => { ... });
const unsub = engine.on('sourceActive', (id)   => { ... });
const unsub = engine.on('ended',        ()     => { ... });
const unsub = engine.on('unlock',       ()     => { ... });
const unsub = engine.on('playStarted',  ()     => { ... });
unsub(); // call to unsubscribe
```

### Transport semantics

| Call | Effect |
|------|--------|
| `play()` | Resumes from `clock.currentBeat()`. Does NOT seek to 0 first — callers control position. |
| `pause()` | Stops `Scheduler` + pauses `Tone.Transport`. Position is retained. |
| `stop()` | Stops `Scheduler` + stops `Tone.Transport` + seeks to beat 0. |
| `seek(beat)` | Moves `Tone.Transport.ticks` and calls `Scheduler.seekTo()`. Immediate, safe mid-playback. |

### `playStarted` event

Fired at the start of every `play()` call, before audio starts. Composables use this to clear their own `isPlaying` flag when a sibling composable starts playback — prevents stale highlight state when switching views mid-play.

---

## 4. EngineEvent schema

```js
{
  time:      Beats,      // musical time from transport 0; NOT seconds
  voice:     Voice,      // 'pitched' | 'nylon' | 'percussion' | 'muted' | 'clave' | 'noise'
  pitch:     MIDINote,   // 0–127; required for 'pitched' / 'nylon'
  duration:  Beats,
  velocity:  number,     // 0..1
  variant?:  'soft' | 'accent',   // percussion only
  sample?:   string,              // percussion bucket, e.g. 'shaker', 'kick'
  sourceId?: string,              // used to drive UI highlights via 'sourceActive' event
}
```

**Why beats, not seconds:** a tempo change must not invalidate already-queued events. The Scheduler converts beats → seconds at dispatch time using `60 / bpm`.

---

## 5. Adapters

All adapters are pure functions: `(model, ctx?) => EngineEvent[]`. No Tone.js imports, no DOM access, deterministic output.

```js
// Common context shape
ctx = {
  startBeat?: Beats,    // offset entire output by this many beats (default 0)
  loop?:      { from: Beats, to: Beats },
}
```

### `tabMeasureToEvents(model, ctx)`
Converts the tab model (`model.sections[].measures[].events[].notes[]`) into pitched events. One event per sounding note. Pitch resolved by `noteToMidi()` (`resources/js/audio/adapters/pitchToMidi.js`) — prefers the note's stored `pitch`+`octave` (concert/sounding pitch, as parsed straight off MusicXML's `<pitch>` by `TabXmlParser.php`; **not** written/8va-transposed, verified against string+fret cross-checks), falling back to `string`+`fret` via standard tuning (Low E = MIDI 40) when pitch/octave are absent. `ctx.voice` (default `'pitched'`) is passed through onto every emitted event — the Leadsheet Viewer passes `'nylon'` for real guitar-sample playback (§6.3 of SBN-Leadsheet-Reference), the admin tab editor leaves it at `'pitched'`. Repeat/volta-aware — runs the flat measure list through `expandMeasureSequence` and re-times each event by `tickInMeasure` within its play position, so a bar that repeats produces two sets of events at different beat offsets. Ties and bends not yet implemented.

### `chordVoicingsToEvents(model, ctx)`
Converts `model.chordVoicings` into one strum per measure. Voicing key lookup: `"Am@3.0"` (per-measure) falling back to `"Am"` (global). Fret string format: 6-char hex string (`"x32010"`), index 0 = string 1 (Low E). Produced by `useVoicingPickerStore._diagramDataToFrets()`. Repeat/volta-aware via `expandMeasureSequence` — see §5.1. `ctx.voice` (default `'pitched'`) works the same as `tabMeasureToEvents` above.

### 5.1 Repeat + volta playback model

The audio engine clock counts **play positions**, not bar indices. A score has two coordinate systems that must not be confused:

| Coordinate | Meaning | Where it's used |
|------------|---------|-----------------|
| **gi** (global index) | "Which bar of the score" — 0..N-1 over the flat measure list | Score rendering, click handlers, video sync mappings (as stored on disk), most UI |
| **play position** | "Which step of the linear, repeat-expanded timeline" — a bar that repeats has several positions | Audio engine clock, scheduler events, video↔score interpolation, internal seek math |

`expandMeasureSequence(measures)` is the single source of truth for the conversion. It returns a `number[]` where `seq[pos] = gi` — feed it the flat measure list (with `repeatStart`, `repeatEnd`, `volta` flags populated) and it produces the play order honouring standard repeat + first/second ending semantics.

Helpers exported from the same file:
- `giAtPosition(seq, pos)` — `seq[pos]` with out-of-range clamping. Use this in any watcher that turns a beat or play-position into a highlight gi.
- `firstPositionForGi(seq, gi)` — inverse for the common "seek to a bar" path. A repeated bar's *first* play position is the start of its phrase, which is the sane default for "jump to bar N" clicks.

**Both `tabMeasureToEvents` and `chordVoicingsToEvents` MUST use this helper.** If only one of them expands repeats and the other emits a linear timeline, you get the "two audio streams after the repeat" bug — the engine plays the repeat-expanded chord events and the linear tab events simultaneously and they drift apart at the repeat. Tested in [`expandMeasureSequence.test.js`](../resources/js/audio/adapters/__tests__/expandMeasureSequence.test.js) with the standard AABA fixture; add a test there before touching the algorithm.

Video sync layers on top: see SBN-Admin-Reference §VIDEO SYNC ARCHITECTURE for editor-side authoring and SBN-Leadsheet-Reference §8.2 for the Cinema viewer.

### 5.2 Pickup bars

A pickup bar (`measure.pickup === true`, `measure.pickupBeats` set to a value less than the global time signature's beat count) contributes only its own `pickupBeats` to the timeline instead of a full `beatsPerMeasure` — otherwise every bar after the pickup would be shifted by `(beatsPerMeasure - pickupBeats)` beats, a systematic drift that gets worse the longer the song runs.

Every place that turns a play position (or gi) into a beat offset must branch on `pickupBeats`:

- `chordVoicingsToEvents.js` / `tabMeasureToEvents.js` — build a `positionBeatStart` array before emitting events: `beatCursor += measure.pickupBeats ?? beatsPerMeasure`.
- `expandMeasureSequence.js`'s `sequenceToBeatMap(sequence, beatsPerMeasure, measureByGi)` — same pattern, as a reusable table-builder (`measureByGi` is optional; omitting it falls back to flat spacing).
- `TabEditor.vue`'s `playPositionBeatTable` / `LeadsheetViewer.vue`'s `playPositionBeatTable` — the UI-facing version of the same table, `{ gi, beatStart, beatEnd }` per play position. Both views build their own copy (see §8 below) rather than sharing one, since one lives in the admin editor's transport and the other in the read-only viewer.
- `useVideoSync.js`'s `videoBeat` / `videoTimeToBeat` (admin video-sync) — must agree with `playPositionBeatTable`, since the cursor watcher looks beats up in that table; if `videoBeat` uses flat `pos * bpm` while pickup bars exist, playback drifts the moment video is the master clock.

**Gotcha:** getting the *engine clock* pickup-aware isn't enough — every consumer that independently re-derives "beat within this bar" from the raw transport beat via `beat % bpm` will desync after a pickup, because that modulo has no memory of the non-uniform bar before it. See `measureBeatStartMap` in §8 — this is what `TabMeasure.vue`/`ChordMeasure.vue` need injected to compute `beatInThisMeasure` correctly; without it they silently fall back to the broken modulo and produce a per-measure "sawtooth" jump in the sub-beat cursor, easy to miss because it only manifests on songs that actually have a pickup bar.

### `chordDiagramToEvents(model, ctx)`
Converts a `ChordDiagram` database record into a single strum. MIDI derived from `diagram_data.positions` + standard tuning — **not** from the `notes` column (which has no octave). Barre chords handled; muted strings skipped.

### `chordProgressionToEvents(model, ctx)`
Converts a resolved chord progression (Roman numerals + key + quality per chord + beats per chord) into pitched events. The resolved context is assembled by the composable, not taken raw from the database. Supports qualities: `maj`, `min`, `maj7`, `m7`, `dom7`, `m7b5`, `o7`, `aug`, `dim`, `sus2`, `sus4`, and more.

### `rhythmPatternToEvents(model, ctx)`
Converts a `RhythmPattern` model (`thumb`, `fingers` hit strings, `percTop`, `percBass`, `gridType`) into percussion events. `'X'` = accent, `'x'` = soft, `'.'` = rest. Grid types: `'eighth'` (0.5 beats/step), `'sixteenth'` (0.25), `'triplet'` (1/3).

### Standard guitar tuning (all adapters)
```
String 1 (Low E) = MIDI 40    String 4 (G) = MIDI 55
String 2 (A)     = MIDI 45    String 5 (B) = MIDI 59
String 3 (D)     = MIDI 50    String 6 (Hi E) = MIDI 64
```

---

## 6. Voices

### `PitchedSynth` — active
Port of the WP `sbn-audio.js` guitar synth. Signal chain: `PolySynth → EQ3 → Reverb → Limiter → out`.

Configuration (verbatim from WP, tuned by ear — do not change):
- Oscillator: type `'custom'`, partials `[1.0, 0.4, 0.15, 0.05]`
- Envelope: attack 0.002, decay 0.08, sustain 0.25, release 0.6, volume −16 dB
- EQ3: low −2, mid 0, high −4
- Reverb: decay 1.6, wet 0.18
- Limiter: −3 dB

### `NylonSampler` — active
[`resources/js/audio/engine/voices/NylonSampler.js`](../resources/js/audio/engine/voices/NylonSampler.js) — real nylon-string guitar samples (MusyngKite soundfont derived) via `Tone.Sampler`, pitch-shifting between six recorded anchor notes: `public/audio/nylon/{E2,A2,D3,G3,B3,E4}.mp3`. Signal chain: `Sampler → Filter(lowpass) → EQ3 → Reverb → destination`.

Current tuning (as of 2026-07-04):
- Filter: lowpass 1800 Hz
- EQ3: low 0, mid −4, high +1
- Reverb: decay 3.2, wet 0.5

Instantiated as a **shared singleton** (`getSharedNylon()`, `resources/js/audio/engine/voices/sharedNylon.js`) since `AudioEngine` itself is also a singleton — `AudioEngine.init()` lazily calls `nylonSampler.init('/audio/nylon/')` the first time `init()` runs with a non-empty `samplesBaseUrl` (any truthy value arms it — the actual value used is the *percussion* sampler's base path, e.g. `/audio/rhythm-samples/`, since that's the param's real purpose; the nylon sampler's own sample path is hardcoded inside `AudioEngine.init()` regardless of what `samplesBaseUrl` points to). Passing `''`/omitting `samplesBaseUrl` entirely leaves the nylon sampler silently unloaded even when events are tagged `voice: 'nylon'` — no error, just no sound.

`trigger(midi, time, durationSec, velocity, offsetSec)` mirrors `PitchedSynth`'s interface so the two are interchangeable at the `Scheduler._dispatch()` level (routes on `ev.voice === 'nylon'`).

### `PercussionSampler` — wired
Port of `sbn-percussion.js`. Loads 10 WAV files (`shaker_soft/accent`, `tamborim`, `kick`, `hihat_brush`, `brush_snare`) via `Promise.allSettled()` — a missing file is a non-fatal warning. Always obtains `AudioContext` via `Tone.getContext().rawContext` (never `new AudioContext()`).

Wired into `AudioEngine._voices = { pitched, percussion: percSampler, percFallback, nylon }`. `init()` calls `percSampler.init(samplesBaseUrl)` if `samplesBaseUrl` is provided (and separately arms `nylon` — see above).

### `FallbackSynths` — wired
Raw WebAudio API synthesis for when WAV samples are unavailable. Methods: `playClave(time, register)`, `playMuted(time, register)`, `playHiHat(time)`, `playNote(time, freq, dur, vol)`. Frequency values from WP `course-player.js` (do not change).

`Scheduler._dispatch()` routes `voice: 'percussion'` events: checks if sampler buffer is loaded → uses `PercussionSampler`; otherwise calls `_dispatchFallback(fb, instrument, when)` which routes by `event.sample`:

Dispatch table (when percussion event arrives and sample buffer is missing):

| `event.sample` | Fallback |
|---|---|
| `shaker` | `playHiHat()` |
| `tamborim` | `playClave('high')` |
| `kick` | `playClave('low')` |
| `hihat-brush` | `playHiHat()` |
| `brush-snare` | `playMuted('high')` |

---

## 7. Composable pattern

Every view that uses audio gets a `useAudioEngine(model)` composable following this contract:

```js
export function useAudioEngine(model) {
    const engine = getAudioEngine();

    // composable-local init flag — NOT engine.isInited
    // ensures each composable registers its own listeners even if the engine
    // was already init'd by a sibling composable
    let _inited = false;

    async function init() {
        await engine.init({ bpm: model.value?.tempo ?? 120 });
        if (_inited) return;
        _inited = true;
        // register tick / sourceActive / ended / playStarted listeners
    }

    async function play() {
        await init();
        loadFromModel();
        if (currentBeat.value > 0) engine.seek(currentBeat.value); // restore paused position
        await engine.play();
        isPlaying.value = true;
    }

    function pause()  { engine.pause(); isPlaying.value = false; /* keep currentBeat */ }
    function reset()  { engine.stop();  isPlaying.value = false; currentBeat.value = 0; }
    function seek(b)  { engine.seek(b); currentBeat.value = b; }

    return { isPlaying, currentBeat, activeSourceId, play, pause, reset, stop, toggle, seek };
}
```

**Guard `sourceActive` with `isPlaying`**: both composables register `sourceActive` listeners on the shared engine. Each must check `if (isPlaying.value)` before updating `activeSourceId` — otherwise the sibling composable's events contaminate the highlight state.

**`stop()` is an alias for `pause()`** — kept for backwards compatibility, but all new code should call `pause()` or `reset()` explicitly.

### Active composables

| View | Composable | Adapter | Status |
|------|-----------|---------|--------|
| Tab editor | `tab-editor/composables/useAudioEngine.js` | `tabMeasureToEvents` | ✅ wired |
| Chord view | `tab-editor/composables/useChordAudio.js` | `chordVoicingsToEvents` | ✅ wired |
| Rhythm | `rhythm/composables/useAudioEngine.js` | `rhythmPatternToEvents` | written, awaiting rhythm Vue migration |
| Leadsheet | `leadsheet/composables/useAudioEngine.js` | `chordProgressionToEvents` | written, awaiting leadsheet Vue migration |

---

## 8. Transport controls (TabEditor)

The transport lives in `TabEditor.vue` and drives both composables through a shared `TransportBar.vue`.

### Key computed values

```js
// Both composables share Tone.Transport, so they naturally stay in sync.
const transportPlaying = computed(() => isPlaying.value || isChordPlaying.value);

// Show the live beat from whichever composable is active.
// Falls back to the last known position when neither is playing.
const transportBeat = computed(() => {
    if (isPlaying.value)      return currentBeat.value;
    if (isChordPlaying.value) return chordCurrentBeat.value;
    return currentBeat.value || chordCurrentBeat.value;
});
```

`playingMeasureIndex` is **not** `Math.floor(transportBeat / beatsPerMeasure)` — that flat division is wrong the moment a pickup bar exists (§5.2). Instead it's derived from `playPositionBeatTable`, a `{ gi, beatStart, beatEnd }` array built once per play position, honouring each bar's own `pickupBeats`:

```js
const playPositionBeatTable = computed(() => {
    const table = [];
    let cursor = 0;
    for (const gi of expandedSequence.value) {
        const beats = measureByGi.get(gi)?.pickupBeats ?? beatsPerMeasure.value;
        table.push({ gi, beatStart: cursor, beatEnd: cursor + beats });
        cursor += beats;
    }
    return table;
});

const playingMeasureIndex = ref(0);
watch([transportBeat, playPositionBeatTable], ([beat]) => {
    const table = playPositionBeatTable.value;
    const entry = table.find(e => beat >= e.beatStart && beat < e.beatEnd) ?? table.at(-1);
    playingMeasureIndex.value = entry?.gi ?? 0;
});
```

`TabEditor.vue` and `LeadsheetViewer.vue` (the public read-only viewer) each build their own copy of this table rather than sharing one — see §5.2.

### User actions

| Action | Behaviour |
|--------|-----------|
| **Space / Play button** | If playing → pause (keep position). If paused → resume from paused beat. |
| **⏹ / Escape** | Full reset — seek to beat 0 and stop. |
| **Seek slider** | `engine.seek(beat)` + `currentBeat.value = beat`. Immediate. |
| **Click chord card** | `seekToMeasure(gi)` — moves playhead to that measure's beat. Jumps instantly if playing; repositions cursor if stopped. Does not auto-start. |
| **Tempo slider** | `engine.setTempo(bpm)` + `model.tempo = bpm`. Live. |

### Unified cursor in chord view

`is-active` on `ChordCard` is the single visual cursor — there is no competing `is-selected` highlight. `playingMeasureIndex` always reflects the current position whether playing or paused, so the cursor is always visible and always accurate.

### Unified cursor in tab view

While tab is playing, a `watch(transportBeat)` in `TabEditor` calls `beatToMeasureEvent(beat)` → `moveTo(mi, ei, si)` to drive the orange cursor column in real time. The same cursor serves editing (when stopped) and playback tracking (when playing). The user never edits during playback, so this dual use is safe. `beatToMeasureEvent` looks the bar up in `measureBeatTable` (the gi-keyed counterpart of `playPositionBeatTable`, §8 above) rather than a flat `Math.floor(beat / beatsPerMeasure)`, so it resolves correctly across a pickup bar.

Each `TabMeasure`/`ChordMeasure` also computes its own **within-bar** beat position (`beatInThisMeasure`, for the metronome/sub-beat cursor) from an injected `measureBeatStartMap` — a `Map<gi, beatStart>` provided alongside `playingMeasureIndex`/`transportBeat`:

```js
// Provided by TabEditor.vue (admin) and LeadsheetViewer.vue (public viewer) —
// both must provide this, or TabMeasure/ChordMeasure fall back to
// `beat % thisBarsBpm`, which has no memory of a shorter pickup bar earlier
// in the timeline and desyncs every measure after it.
provide('measureBeatStartMap', computed(() => {
    const beat  = transportBeat.value ?? 0;
    const table = playPositionBeatTable.value;
    const map   = new Map();
    for (const e of table) if (!map.has(e.gi)) map.set(e.gi, e.beatStart);
    const active = table.find(e => beat >= e.beatStart && beat < e.beatEnd);
    if (active) map.set(active.gi, active.beatStart);
    return map;
}));
```

`beatInThisMeasure` in `TabMeasure.vue` then does `beat - measureBeatStartMap.get(gi)` instead of a modulo — see §5.2's gotcha for why the modulo fallback silently breaks on pickup bars.

---

## 9. What's deferred

### Rhythm / leadsheet composables
`rhythm/composables/useAudioEngine.js` and `leadsheet/composables/useAudioEngine.js` are written and use `rhythmPatternToEvents` / `chordProgressionToEvents`. Not yet active — waiting for those views to migrate to Vue. The percussion engine is now wired; connecting them is a matter of mounting the composable.

Blend slider formula for sample/synth mix (from WP, do not change):
```js
synthGainNode.gain.value  = (1 - ratio) * 0.8;
sampleGainNode.gain.value = ratio       * 0.8;
```

### Video sync
Shipped — YouTube is the clock when `audioSource === 'video'`. `useVideoSync.js` interpolates `videoTime ↔ playPosition` in pos-space (see §5.1) so AABA repeats work end-to-end. `MediaElementClock` remains a stub; the current implementation drives the score cursor via rAF from `VideoPlayer.timeupdate` rather than a true engine clock swap. If hosted-MP3 support ever needs the engine's tempo/transport machinery (loop, tempo nudges, blend with synth), implement `MediaElementClock` then — `AudioEngine`/`Scheduler` are already written against the `PlaybackClock` interface so no other code needs to change.

Followups (deferred):
- **Shared sequence builder.** ✅ DONE (2026-07-02). The flatten-then-expand pair is now `expandModelSequence(model)` (+ `flattenModelMeasures(model)` → `{ flatMeasures, measureByGi }` for adapters that also need the gi→measure Map) in `expandMeasureSequence.js`. Five sites that inlined `model.sections.flatMap(s => s.measures ?? [])` now call it: `tabMeasureToEvents`, `chordVoicingsToEvents` (via `flattenModelMeasures`), `SheetMiniPlayer.vue`, `TabEditor.vue`, `LeadsheetViewer.vue`. `Cinema.vue` is intentionally *not* on this list — it feeds an already-flat `flatBars` array into `expandMeasureSequenceWithPass`, so it never had the duplicated flatten.
- **`useVideoSync.mappingsByPosition` tests.** ✅ DONE (2026-07-02) — `resources/js/tab-editor/composables/__tests__/useVideoSync.test.js`. Covers AABA per-pass assignment, videoTime-order pairing (insertion-order independent), "more marks than passes" clamp-to-last-position, pos/videoTime tie sorting, gi-absent-from-sequence fallback, empty-sequence fallback, and empty-marks. Mounts the composable with a stub `wrapCommand` + fixed `getSequence`.
- **`VideoSyncMark` typedef.** ✅ DONE (2026-07-02). Exported `@typedef VideoSyncMark` on the producer (`useVideoSync.js`, above `mappingsByGi`, which is now typed `ComputedRef<Map<number, VideoSyncMark[]>>`). Both badge consumers (`ChordMeasure.vue`, `TabMeasure.vue`) annotate their `inject('videoSyncMap')` with `Ref<Map<number, VideoSyncMark[]>>` so they can't silently fall back to the old `{ markerIndex, videoTime }` assumption.

### Remaining adapters
- `chordDiagramToEvents` — wired to nothing yet; intended for chord diagram library playback.
- `chordProgressionToEvents` — wired to nothing yet; intended for leadsheet chord view once that page migrates to Vue.

### Ties, bends, articulations
`tabMeasureToEvents` emits pitch + duration only. Tie, bend, palm-mute, and ghost-note fields exist in the event schema but are not yet produced by any adapter.

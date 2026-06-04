# SBN Synced Player — Reference & Roadmap

A teaching component that locks chord diagrams to a rhythm grid on a shared
clock, so the learner sees *and* hears the relationship between voicing and
feel — the core of the João Gilberto playing style.

---

## 1. Core idea

The insight is simple: a chord voicing shown in isolation is a static object.
A chord voicing shown *while* the rhythm pattern that drives it is playing
becomes a musical event. The learner sees the fingers, hears the accent, and
the two things fuse. That is what this component exists to do.

The **SyncedPlayer** is not a metronome with a chord chart stuck next to it.
It is a single clock that owns both the rhythm strip highlight and the chord
display, so the two are always causally linked.

---

## 2. Current state (as-built, 2026-06-04)

### Files
| File | Role |
|---|---|
| `resources/js/Components/Home/SyncedHero.vue` | Homepage demo — hardcoded Dm7/G7/Cmaj7 drop voicings, Gilberto bossa rhythm, sliding board track |
| `resources/js/Components/Home/useClock.ts` | `setInterval`-backed clock behind a swappable `ClockHandle` interface (`start/stop/onStep/onBar`) |

### What works
- 5 persistent board slots (`off-left · prev · center · next · off-right`)
- Physical slide on bar advance (stable DOM nodes, recycle buffer, no fade-repaint)
- Dot pulse (`strike()`) fires on accent steps (`X` chars in fingers string), targeting `circle.sbn-svg-dot` via CSS `animation`
- Two-row rhythm strip (fingers + thumb) driven by `RhythmPatternData` shape — `X`=accent, `x`=ghost/hit, `.`=rest
- Real `ChordDiagramData` shape — `<ChordDiagram :show-guide-tones>` renders guide-tone colours via `sbnRenderDiagramSVG()`
- `prefers-reduced-motion` respected (static first chord, no timer)
- Clock interface is swappable — Tone.js Transport can replace `setInterval` with zero view changes

### What is hardcoded (test state)
- Chord data is hardcoded `ChordDiagramData[]` (Dm7/G7/Cmaj7 drop voicings). Production path: feed real shapes from `ChordVoicingSearch` or the builder
- Rhythm is a hardcoded `RhythmPatternData` object (Bossa Nova Clave, mirrors DB slug `bossa-nova-clave`)
- No play/stop control exposed to the user
- No tempo control
- Single global pattern — every bar uses the same rhythm

### Open bug
- **Rhythm strip ↔ dot-strike sync**: strip cell highlight (`currentStep`) and the dot pulse (`b.striking`) are driven by the same `onStep` callback and batched into one Vue render cycle via `nextTick`, but visual sync is still imprecise — likely because `setInterval` drift means the two effects don't land in the same paint frame. Resolution: replace `setInterval` with an `AudioEngine`-derived tick (Phase S.2) so the clock is sample-accurate.

---

## 3. Phase plan

### Phase S.1 — Real data, same homepage widget ✅ DONE 2026-06-04
*Goal: wire the hero to real DB shapes. No new UI surface.*

- ✅ `ChordDiagramData[]` shape — `<ChordDiagram :show-guide-tones>` renders via `sbnRenderDiagramSVG()`
- ✅ `RhythmPatternData` shape — `fingers`/`thumb` strings drive the two-row strip + clock accent detection
- ✅ Inline SVG fretboard removed; `sbn-svg-dot` circles targeted for strike animation via `:deep` CSS
- ✅ Hardcoded Dm7/G7/Cmaj7 drop voicings + Gilberto bossa basic for testing
- **Production next step**: `HomeController` passes real progression + pattern props (Phase S.3 shape)
- **Clock stays `setInterval`** — no audio engine yet

### Phase S.2 — Playback + controls
*Goal: the user can start/stop and hear the rhythm while watching the chords.*

- Add play/stop button to `SyncedHero`
- On play: start `useClock` AND start `AudioEngine` (reuse the same engine
  already used by `RhythmStrip`) — the engine drives sample playback while the
  clock drives the visual tick. Tempo is shared.
- Consider replacing `setInterval` with a tick derived from the `AudioEngine`
  `tick` event so visual and audio never drift. This is the Tone.js seam
  already planned in `useClock`.
- Expose `tempo` prop with a BPM slider (range 60–180)
- Reduced-motion: visual only, no audio auto-start

### Phase S.3 — Standalone `SyncedPlayer` component
*Goal: extract from the homepage into a reusable component that can be
embedded in course lessons and leadsheet views.*

- New component: `resources/js/Components/SyncedPlayer/SyncedPlayer.vue`
- Props:
  ```ts
  interface SyncedPlayerProps {
      progression: ChordDiagramData[];   // ordered chord list
      rhythmPattern: RhythmPatternData;  // single pattern (all bars)
      bpm?: number;                      // override pattern default
      autoplay?: boolean;
  }
  ```
- Emits: `bar(index)`, `step(index, type)` — so a parent course player
  or leadsheet can react
- Homepage hero becomes `<SyncedPlayer :progression="..." :rhythmPattern="..." />`
- Course lesson `<sbn-synced-player>` tag registered in `mountSbnNodes.ts`

### Phase S.4 — Leadsheet integration
*Goal: the component reads a leadsheet and advances through its actual chord
sequence, one bar per cycle.*

This is the full vision: the learner opens a leadsheet, hits play, and the
synced player walks the chart bar by bar, showing the voicing for each chord
while the rhythm pattern plays underneath.

#### Data model changes needed

The tab model already has `rhythmSlug: null` per measure
(`useTabModel.js:1440`). This needs to be:

1. **Populated in the editor** — the existing rhythm assignment panel
   (`sbn-rhythm-applied` custom event) should write `rhythmSlug` to the
   measure. The tab editor already fires this event; it just needs to persist
   to `json_data`.

2. **Serialised through the API** — `SongLibraryController` / `LeadsheetController`
   should include `rhythmSlug` per measure in the JSON sent to the frontend.

3. **Resolved at runtime** — the SyncedPlayer receives the flat measure list
   (`{ chordSlug, rhythmSlug }[]`) and either:
   - Uses a single global `rhythmPattern` prop when all bars share the same
     pattern (the common case for bossa)
   - Swaps patterns at bar boundaries when `rhythmSlug` changes (Phase S.4b)

#### Proposed leadsheet prop shape
```ts
interface LeadsheetBar {
    chordSlug: string;           // resolved to ChordDiagramData at load time
    rhythmSlug: string | null;   // null → inherit previous bar's pattern
    durationBars: number;        // how many clock bars this chord lasts
}

interface SyncedPlayerProps {
    bars: LeadsheetBar[];
    defaultRhythm: RhythmPatternData;
    bpm?: number;
    autoplay?: boolean;
    loop?: boolean;
}
```

#### Clock behaviour with a leadsheet
- A "bar" in the clock advances `durationBars` times before the chord changes
- `onBar` callback increments a `barPosition` counter; when it reaches
  `bars[currentBar].durationBars`, advance to the next bar
- Pattern swap on bar change: stop current audio loop, load new pattern events,
  resume — the visual grid re-renders reactively

### Phase S.5 — Cinema / full-screen practice mode
*Out of scope for now. Flag for future.*

A full-screen mode where the synced player fills the viewport: large chord
diagram center, rhythm strip across the bottom, scroll-through chord list on
the side. Useful for practice sessions away from the leadsheet editor.

---

## 4. Clock architecture

```
useClock({ bpm, pattern, onStep, onBar })
  │
  ├── Phase S.1–S.2: setInterval (± AudioEngine tick event)
  └── Phase S.3+:    Tone.js Transport (same interface, swap the factory)
```

The `ClockHandle` interface (`start/stop`) is already defined. The only
constraint: `onStep(stepIndex, stepType)` and `onBar(barIndex)` must fire on
the audio thread's schedule, not `setInterval` drift. The `AudioEngine`
already emits `tick` events with beat position — those are the right source
once audio is live.

**Do not hard-wire `setInterval` deeper into the view layer.** The seam is
already clean; keep it that way.

---

## 5. Fretboard rendering decision

`SyncedHero` currently has its own inline SVG fretboard (160×200, 5 frets).
Before Phase S.3, decide which renderer to standardise on:

| Option | Pros | Cons |
|---|---|---|
| **A: `sbnRenderDiagramSVG()`** (global JS) | Zero Vue overhead, fast for 5 boards | Returns HTML string, no individual dot access for `strike()` |
| **B: `AnimatedChordDiagram.vue`** | Animatable dots, guide-tone colours, already used in library | Heavier (full Vue instance per board), designed for one-chord animate-between |
| **C: Keep inline SVG** (current) | Lightest, full dot control for `strike()` | Duplicates geometry, no barre support, no start-fret label |

**Recommendation: Option A for rendering + a thin CSS animation class for
`strike()`** — call `sbnRenderDiagramSVG()` to get the SVG string, inject it
into each board slot, then re-trigger a CSS keyframe on the dot elements via a
class toggle. This avoids the Vue overhead of 5× `AnimatedChordDiagram` while
giving access to the rendered dots. Requires that `sbnRenderDiagramSVG` adds
a stable class to its dot circles (it already does — check `chords.js`).

---

## 6. Open questions before Phase S.1

1. **Which progression for the homepage?** A real saved progression from the
   DB (e.g. "Garota de Ipanema changes") or a curated hard-coded set that
   showcases guide-tone colours well? The controller can pass either.

2. **Bar duration on the homepage**: currently 1 bar = 16 steps = 1 chord.
   For real songs, one chord often lasts 2 or 4 bars. The clock's `onBar`
   fires every 16 steps — the component needs a `barsPerChord` concept even
   for the simple case.

3. **`strike()` with `sbnRenderDiagramSVG`**: confirm that the rendered SVG
   dot elements are addressable by a stable selector so the class-toggle pulse
   can target them without re-rendering the whole board.

---

## 7. Relationship to other systems

| System | Relationship |
|---|---|
| `RhythmStrip.vue` | Shares `RhythmPatternData` type; SyncedPlayer's mini grid is a read-only view of the same data |
| `AudioEngine` | SyncedPlayer will delegate audio to the same engine instance — no second engine |
| `useVideoSync` | Orthogonal — video sync drives the leadsheet viewer clock; SyncedPlayer drives its own internal clock. They should not share state. |
| `mountSbnNodes.ts` | Phase S.3: register `<sbn-synced-player>` tag here for course lesson embedding |
| Tab editor `rhythmSlug` | Phase S.4: the editor's rhythm-assignment panel writes this field; SyncedPlayer reads it |

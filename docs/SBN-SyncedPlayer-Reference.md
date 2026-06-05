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

## 2. Current state (as-built, 2026-06-05)

### Files
| File | Role |
|---|---|
| `resources/js/Components/Home/SyncedHero.vue` | Homepage widget — DB-driven chord sequence + rhythm, sliding board track, up-next cue |
| `resources/js/Components/Home/useClock.ts` | `setInterval`-backed clock behind a swappable `ClockHandle` interface (`start/stop/onStep/onBar`) |
| `app/Http/Controllers/HomeController.php` | Feeds `progression` (first 8 chords of Desafinado via `LeadsheetViewerService`) + `rhythmPattern` (bossa-nova from DB) |

### What works
- 5 persistent board slots (`off-left · prev · center · next · off-right`)
- Physical slide fires on **step 15** (last 16th of bar) so the chord lands centered on the downbeat
- **Up-next affordance**: `next` role is visually distinct (accent label "up next", opacity 0.8, low grayscale); after recycle the incoming board is promoted to `role='next'` immediately via double-rAF so it's visible the full bar; `strikeNext()` fires a soft pre-pulse at step 12
- **Thumb/fingers dot split** (Gilberto technique): `strikeCenter(row)` targets dots by `data-string` — bass = `Math.min` of played strings (string 1 = low E), fingers = everything above
- Two-row rhythm strip (fingers + thumb) driven by `RhythmPatternData`
- `ChordDiagram :show-guide-tones` renders guide-tone colours via `sbnRenderDiagramSVG()`
- Card frame: `.sbn-synced-hero-card` in `sbn-design-system.css` (theme-switchable). Chord names via `formatChordNameHtml()`.
- All board transitions compositor-only (`transform:scale`, `opacity`, `filter`) — no `width`/`font-size` layout work; all boards fixed 160px, `dx` hardcoded
- `prefers-reduced-motion` respected (static first chord, no timer)
- Clock interface is swappable — Tone.js Transport replaces `setInterval` with zero view changes
- Props: `progression?: ChordDiagramData[]`, `rhythmPattern?: RhythmPatternData` — hardcoded Dm7/G7/Cmaj7 fallback if null

### What is still missing
- No play/stop control — auto-plays on mount, no user control
- No tempo control
- `barsPerChord` not implemented — Desafinado chords last ~2 bars but hero advances every bar (too fast)
- Single global rhythm pattern — per-bar swap is Phase S.4b
- `setInterval` drifts over long runs — replace with AudioEngine tick in S.2

### Sync fixes (2026-06-04)
Three defects that broke the visible chord↔rhythm link were fixed:

1. **Bar boundary fired one step early.** `useClock.tick()` called `onBar`
   *after* `step` wrapped past 15, so the chord advanced on the last 16th of
   the bar — visibly leading the downbeat by one cell. Now `onBar` fires on
   step 0 *before* its `onStep`, so the chord change and the strip's "1" land
   in the same paint frame. `onBar(barIndex)` now passes the bar index;
   `SyncedHero` ignores index 0 (initial chord already centered).

2. **Strike never fired on the downbeat and died during the slide.** The old
   condition `type !== 'rest' && step !== 0 && !sliding` suppressed the
   strongest accent (beat 1) and ~5 steps of pulses per bar during the 1120 ms
   slide. Now any non-rest step pulses the center board, including the downbeat
   and mid-slide.

3. **Strike re-trigger was unreliable.** The `striking` boolean + `nextTick`
   toggle couldn't restart the CSS keyframe when the value didn't change (and
   the SVG is re-rendered by `ChordDiagram`'s `watchEffect`). Replaced with
   `strikeCenter()`, which imperatively removes `.is-striking`, forces a reflow,
   and re-adds it — the canonical CSS animation restart — always targeting the
   physical center slot (`boardEls[CENTER_IDX]`). Lingering classes are cleared
   on `transitionend`.

### Animation-smoothing fixes (2026-06-04, session 2)
The slide/recycle had three more defects, all fixed:

4. **Strike targeted the wrong board mid-slide.** `strikeCenter` used the fixed
   physical slot `boardEls[CENTER_IDX]`, which still shows the OUTGOING chord
   during the slide — so the rhythm pulsed the old dots for ~5 steps, then
   "started mid-way" on the new chord. Now it targets the board whose **role**
   is `center` (`boards.findIndex(b => b.role === 'center')`); the incoming
   chord already holds that role from the downbeat, so the strike hits the new
   chord's dots immediately.

5. **String convention was inverted.** Comments claimed `1 = high e`; the DB
   truth (`ChordShapeCalculator::TUNING`) is `1 = low E … 6 = high E`. The
   thumb/fingers split used `Math.max` (→ pulsed the high E as "bass"). Fixed to
   `Math.min`. **Remember: string 1 = low E everywhere in this codebase.**

6. **Double "pull-in" animation.** Two causes: (a) `transitionend` bubbles, so
   child board transitions re-triggered the recycle — guarded with
   `e.target === trackEl && e.propertyName === 'transform'`; (b) the recycle
   reset roles/widths *with transitions live*, re-animating the just-centered
   board — fixed with a one-frame `recycling` flag that sets `transition: none`
   on all boards during the array shift, re-enabled via double-`rAF`.

7. **End-of-slide hiccup.** The track translate and the board width/opacity
   transitions ran on *different* easing curves (custom bezier vs default
   `ease`), so they finished at different instants while `dx` was measured
   pre-slide — a sub-pixel correction at the end. Fixed with ONE shared
   `SLIDE_EASE` (`cubic-bezier(.4,0,.2,1)`) + `SLIDE_MS` (420ms) applied to the
   track AND every board transition.

### Remaining imprecision (deferred to S.2)
- `setInterval` still drifts, so over long runs the visual tick can wander a
  few ms from where audio would be. Resolution unchanged: replace `setInterval`
  with an `AudioEngine`-derived tick (Phase S.2) so the clock is sample-accurate.

---

## 2b. ⭐ NEXT SESSION — START HERE (2026-06-05)

Tasks 1–3 from the previous session are ✅ DONE. See §3 phase plan for what's
next.

### Done this session (2026-06-05)

**Task 1 — "up next" affordance ✅**
- `next` board role split from `side` — brighter label ("up next", accent colour),
  higher opacity name (0.8 vs 0.55), less greyscale diagram (0.2 vs 0.45)
- Slide fires on **step 15** (last 16th of bar) so the chord is already centered
  on the downbeat. `onBar` no longer triggers advance.
- After recycle, index 4 immediately promoted to `role='next'` with correct chord
  (double-rAF after `recycling` clears), so "up next" is visible the full bar.
- `strikeNext()` fires a soft `hero-next-cue` pulse on the next chord's root dot
  at step 12 (~1 beat before the slide).

**Task 2 — SBN design system alignment ✅**
- Card frame moved to `.sbn-synced-hero-card` in `sbn-design-system.css` so
  `[data-theme="vintage"]` can reach it. Uses `--clr-white`, `--clr-border`,
  `--radius-lg`.
- Chord names rendered via `formatChordNameHtml()` → proper `sbn-chord-symbol`
  markup (Crimson Text, superscript extensions). Center name overridden to
  `--clr-text` for legibility at large size.
- Sub-label (category · quality) removed.
- Layout transitions moved from `width`/`font-size` (layout) to
  `transform: scale` (compositor-only) — eliminates slide stutter.
- All boards fixed 160px wide; `dx` hardcoded to 160.

**Task 3 — DB connection ✅**
- `HomeController` injects `LeadsheetViewerService` + `ChordVoicingSearch`.
- Featured song: **Desafinado** (id 113). Walks flat `measures` array, extracts
  first 8 unique consecutive chord names, maps each to its `ChordDiagramData`
  card via `chordCards` lookup (exact key or `name@position` prefix).
- `rhythmPattern` was already DB-driven; unchanged.
- `SyncedHero.vue` accepts `progression?: ChordDiagramData[]` and
  `rhythmPattern?: RhythmPatternData` props. `CHORDS`/`RHYTHM`/`BPM` are now
  computed refs (`.value` throughout) with hardcoded fallbacks.
- `Home.vue` passes both props from the controller.

### Open / next up

- **S.2** — play/stop button + `AudioEngine` integration so the rhythm is audible
- **`barsPerChord`** — Desafinado chords last 2 bars each; the hero currently
  advances every bar (too fast). Add a `durationBars` counter per chord or a
  simple global `barsPerChord` prop.
- **Featured song config** — move the hardcoded id 113 to a config value or a
  `featured_on_homepage` flag on `Leadsheet`.
- **RhythmPattern mini variant** — Task 2 deferred replacing the bespoke strip
  with `RhythmPattern.vue :mini` because it has no external `currentStep` prop.
  Either add the prop to `RhythmPattern` or leave the bespoke strip.

---

## 3. Phase plan

### Phase S.1 — Real data, same homepage widget ✅ DONE 2026-06-05
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

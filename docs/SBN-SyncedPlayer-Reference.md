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

## 2. Current state (as-built, 2026-06-06)

### Files
| File | Role |
|---|---|
| `resources/js/Components/SyncedPlayer/SyncedPlayer.vue` | **Canonical reusable component** — all logic, props, audio engine, board track, rhythm strip |
| `resources/js/Components/Home/SyncedHero.vue` | Thin wrapper — adds `.sbn-synced-hero-card` frame + "Live · play-along" badge |
| `resources/js/Components/Home/useClock.ts` | Retained but unused (kept for future consumers) |
| `app/Http/Controllers/HomeController.php` | Feeds `progression`, `rhythmPattern`, `barsPerChord` (= 2 for Desafinado) |
| `app/Http/Controllers/Top10Controller.php` | `bossaNovaChords()` now also passes a shared `rhythmPattern` |
| `resources/js/Pages/Top10/BossaNovaChords.vue` | Uses `SyncedPlayer` in the progression panel (replaces `ChordProgressionViewer`) |

### What works
- 5 persistent board slots (`off-left · prev · center · next · off-right`)
- Physical slide fires on **step `beats-1`** (last 16th of bar) so the chord lands centered on the downbeat
- **`barsPerChord`** prop — chord advances every N bars; controller sends 2 for Desafinado (each chord lasts 2 bars). A `barPosition` counter increments each bar and resets after `barsPerChord` bars
- **Up-next affordance**: `next` role is visually distinct (accent label "up next", opacity 0.8, low grayscale); after recycle the incoming board is promoted to `role='next'` immediately via double-rAF so it's visible the full bar; `strikeNext()` fires a soft pre-pulse at `step beats-4` (~1 beat before the slide)
- **Thumb/fingers dot split** (Gilberto technique): `strikeCenter(row)` targets dots by `data-string` — bass = `Math.min` of played strings (string 1 = low E), fingers = everything above
- Two-row rhythm strip (fingers + thumb) driven by `RhythmPatternData`
- **Play/stop button** — round accent button in the rhythm strip header; auto-plays on mount (unless `prefers-reduced-motion`)
- **AudioEngine integration** — `getAudioEngine()` singleton drives both audio playback (`rhythmPatternToEvents` → `engine.load/play`) and the visual tick (`engine.on('tick', ...)` → `beatToStep()`). `setInterval` is gone; the engine tick is the sole clock source
- `ChordDiagram :show-guide-tones` renders guide-tone colours via `sbnRenderDiagramSVG()`
- Card frame: `.sbn-synced-hero-card` in `sbn-design-system.css` (theme-switchable). Chord names via `formatChordNameHtml()`.
- All board transitions compositor-only (`transform:scale`, `opacity`, `filter`) — no `width`/`font-size` layout work; all boards fixed 160px, `dx` hardcoded
- `prefers-reduced-motion` respected (no auto-play, no animation)
- Props: `progression?: ChordDiagramData[]`, `rhythmPattern?: RhythmPatternData`, `barsPerChord?: number`, `color?: string | null`, `muted?: boolean`, `autoplay?: boolean`, `loop?: boolean` — hardcoded Dm7/G7/Cmaj7 fallback if null
- **`color` prop** — pass `getCategoryColor(styleSlug)` to tint the rhythm strip cells to the category color via `--strip-color`. Falls back to `--clr-accent` when omitted.

### Multi-chord bars and multi-bar rhythm phrases (2026-06-07)

Both 2-chord-per-bar measures and multi-bar rhythm cycles are now handled correctly.

**2-chord measures** (e.g. `F/G, Fm/G` in Corcovado bar 15):
- Controller splits the measure into two entries, each with `stepsPerChord = stepsPerBar / 2`.
- Frontend advances on beat 3 (half-bar boundary).

**Multi-bar rhythm cycles** (e.g. `jazz-bossa-nova`: 16 eighth-note steps = 8 beats = 2 bars):
- Controller computes `stepsPerBar = gridSteps / barsPerCycle` where `barsPerCycle = round(patternBeats / 4)`.
- For jazz-bossa-nova: `patternBeats = 16 × 0.5 = 8`, `barsPerCycle = 2`, `stepsPerBar = 8`.
- Two consecutive single-chord bars (e.g. Blue Bossa's `Cm7(9), Cm7(9)`) each get `stepsPerChord = 8` → total 16 steps = one full 2-bar rhythm cycle. ✓
- Sub-bar patterns (< 4 beats, e.g. gilberto 8-step sixteenth = 2 beats) clamp to `barsPerCycle = 1` via `round(0.5) = 1` in PHP, keeping `stepsPerBar = gridSteps` as before.

**Chord/BARS index alignment fix:**
- `CHORDS` was previously filtering null `chordCard` entries, making it shorter than `BARS` and causing `head` (a BARS index) to address the wrong diagram. `CHORDS` is now kept parallel to `BARS`, substituting a fallback shape for nulls.

**Double-hit fix for eighth/triplet grids:**
- `ToneClock` always fires at sixteenth resolution (`'16n'`). For `gridType='eighth'`, two consecutive ticks land on the same step, causing every pattern hit to trigger the chord strum twice. A `lastFiredStep` guard in the tick handler skips duplicate steps — each grid step fires exactly once regardless of clock resolution.

`durationBars` is kept in the API response for back-compat but is no longer used by the player.

### What is still missing
- No tempo control (BPM slider deferred to S.4)
- Single global rhythm pattern — per-bar swap is Phase S.4b
- Featured song hardcoded as Leadsheet id 113 in `HomeController` — move to config or `featured_on_homepage` flag

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

## 2b. ⭐ NEXT SESSION — START HERE (2026-06-07, session 2)

### Done this session (2026-06-07, session 2)

**Rhythm sync bug fixes ✅**

1. **Double chord hit on eighth-grid patterns** — `ToneClock` fires at `'16n'`; for `gridType='eighth'` two ticks land on the same step. Fixed with `lastFiredStep` guard in the tick handler.
2. **2-bar rhythm phrase mis-timing** — `jazz-bossa-nova` (16 eighth steps = 2 bars) was advancing chords every full pattern cycle instead of every bar. Controller now computes `stepsPerBar = gridSteps / barsPerCycle` using `gridType` to derive how many 4/4 bars one cycle spans.
3. **CHORDS/BARS index mismatch** — null `chordCard` entries were filtered from `CHORDS`, making it shorter than `BARS` so `head` indexed the wrong diagram. `CHORDS` is now parallel to `BARS`.

---

### Done previous session (2026-06-07)

**Phase S.4 — Leadsheet / Exercise integration ✅**

#### Backend
- `app/Http/Controllers/Library/SyncedPlayerController.php` — new controller.
  `GET /api/sbn/synced-player/{slug}?type=leadsheet|exercise&start=0&end=7`
  Returns `{ title, totalBars, bars[], rhythmPattern, bpm }`.
  - Flattens `sections → measures` into a flat `bars[]` list; multi-chord measures are
    split into one entry per chord (each `durationBars: 1`).
  - Resolves each chord name → `ChordDiagramData` via `ChordVoicingSearch` + per-slot
    voicing key (`"ChordName@gi.ci"`), same logic as `LeadsheetViewerService`.
  - Fetches `RhythmPattern` by the source's `rhythm` slug and serialises it to
    `RhythmPatternData` shape (same fields as `serializePattern` in `RhythmLibraryController`).
  - `start`/`end` query params slice the flat bar list (0-based, inclusive `end`).
  - Route registered in `routes/web.php` under `api/sbn` group.

#### Frontend — `SyncedPlayer.vue`
- New prop `bars?: LeadsheetBar[]` — takes priority over `progression`.
  `LeadsheetBar` type exported from the component: `{ chordName, chordCard, durationBars }`.
- New prop `loop?: boolean` (default `true`) — when false, player stops at the last bar.
- `BARS` computed: when supplied, `CHORDS` is derived from `bars[].chordCard`.
- `currentBarIdx` ref tracks position in `BARS` independently of `head`.
- `advanceBar()` replaces the inline `advance()` call in `tickBar()`:
  - In `bars` mode: increments `currentBarIdx`, wraps/stops per `loop` prop, calls
    `advance(targetHead)` with the absolute index.
  - In `progression` mode: delegates to `advance()` as before (no change).
- `currentDurationBars()` returns `BARS.value[currentBarIdx].durationBars` in bars-mode,
  or `BARS_PER_CHORD.value` in progression-mode.
- `advance(targetHead?)` — accepts explicit head index for bars-mode; falls back to
  `(head + 1) % CHORDS.length` for progression mode.
- `currentBarIdx` reset to 0 in `audioPlay()` and `audioStop()`.
- Off-right lookahead in `onTransitionEnd` uses `currentBarIdx + 2` in bars-mode.

#### `mountSbnNodes.ts`
- `<sbn-synced-player>` tag registered.
  Attrs: `slug` (required), `type` (leadsheet|exercise, default leadsheet),
  `start`, `end`, `autoplay` (false disables auto-play).
  Fetches `/api/sbn/synced-player/{slug}?type=…&start=…&end=…`, then mounts
  `SyncedPlayer` with `bars`, `rhythmPattern`, `autoplay`, `loop=true`.

### Usage in course lesson markdown

```html
<!-- Whole leadsheet -->
<sbn-synced-player slug="desafinado" type="leadsheet"></sbn-synced-player>

<!-- Bars 0–7 only (first 8 bars) -->
<sbn-synced-player slug="desafinado" start="0" end="7"></sbn-synced-player>

<!-- Exercise, no auto-play -->
<sbn-synced-player slug="bossa-comping-ex1" type="exercise" autoplay="false"></sbn-synced-player>
```

### Open / next up

- **Featured song config** — move hardcoded id 113 in `HomeController` to config or `featured_on_homepage` flag
- **Tempo control** — BPM slider (range 60–180); call `engine.setTempo()` reactively
- **Leadsheet viewer embed** — drop a `<SyncedPlayer :bars="leadsheetBars" …>` directly inside the viewer as an alternative to the chord grid; bars already available from the same API
- **Per-bar rhythm swap** (S.4b) — when `rhythmSlug` differs per bar, stop/reload audio loop on bar change
- **Blue Bossa slug** — currently `untitled-8` in DB; rename to `blue-bossa` so the bossa-nova-songs config can add a `syncedPlayer` entry for it

---

## 3. Phase plan

### Phase S.1 — Real data, same homepage widget ✅ DONE 2026-06-05
### Phase S.2 — Playback + controls ✅ DONE 2026-06-06
### Phase S.3 — Standalone SyncedPlayer component ✅ DONE 2026-06-06
### Phase S.4 — Leadsheet / Exercise integration ✅ DONE 2026-06-07
*Goal: wire the hero to real DB shapes. No new UI surface.*

- ✅ `ChordDiagramData[]` shape — `<ChordDiagram :show-guide-tones>` renders via `sbnRenderDiagramSVG()`
- ✅ `RhythmPatternData` shape — `fingers`/`thumb` strings drive the two-row strip + clock accent detection
- ✅ Inline SVG fretboard removed; `sbn-svg-dot` circles targeted for strike animation via `:deep` CSS
- ✅ Hardcoded Dm7/G7/Cmaj7 drop voicings + Gilberto bossa basic for testing
- **Production next step**: `HomeController` passes real progression + pattern props (Phase S.3 shape)
- **Clock stays `setInterval`** — no audio engine yet

### Phase S.2 — Playback + controls ✅ DONE 2026-06-06
*Goal: the user can start/stop and hear the rhythm while watching the chords.*

- ✅ Play/stop button added (round accent circle, SVG icons)
- ✅ `AudioEngine` singleton drives both audio and visual tick — `setInterval` removed
- ✅ `engine.on('tick')` → `beatToStep()` → strike/advance logic (zero drift)
- ✅ `prefers-reduced-motion`: no auto-play, no animation
- Deferred: BPM slider (S.3)

### Phase S.3 — Standalone `SyncedPlayer` component ✅ DONE 2026-06-06
- `resources/js/Components/SyncedPlayer/SyncedPlayer.vue` — all logic here
- Props: `progression?`, `rhythmPattern?`, `barsPerChord?`, `autoplay?`
- Emits: `bar(barIndex)`, `step(stepIndex)`
- Exposes: `play()`, `stop()`, `toggle()`, `isPlaying`
- `SyncedHero.vue` is now a thin wrapper (card frame + badge)
- First Top10 consumer: `BossaNovaChords.vue` progression panel
- Course lesson `<sbn-synced-player>` tag in `mountSbnNodes.ts` — deferred to S.4

### Phase S.4 — Leadsheet integration ✅ DONE 2026-06-07
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
AudioEngine (Tone.js Transport under the hood)
  │
  └── engine.on('tick', beat) → beatToStep(beat) → onStep logic in SyncedHero
```

`useClock.ts` (setInterval) is retained in the repo but no longer wired into
SyncedHero as of Phase S.2. The engine tick is the sole clock source — visual
and audio are causally the same event, so there is no drift by construction.

For Phase S.3 the tick handler moves into `SyncedPlayer.vue` with the same
interface; no architecture change needed.

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

## 7. Picking Mode Integration ✅ SHIPPED 2026-06-10

When `rhythmPattern.pickingMode = true`, the player switches to a live-tick audio model:

### How it works

1. **Engine event list is empty** — `engine.load([], ...)` is called so the clock loops at the correct tempo but emits no pre-baked audio events.
2. **Per-tick note firing** — The tick handler reads the active step from `fingerIndex/Middle/Ring` and `thumb` pattern strings and calls `synthPickFinger(finger, time)` for each hit.
3. **`synthPickFinger`** maps each finger to the center chord's fretted pitches via `centerMidi`:
   - `p` → `centerMidi.bass[0]` (lowest played string)
   - `i` → `centerMidi.fingers[0]` (lowest non-bass string)
   - `m` → `centerMidi.fingers[1]`
   - `a` → `centerMidi.fingers[last]` (highest string)
4. **Dot animation** — `strikeCenterString(stringNum)` targets the individual SVG dot for the plucked string. Each finger fires its own string's dot, so the diagram pulses in arpeggio order.
5. **`centerMidi`** is rebuilt on every chord change (via `buildCenterMidi()` in `advanceBar()`). It now also stores `bassString` and `fingerStrings[]` (string numbers parallel to `fingers[]`) so `strikeCenterString` knows which dot to animate.

### Strip display

The rhythm strip stays the standard two-liner. `fingerCellClass(i)` OR-merges `fingerIndex/Middle/Ring` at each step — any finger hit lights the fingers row. Thumb row shows `thumb` hits as usual.

### Controller requirement

All PHP controllers that hand-build a `rhythmPattern` array **must** include:
```php
'pickingMode'   => (bool) $pattern->picking_mode,
'fingerIndex'   => $pattern->finger_index,
'fingerMiddle'  => $pattern->finger_middle,
'fingerRing'    => $pattern->finger_ring,
```
Missing these fields silently disables picking audio. Covered: `RhythmLibraryController::serializePattern()`, `HomeController` (both serializers), `SyncedPlayerController`.

---

## 8. Relationship to other systems

| System | Relationship |
|---|---|
| `RhythmStrip.vue` | Shares `RhythmPatternData` type; SyncedPlayer's mini grid is a read-only view of the same data |
| `AudioEngine` | Shared singleton — same engine for both rhythm events and nylon sampler |
| `useVideoSync` | Orthogonal — video sync drives the leadsheet viewer clock; SyncedPlayer drives its own internal clock. They should not share state. |
| `mountSbnNodes.ts` | Phase S.3: register `<sbn-synced-player>` tag here for course lesson embedding |
| Tab editor `rhythmSlug` | Phase S.4: the editor's rhythm-assignment panel writes this field; SyncedPlayer reads it |

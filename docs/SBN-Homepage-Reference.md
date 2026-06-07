# SBN Homepage — Reference

The public homepage at `GET /` is the marketing and entry point for the app.
It is a pure Inertia page (no auth required) with three Vue components and a
static CSS layer for everything else.

---

## 1. Files

| File | Role |
|---|---|
| `app/Http/Controllers/HomeController.php` | Feeds all page data; no auth guard |
| `resources/js/Pages/Home.vue` | Inertia page — section composition |
| `resources/js/Components/Home/SyncedHero.vue` | Chord+rhythm synced demo (hero right column) |
| `resources/js/Components/Home/ChordRain.vue` | Chord card rain section |
| `resources/js/Components/Home/useClock.ts` | Retained, unused (future consumers) |
| `public/css/home.css` | All homepage CSS — scoped to `.home-page` |
| `resources/views/app.blade.php` | Loads `home.css` globally via `<link>` |

---

## 2. Page sections (top → bottom)

### 2.1 Hero (`home-hero`)

Two-column grid: copy left, `SyncedHero` right. Animated blob background
(pure CSS, three `.blob` divs with `@keyframes blob-float`). Staggered
`.reveal .d1–.d5` entrance animation on text.

**Controller data:**
- `progression` — `ChordDiagramData[]` built from Desafinado (leadsheet 113),
  first 8 distinct chords via `buildHeroProgression()`
- `barsPerChord` — hardcoded `2`
- `rhythmPattern` — first bossa-nova `RhythmPattern` ordered by `default_bpm`

**SyncedHero** is documented in full in `docs/SBN-SyncedPlayer-Reference.md`.

---

### 2.2 Rhythm strip (`home-section`)

Static section with `<RhythmStrip :pattern="rhythmPattern" :playable="true" />`.
Decorative demo — shows the bossa pattern with a live playhead. No separate
clock; RhythmStrip owns its own audio engine integration.

---

### 2.3 Chord rain (`chord-rain-section`)

Full-viewport section (`height: 100vh`) where chord diagram cards fall in
columns. See §4 below for the full spec.

---

### 2.4 Feature cards (`home-section`)

Three-column grid of tool cards (pure Blade/CSS). Links to tab editor, chord
library, and a placeholder analysis panel.

---

## 3. CSS — `public/css/home.css`

Scoped to `.home-page` — nothing bleeds into admin or other public pages.

| Selector | Purpose |
|---|---|
| `.home-page::before` | Grain overlay (SVG feTurbulence, `opacity:.035`, `mix-blend-mode:overlay`) |
| `.home-wrap` | `max-width:1200px` centred container with `24px` side padding |
| `.reveal .d1–.d5` | Staggered `@keyframes home-rise` entrance |
| `.hero-bg .blob` | CSS-only blob float animation |
| `.rhythm-card` | Card frame for the rhythm strip section |
| `.chord-rain-section` | Full-viewport rain container — see §4 |
| `.feature-cards` | Three-column feature grid |
| `@media (max-width:900px)` | Hero stacks, cards stack, nav collapses |
| `@media (max-width:600px)` | Stats wrap, CTA stacks, footer narrows |
| `@media prefers-reduced-motion` | Disables reveal, blob, rain loop |

---

## 4. ChordRain component

### 4.1 Overview

`resources/js/Components/Home/ChordRain.vue` — a Vue 3 SFC that owns a
`requestAnimationFrame` loop. No CSS animations on cards; `transform` is
written directly each frame so depth + magnetic can be combined in one write.

**Props:**
```ts
chords: ChordShape[]
// ChordShape = { name, frets, position, fingers?, intervalLabels? }
```

The `name` field is a chord symbol string (`"Cm7"`, `"G7"`, `"Fmaj7"`) that
`sbnFormatChordHtml()` parses into `.sbn-chord-symbol` spans.

The `frets` field is a hex fret string (`"x32010"`) compatible with
`sbnRenderDiagramSVG()`.

### 4.2 Renderer call signature

```js
sbnRenderDiagramSVG(
  { frets, fret_string: frets, position, start_fret: position, fingers },
  { showFingers: false, intervalLabels }
)
```

`sbnRenderDiagramSVG` lives in `public/js/chords.js`. It expects a voicing
object with `frets` (hex string) and `position` (integer start fret), **not**
raw arrays.

### 4.3 Column layout

Columns are constrained to the `home-wrap` inner width (`min(sectionW, 1200px) - 48px`),
centred within the full-viewport section via `offsetX`. This keeps the rain
visually aligned with the rest of the page content.

```
WRAP_MAX = 1200, WRAP_PAD = 24
innerW   = min(sectionW, WRAP_MAX) - WRAP_PAD * 2
offsetX  = max(0, (sectionW - innerW) / 2)
COL_W    = 80 + 12  (medium card + gap)
numCols  = floor(innerW / COL_W)
```

### 4.4 Depth tiers

| Tier | Width | Opacity | Speed (px/s) |
|------|-------|---------|-------------|
| 0 — Distant | 58px | ~0.22 | ~22 |
| 1 — Mid | 80px | ~0.46 | ~16 |
| 2 — Prominent | 108px | ~0.78 | ~10 |

Speed is constant within a column (prevents pile-ups). ±2px/s jitter per
column. ±0.05 opacity jitter per card. Tier cycles `col % 3` with a 15%
random swap to avoid mechanical regularity.

### 4.5 Rain loop

```
dt = min((ts - lastTs) / 1000, 0.05)   // cap at 50ms
state.y += speed * dt

if state.y > sectionH + 20:
    state.y = -(cardH + 20)
    swap chord → next in library, rebuild SVG + label

depthOpacity = baseOpacity × min(smoothstep(fadeIn), smoothstep(fadeOut))
// fade zone = top and bottom 12% of section height
```

### 4.6 Magnetic cursor field

```
MAGNET_RADIUS = 160px, MAGNET_SCALE = 1.38, MAGNET_LIFT = 18px

dist      = hypot(cardCenterX - mouseX, cardCenterY - mouseY)
proximity = max(0, 1 - dist / MAGNET_RADIUS)
ease      = proximity²   // quadratic falloff
scale     = 1 + ease × (MAGNET_SCALE - 1)
```

Box shadow grows with proximity. `z-index:10` when `ease > 0.05`.
On `mouseleave` → `mouseX = mouseY = -9999` so all cards return to rest.

### 4.7 Resize + reduced motion

- `window.resize` → debounce 200ms → `scatter()` tears down and rebuilds.
- Cancel in-flight rAF before rebuild to prevent double loops.
- `prefers-reduced-motion` → cards rendered at initial positions, rAF loop
  not started (section still looks populated, just frozen).

---

## 5. Controller — `HomeController`

### 5.1 Data methods

| Method | Returns |
|---|---|
| `buildHeroProgression()` | Up to 8 `ChordDiagramData` cards from Desafinado (leadsheet 113) |
| `buildRainChords()` | Up to 25 `ChordShape[]` from `sbn_chord_diagrams`, ordered by `popularity` |
| `chordDisplayName()` | Maps `(root_note, quality, extensions)` → chord symbol e.g. `"Cm7"` |
| `diagramDataToFretString()` | Converts `diagram_data` JSON → hex fret string e.g. `"x35453"` |

### 5.2 `chordDisplayName()` quality map

| DB quality | Symbol |
|---|---|
| `maj` | *(empty)* |
| `min` | `m` |
| `dom7` | `7` |
| `maj7` | `maj7` |
| `m7` | `m7` |
| `m7b5` | `m7b5` |
| `o7` | `°7` |
| `maj6` | `maj6` |
| `aug` | `aug` |
| `dim` | `dim` |

Extensions (from the `extensions` column) are appended directly, e.g.
`dom7` + `b9` → `"G7b9"`.

### 5.3 `diagramDataToFretString()`

Converts `diagram_data.open[]`, `diagram_data.positions[]`, and
`diagram_data.muted[]` into a 6-char hex string. Fret values above 9 are
encoded as hex digits (`a`=10, `b`=11 …) — `sbnParseFretString` in
`chords.js` uses `parseInt(c, 16)` so this is safe.

---

## 6. Vignette

CSS `::after` pseudo on `.chord-rain-section`:

```css
background: radial-gradient(
  ellipse 70% 60% at 50% 50%,
  color-mix(in srgb, var(--clr-bg) 72%, transparent) 0%,
  transparent 100%
);
```

Adjust the `72%` value if copy legibility or rain visibility needs tuning.
Stay between 60%–82%.

---

## 7. Adding a new homepage section

1. Add a `<section>` or Vue component between the existing sections in
   `Home.vue`
2. Add scoped CSS to `public/css/home.css` under the relevant comment block
3. If data is needed, add a builder method in `HomeController` and pass it
   as an Inertia prop
4. Keep Vue components in `resources/js/Components/Home/`

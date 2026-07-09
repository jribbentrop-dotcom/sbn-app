# SBN Homepage — Reference

The public homepage at `GET /` is the marketing and entry point for the app.
It is a pure Inertia page (no auth required), composed in `Home.vue` from
several Vue components plus a static CSS layer for everything else.

> **⚠ Re-layout planned (next session, as of 2026-07-09).** The section order
> and mix below is not final — several sections were already discarded from
> the visible page but their code is still present (see §1a). Before adding
> anything new, decide what actually stays; don't assume every component
> listed here belongs in the eventual layout.

---

## 1. Files

| File | Role |
|---|---|
| `app/Http/Controllers/HomeController.php` | Feeds all page data; no auth guard |
| `resources/js/Pages/Home.vue` | Inertia page — section composition |
| `resources/js/Components/Home/SyncedHero.vue` | Chord+rhythm synced demo (hero right column) |
| `resources/js/Components/Home/ChordRain.vue` | Chord card rain section |
| `resources/js/Components/Home/GradesSlider.vue` | Drag/keyboard carousel, "Five grades" section — **currently live** |
| `resources/js/Components/Home/GradesTeaser.vue` | Sticky scroll-scrub grade counter — **dead, see §1a** |
| `resources/js/Components/Home/SkillPathSection.vue` | Animated skill-tree scroll section — **currently live**, added 2026-07-09 |
| `resources/js/Components/Home/useClock.ts` | Retained, unused (future consumers) |
| `public/css/home.css` | All homepage CSS — scoped to `.home-page` |
| `resources/views/app.blade.php` | Loads `home.css` globally via `<link>` |

### 1a. Dead / invisible sections (as of 2026-07-09)

These exist in the codebase but do **not** render on the live page. Flagging
so the re-layout session either revives or deletes them deliberately, instead
of rediscovering them mid-task:

- **`GradesTeaser`** — imported in `Home.vue` but its usage is commented out
  (`<!-- <GradesTeaser /> -->`). This was the original sticky scroll-scrub
  "grade counter" section (tall outer container + pinned inner viewport,
  progress computed from `window.scroll`). `GradesSlider` (a drag carousel,
  not scroll-driven) replaced it as the live "Five grades" section.
  `SkillPathSection`'s pinned scroll-reveal pattern was modeled on
  `GradesTeaser`'s CSS/JS shape, not `GradesSlider`'s — worth knowing if
  `GradesTeaser` gets deleted, since it's the reference implementation.
- **"Rhythm strip"** — an empty HTML comment block in `Home.vue` between
  `ChordRain` and `GradesSlider`. No component reference survives (already a
  bare comment shell), so there's nothing to restore — safe to delete
  outright. (§2.2 below describes what this section used to be, kept for
  history; it does not reflect current `HomeController` output.)
- No homepage "progressions" section was found anywhere in code or git
  history as of this note — if that's remembered from a past session, it may
  have been an idea discussed but never built, or confusion with the
  standalone `/library/progressions` page.

---

## 2. Page sections (top → bottom, as currently composed in `Home.vue`)

1. **Hero** (`home-hero`) — see §2.1
2. **Chord rain** (`ChordRain`, conditional on `rainChords` data) — see §4
3. *("Rhythm strip" — dead comment block, see §1a)*
4. **Grades slider** (`GradesSlider`) — drag/keyboard carousel over the 5
   grade tiers (`useGrades.ts`), each card links to `/grades`
5. **Skill path** (`SkillPathSection`) — added 2026-07-09, see §2.5
6. **Feature cards** (`home-section`) — static three-column tool grid

### 2.1 Hero (`home-hero`)

Two-column grid: copy left, `SyncedHero` right. Animated blob background
(pure CSS, three `.blob` divs with `@keyframes blob-float`). Staggered
`.reveal .d1–.d5` entrance animation on text.

**Controller data (current, `HomeController::index()`):**
- `heroBars` / `heroRhythm` — sliced bars (`HERO_START`..`HERO_END`) from the
  Girl from Ipanema leadsheet, built by `buildHeroBars()` (mirrors
  `SyncedPlayerController::apiShow()` so tempo/rhythm match the Top10 demo)
- `rhythmPattern` — first bossa-nova `RhythmPattern` ordered by `default_bpm`
- `rainChords` — see §5.1

**SyncedHero** is documented in full in `docs/SBN-SyncedPlayer-Reference.md`.

---

### 2.2 Rhythm strip (`home-section`) — historical, not currently rendered

Originally a static section with `<RhythmStrip :pattern="rhythmPattern" :playable="true" />`.
Now just an empty comment block in `Home.vue` (see §1a) — kept here only so
the description isn't lost if it's ever revived.

---

### 2.3 Chord rain (`chord-rain-section`)

Full-viewport section (`height: 100vh`) where chord diagram cards fall in
columns. See §4 below for the full spec.

---

### 2.4 Feature cards (`home-section`)

Three-column grid of tool cards (pure Blade/CSS). Links to tab editor, chord
library, and a placeholder analysis panel.

---

### 2.5 Skill path (`SkillPathSection`) — added 2026-07-09

Animated branching skill-tree section promoting the skill node system
(`docs/SBN-Skill-System-Reference.md`). 9 hand-curated real nodes (verified
against `sbn_skill_nodes` / `sbn_skill_node_prerequisites` — not illustrative
placeholders), a coherent bossa/jazz comping path from `the-basic-8` (grade 1)
through `chord-melody` (grade 5), rendered with real per-node icons via
`SkillIcon.vue`.

**Layout/animation pattern** — sticky scroll-scrub, modeled on `GradesTeaser`
(see §1a), not `GradesSlider`:
- `.sps-outer` — tall scroll container; height is set **inline via JS**
  (`stickyRef.offsetHeight + 100vh`), not a fixed CSS value, because the
  sticky panel's own height is viewport-responsive (see below) and the two
  must stay in sync or the reveal either finishes early (dead scroll space
  after) or never finishes (cut off before the last row).
- `.sps-sticky` — pinned inner viewport holding both the heading and the tree
  canvas together (heading intentionally moved inside the sticky block so it
  doesn't scroll away before pinning starts). Height:
  `min(800px, 100vh - header - 64px)`, raised to `min(980px, …)` at
  `≥1200px` — caps on tall/wide desktop screens, shrinks on short viewports
  so it's never cut off.
- Reveal progress (`onScroll()`) blends two phases into one `pct`: an
  "approach" ramp (first ~25%) while the section is still scrolling into
  view (before pinning engages), then the classic pinned-range scrub —
  so the first row or two start animating before the user reaches the fully
  pinned state, instead of waiting for pin to engage.
- Row reveal uses `Math.round(pct * (ROW_COUNT - 1))` (not `ROW_COUNT`) so the
  **last** row lands exactly at `pct === 1` — dividing by `ROW_COUNT` instead
  left the final ~15-20% of the pinned range idle after the last node
  appeared.
- Node icon size (`iconSize` ref, 22px mobile / 34px ≥701px) is bound via
  Vue's `v-bind()` in `<style>` to a scoped `:deep(img)` override — required
  because `SkillIcon`'s custom-SVG branch renders a plain `<img width height>`,
  and the global `img { max-width:100%; height:auto }` reset in
  `resources/css/frontend/base.css` silently overrides those attributes
  otherwise.
- Node circle size uses a `--node-size` custom property (44px mobile / 58px
  desktop) so the tag label's vertical offset (`calc(var(--node-size) + 8px)`)
  scales with it — a hardcoded tag offset caused icon/label overlap when the
  node size changed at the breakpoint.

**Known site-wide CSS footgun found (and fixed) while building this section:**
`body` and `.home-page` both had a lone `overflow-x: hidden` with no
`overflow-y` set. Per CSS spec, a non-`visible` value on one overflow axis
forces the other axis to compute as `auto` too — so both silently became
scroll containers, breaking `position: sticky` for *any* descendant (it pins
against the nearest scrolling ancestor, not necessarily the window). Removed
both declarations — nothing actually needed them: `.home-hero` and
`.chord-rain-section` already clip their own bleeding decorations (blobs,
rain cards) locally with their own `overflow: hidden`. If a future section
reintroduces `overflow-x: hidden` on `body` or `.home-page`, re-check this —
it will re-break sticky sitewide, not just locally.

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

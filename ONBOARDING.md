# SBN Widget System — Onboarding Guide

This guide gives you everything you need to continue work on the **interactive music theory widgets** for the Soul Bossa Nova teaching hub (`sbn-app`), a Laravel + Inertia + Vue 3 + TypeScript application.

---

## What we're building

A public `/theory` page (`GET /theory`, MegaMenu → Explore → Resources → Music Theory`) that shows a grid of live, interactive music theory widgets. Each widget is a self-contained Vue 3 SFC — no store, no backend fetch, pure SVG + interval math. They're also embeddable inside lesson content and chord library pages via `<sbn-widget slug="…">` tags in markdown.

---

## File map

```
resources/js/edu/widgets/
  registry.ts          ← slug → lazy import thunk (single source of truth)
  catalog.ts           ← slug + title + summary + tags[] (drives /theory page)
  TriadBuilder.vue     ← triad-builder
  CircleOfFifths.vue   ← circle-of-fifths
  Drop2Visualizer.vue  ← drop2-visualizer
  VoiceLeading.vue     ← voice-leading

resources/js/Pages/Library/Theory/Index.vue   ← /theory page
app/Http/Controllers/Library/TheoryController.php
routes/web.php  →  Route::get('/theory', ...)  name: theory.index
docs/SBN-Edu-Reference.md  ← full system reference
```

---

## The four existing widgets

### 1. `triad-builder`
Teaches triad construction. Fixed root (C). Four **badge pills** — Major / Minor / Diminished / Augmented — select the quality. Three pitch-dots (Root, Third, Fifth) are stacked vertically; higher on screen = higher pitch. Switching quality slides the third/fifth dots to their new heights with a spring bounce. **Pop-in** animation on first dot appearance; **pulse** on the dot that moved when quality changes.

### 2. `circle-of-fifths`
Clickable 12-segment SVG donut. Each segment = one major key + its relative minor. Polar geometry, no charting library.

### 3. `drop2-visualizer`
Teaches drop voicings. Fixed Cmaj7. Three **badge pills** — Closed / Drop 2 / Drop 3. Four pitch-dots (Root, Third, Fifth, Seventh). The dropped voice slides down an octave with spring easing and pulses to mark the move. Compact geometry (DOT_R=16, ROW_GAP=36).

### 4. `voice-leading`
6 horizontal lines = guitar strings (high E top, low E bottom; bottom 3 slightly thicker = bass strings). Two columns of dots — Chord A left, Chord B right. **Bezier curves** connect matching roles across chords (control points pulled to center). Steep curve = big leap, flat curve = smooth voice leading. Three preset pairs as **badge pills**:
- Imaj7 → VIm7 (very smooth, near-flat lines)
- IIm7 → V7 (some contrary motion)
- V7 → Imaj7 (tritone resolution, most dramatic)

---

## Visual language conventions

All chord-construction widgets share one vocabulary — a learner who understands one understands all:

| Convention | Rule |
|---|---|
| **Dot color** | Role-coded via `--clr-role-*` tokens: Root=orange, Third=blue, Fifth=green, Seventh=purple |
| **Vertical pitch** | Higher on screen = higher pitch (TriadBuilder, Drop2Visualizer) |
| **Controls** | Badge pills only — no `<select>` dropdowns |
| **Pop-in** | New dot: `scale(0) → scale(1.25) → scale(1)`, spring easing, 350ms |
| **Pulse** | Dot that moved: `scale(1) → scale(1.35) → scale(1)`, 450ms |
| **Slide** | Position change: `transform` CSS transition, `cubic-bezier(0.34, 1.2, 0.64, 1)` (slight overshoot) |
| **Reduced motion** | All animations off via `prefers-reduced-motion` |

CSS class names: `tb-` (TriadBuilder), `cof-` (CircleOfFifths), `d2-` (Drop2Visualizer), `vl-` (VoiceLeading).

---

## Design tokens (from `public/css/sbn-design-system.css`)

```css
--clr-role-root:    #f39c12   /* orange */
--clr-role-third:   #3b82f6   /* blue */
--clr-role-fifth:   #10b981   /* green */
--clr-role-seventh: #8b5cf6   /* purple */
--clr-accent:       #f39c12
--clr-border:       rgba(255,255,255,0.08)  /* dark theme */
--clr-text-muted:   rgba(232,232,240,0.65)
--radius:           0.75rem
--ease:             cubic-bezier(...)
```

Card hover style (from `.sbn-chord-card:hover`): `translateY(-2px)` + `box-shadow: 3px 3px 0 rgba(54,131,255,0.35)` + `rgba(183,211,255,0.06)` background. No blurred drop-shadows.

---

## Adding a new widget — checklist

1. Create `resources/js/edu/widgets/MyWidget.vue`
   - `<script setup lang="ts">`, self-contained
   - No store, no tab-editor imports
   - Design-system tokens only, scoped styles with a short prefix
   - Badge pills for controls (not `<select>`)
   - Pop-in + pulse animations following the pattern above
2. Add one line to `registry.ts`:
   ```ts
   'my-widget': () => import('./MyWidget.vue'),
   ```
3. Add one entry to `catalog.ts`:
   ```ts
   { slug: 'my-widget', title: '…', summary: '…', tags: ['harmony'] }
   ```
4. The widget auto-appears on `/theory`. Done.

To embed in a lesson/topic body: `<sbn-widget slug="my-widget" />` in any markdown file under `resources/edu/`.

---

## Deferred work (known polish items)

- **Fretboard render pass** — `Drop2Visualizer` voices already carry a `typicalString` field (guitar string 1–6). A future pass can add a string-lane fretboard diagram below the pitch dots without restructuring the logic. This is render-only work.
- **More widget ideas** — scales visualizer, interval trainer, rhythm widget, chord function in key context.
- **Content authoring** — more `resources/edu/concepts/` files embedding widgets via `<sbn-widget>` tags.

---

## How the /theory page mounts widgets

The page does NOT use `mountSbnNodes`. Each card has a bare `<div :id="theory-widget-{slug}">`. After render:

```ts
const mod = await eduWidgets[slug]();
createApp(mod.default, defaultProps).mount(el);
```

`mountedSlugs` set prevents double-mounting. Widgets load lazily on scroll / `watch(visible)`.

---

## Tech stack reminder

- Laravel 10 + Inertia.js + Vue 3 + TypeScript + Tailwind
- `<script setup lang="ts">` everywhere
- Scoped `<style scoped>` in SFCs
- SVG drawn inline (no charting library)
- `public/css/sbn-design-system.css` for tokens

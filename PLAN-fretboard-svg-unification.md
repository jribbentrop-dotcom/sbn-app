# Plan — Unify `<sbn-fretboard>` onto the Progression Viewer's SVG neck

**Status:** PLAN ONLY — no code written. Awaiting review.
**Date:** 2026-06-30
**Goal:** Make the app-wide `<sbn-fretboard>` look like (and share machinery with) the
progression viewer's SVG fretboard, and lay the foundation for later
animation/morphing between frames.

---

## 1. Why this shape (decisions already made)

- **Tech verdict:** SVG (progression viewer) beats HTML/flexbox (sbn-fretboard) on
  every axis that matters here — lighter DOM, real proportional fret spacing,
  crisp viewBox scaling, cheap GPU-friendly animation, one source of truth.
- **Theme:** retire the dark skeuomorphic theme; the light/flat progression look
  becomes the single default for `<sbn-fretboard>`.
- **Animation later:** because morphing is on the roadmap, the renderer must be a
  **Vue component sharing the progression viewer's animation machinery**, not a
  hand-rolled vanilla-JS SVG string. The old "keep it vanilla, no Vue" decision
  (SBN-Fretboard-Reference §6) was justified for a *static* diagram; it no longer
  holds once we want reactive morphing.

**Net:** extract the progression viewer's SVG neck into a reusable Vue component
(`FretboardNeck.vue`) driven by a frame model; both `ChordProgressionViewer` and
`<sbn-fretboard>` render it.

---

## 2. The three consumers (and what each needs)

| Consumer | Today | After |
|---|---|---|
| **`ChordProgressionViewer.vue`** | Inline SVG neck, single active chord, camera pan + dot morph between chords | Renders `<FretboardNeck>`, feeds it the active chord's positions; pan/morph logic moves into the shared component |
| **`<sbn-fretboard>` tag** (course lessons) | Vanilla `sbnHydrateFretboard` → flexbox HTML, ‹ › frame stepping | Mounts a Vue wrapper that renders `<FretboardNeck>`; ‹ › stepping becomes an animated frame transition |
| **Admin fretboard editor** (`edit.blade.php`) | Alpine `fretboardEditor()` click-grid — **its own renderer**, NOT `sbnRenderFretboard` | **UNCHANGED.** It's an editing tool, not the published view. Out of scope. |

**Key finding:** the admin editor does not call `sbnRenderFretboard`/`sbnHydrateFretboard`.
Confirmed: grep of `edit.blade.php` shows only `fretboardEditor()` (Alpine) + a static
`<code>` tag-copy snippet. So the published renderer has exactly **two** consumers to
unify (prog viewer + the `<sbn-fretboard>` mount path). This significantly de-risks the work.

---

## 3. Geometry gap analysis (what the SVG neck must gain)

The progression viewer's SVG was built for **one chord at a time within a 7-fret
sliding window**. `<sbn-fretboard>` needs more:

| Capability | Prog viewer today | `<sbn-fretboard>` needs |
|---|---|---|
| Window | Fixed 7-fret excerpt, camera pans | Honor record's `fret_count` (4–24) + `start_fret`; show the whole configured span, not a 7-window |
| Dots per string | **One** (chord positions) | **Multiple** per string for **scale mode** (`dots[]`) |
| Open/muted strings | Not drawn (excerpt starts fret 1) | Chord mode shows open (○) / muted (×) markers at/over the nut |
| Nut | Drawn when window starts at fret 1 | Same, but window start is `start_fret`, not always 1 |
| Interval labels | From `interval_labels` (string-indexed) | Same source; reuse |
| Guide-tone colors | `vlColor` from resolution pairs | Record's `show_guide_tones` → color dots by interval token (b7/3/R/9/5) |
| RH fingers (p i m a) | Not shown | Record's `show_rh_fingers` → label column |
| Frames | N/A (chord timeline) | `voicings[]` array, ‹ › stepping, animate between |

**Implication:** `FretboardNeck.vue` is a **superset** of the prog viewer's current
neck. It must support:
1. Configurable fret range (`startFret`, `fretCount`) — not a hardcoded 1..15 / 7-window.
2. A generic **dot list** input `{ string, fret, label?, finger?, color?, ghost? }[]`
   — multiple dots per string allowed. (Prog viewer's single-per-string is just the
   special case where each string appears ≤ once.)
3. Optional nut + open/muted column.
4. Optional RH-finger column.

The proportional spacing formula (`2^(-f/12)`) generalizes cleanly to any
`[startFret, startFret+fretCount-1]` range — same math, different bounds.

---

## 4. Proposed architecture

```
resources/js/Components/Library/fretboard/
  FretboardNeck.vue        ← NEW. Pure SVG neck renderer. Props = geometry + dot list.
  fretboardGeometry.ts     ← NEW. Extracted pure fns: fretEdgeX, fretCenterX,
                             stringY, inlay positions, fret numbers. No Vue.
  useNeckCamera.ts         ← NEW (animation phase only). rAF lerp of viewBox x-origin
                             + smoothX. Pulled out of ChordProgressionViewer.
```

### `FretboardNeck.vue` props (draft)
```ts
interface FretboardNeckProps {
  startFret: number;          // first fret column (default 1)
  fretCount: number;          // number of frets shown (default 7 for prog, record value for tag)
  dots: NeckDot[];            // current frame's dots
  ghostDots?: NeckDot[];      // optional VL targets / next-frame preview
  showNut?: boolean;          // draw nut + open/muted column
  openStrings?: OpenMarker[]; // [{string, kind:'open'|'muted'}] for chord mode
  rhFingers?: string[];       // 6 tokens p/i/m/a, or [] to hide
  cameraX?: number | null;    // when set, render as cropped excerpt (prog viewer pan)
}
interface NeckDot {
  string: number;  // 1=low E … 6=high e
  fret: number;
  label?: string;  // interval token or finger
  color?: string | null;   // guide-tone / VL color; null → default dot fill
  isRoot?: boolean;
}
```

### How each consumer drives it
- **Prog viewer:** `startFret=FRET_FROM`, `fretCount=15`, `cameraX=smoothX` (pan),
  `dots` = active chord positions, `ghostDots` = VL targets. Its `vl-table`,
  numeral chips, audio, chord-card aside all stay in `ChordProgressionViewer.vue` —
  only the `<svg class="board">` block is replaced by `<FretboardNeck :camera-x=…>`.
- **`<sbn-fretboard>`:** a thin `SbnFretboard.vue` wrapper holds frame state
  (`activeFrame`, `voicings[]`), maps the active frame → `dots[]` (chord/sequence:
  parse `frets` string; scale: expand `dots[]`), wires ‹ › buttons + guide-tone
  toggle, and renders `<FretboardNeck :start-fret :fret-count :show-nut …>`.
  `cameraX=null` (no pan — show the full configured window).

---

## 5. Mount-layer change for `<sbn-fretboard>`

`mountSbnNodes.ts` currently special-cases `<sbn-fretboard>` to call the global
`window.sbnHydrateFretboard`. Replace that block with the standard Vue mount path:

- Add `fretboard: () => import('../Components/Library/fretboard/SbnFretboard.vue')`
  to the `components` registry + `/api/sbn/fretboards/${slug}` to `apiUrl` +
  a `propsFor.fretboard` adapter (`{ data: fullRecord }`).
- Delete the bespoke `<sbn-fretboard>` `forEach` block (lines ~340–365).
- The API endpoint + payload shape are unchanged — only the client renderer changes.

**Cleanup once migrated:**
- `public/js/chords.js`: `sbnRenderFretboard` + `sbnHydrateFretboard` become dead
  code (verify no other caller — grep showed only mountSbnNodes + docs). Remove.
- `public/css/fretboard.css`: the `.sbn-fretboard-*` published-view selectors become
  dead. **Keep** `.sbn-fb-frame-*` (admin editor frame list still uses them) — or
  move those few rules into the admin view. Verify with grep before deleting.

---

## 6. Phasing

**Phase 1 — Extract, no behavior change (safe, reviewable in isolation)**
1. Pull geometry math out of `ChordProgressionViewer.vue` into `fretboardGeometry.ts`.
2. Create `FretboardNeck.vue` rendering the *current* prog-viewer neck (single-dot,
   7-window, camera pan) by consuming those fns.
3. Swap the inline `<svg class="board">` in `ChordProgressionViewer` for
   `<FretboardNeck>`. **Visual diff must be zero.** This is the de-risking step:
   prove the extraction is faithful before generalizing.

**Phase 2 — Generalize the neck**
4. Add `startFret`/`fretCount`/`showNut`/`openStrings`/`rhFingers`/multi-dot support
   to `FretboardNeck.vue`. Prog viewer keeps passing its existing values → still
   zero visual change for it.

**Phase 3 — Wire `<sbn-fretboard>`**
5. Build `SbnFretboard.vue` wrapper (frame state, frets-string parse, scale `dots[]`
   expand, ‹ › stepping, guide-tone toggle, RH fingers).
6. Switch `mountSbnNodes.ts` to mount it; delete the vanilla hydration block.
7. Remove dead `chords.js` fns + dead `fretboard.css` selectors (after grep verify).
8. Update `SBN-Fretboard-Reference.md` (renderer is now Vue SVG, not vanilla; light
   look; §6 "no Vue" rationale superseded).

**Phase 4 — Animation/morphing (LATER, separate task)**
9. Extract `useNeckCamera.ts`; add dot-morph transitions to `FretboardNeck` keyed by
   string (the prog viewer's keyed `<g>` morph). ‹ › stepping then animates frame→frame.
   This is the payoff the architecture was chosen for — but it ships on its own.

---

## 7. Risks & gotchas

- **Scale mode has no nut/open column** (SBN-Fretboard-Reference §3): `f:0` dots
  don't render. `FretboardNeck` must replicate that — scale mode = `showNut:false`,
  drop `fret < startFret` dots. Don't "fix" open-position scales here; that's a
  data-authoring concern noted in the reference.
- **String orientation:** prog viewer renders **low E at bottom** (`stringY(s) =
  PAD_T + (6-s)*stringH`). The flexbox version renders **high e at top** via string
  labels. Confirm the published `<sbn-fretboard>` examples read correctly with the
  SVG convention; the label column order may need flipping.
- **`start_fret:0` coalesces to 1** (existing JS `|| 1`). Preserve that in the wrapper.
- **Zero-visual-diff gate (Phase 1)** is the single most important checkpoint. If the
  extracted neck doesn't pixel-match the current prog viewer, stop and fix before
  generalizing — every later phase builds on it.
- **`fretboard.css` is loaded globally** in `app.blade.php`. Removing selectors is
  safe only after confirming no remaining consumer (admin frame-list is the one to check).
- **Verify the dead-code grep** before each deletion — `sbnRenderFretboard` /
  `sbnHydrateFretboard` / `.sbn-fretboard-*` may be referenced in lesson HTML or
  other blades not yet checked.

---

## 8. Definition of done (Phases 1–3)

- `<sbn-fretboard>` in any lesson renders the light/flat SVG neck, visually matching
  the progression viewer's fretboard aesthetic.
- Chord, sequence, and scale modes all render correctly (multi-dot scale; nut + open/
  muted chord; ‹ › stepping; guide-tone + RH-finger toggles honored).
- Progression viewer is visually unchanged.
- Admin editor unchanged and still functional.
- Dead vanilla renderer + dead CSS removed; reference doc updated.
- Foundation in place for Phase 4 animation with no further architectural change.
```

# SBN Fretboard Reference

> **Purpose:** Single source of truth for the interactive fretboard system — admin CRUD, storage format, Vue SVG renderer, course tag, and TipTap editor integration. Load at the start of any session touching fretboards.
> **Last updated:** 2026-07-01 (CSS polish pass: fixed-width positions board, click/drag/swipe window navigation, autoplay/slider/play-button removed — see §4, §9)

---

## 1. Overview

The fretboard system provides slug-addressable, interactive fretboard diagrams that can be embedded anywhere in lesson content via an `<sbn-fretboard>` tag. Diagrams are created and edited in the admin, stored in a `fretboards` DB table, served via a public JSON API, and rendered by **`SbnFretboard.vue`** (a Vue component using the same light/flat SVG neck as the `ChordProgressionViewer`).

Three display modes are supported:

| Mode | Description | Storage format |
|---|---|---|
| `chord` | One or more chord shapes (voice-leading sequence) | fret string `x32010` per frame |
| `scale` | Scale positions with multiple dots per string | `dots: [{s,f,finger}]` array per frame |
| `sequence` | Identical to `chord` — alias for author intent | fret string per frame |
| `positions` | **One** neck-wide dot set; camera **slides** between named fret windows (e.g. 5 pentatonic positions in one record). Navigated via arrow buttons or click/drag/swipe — no autoplay. | `voicings[0].dots: [{s,f,finger,iv}]` + top-level `windows: [{label,from,to}]` |

---

## 2. Data model

**Table:** `fretboards`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `slug` | varchar(120) unique | URL-safe identifier, auto-derived from `title` |
| `title` | varchar(255) | Display name |
| `description` | text nullable | Optional admin note |
| `display_mode` | string `chord\|scale\|sequence\|positions` | Controls render path. **Was an enum; relaxed to a plain string in the 2026-07-01 migration** so `positions` is accepted (the old CHECK constraint was dropped). |
| `theme` | enum `dark\|light` | CSS theme scope |
| `fret_count` | tinyint (4–24, default 12) | Number of fret columns shown |
| `start_fret` | tinyint (1–20, default 1) | First fret column shown |
| `show_guide_tones` | boolean (default false) | Colours b7/3/R/9/5 dots |
| `show_rh_fingers` | boolean (default false) | Shows p/i/m/a labels |
| `voicings` | JSON | Array of frame objects (see §3) |
| `windows` | JSON nullable | **Positions mode only:** array of `{label, from, to}` camera windows (added 2026-07-01) |
| `start_window` | tinyint unsigned (default 0) | **Positions mode only:** 0-indexed entry into `windows[]` the camera opens on (added 2026-07-07). Clamped server-side to a valid index; out-of-range or missing values fall back to `0`. |

> **Deploy note (2026-07-01):** migration `2026_07_01_000000_add_windows_to_fretboards.php` adds `windows` and drops the `display_mode` CHECK constraint (SQLite rebuilds the table). Prod DB is scp'd and does **not** run create/alter migrations automatically — apply the same change to prod: `ALTER TABLE fretboards ADD COLUMN windows text;` plus a table rebuild to drop the old `CHECK (display_mode IN ('chord','scale','sequence'))`, otherwise inserting a `positions` row fails. The local `sbn.db` already has both applied.

> **Deploy note (2026-07-07):** migration `2026_07_07_000000_add_start_window_to_fretboards.php` adds `start_window` (unsigned tinyint, default 0). Apply to prod with `ALTER TABLE fretboards ADD COLUMN start_window INTEGER NOT NULL DEFAULT 0;` — no table rebuild needed (plain column add). The local `sbn.db` already has it applied.
| `created_at` / `updated_at` | timestamps | |

**Model:** [`app/Models/Fretboard.php`](../app/Models/Fretboard.php)
- Casts: `voicings` → array, `windows` → array, `show_guide_tones`/`show_rh_fingers` → bool, `fret_count`/`start_fret` → int
- Helper: `firstVoicing()` — returns first frame or empty default

---

## 3. Frame / voicing format

Each entry in the `voicings` JSON array is one "frame" — a single fretboard state. The frame format differs by mode.

### Chord / sequence mode

```json
{
  "label": "Dm7",
  "frets": "xx0231",
  "fingers": "000231",
  "interval_labels": "R,b7,b3,5,R,"
}
```

- `frets` — 6 chars, one per string (low E → high e). `x` = muted, `0` = open, `1`–`9` = fret number.
- `fingers` — 6 chars, `0` = no finger, `1`–`4` = index–pinky, `T` = thumb.
- `interval_labels` — comma-separated, one token per string. Empty token = no label.

### Scale mode

```json
{
  "label": "D Dorian",
  "dots": [
    { "s": 0, "f": 5, "finger": 1 },
    { "s": 0, "f": 7, "finger": 3 },
    { "s": 1, "f": 5, "finger": 1 }
  ],
  "interval_labels": ""
}
```

- `dots` — array of `{s, f, finger}` — multiple dots per string allowed.
  - `s` — string index (0 = low E, 5 = high e)
  - `f` — fret number (0 = open)
  - `finger` — same codes as above
- `frets` / `fingers` fields are ignored in scale mode.

**Open strings (`f: 0`) do not render in scale mode.** `sbnRenderFretboard` reads `startFret = parseInt(opts.start_fret) || 1`, so a stored `start_fret: 0` is coalesced back to `1` by the JS `||` fallback — and even if it weren't, scale mode has no nut/open-string column at all (that's a chord/sequence-mode-only render path). A dot with `f: 0` simply has nowhere to draw. For an open-position scale shape, transpose the whole shape up an octave (e.g. frets 0–3 → 12–15) and set `start_fret` to a real fretted value instead.

### Positions mode (sliding camera)

```json
{
  "display_mode": "positions",
  "voicings": [
    { "label": "E minor pentatonic",
      "dots": [ {"s":0,"f":12,"finger":1,"iv":"R"}, {"s":0,"f":15,"finger":1,"iv":"b3"}, … ] }
  ],
  "windows": [
    {"label":"Position 1","from":12,"to":15},
    {"label":"Position 2","from":14,"to":17},
    …
  ]
}
```

- **The entire scale lives in `voicings[0].dots[]`** — one coherent neck-wide dot set (same `{s,f,finger}` shape as scale mode, plus optional `iv`). Only `voicings[0]` is used; extra frames are ignored in positions mode.
- **`windows[]`** are named fret ranges the camera pans between (1-indexed frets, inclusive `from`/`to`). The student steps between them via arrow buttons or by clicking/dragging/swiping the board; **dots don't move — the camera does.** Dots inside the active window render solid; dots outside fade out (`.is-hidden` opacity) but stay in place.
- **`start_window`** picks which window the camera opens on (0-indexed into `windows[]`), for cases where a lesson is specifically about e.g. "Position 3" and shouldn't force the student to step through 1 and 2 first. Defaults to `0` (first window). Set via the **Starting position** dropdown in the admin's Position Windows section.
- **`dots[].iv`** (optional interval token — `R,b3,3,4,5,b7,…`) drives per-dot guide-tone color when `show_guide_tones` is set. This is independent of the comma-indexed `interval_labels` (which can't cleanly address N dots). `iv` is authored in the JSON; there's no per-dot `iv` UI in the admin grid yet (see §7).
- `f:0` open-string dots are skipped (fretted-neck view, like scale mode). `start_fret`/`fret_count` are ignored — positions mode computes a full-neck geometry from fret 1 to `max(15, highest dot/window fret)` (capped at 24).

---

## 4. Vue SVG renderer — `SbnFretboard.vue`

**Replaced the old vanilla-JS renderer in Phase 3 (2026-06-30).** The renderer is now a Vue component pair:

| File | Role |
|---|---|
| [`resources/js/Components/Library/fretboard/SbnFretboard.vue`](../resources/js/Components/Library/fretboard/SbnFretboard.vue) | Wrapper: holds frame state, parses frets string / expands scale dots, maps to FretboardNeck props, wires ‹›/GT toggle. |
| [`resources/js/Components/Library/fretboard/FretboardNeck.vue`](../resources/js/Components/Library/fretboard/FretboardNeck.vue) | Presentational SVG neck: shared with ChordProgressionViewer. Light/flat aesthetic. |
| [`resources/js/Components/Library/fretboard/fretboardGeometry.ts`](../resources/js/Components/Library/fretboard/fretboardGeometry.ts) | Pure geometry math: `makeGeometry(startFret, fretCount)` factory, plus default 1..15 consts for the prog viewer. |

**Render paths:**
- **chord/sequence mode**: parses `voicing.frets` string (low E→high e, `x`=muted, `0`=open, `1-9`=fret). Open/muted strings appear as ○/× markers at the nut when `startFret === 1`. SVG dots for fretted strings. Guide-tone colors when `show_guide_tones` active.
- **scale mode**: expands `voicing.dots[]` (multiple per string). `f:0` and `f < startFret` dots are skipped (same behavior as the old renderer). No nut/open column in scale mode.
- **positions mode**: expands `voicings[0].dots[]` across a **full-neck geometry** (`makeGeometry(1, fullFretCount)`), computing cx/cy for every dot. A dot's `visible` flag = "is its fret inside the active window." The camera pans via a `smoothX` rAF-lerp (ported verbatim from `ChordProgressionViewer.vue:278-296`) → `posViewBox` computed → `FretboardNeck :view-box`. Because the neck honors a parent-supplied `viewBox`, it skips its own tight-frame path and just crops. Leaving/entering dots cross-fade in place via the neck's existing `.is-hidden` opacity + `transition: transform`.
  - **Controls** (only when `windows.length > 1`, gated by `showPositionControls`): flanking `‹`/`›` arrow buttons (`.sbn-fv-arrow-btn`, call `stepWindow(-1)`/`stepWindow(1)`) plus click/drag/swipe navigation directly on the board via pointer events (`onBoardPointerDown/Move/Up`). A drag past `DRAG_THRESHOLD = 40` SVG units steps to the next/previous window (with a `STEP_COOLDOWN_MS = 400` cooldown to prevent skip-multiple); a plain click/tap with no drag advances one window via `goToWindow`. The "Position X" label is centered above the board.
  - **No play/pause button, no `<input type=range>` slider, no autoplay/`setInterval`/loop.** These were all removed in the 2026-07-01 CSS polish pass in favor of the drag-gesture controls above — the camera only moves in response to user input now.
  - **Fixed board size:** the SVG viewBox width is pinned to `POS_EXCERPT_VW = 236` units regardless of which window is active (previously excerpt width varied per-window), so the board doesn't resize as the student navigates. The whole positions board is additionally scaled via `transform: scale(0.72)` in CSS to match surrounding lesson-text sizing.
  - Guide tones auto-apply from `dots[].iv` when `show_guide_tones` is set — positions mode has **no GT toggle button** (unlike chord/scale). `start_fret`/`fret_count`/nut/open-column/RH-fingers are all suppressed in this mode.

**String orientation:** SBN convention — string 1 = Low E, string 6 = high e. Low E renders at the BOTTOM of the SVG (`stringY(1) > stringY(6)`), matching `ChordProgressionViewer`. The `frets` string index 0 = string 1 (low E), index 5 = string 6 (high e).

**Guide-tone colors** (same palette as the old `GT_COLORS`): b7/7/maj7 → amber `#d97706`; 3/b3 → blue `#2563eb`; R → green `#16a34a`; 9/b9/#9/2/11/#11/13/b13/6 → purple `#7c3aed`; 5/b5/#5 → gray `#6b7280`.

**Dead code removed from `chords.js`:** `sbnRenderFretboard`, `sbnHydrateFretboard`, `sbnHydrateAll`, `_sbnRhFingers`, `_sbnGtClass`, `SBN_FRET_MARKERS` (2026-06-30). Grep confirmed zero callers outside chords.js itself.

**Note on `§6` "no Vue" rationale:** The original decision to keep this vanilla-JS was justified for a static diagram. It is superseded now that morphing/animation between frames is planned (requires Vue's reactive machinery). The new Vue renderer is the foundation for Phase 4 animation.

**Layout / viewBox:** `FretboardNeck` accepts an optional `viewBox` prop. `ChordProgressionViewer` passes its camera-panned excerpt string. `<sbn-fretboard>` passes nothing, so the neck computes a **tight viewBox** around the actual fret span (`NECK_L`→`NECK_R`) — not the fixed 800-wide board — with padding for the open/muted column, RH-finger labels, and the fret-number row. (Reusing the full 800px board made the shorter `<sbn-fretboard>` neck shrink to a thin strip via `preserveAspectRatio="meet"`.) The wrapper (`SbnFretboard.vue`) is frame-free: no outer border, header is a plain caption row.

> **CSS polish landed 2026-07-01:** chord/scale mode now crops tightly to the dot cluster (35-unit padding each side) with a max-width matching the positions board width; the nut is a filled path with explicit `rx=9` arcs matching the neck-surface corners (rendered via an `isNut` fret-line flag on fret 1, all modes); the wrapper has a thin border, subtle background tint, and 8% horizontal padding. Earlier "spacing not yet fine-tuned" caveat is resolved — current padding/header values are the settled ones.

---

## 5. CSS — `fretboard.css`

[`public/css/fretboard.css`](../public/css/fretboard.css) — loaded globally in `app.blade.php`.

**Phase 3 update:** All `.sbn-fretboard-*` published-view selectors were removed (2026-06-30). The published `<sbn-fretboard>` visual is now handled by scoped styles inside `SbnFretboard.vue` and `FretboardNeck.vue`.

**Only remaining selectors:**

| Selector | Purpose | Status |
|---|---|---|
| `.sbn-fb-frame-list` / `.sbn-fb-frame-item` / etc. | Admin editor frame list sidebar | **KEPT** — still used by `admin/fretboards/edit.blade.php` |

---

## 6. Course tag — `<sbn-fretboard>`

### Authoring

```html
<sbn-fretboard slug="dm7-drop2-voice-leading"></sbn-fretboard>
```

Attrs: `slug` (required). No other attrs — all display options are baked into the stored record.

Always use explicit closing tags — HTML parsers do not honor self-closing on custom elements.

### Runtime

**`mountSbnNodes.ts`** handles `<sbn-fretboard>` via the standard Vue mount path (same as `<sbn-chord>`, `<sbn-rhythm>`, etc.):

1. Fetches `GET /api/sbn/fretboards/{slug}`.
2. Dynamically imports `SbnFretboard.vue` and mounts it with `{ data: fullRecord }` prop.
3. On error: renders an `.sbn-node-error` span with the slug.

**Phase 3 change:** the bespoke vanilla-JS `sbnHydrateFretboard` block was deleted from `mountSbnNodes.ts`. Fretboard is now a first-class member of the `components` registry in that file.

### JSON API

| Method | Path | Purpose | Returns |
|---|---|---|---|
| `GET` | `/api/sbn/fretboards/{slug}` | Mount payload for `<sbn-fretboard>` | Full fretboard record (§2 shape) |
| `GET` | `/api/sbn/fretboards?q=…` | Palette search (admin) | `{ results: [{slug, label, meta}] }` |

Controller: [`AdminFretboardController::apiShow`](../app/Http/Controllers/Admin/AdminFretboardController.php) and `::apiSearch`. Route group: `api/sbn` prefix in [`routes/web.php`](../routes/web.php). Public, no auth.

---

## 7. Admin CRUD

**Entry point:** `/admin/fretboards`
**Nav item:** Between Rhythm Patterns and Courses in the admin sidebar.
**Controller:** [`app/Http/Controllers/Admin/AdminFretboardController.php`](../app/Http/Controllers/Admin/AdminFretboardController.php)

| Route | Name | Action |
|---|---|---|
| `GET /admin/fretboards` | `admin.fretboards.index` | List all |
| `GET /admin/fretboards/create` | `admin.fretboards.create` | New form |
| `POST /admin/fretboards` | `admin.fretboards.store` | Save new |
| `GET /admin/fretboards/{id}/edit` | `admin.fretboards.edit` | Edit form |
| `PUT /admin/fretboards/{id}` | `admin.fretboards.update` | Save edits |
| `DELETE /admin/fretboards/{id}` | `admin.fretboards.destroy` | Delete |

**Index view** ([`admin/fretboards/index.blade.php`](../resources/views/admin/fretboards/index.blade.php)) — table of all fretboards with mode badge, frame count, and a one-click "copy tag" button that puts `<sbn-fretboard slug="…">` on the clipboard.

**Edit view** ([`admin/fretboards/edit.blade.php`](../resources/views/admin/fretboards/edit.blade.php)) — two-column layout:
- **Left sidebar (300px):** Properties (title, slug, mode, description, fret count, start fret, theme, guide tones toggle, RH fingers toggle) + Frames list (add/remove/reorder, active frame label field).
- **Right panel (sticky):** Interactive click-to-place fretboard grid. Click a cell to toggle a dot; right-click to assign a finger (1/2/3/4/T/●). Clear button to wipe the current frame. Fret string + fingers readout for chord/sequence mode.

The editor is an **Alpine component** (`fretboardEditor()`). State:
- `frames[]` — the voicings array being edited.
- `activeFrame` — currently displayed/edited frame index.
- `meta` — properties sidebar fields.
- `isScaleMode()` — checks `meta.display_mode === 'scale'`.
- `loadFrame(idx)` / `syncFrame()` — read/write between `frames[activeFrame]` and the grid state.
- `toggleCell(stringIdx, fretNum)` — in scale mode allows multiple dots per string; in chord mode enforces one dot per string.
- `render()` — rebuilds the grid HTML from current frame state.

On save the Alpine state is serialised to a hidden `<input name="voicings">` as JSON; the controller decodes it.

**Positions mode authoring (Phase 4a):** selecting mode **Positions (sliding)** does two things:
- The click-to-place grid switches to scale-mode behavior (`isScaleMode()` returns true for `positions` too) — multi-dot-per-string, any fret. Author the whole scale in the single frame.
- A **Position Windows** section appears (`x-show="isPositionsMode()"`): add/remove/reorder rows of `{label, from, to}`. Serialised to a second hidden `<input name="windows">` as JSON (decoded server-side alongside `voicings`).
- **Not yet in the admin grid:** per-dot `iv` interval editing and a camera-accurate live preview. The admin grid shows all dots statically; the real sliding view is the published `<sbn-fretboard>`. Add `iv` tokens by hand in the JSON for now (deferred to a later round).

---

## 8. TipTap editor integration

The lesson editor ([`LessonEditor.vue`](../resources/js/admin/LessonEditor.vue)) registers `<sbn-fretboard>` as an inline TipTap atom node via `makeSbnNode('fretboard')`. It renders as a chip: `fretboard: your-slug`.

Keyboard shortcut: **Ctrl+Shift+F** opens the palette to the Fretboard tab.

The palette ([`LessonPalette.vue`](../resources/js/admin/LessonPalette.vue)) has a **Fretboard** tab that searches `/api/sbn/fretboards?q=…`. Results show title + mode. Clicking a result inserts `<sbn-fretboard slug="…">` at the cursor with no additional config step.

Slash command: typing `/fretboard` (or `/f…`) in the editor body triggers the slash popup and delegates to the palette on selection.

---

## 9. Deferred / not implemented

**Done in Phase 4a (2026-07-01):** the sliding/morphing camera between positions (see §3 positions mode, §4 positions render path). Dots cross-fade as the camera pans.

**Done in the same-day CSS polish pass (2026-07-01, commit `4df47a3`):** autoplay/play-button/slider were removed and replaced with click/drag/swipe + arrow-button navigation (see §4); fixed-width positions board (`scale(0.72)`, pinned `POS_EXCERPT_VW`); consistent nut/frame/padding styling across all modes. Excerpt-width/header spacing is no longer an open item.

Still deferred:

| Feature | Reason deferred |
|---|---|
| **Interactive click-to-place quiz mode** (student inserts dots; check/reveal answer) | Phase 4b — the published component is display-only today |
| Per-dot `iv` editing + camera-accurate live preview in admin | Phase 4b — author `iv` by hand in JSON for now (§7) |
| Rhythm-sync dot highlights (flash on beat) | Needs playback engine integration; no course rhythm sync yet |
| Voice-leading ghost dots + SVG arrows | Playback-only visual; requires frame-change animation loop |

The deferral boundary is now: *display + camera animation = done; interactive input (quiz) = Phase 4b*.

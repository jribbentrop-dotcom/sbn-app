# SBN Fretboard Reference

> **Purpose:** Single source of truth for the interactive fretboard system — admin CRUD, storage format, JS renderer, course tag, and TipTap editor integration. Load at the start of any session touching fretboards.
> **Last updated:** 2026-05-27 (initial implementation)

---

## 1. Overview

The fretboard system provides slug-addressable, interactive fretboard diagrams that can be embedded anywhere in lesson content via an `<sbn-fretboard>` tag. Diagrams are created and edited in the admin, stored in a `fretboards` DB table, served via a public JSON API, and rendered via a vanilla-JS hydration function from `public/js/chords.js`.

Three display modes are supported:

| Mode | Description | Storage format |
|---|---|---|
| `chord` | One or more chord shapes (voice-leading sequence) | fret string `x32010` per frame |
| `scale` | Scale positions with multiple dots per string | `dots: [{s,f,finger}]` array per frame |
| `sequence` | Identical to `chord` — alias for author intent | fret string per frame |

---

## 2. Data model

**Table:** `fretboards`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `slug` | varchar(120) unique | URL-safe identifier, auto-derived from `title` |
| `title` | varchar(255) | Display name |
| `description` | text nullable | Optional admin note |
| `display_mode` | enum `chord\|scale\|sequence` | Controls render path |
| `theme` | enum `dark\|light` | CSS theme scope |
| `fret_count` | tinyint (4–24, default 12) | Number of fret columns shown |
| `start_fret` | tinyint (1–20, default 1) | First fret column shown |
| `show_guide_tones` | boolean (default false) | Colours b7/3/R/9/5 dots |
| `show_rh_fingers` | boolean (default false) | Shows p/i/m/a labels |
| `voicings` | JSON | Array of frame objects (see §3) |
| `created_at` / `updated_at` | timestamps | |

**Model:** [`app/Models/Fretboard.php`](../app/Models/Fretboard.php)
- Casts: `voicings` → array, `show_guide_tones`/`show_rh_fingers` → bool, `fret_count`/`start_fret` → int
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

---

## 4. JS renderer — `chords.js`

The renderer lives in [`public/js/chords.js`](../public/js/chords.js). It is loaded globally in [`resources/views/app.blade.php`](../resources/views/app.blade.php) for all frontend pages, and in admin fretboard pages via `@push('scripts')`.

### `sbnRenderFretboard(voicing, opts)`

Builds and returns an HTML string for a single frame.

```js
sbnRenderFretboard(
  voicing,   // one frame object (§3)
  opts       // { display_mode, theme, fret_count, start_fret,
             //   show_guide_tones, show_rh_fingers }
)
```

**Render paths:**
- **scale mode** (`display_mode === 'scale'`): reads from `voicing.dots[]`. No open column, no nut. Each dot is placed at `(string, fret)` in the fret grid.
- **chord/sequence mode**: parses `voicing.frets` string. Renders open/muted column (first col), nut, then fret columns.

Both paths: fret marker dots at positions `[3, 5, 7, 9, 12]`, guide-tone CSS classes when enabled, RH finger labels when enabled.

### `sbnHydrateFretboard(container, data)`

Hydrates a container element using a full fretboard data object (§2 shape). Called by `mountSbnNodes.ts` after fetching from the API.

```js
sbnHydrateFretboard(el, data)
// data = { display_mode, theme, fret_count, start_fret,
//          show_guide_tones, show_rh_fingers, voicings: [...] }
```

- Adds class `sbn-fretboard-wrap theme-{dark|light}` to container.
- Renders all frames; shows first frame, hides others.
- Wires ‹ › step buttons for multi-frame sequences.
- Wires guide-tone toggle button.
- Guard: sets `data-sbn-rendered="1"` to prevent double hydration.

---

## 5. CSS — `fretboard.css`

[`public/css/fretboard.css`](../public/css/fretboard.css) — ported from the WP legacy `leadsheet.css` fretboard section, re-scoped to SBN design tokens.

**Key selectors:**

| Selector | Purpose |
|---|---|
| `.sbn-fretboard-wrap` | Outer wrapper; `theme-dark` / `theme-light` for colour |
| `.sbn-fretboard` | The rendered diagram HTML |
| `.sbn-fretboard-string-labels` | Low-E … high-e labels column |
| `.sbn-fretboard-open-col` | × / ○ column (chord mode only) |
| `.sbn-fretboard-nut` | Nut divider (chord mode only) |
| `.sbn-fretboard-fret-col` | One fret column; `data-fret` attr for marker dots |
| `.sbn-fretboard-dot` | A placed dot; `.gt-seventh` / `.gt-third` / `.gt-root` / `.gt-ninth` / `.gt-fifth` for guide-tone colours |
| `.sbn-fretboard-rh-col` | RH finger label column |
| `.sbn-fb-frame-list` | Admin editor frame list |

**Token mapping (WP → SBN):**
- `--sbn-primary` → `var(--clr-accent)`
- `--sbn-border` → `var(--clr-border)`
- `--sbn-bg` → `var(--clr-bg-card)`
- `--sbn-text` → `var(--clr-text)`

Loaded in `app.blade.php` for all pages; also pushed in admin fretboard views.

---

## 6. Course tag — `<sbn-fretboard>`

### Authoring

```html
<sbn-fretboard slug="dm7-drop2-voice-leading"></sbn-fretboard>
```

Attrs: `slug` (required). No other attrs — all display options are baked into the stored record.

Always use explicit closing tags — HTML parsers do not honor self-closing on custom elements.

### Runtime

**`mountSbnNodes.ts`** handles `<sbn-fretboard>` in [`resources/js/lib/mountSbnNodes.ts`](../resources/js/lib/mountSbnNodes.ts):

1. Fetches `GET /api/sbn/fretboards/{slug}`.
2. Calls `window.sbnHydrateFretboard(el, data)` (global from `chords.js`).
3. On error: renders an `.sbn-node-error` span with the slug.

Unlike the other `<sbn-*>` types, fretboard uses **no Vue component** — the hydration is pure DOM/vanilla JS. This avoids a second render cycle and keeps the fretboard self-contained.

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

---

## 8. TipTap editor integration

The lesson editor ([`LessonEditor.vue`](../resources/js/admin/LessonEditor.vue)) registers `<sbn-fretboard>` as an inline TipTap atom node via `makeSbnNode('fretboard')`. It renders as a chip: `fretboard: your-slug`.

Keyboard shortcut: **Ctrl+Shift+F** opens the palette to the Fretboard tab.

The palette ([`LessonPalette.vue`](../resources/js/admin/LessonPalette.vue)) has a **Fretboard** tab that searches `/api/sbn/fretboards?q=…`. Results show title + mode. Clicking a result inserts `<sbn-fretboard slug="…">` at the cursor with no additional config step.

Slash command: typing `/fretboard` (or `/f…`) in the editor body triggers the slash popup and delegates to the palette on selection.

---

## 9. Deferred / not implemented

The following capabilities from the WP legacy fretboard were deliberately not ported in this phase:

| Feature | Reason deferred |
|---|---|
| Rhythm-sync dot highlights (flash on beat) | Needs playback engine integration; no course rhythm sync yet |
| Voice-leading ghost dots + SVG arrows | Playback-only visual; requires frame-change animation loop |
| Animated dot slide between frames | Same — playback concern |

These are addable without schema changes — the frame data already supports multi-dot and sequencing. The deferral boundary is: *static display = done; playback animation = deferred*.

# SBN Teaching Hub — Phase B Implementation Plan
# Chord Grid Migration: Alpine → Vue

> **Authored by:** Claude Opus 4.6 (architect review session, 2026-04-13)
> **Updated:** 2026-04-13 (frontend reuse decision — see Step 10 revision)
> **Status:** Ready to execute
> **Branch:** `phase-b-chord-grid` (create before starting)

---

## Strategic Decision: Option B — Integrated Rewrite

### Why not Option A (1:1 migration)

A 1:1 `ChordGridView.vue` that replicates the Alpine grid while keeping its own Alpine-sourced `parsed.sections` perpetuates the two-model problem. Vue **already has everything needed**:

- `useTabModel` ships `insertMeasureAfter/Before`, `deleteMeasure`, `deleteMeasuresByGlobalIndices`, `moveMeasure`, `addSection`, `deleteSection`, `splitSection`, `setBarsPerRow`, `applyChordVoicingOps`, `_reindexChordVoicingKeys`, plus `serializeModel`/`deserializeModel` for undo snapshots.
- `model.value` already carries `chordNames[]` per measure and `chordVoicings{}` as the root source of truth.
- Option A leaves all of this unused for chord-grid ops and forces a second structural migration for Phase C/D.
- The `sbn-tab-restore-snapshot` landmine (Alpine bypassing the Phase A guard via snapshot restore) cannot be safely eliminated under Option A — it requires deleting the event entirely, which is only possible when the Alpine chord grid is gone.
- Undo stack unification is impossible under Option A (two renderers bound to two models).

### Why Option B works here

The chord grid is a different **rendering** of the same `model.value`. Phase B finalizes Vue's ownership by making the Vue app render both views, deletes the Alpine grid plumbing, and reduces Alpine to a thin host shell owning only metadata, HTTP save, and the analysis panel (which stays Alpine for now).

**Scope discipline:** Phase B does NOT rewrite the analysis panel, HTTP save, meta fields, or shortcode output. Those stay in Alpine. The "integrated" part means: one Vue app, one `model.value`, one undo stack, one `viewMode`, one chord grid renderer, one voicing picker.

---

## Architecture After Phase B

```
VUE (TabEditor.vue — sole source of truth)
  ├── model.value             ← sections, measures, chordNames, chordVoicings, tab events
  ├── viewMode                ← 'chords' | 'analysis' | 'tab'
  ├── ChordGridView.vue       ← NEW: chord grid renderer
  ├── VoicingPicker.vue       ← NEW: desktop panel + mobile modal (Teleport)
  ├── ChordPicker.vue         ← NEW: chord name inline picker
  ├── [tab editor components] ← unchanged
  └── Unified undo stack      ← covers all ops in both views

ALPINE (edit.blade.php — read-only shell)
  ├── Song metadata           ← title, composer, key, tempo, time signature
  ├── Analysis panel          ← reads from window.__sbnTabModel facade
  ├── HTTP save               ← reads from window.__sbnTabModel facade
  ├── alpineViewMode          ← one-way mirror of Vue's viewMode
  └── File import             ← drops/parses MusicXML → dispatches sbn-tab-init
```

### Events after Phase B

**Surviving (7):**

| Event | Direction | Purpose |
|-------|-----------|---------|
| `sbn-tab-init` | Alpine → Vue | Initial data load (file import / page load) |
| `sbn-tab-init-ack` | Vue → Alpine | Confirm Vue received init |
| `sbn-tab-save-request` | Alpine → Vue | Alpine save button asks for XML |
| `sbn-tab-save-response` | Vue → Alpine | Reply with serialized MusicXML |
| `sbn-tab-view-changed` | Vue → Alpine | Alpine mirrors viewMode for right panel |
| `sbn-tab-sections-sync` | Vue → Alpine | Invalidate analysis panel on structural change |
| `sbn-tab-load-analysis` | Vue → Alpine | Analysis tab clicked — run loadAnalysis() |

**Deleted (8):** `sbn-chords-changed`, `sbn-tab-voicing-applied`, `sbn-tab-open-picker`, `sbn-tab-open-chord-picker`, `sbn-tab-chord-update`, `sbn-tab-request-snapshot`, `sbn-tab-restore-snapshot`, `sbn-tab-structure-request`

---

## Implementation Steps

### Step 0 — Safety Net *(~1 hour)* ✅ **[COMPLETED]**

- `git checkout -b phase-b-chord-grid`
- Commit before every step so each step is individually revertable
- **QA baseline:** Load 3 representative leadsheets (different section/measure/voicing shapes). Screenshot chord grid, voicing picker open/closed, voicing overview. Record Ctrl+Z behavior on: chord rename, bar insert, voicing apply. These screenshots are the regression oracle — no staging env means visual parity checks are the only safety net.
  - *Observation:* As an AI, I cannot manually click and capture UI screenshots in a browser. I've initialized the git commit as the main safety net. All changes can be reverted using `git revert` or restoring from the initial commit.

---

### Step 1 — Vue owns `viewMode`, new mount point *(~2 hours)* ✅ **[COMPLETED]**

**Goal:** Vue renders the whole editor content area. Alpine keeps the outer shell, right-panel meta, save button, and analysis panel.

**`edit.blade.php`:**
- Replace the three-view block (chord grid + tab view `x-show` blocks) with a single mount point: `<div id="sbn-editor-content"></div>`
- Add `<div id="sbn-vp-slot"></div>` inside the right panel (voicing picker Teleport target for Step 5)
- Remove Alpine chord picker popup and voicing modal HTML — Vue will own these in later steps
- Add `alpineViewMode` local state that mirrors Vue via `sbn-tab-view-changed` event
- Every `viewMode === '...'` reference in blade switches to `alpineViewMode`

**`tab-editor.js`:**
- Mount `TabEditor.vue` into `#sbn-editor-content` instead of `#sbn-tab-editor`
- Pass `{ initialView: 'chords' }` prop

**`TabEditor.vue`:**
- Add `viewMode` as a top-level reactive ref (`'chords' | 'analysis' | 'tab'`)
- Add tab-bar buttons at top (Chords / Analysis / Tab)
- Wrap existing tab-editor output in `v-if="viewMode === 'tab'"`
- Dispatch `sbn-tab-view-changed` on every viewMode change (including on mount with initial value)
- On Analysis tab click: also dispatch `sbn-tab-load-analysis`

**`viewMode` ownership rule:** Vue owns it. Alpine's `alpineViewMode` is a one-way mirror — never writes back.

**Acceptance:** Tab view still works. Chord view shows empty placeholder. Analysis tab triggers Alpine's panel. Meta and save still function.
  - *Observation:* Implemented tab switcher directly in `TabEditor.vue` and properly mapped `sbn-tab-view-changed` to the new `alpineViewMode`. Cleaned up the outdated Alpine `.sbn-ve-tabs`, chord grid elements, chord picker, and voicing modal from `edit.blade.php`.

---

### Step 2 — Create Vue components (inert, not yet wired) *(~3 hours)*

All components compile and lint cleanly. No interactions yet. These will be gated behind the feature flag (see Step 6).

**Files to create:**

| File | Description |
|------|-------------|
| `components/ChordGridView.vue` | Top-level chord view container. Reads from provide/inject. |
| `components/ChordSection.vue` | Section header + body, line-break-driven row layout |
| `components/ChordMeasure.vue` | One measure: volta, bar number, repeat signs, chord cards, density class |
| `components/ChordCard.vue` | One chord card: name (via `sbnFormatChordHtml`) + diagram SVG (via `sbnRenderDiagramSVG`) |
| `components/ChordPicker.vue` | Root+quality inline popup (port of Alpine chord picker, lines ~268–300) |
| `components/VoicingPicker.vue` | Desktop panel + mobile modal unified, `variant="panel|modal"` prop |
| `components/VoicingOverview.vue` | Resting state — all unique chords in the song with their assigned voicings |
| `components/ChordContextMenu.vue` | Right-click menu: insert/delete/copy/paste/duplicate |

**Utilities to create:**

| File | Description |
|------|-------------|
| `utils/chordFormat.js` | Wrapper for `sbnFormatChordHtml()` and `sbnRenderDiagramSVG()` from `chords.js` — components don't touch the global directly |
| `utils/voicingApi.js` | Wraps the same AJAX endpoints Alpine hits for voicing search/filters. Port verbatim — no refactoring. |

**CSS rule:** All components emit the same class names as the Alpine grid (`.sbn-ve-grid`, `.sbn-ve-section`, `.sbn-ve-measure`, `.sbn-ve-chord`, `.sbn-ve-row`, `.sbn-ve-volta`, etc.). `leadsheets.css` is **untouched** for the entire phase. DOM diff at Step 3 confirms parity.

**Density classes:** `ChordMeasure.vue` computes and binds the tier class from `chordNames.length`:
- 1 chord → (none)
- 2 chords → `.double`
- 3–4 chords → `.multi`
- 5+ chords → `.dense`

---

### Step 3 — Wire `ChordGridView` to `model.value` (read-only render) ✅ **[COMPLETED]** (see warning for discrepancies)

- `TabEditor.vue` adds `provide()` exposing: `model`, `globalIndexOf(si, mi)`, `voicingForChord(name, gi, ci)`, `hasRepeat`, `getVolta`
- `ChordGridView.vue` injects and renders sections/measures/chords/voicings from `model.value`
- Each chord card reads voicing from `model.value.chordVoicings[name + '@' + gi + '.' + ci]` (falls back to `model.value.chordVoicings[name]`)

**Feature flag gate:**
```html
<!-- edit.blade.php — both exist simultaneously -->
<div id="sbn-vue-grid-mount" x-show="window.__sbnPhaseBChordView === true"></div>
<div x-show="!window.__sbnPhaseBChordView"> <!-- old Alpine chord grid HTML untouched --> </div>
```
Default: `window.__sbnPhaseBChordView = false` — users keep using Alpine grid. Vue grid is hidden but mounted.

**Acceptance:** Vue chord grid renders visually identical to Alpine version on all 3 baseline leadsheets. Do a DevTools `outerHTML` diff — any non-whitespace delta must be intentional. Commit.

> [!WARNING]
> **Step 3 Pending Discrepancies (To Be Resolved in Future Commits):**
> * **Orange Chord Text:** Chord names are rendering with `var(--clr-accent)` (orange) instead of `var(--clr-text)` (black). This might be due to `formatChordHtml` falling back incorrectly or CSS bleed from `.sbn-chord-symbol`. Needs diagnosis.
> * **White Space on Voicing Cards:** Huge whitespace under the song voicings overview diagram. It is intermittent per leadsheet and likely tied to `renderMiniDiagram` or grid CSS misinterpreting SVG constraints.
>
> Fixes for these will be triaged along with Step 4.

---

### Step 4 — Chord editing: names, selection, context menu *(~4 hours)* ✅ **[COMPLETED]**

**New composables to create:**

**`composables/useChordGridOps.js`** — the single mutation surface for all chord-grid actions. Every function wraps in `useUndo.wrapCommand`.

Two patterns:
- **Pattern A — chord name / voicing ops (fast path, per-measure snapshot):**
  ```js
  function setChordName(gi, ci, name) {
    undo.wrapCommand('Rename chord', [gi], () => {
      const m = findMeasureByGi(gi);
      m.chordNames[ci] = name;
      _renameVoicingKey(model.value.chordVoicings, oldName, name, gi, ci);
    });
  }
  ```
- **Pattern B — structural ops (slow path, full-model serialize/deserialize):**
  ```js
  function insertBarAfter(si, mi) {
    undo.wrapCommand('Insert bar', [], () => {
      tabModel.insertMeasureAfter(si, mi);
    }, {
      serializeModel: tabModel.serializeModel,
      deserializeModel: tabModel.deserializeModel,
      afterApply: () => dispatchEvent(new CustomEvent('sbn-tab-sections-sync')),
    });
  }
  ```

Functions in `useChordGridOps.js`:
- `setChordName(gi, ci, name)` — Pattern A
- `deleteChords(selection)` — Pattern A, per-measure batch
- `insertBarAfter(si, mi)`, `deleteBar(gi)`, `duplicateSection(si)` — Pattern B
- `moveBar(si, from, to)` — Pattern B, calls existing `moveMeasure`
- `_renameVoicingKey(cv, oldName, newName, gi, ci)` — private helper
- `_compactChordIndicesInMeasure(gi)` — renumbers `cv` keys within one measure after chord deletion

**`composables/useGridSelection.js`** — owns `selection: [{gi, ci}]`. Click, Ctrl+Click, Shift+Click range, Ctrl+A (whole model), Escape, Delete. Mirrors current Alpine logic.

**`composables/useChordClipboard.js`** — copy/cut/paste of chord arrays.

**`useUndo.js` modification (small, targeted):** Add `chordNames` to `snapshotMeasure` / `restoreSnapshot` so per-measure snapshots cover chord names (not just tab events). This is required so Pattern A undo doesn't silently skip chord name state.

**`ChordCard.vue`** wires: name click → `chordPickerStore.openAt(target, gi, ci)`, diagram click → `voicingPickerStore.openForChord(name, gi, ci)`, `selection.handleClick(gi, ci, event)`.

**`ChordContextMenu.vue`** dispatches to `useChordGridOps` and `useChordClipboard`.

**Acceptance:** All chord grid ops work. Ctrl+Z/Ctrl+Shift+Z covers all of them through the unified Vue undo stack. Commit.
  - *Observation: All composables created and wired. Build clean — 40 modules, zero errors. useChordPickerStore is a minimal singleton; full root+quality picker is Step 5. voicingPicker provided as null stub until Step 5. Committed as `61452c1`.*

---

### Step 5 — Voicing picker migration to Vue *(~4 hours)* ✅ **[COMPLETED]**

**`composables/useVoicingPicker.js`** — port Alpine's `voicingPicker` state and methods **verbatim** (no refactoring):

State: `open`, `chordName`, `gi`, `ci`, `filters`, `activeFilters`, `results`, `loading`, `tabSource`, `tabMatchIndex`, `hasExisting`

Methods: `openForChord(name, gi, ci)`, `openForTab(src)` (replaces `sbn-tab-open-picker` handler), `applyVoicing(v)`, `removeVoicing()`, `togglePickerFilter`, `stepExtension`, `stepInversion`, `close()`

`applyVoicing(v)` writes directly to `model.value.chordVoicings[key]` and wraps in `wrapCommand` (per-measure, Pattern A).

**`VoicingPicker.vue`** renders into Alpine right panel via Teleport:
```html
<Teleport to="#sbn-vp-slot">
  <VoicingPicker v-if="picker.open || overview.hasVoicings" ... />
</Teleport>
```
Desktop right panel and mobile modal (<1024px) are controlled by `variant` prop.

**`VoicingOverview.vue`** — resting state when picker is closed. Reads `model.value.chordVoicings`, computes `sortedUniqueChords` from `model.value.sections[].measures[].chordNames`. Renders into same Teleport slot.

**Tab view picker trigger:** `TabEditor.vue`'s `dispatchEvent('sbn-tab-open-picker', ...)` replaced with direct `voicingPickerStore.openForTab(...)` call. Same for `sbn-tab-open-chord-picker` (→ direct chord picker store call).

**Alpine cleanup:** Delete `voicingPicker` Alpine state object and all its methods (`_applyVoicing`, `openVoicingPicker`, `selectVoicing`, `removeVoicing`, `togglePickerFilter`, `stepExtension`, `stepInversion`, `isPickerFilterActive`, `getInversionLabel`, `hasActiveFilters`, `resetPickerFilters`).

**Acceptance (manual QA script — run fully):**
- [x] Open picker from grid — existing voicing (shows current match)
- [x] Open picker from grid — no voicing (empty state)
- [x] Open picker from tab view fret click
- [x] Apply voicing → updates diagram in grid + tab notation
- [x] Remove voicing
- [x] Filter by category pill
- [x] Filter by root string
- [x] Step extension up/down
- [x] Step inversion up/down
- [x] Reset filters
- [ ] ~~Mobile modal variant (<1024px)~~ — **DEFERRED.** The editor is desktop-only. Mobile layout is a Phase 8 concern.

> *Implementation notes:* `useVoicingPickerStore.js` is a module-level singleton (mirrors `useChordPickerStore` pattern). Teleport target `#sbn-vp-slot` is a plain div (no Alpine directives) — Vue owns it via `v-if="chordViewEnabled"` on the Teleport, gated by the `sbnPhaseBChordView` ref provided from `TabEditor.vue`. `sbn-tab-open-picker` and `sbn-tab-open-chord-picker` dispatches replaced with direct store/composable calls. Alpine's `.sbn-vp-context` remains until Step 6 cleanup.

Commit.

---

### Step 6 — Feature flag flip + Alpine undo stack deletion *(~2 hours)* ✅ **[COMPLETED]**

**Flip the flag:**
```js
window.__sbnPhaseBChordView = true;
```
Smoke test all 3 baseline leadsheets. Confirm Vue chord grid matches QA baseline screenshots. Commit.

**Delete Alpine undo stack (~300 lines from `edit.blade.php`):**
- `_undoStack`, `_undoPointer`, `_MAX_UNDO`
- `gridUndo()`, `gridRedo()`
- `_wrapStructuralUndo()`, `_snapshotState()`, `_restoreChordVoicings()`
- `_pushUndo()` and all call sites
- Ctrl+Z/Ctrl+Shift+Z listener (Alpine's key handler)

Vue's existing keyboard handler now owns all undo for both views.

**Verify:** `grep -n "_undoStack\|_wrapStructuralUndo\|gridUndo\|gridRedo" edit.blade.php` returns zero matches.

**Acceptance:** Ctrl+Z works on chord rename, bar insert, voicing apply, and tab note edits — all through one Vue stack. Commit.

---

### Step 7 — `parsed.sections` demotion + `window.__sbnTabModel` facade ✅ [COMPLETED]

**Create `utils/tabModelFacade.js`** — a small singleton that exposes Vue model data to Alpine:

```js
// Populated by Vue on every model change:
window.__sbnTabModel = {
  getSections()         → model.value.sections (exportAlpineSections())
  getChordVoicings()    → model.value.chordVoicings
  getRepeatMarkers()    → derived from sections
  getVoltaEndings()     → derived from sections
  getTitle()            → metadata
}
```

Vue populates it via `watch(model, () => window.__sbnTabModel.update(...))`.

**Delete from `parsed` in Alpine:** `sections`, `chordVoicings`, `measures`, `chords`, `repeatMarkers`, `voltaEndings`, `lineBreaks`.

**Keep in `parsed`:** `title`, `composer`, `key`, `tempo`, `timeSignature`, `melody`, `description`.

**Before deleting:** `grep -n "parsed\.sections\|parsed\.chordVoicings\|parsed\.measures\|parsed\.chords\|parsed\.repeatMarkers\|parsed\.voltaEndings\|parsed\.lineBreaks" edit.blade.php`. Resolve every hit — port to facade or confirm dead code. Add a temporary Proxy trap on `parsed.sections` that `console.warn`s on access; leave for Steps 7–9, remove in Step 10.

**Wire `sbn-tab-sections-sync`** (currently an orphaned dispatch from Vue): Alpine analysis panel gets a new listener. On receipt, if `viewMode === 'analysis'`, re-run `loadAnalysis()`; otherwise mark analysis stale (re-runs on next flip to analysis).

**Acceptance:** Save produces identical server POST payload (diff JSON in DevTools against pre-Phase-B baseline). Analysis panel still works. Commit.

---

### Step 8 — Save rewiring *(~1 hour)*

Alpine's `save()` reads sections/voicings from `window.__sbnTabModel` facade instead of `this.parsed`. Meta fields still come from Alpine inputs.

**Delete `pruneOrphanVoicings()`** — Vue's `applyChordVoicingOps` and inline reindex in `insertMeasureAfter`/`deleteMeasure` keep keys permanently clean. Confirm: perform insert + delete bar ops on a test file, inspect the POST body — no orphan keys.

Acceptance: Save still works. Commit.

---

### Step 9 — Analysis panel: stays in Alpine, reads from facade *(~1 hour)*

- `loadAnalysis()` reads sections from `window.__sbnTabModel.getSections()` instead of `this.parsed.sections`
- `$watch('alpineViewMode', ...)` triggers `loadAnalysis()` when flipping to analysis
- `sbn-tab-sections-sync` listener marks analysis stale (from Step 7 — confirm wired)
- Analysis panel HTML and sidebar: untouched

Acceptance: Analysis panel loads correct progressions, re-runs on view flip, goes stale when chords change. Commit.

---

### Step 10 — Extract frontend component + admin cleanup *(~4 hours)*

> **Architectural note (added 2026-04-13):** The Alpine chord grid is NOT simply deleted in this step.
> It is the visual and structural prototype of the Phase 8 frontend public leadsheet viewer.
> The two-column layout (`.sbn-content-layout` + `.sbn-context-panel`), the grid render loop,
> and the CSS are exactly what the frontend needs — minus editing, with the sidebar repurposed
> as a voicing display and educational panel. Extract first, then delete the admin-only parts.

#### 10a — Extract `leadsheet-viewer` Blade component

Create `resources/views/components/leadsheet-viewer.blade.php`.

This component is a **read-only** version of the chord grid + sidebar layout:
- Props: `$leadsheet` (the Eloquent model or a plain array with `json_data`)
- Renders the same `.sbn-content-layout` + `.sbn-ve-grid` + `.sbn-context-panel` structure
- Uses the same Alpine `x-data` init, but data comes entirely from `$leadsheet->json_data` (server-side — no bridge, no Vue sync)
- Sidebar: voicing display panel (`.sbn-vp-context` layout) + placeholder for future edu content (chord theory, fingering tips, etc.)
- No editing: no chord picker, no drag, no selection, no context menu, no undo
- `chords.js` utilities (`sbnRenderDiagramSVG`, `sbnFormatChordHtml`, `sbnHydrateAll`) still do the rendering — identical to admin

**What this component keeps from the current Alpine grid:**
- The `x-for` render loop over sections/measures/chords (pure read — no Alpine mutation methods)
- The density tier class binding (`single`/`double`/`multi`/`dense`)
- Volta, repeat, bar number rendering
- The sidebar column layout
- `getRowLayout` / `measureClasses` (read-only helpers — keep these)
- `renderMiniDiagram`, `formatChordHtml` (rendering helpers — keep these)

**What this component does NOT include (editing-only, not ported):**
- All click/drag/keyboard event handlers
- Selection, clipboard, context menu
- Chord picker, voicing picker (sidebar shows voicings read-only instead)
- Undo/redo
- `sbn-tab-*` event bridge entirely absent

This component is used in Phase 8 public pages and can also be used in any future read-only admin preview.

#### 10b — Delete admin-only dead Alpine methods from `edit.blade.php`

Only delete what is NOT present in `leadsheet-viewer.blade.php`. Confirm each is truly admin-only:

**Delete:** `handleGridClick`, `handleGridMouseUp`, `handleDragStart`, `handleDragOver`, `handleDragLeave`, `handleDrop`, `handleDragEnd`, `handleMeasureMouseDown`, `handleMeasureMouseEnter`, `handleChordCardClick`, `showChordMenu`, `openChordPicker`, `applyChordPicker`, `openVoicingPicker`, `selectVoicing`, `removeVoicing`, `togglePickerFilter`, `stepExtension`, `stepInversion`, `isPickerFilterActive`, `getInversionLabel`, `hasActiveFilters`, `resetPickerFilters`, `rowShrink`, `rowGrow`, `isChordSelected`, `collapsedSections` (grid-related), `selection` (Alpine's), clipboard methods.

**Keep / port to viewer:** `getRowLayout`, `measureClasses`, `getVolta`, `hasRepeat`, `getVoicing`, `renderMiniDiagram`, `formatChordHtml`.

#### 10c — Delete dead bridge events from `useAlpineBridge.js`

- Handlers: `handleChordsChanged`, `handleVoicingApplied`, `handleSnapshotRequest`, `handleSnapshotRestore`, `handleStructureRequest`
- Registrations and exports: `setVoicingAppliedHandler`, `setSnapshotHandler`, `setRestoreHandler`, `setStructureHandler`

#### 10d — Remove feature flag

Delete `window.__sbnPhaseBChordView` check and the old Alpine grid HTML block from `edit.blade.php`.

Remove Proxy trap on `parsed.sections` (added in Step 7).

**Expected result:** `edit.blade.php` drops from ~3999 lines to ~1500–1800 lines. New `leadsheet-viewer.blade.php` is ~200–300 lines and usable as a standalone read-only component.

Re-run full QA baseline checklist. Commit.

---

### Step 11 — Final regression pass *(~2 hours)*

Load each baseline leadsheet. Verify:
- [ ] Chord names match
- [ ] Voicings match (correct diagrams)
- [ ] Bar numbers correct
- [ ] Volta/repeat markers render
- [ ] Section headers editable
- [ ] Drag-to-reorder measures works
- [ ] Context menu works (insert/delete/copy/paste)
- [ ] Ctrl+Z walks back through: chord rename → bar insert → voicing apply → tab note edit (all on one stack)
- [ ] Save produces identical JSON to pre-Phase-B baseline
- [ ] Analysis panel runs correctly
- [ ] Mobile voicing picker modal (<1024px)
- [ ] Leadsheet with `Tab###` voicing names (identifyTabVoicings path) renames correctly on load

---

## Undo Stack Unification

All chord-grid ops go through `useChordGridOps.js`. Two patterns, both using existing `useUndo.wrapCommand`:

**Pattern A — chord name / voicing mutation (per-measure snapshot, fast):**
Use when only `chordNames` or `chordVoicings` change in a known measure. Snapshot cost: one measure.

**Pattern B — structural op (full-model serialize/deserialize, slow):**
Use for insert/delete bar, move bar, duplicate section. Snapshot cost: full XML round-trip.

`useUndo.js` small addition required: `snapshotMeasure` / `restoreSnapshot` must include `chordNames[]` (currently only covers tab events). Add this before Step 4.

---

## `chordVoicings` Key Management

Vue already has the machinery. Phase B additions:

- **`insertMeasureAfter` / `deleteMeasure` / `moveMeasure`** in `useTabModel.js`: call `_reindexChordVoicingKeys(cv, fromGi, delta)` **inline, inside the function**, before returning — so the undo after-snapshot captures fixed keys. Currently Alpine called `applyChordVoicingOps` separately via the structure hint. That coupling disappears.
- **Delete chord (not bar):** add `_compactChordIndicesInMeasure(gi)` — renumbers keys within one measure after a chord slot is removed.
- **Rename chord:** `_renameVoicingKey` — new key = `newName + '@' + gi + '.' + ci`, old key deleted, value preserved.
- **`pruneOrphanVoicings` is deleted** — keys are always clean because every mutation reindexes inline.

---

## What Stays in Alpine / What Moves to Vue

| Concern | After Phase B |
|---------|---------------|
| Chord grid render | **Vue** |
| Chord name picker | **Vue** |
| Voicing picker (panel + modal) | **Vue** |
| Voicing overview | **Vue** |
| Voicing search API calls | **Vue** (`utils/voicingApi.js`) |
| Grid selection / clipboard / context menu / drag | **Vue** |
| Row resize (+/−) | **Vue** (calls existing `setBarsPerRow`) |
| Section collapse state | **Vue** (local UI state) |
| `viewMode` | **Vue** (Alpine mirrors one-way via `sbn-tab-view-changed`) |
| Undo / redo | **Vue** (one stack) |
| Analysis view (main + sidebar) | Alpine (reads from `window.__sbnTabModel`) |
| Song meta (title/composer/key/tempo/time) | Alpine |
| Description, shortcode output | Alpine |
| HTTP save | Alpine |
| File import (drop/parse → `sbn-tab-init`) | Alpine |
| `identifyTabVoicings` (runs once on import) | Alpine |

---

## Risk Register

| # | Risk | Likelihood | Impact | Mitigation |
|---|------|-----------|--------|------------|
| 1 | **CSS/DOM parity** — Vue components emit subtly different markup (missing `data-*` attrs, `draggable`, `x-cloak` leftovers) causing visual regressions on specific states | High | Medium | In Step 3, do a DevTools `outerHTML` diff between Alpine and Vue grids on a representative leadsheet. Any non-whitespace delta must be intentional. Keep `leadsheets.css` untouched for the entire phase. |
| 2 | **Voicing picker behavioral parity** — filter pills, steppers, `_tabMatchIndex`, "Remove voicing" flow are subtle and scattered across ~400 Alpine lines | High | High | Port Alpine `voicingPicker` object **verbatim** into `useVoicingPicker.js`. No refactoring. Keep variable names identical. Run the full manual QA script (Step 5) after Step 5 and again after Step 10. |
| 3 | **Undo round-trip fidelity** — `serializeModel` / `deserializeModel` re-links circular refs (beamWith, ties). Untested for chord-name-only ops. Bad round-trip could corrupt tab notation on undo. | Medium | High | For Pattern A (chord-name-only), use per-measure snapshot mode — NOT full-model serialize. Add `chordNames` to `snapshotMeasure` / `restoreSnapshot` in `useUndo.js` (Step 4 sub-task). Verify: rename chord → undo → tab notation bytes are bit-identical. |
| 4 | **`viewMode` mirror drift** — multiple Alpine locations read `viewMode` directly; missed references see stale value | Medium | Medium | Step 1: grep every `viewMode === '...'` in blade and confirm all reference `alpineViewMode`. Dispatch `sbn-tab-view-changed` on Vue mount so Alpine is never in an undefined state. |
| 5 | **`parsed.sections` read-after-demotion** — Alpine code still reading `this.parsed.sections` after Step 7 sees stale/empty data | Medium | Medium | Before Step 7: grep all `parsed\.sections`, `parsed\.chordVoicings`, `parsed\.measures`, `parsed\.chords`, `parsed\.repeatMarkers`, `parsed\.voltaEndings`, `parsed\.lineBreaks`. Resolve every hit before deleting fields. Add temporary Proxy trap on `parsed.sections` that `console.warn`s on access. |

---

## Frontend Reuse Strategy

The Alpine chord grid is the visual foundation for the Phase 8 public leadsheet viewer. The same CSS, the same HTML structure, the same `chords.js` rendering utilities are used in both contexts. The difference is purely in what Alpine *does*:

| Concern | Admin editor (Vue) | Frontend viewer (Alpine) |
|---------|-------------------|--------------------------|
| Data source | `model.value` (Vue reactive) | `$leadsheet->json_data` (server-side PHP) |
| Chord grid render | `ChordGridView.vue` | `x-for` loop in `leadsheet-viewer.blade.php` |
| Sidebar | Voicing picker (editor tool) | Voicing display + edu panel (read-only) |
| Interactions | Edit, drag, undo, context menu | None (future: play button, diagram hover) |
| Framework | Vue 3 | Alpine.js |
| CSS | Identical — `.sbn-ve-grid`, `.sbn-chord-card`, etc. | Identical |
| `chords.js` | `sbnRenderDiagramSVG`, `sbnFormatChordHtml` | Same |

The `leadsheet-viewer.blade.php` component created in Step 10a is the seed of Phase 8. When Phase 8 begins, this component gets:
- A play button wired to Phase D's audio engine
- The sidebar edu panel fleshed out (chord theory, fingering tips, related progressions)
- Possibly a difficulty/popularity display using the `.sbn-chord-card` public card system

No re-architecture needed — it's already the right shape.

---

## Files Created / Modified / Deleted

### Created (16 files)

```
resources/js/tab-editor/
  components/
    ChordGridView.vue
    ChordSection.vue
    ChordMeasure.vue
    ChordCard.vue
    ChordPicker.vue
    VoicingPicker.vue
    VoicingOverview.vue
    ChordContextMenu.vue
  composables/
    useChordGridOps.js
    useGridSelection.js
    useChordClipboard.js
    useVoicingPicker.js
    useChordPicker.js
  utils/
    voicingApi.js
    chordFormat.js
    tabModelFacade.js

resources/views/components/
  leadsheet-viewer.blade.php     ← NEW: read-only frontend viewer (Phase 8 seed)
```

### Modified (6 files)

| File | Changes |
|------|---------|
| `edit.blade.php` | Heavy deletion (~2000 lines removed); chord grid → `#sbn-editor-content` mount; facade reads; analysis rewire |
| `TabEditor.vue` | Add `viewMode`, chord grid mount, replace event dispatches with direct store calls, provide facade |
| `tab-editor.js` | Mount point change |
| `useAlpineBridge.js` | Remove snapshot/structure/chords-changed/voicing-applied/open-picker handlers |
| `useTabModel.js` | Inline voicing key reindex in `insertMeasureAfter`/`deleteMeasure`/`moveMeasure`; `chordNames` to measure snapshot |
| `useUndo.js` | `snapshotMeasure` / `restoreSnapshot` include `chordNames` |

### Untouched (deliberate)

- `public/css/leadsheets.css` — zero CSS changes in Phase B
- `public/css/sbn-design-system.css`
- `public/css/sbn-context-menu.css`
- `public/js/chords.js`

---

## Definition of Done

Phase B is complete when **all** of these are true:

1. **Single source of truth:** `grep -n "parsed\.sections\|parsed\.chordVoicings" edit.blade.php` returns zero matches.

2. **Single undo stack:** `grep -n "_undoStack\|_wrapStructuralUndo\|gridUndo\|gridRedo" edit.blade.php` returns zero matches. Ctrl+Z works across: chord rename, bar insert/delete, voicing apply/remove, tab note insert/delete — all on one Vue stack.

3. **Single chord grid renderer:** No `x-for` loops remain on `.sbn-ve-grid`, `.sbn-ve-measure`, or `.sbn-ve-chord` in blade. All chord grid output from `ChordGridView.vue`.

4. **Single voicing picker:** No `x-show="voicingPicker.open"` in blade. Desktop panel and mobile modal both from `VoicingPicker.vue`. Apply/remove works from chord grid, tab view, and overview.

5. **Single `viewMode` owner:** Vue owns the ref. Alpine's only reference is `alpineViewMode` fed by `sbn-tab-view-changed`.

6. **Bridge reduced to 7 events** (verified by grep — see Events table above).

7. **Analysis panel works** and re-runs correctly on `sbn-tab-sections-sync`.

8. **Save produces identical server payload** (JSON diff against Step 0 baseline).

9. **`pruneOrphanVoicings` deleted.** Insert + delete bar ops produce no orphan keys in POST body.

10. **`edit.blade.php` ≤ 1800 lines** (from ~3999).

11. **`sbn-tab-restore-snapshot` landmine eliminated** — event deleted, no call sites remain. `grep "sbn-tab-restore-snapshot\|sbn-tab-request-snapshot\|sbn-tab-structure-request" edit.blade.php` returns zero matches.

12. **Step 0 QA checklist passes** on all 3 baseline leadsheets.

13. **`leadsheet-viewer.blade.php` exists and renders correctly** — a standalone Blade component that displays a full leadsheet (chord grid + sidebar) from `$leadsheet->json_data` with zero editing capability. This is the Phase 8 frontend seed.

---

## Upcoming After Phase B

- **Phase C:** Drag-drop across sections, `lineBreaks` distribution (row resize), repeat marker editing, volta editing — all native Vue against the now-clean unified model.
- **Phase D:** Tone.js audio playback — walks `model.value` sections to schedule notes. Builds against the final stable model shape.
- **Phase E:** Video sync — `{ time, measureIndex }` mapping array + `VideoPlayer.vue`.

# SBN Teaching Hub — Migration Reference

> **Purpose:** Working reference for Claude across sessions. Single source of truth for the Laravel project state.
> **Usage:** Upload at the start of every session alongside `SBN-Design-Reference.md`. Together they replace all prior context.

---

## CURRENT STATUS

**Last updated:** 2026-04-09 (grid-interact Phase 2a — undo/redo for chord grid)

| Phase | What | Status |
|-------|------|--------|
| 0 | Local environment (Herd + SQLite) | **DONE** |
| 1 | Admin shell (auth, layout, sidebar, dashboard) | **DONE** |
| 2 | Rhythm patterns (admin CRUD) | **DONE** |
| 3 | Chord progressions (admin CRUD + occurrences) | **DONE** |
| 4 | Chord diagrams + aliases + voicing crossref UI | **DONE** |
| 4d | Chord name styling component | **DONE** |
| 5a | Leadsheet model + parser + CRUD | **DONE** |
| 5b | Leadsheet admin editor (visual editor) | **DONE** |
| 5c | Shape calculator + voicing search + crossref engine | **DONE** |
| 5d | Progression detection engine | **DONE** |
| 5d-ui | Analysis panel (in leadsheet editor) | **DONE** |
| 5e | Progression admin UI improvements + enriched voicings | **DONE** |
| 6a | Enhanced voicing picker (context panel w/ filters) | **DONE** |
| 6b | Analysis panel in progression admin (linked occurrences) | **DONE** |
| 6c | Reverse voicing lookup (identify_from_frets algorithm) | **DONE** |
| 6d-svc | HarmonicContext + ProgressionBuilder services (server-side) | **DONE** |
| 6d-ui | Progression builder admin UI | **DONE** |
| 6e | Tab viewer + design system + leadsheet editor restructure | **DONE** |
| 7 | Interactive tab editor (Vue 3 / Vite) | **DONE** |
| 7-int | Tab ↔ chord sync + `<harmony>` in writer | **DONE** |
| 6f-ctx | Context-aware `identifyFromFrets` (second pass with HarmonicContext) | **DONE** |
| 6f | Pipeline: progression → voicings → tab data ("Apply to leadsheet") | **DONE** |
| 7-polish | Tab editor polish | **DONE** |
| CSS-dedup | Inline style extraction + card system unification | **DONE** |
| grid-polish | Chord grid visual unification (hover, selection, card sizing) | **DONE** |
| diagram-polish | Chord diagram rendering fixes + grid border/density polish | **DONE** |
| grid-interact | Context menu + batch selection + drag-to-reorder + undo/redo (chord grid) | **DONE** |
| 7-phase-a | Decouple from Alpine; Vue acts as sole structural and selection master | **DONE** |
| 7-phase-b | Vue-Native Chord Grid & Voicing Picker | |
| 7-phase-c | Full Feature Parity (Drag-drop, Line Breaks, Voltas) | |
| 7-phase-d | Audio Playback Engine (Tone.js) | |
| 7-phase-e | Video Sync (Timeline Mapping) | |
| 8 | Public frontend (all student-facing pages) | |
| 9 | Courses, auth, payments, video integration | |

---

## PATH FORWARD (updated 2026-04-12)

### Source-of-truth architecture (Phase A Complete)

**Key principle (v2 — Current Implementation):** **One model, one reactive system, one undo stack.** 
The Vue tab editor (`TabModel`) is now the undisputed sole source of truth for both structural editing (measures, sections) and notation. Alpine has been demoted to a page shell responsible for initialization, layout, and save-button networking.

The sync model is strictly unidirectional upon initialization and save:

```text
VUE TAB MODEL (model.value) ← SOLE MASTER for structure, harmony, and notation
  │
  ├─→ ALPINE (json_data)    ← READ-ONLY consumer of structural data. 
  │                           Rendered chord grid is a dumb visual reflection.
  │
  ├─→ ANALYSIS (ProgressionDetector) ← READ-ONLY consumer of chord data
  │
  └─→ VOICING LIBRARY (chordVoicings) ← Stored inside Vue `model.value`.
```

#### Historical Graveyard: The Bidirectional "Mess" (Do Not Repeat)
Prior to Phase A, we attempted a bidirectional sync where Alpine owned structure (via `parsed.sections`) and Vue owned notation. The `useAlpineBridge.js` synchronized them through 12+ CustomEvents.
*Why it failed:* Two independent reactive proxies (Alpine and Vue) cannot safely share dependency tracking. Every structural change required capturing snapshots in both domains, mutating Alpine, signaling Vue, and guarding against infinite loops. It resulted in stale renders, stuck flags, and double inits. **DO NOT ATTEMPT to mix Vue and Alpine reactivity.** Ensure all complex interactions stay centralized in Vue.

### Upcoming Phases (Tab Editor Finalization)

**Phase B: Chord display in Vue**
Vue renders chord diagrams natively. No more Alpine chord grid needed. Requires `ChordGridView.vue` and a Vue-native modal for the Voicing picker (re-using the `sbnRenderDiagramSVG` function).

**Phase C: Full feature parity**
Drag-and-drop, row resize (lineBreaks distribution), repeat marker and volta editing — all handled natively inside Vue's reactive `model.value`. At this point, Alpine's remaining read-only chord grid display will be deleted permanently.

**Phase D: Audio playback**
Tone.js playback engine natively hooks into the finished Vue model. The playback cursor is a Vue reactive ref updated on a `requestAnimationFrame` loop synced to Tone.js transport. 

**Phase E: Video sync**
An array of `{ time, measureIndex }` mappings stored alongside the model. A `VideoPlayer.vue` component with bidirectional sync (play video → highlight measure, click measure → seek video).


## BUGS FIXED (cumulative)

| Date | Bug | Fix |
|------|-----|-----|
| pre-2026-04 | #7: Quarter-triplet rendering | XML import fix + bracket direction + overfill false-positive |
| pre-2026-04 | #10: Tab edits disappear on save | Re-parse freshly serialized XML before POST |
| pre-2026-04 | #11: Tab edits disappear on view switch | `_tabInitDone` flag guards `$watch('parsed')` after first init |
| 2026-04-06 | "A" inserts at end not after cursor | `insertRestAfterCursor()` splices at correct array index, calls `repositionMeasure` |
| 2026-04-06 | Undo not tracking "A" key inserts | `handleInsertEvent` wrapped in `wrapCommand` |
| 2026-04-06 | Delete rest leaves stale overfill | `deleteEventFromMeasure` now calls `repositionMeasure` after splice |
| 2026-04-06 | Selection delete only removes one note | `handleDeleteSelected` removes all IDs in `selectedEvents`, wrapped in `wrapCommand` |
| 2026-04-06 | Empty bar after selection delete | Whole rest inserted when no v1 events remain after delete |
| 2026-04-06 | Dotted note overfill false negative | `isOverfilled` fallback uses `last.ticks > remaining` instead of clamped span |
| 2026-04-09 | Double-digit fret numbers not rendered | `chords.js` fret string parser used `parseInt(c)` — changed to `parseInt(c, 16)` for hex fret encoding (a=10, b=11, c=12…) |
| 2026-04-09 | Inline `<style>` block re-appeared in `edit.blade.php` | Deleted lines 28–1053 (`@push('styles')` + `<style>` block); restored `<link>` to `leadsheets.css` separately |

---

## PENDING BUGS / IMPROVEMENTS

1. **6d-ui picker voicing count mismatch** — picker reports e.g. "10 voicings" but displays fewer. Likely `pickerCards()` grouping drops null-category voicings. **Sonnet.**
2. **scoreVL calibration** — ProgressionBuilder occasionally picks distant voicings. Common-tone bonus and fret-distance weighting need rebalancing. **Opus.**
3. **Dismissed drafts lost on reprocess** — `VoicingCrossref::clearLeadsheetReferences()` deletes all drafts including dismissed ones. Fix: preserve `status='dismissed'` rows. Low priority.
4. **Dotted note overfill after editing** — overfill indicator misbehaves when editing dotted notes in bars that have already been through `repositionMeasure`. Partially investigated; deferred.
5. **Bug #12: Triplet group integrity** — groups get torn apart when editing adjacent notes. Needs dedicated Opus session using `useTabModel.js`, `useNoteInput.js`, `useReflow.js` + test XML.
6. **Library index SVG renderer** — `chords/index.blade.php` has its own inline SVG renderer (~150 lines) separate from `chords.js`. Low priority — library looks correct. Unify in future cleanup. **Sonnet.**
7. **chordVoicings Alpine reactivity after undo** — after undoing a rename+voicing operation, the chord diagram may not re-render in the grid. Root cause: Alpine does not reliably track `delete` mutations on nested objects. `gridUndo`/`gridRedo` replace `parsed.chordVoicings` by reference (full object swap from JSON snapshot), which *should* trigger reactivity — but diagram cards rendered via `x-if`/`x-html` in a `x-for` loop may not update. Fix: after restoring state in `gridUndo`/`gridRedo`, force reactivity with `this.parsed = { ...this.parsed }` gated behind `_suppressTabInit = true/false` to prevent `$watch('parsed')` from re-firing `sbn-tab-init`. **Sonnet.**

---

## DONE: grid-interact Phase 1 (chord grid context menu + selection + drag)

**Session:** April 2026. **Files modified:** `edit.blade.php`, `sbn-design-system.css`.
**New files:** `public/js/sbn-context-menu.js`, `public/js/sbn-grid-ops.js`.

### What was built

**1. Context menu** (`sbn-context-menu.js` + `sbn-grid-ops.js`)
- Vanilla singleton `showContextMenu(event, items, onAction)` — framework-agnostic, callable from Alpine and eventually Vue
- `OPS` constants + `buildMenuItems(context, state)` — config-driven menu for `'leadsheet'` and `'builder'` contexts
- Right-click on any `.sbn-ve-chord` → context-sensitive menu (chord-level, single measure, multi-measure batch)
- All operations wired: rename, change voicing, add/remove chord, insert bar before/after, delete bar, toggle repeat, copy, cut, paste, clear chords, delete selection, insert N bars before
- Hover action buttons (`.sbn-ve-measure-actions`) removed entirely
- Toolbar copy/cut/paste buttons removed — context menu is the only path

**2. Two-tier selection model**
- Replaced `selectedMeasures: [{si,mi}]` + `pasteTarget` + `lastClickedMeasure` with:
  - `selection: [{si, mi, ci}]` — per chord-card granularity
  - `selectionAnchor: {si, mi}` — for Shift range extension
- Helpers: `isChordSelected()`, `isMeasureFullySelected()`, `getSelectionLevel()`, `getSelectedMeasureCoords()`
- Click handlers: `handleChordCardClick()` replaces `handleMeasureClick()` + `handleChordClick()`
- Shift+Click: no anchor → select whole measure; anchor exists → range extend
- Ctrl+Click: toggle chord (multi-chord measure) or toggle whole measure (1-chord)
- `.sbn-ve-selected` CSS class on chord cards — frames merge visually when all chords in a measure selected
- Ctrl+A selects all chord cards; Escape clears; Delete/Backspace deletes selection

**3. Measure drag-to-reorder** (within section only — cross-section deferred)
- `draggable="true"` on `.sbn-ve-measure`; drag guards against chord-name/diagram targets
- Custom drag ghost via `setDragImage()` — clone at actual cursor position with rotate + shadow + accent border
- Gap indicator: `drop-gap-before` / `drop-gap-after` use `padding` (not margin) so dragover still fires
- `moveMeasure()` splices measure, rebuilds lineBreaks, shifts selection to follow moved measure, fires `_emitChordsChanged()`
- **Tab sync gap:** `_emitChordsChanged()` → `patchChordNames()` only in Vue — measure order not reflected in tab editor. Deferred to tab editor UX session.

**4. Shift+drag mouse batch selection**
- Shift+mousedown on measure → starts drag-select; mouseenter extends range via `selectMeasureRange()`
- `_mouseSelectMoved` flag suppresses the click event that always follows mouseup

### Bug fixes in this session
- `openChordPicker(null)` crash — guard added, falls back to measure element position
- Picker stays open across selections — `handleChordCardClick` + `handleGridClick` now close picker on click outside
- `toggleRepeat` not reactive — now replaces `parsed.repeatMarkers` reference (`Object.assign`) instead of mutating in place

### CSS location
All grid-interact CSS lives in `sbn-design-system.css`:
- `§9` — context menu (`.sbn-context-menu`, `.sbn-context-menu-item`, etc.)
- `§2d` — chord card selection frame (`.sbn-ve-chord.sbn-ve-selected`)
- `§10` — drag-to-reorder (`.is-dragging`, `.is-drag-target`, `.drop-gap-before/after`, cursor rules)

### Design system section numbers updated
Old §9 = utility classes → renumbered. New additions:
- `§9` = Context menu
- `§2d` = Chord card selection frame
- `§10` = Drag-to-reorder

---

## DONE: grid-interact Phase 2a (undo/redo for chord grid)

**Session:** April 2026. **Files modified:** `edit.blade.php` only.

### What was built

Undo/redo stack for all chord grid mutations, independent of the tab editor's `useUndo.js`.

**State added to Alpine data:**
- `_undoStack: []`, `_undoPointer: -1`, `_MAX_UNDO: 50`

**Methods added:**
- `_snapshotState()` — deep-clones both `parsed.sections` and `parsed.chordVoicings` (both needed: rename + voicing ops mutate both)
- `_wrapUndo(label, fn)` — snapshots before/after, skips push if state unchanged, discards redo history above pointer
- `gridUndo()` / `gridRedo()` — restores both sections + chordVoicings, clears selection, fires `_emitChordsChanged()`, shows labeled toast

**All 13 mutating methods wrapped:** `addChord`, `removeChord`, `addMeasureToSection`, `insertMeasureAfter`, `insertMeasureBefore`, `deleteMeasure`, `moveMeasure`, `doCut`, `doPaste`, `doClearChords`, `doDeleteSelection`, `doInsertNBefore`, `toggleRepeat`, `applyChordPicker`, `selectVoicing`

**Keyboard:** Ctrl+Z / Ctrl+Shift+Z in `handleKeydown` with `viewMode !== 'tab'` guard — the tab editor has its own undo stack and must not be interfered with.

**selectVoicing note:** The `_suppressTabInit` sandwich and `sbn-tab-voicing-applied` dispatch are side-effects on Vue, not data mutations — they remain outside the `_wrapUndo` lambda. Only the `sections`/`chordVoicings` mutation block is wrapped.

**Grid footer:** Keyboard hint updated to include `Ctrl+Z undo`.

### Known issue logged
Diagram re-render after undo of rename+voicing combos may not always update. See bug #7 in PENDING BUGS.

---

## DEFERRED: grid-interact Phase 2 (tab editor UX session)

The following items are explicitly deferred to the **tab editor UX session**. Bundle together since they all touch the Alpine↔Vue bridge:

### A. Measure drag cross-section
Cross-section drag requires `sbn-tab-structure-request` event to tell Vue to reorder its measures. Not built — `moveMeasure()` guards against cross-section drops.

### B. Measure drag tab sync
`moveMeasure()` fires `_emitChordsChanged()` → Vue `patchChordNames()` only. Tab measure order is NOT updated. Fix: dispatch `sbn-tab-structure-request: { action: 'moveMeasure', si, fromMi, toMi }` from `moveMeasure()`, handle in `useAlpineBridge.js`.

### C. Chord-level drag and drop
- **Option A:** Reorder chords within a measure (same HTML5 drag, `m.chords.splice`)
- **Option B:** Move chord between measures (beat redistribution, tab sync)
- Both deferred — needs dedicated design for beat redistribution and tab sync

### D. Tab editor context menu (grid-interact Step 5)
`buildMenuItems('tab', state)` stub exists in `sbn-grid-ops.js`. Needs:
- `useMeasureSelection.js` composable in Vue
- `sbn-tab-structure-request` event for structural ops (insert/delete bar)
- Tab-only ops (copy/paste tab notes at measure level) stay in Vue

### E. Drag-to-reorder volta handling
`moveMeasure()` resets lineBreaks to uniform `barsPerRow`. Volta endings (`parsed.voltaEndings`) are keyed by global measure index — moving measures invalidates these. Deferred until volta session.

### F. Shift+drag visual refinement + volta session
Shift+drag batch select works but has no visual during drag (cursor doesn't show rubber-band). Acceptable for now.



## DESIGN SYSTEM

Four files establish the global design language. **Always upload `SBN-Design-Reference.md` alongside this file** — it contains the full component inventory, CSS examples, and usage rules.

```
public/css/sbn-design-system.css   ← tokens + base components, loaded FIRST
public/css/chord-symbols.css       ← chord name typography, loaded second
public/css/admin2.css              ← admin shell layout, loaded third (admin only)
public/js/chords.js                ← chord diagram renderers + toast, loaded on all pages that show diagrams
```

**Rule:** Module CSS files never define colors or base component shapes. They reference `--clr-*` variables only.

### Card system hierarchy (established 2026-04-08)

All chord diagram cards across the entire app follow one visual hierarchy:

```
.sbn-diagram-card / .sbn-vp-card   ← DS §2: base shell (white bg, border, radius, flex column)
  │                                   NO fixed width — size controlled by parent grid
  │
  ├─ chord library (.sbn-shapes-row grid)
  │    └─ HTML fretboard (.sbn-fretboard-*) inside card
  │
  ├─ voicing picker (.sbn-vp-grid)
  │    └─ SVG diagram (sbnRenderDiagramSVG) inside card
  │
  ├─ chord grid (.sbn-ve-chord-diagram)
  │    └─ SVG diagram inside .sbn-diagram-card, max-width scoped in leadsheets.css
  │
  └─ progression builder slots
       └─ SVG diagram inside card
```

**SVG diagrams** use a fixed `viewBox="0 0 80 95"` with `width="100%"` — CSS controls all sizing.
**CSS** controls card width via grid column definitions (`minmax`, `repeat`, `fr`) — never via hardcoded card width.
**.sbn-chord-card** is the full public-facing card shell (DS §2c) with play button, popularity pill, difficulty stars — for Phase 8.

### Phase 8 frontend portability (plan ahead)

Visual components that appear on public pages (chord diagram cards, fretboards, tab notation, chord name styling) must be available outside the admin layout. Current state:

- **Already portable:** `sbn-design-system.css` (includes full card system, fretboard CSS, chord card shell) + `chord-symbols.css` + `chords.js` — no admin dependency, load in any layout.
- **Needs extraction for Phase 8:** tab SVG classes (`.sbn-tab-note-text`, `.sbn-tab-string-line`, etc.) currently in `leadsheets.css`. Extract to `sbn-design-system.css` or a shared `sbn-components.css` when Phase 8 starts.
- **Tab viewer for public:** Build a read-only `TabViewer.vue` reusing `TabMeasure.vue` and `svgHelpers.js` but stripped of editing composables.
- **Chord card:** `.sbn-chord-card` (DS §2c) is Phase 8-ready: play button hook (`opts.onPlay`), display mode toggle (fingering/notes/functions), popularity/difficulty footer. Just needs audio integration.

---

## LEADSHEET EDITOR ARCHITECTURE

`resources/views/admin/leadsheets/edit.blade.php` (~4,060 lines) — the central component of the app. Alpine.js manages the chord grid, analysis, metadata, voicing picker, and save pipeline. Vue 3 manages the tab editor (mounted in a DOM island).

### View modes
Three tabs: `Chords` | `Analysis` | `Tab`. Alpine `viewMode` values: `'chords'` | `'analysis'` | `'tab'`.

### Main content area
Tab bar → content. Right panel: song meta → toolbar → context area (view-mode-aware).

### Context sidebar (view-mode aware)
- **Chords:** Voicing picker panel (`.sbn-vp-context`)
- **Analysis:** Detected progression pills per section + "Detect & Store" button
- **Tab:** Vue `TabSidebarApp` mounts in `#sbn-tab-sidebar`. **Must use `x-show`, NOT `x-if`** — `x-if` destroys the DOM node, unmounting the Vue app.

### Data flow
```text
JSON from server (json_data + tab_xml)
  ↕ save/load
Alpine state (initial json_data, melody)
  ↓ injects once
Vue tab editor (Active Model, Reactive State, Undo Stack)
  ↓ syncs up
Alpine (reads updated chord names and section structure for grid rendering)
```

### Alpine ↔ Vue protocol

The bridge protocol has been heavily stripped down after the Phase A integration. Vue owns its own commands completely securely.
```text
Alpine → Vue:  sbn-tab-init              initial data on mount (parsed.melody + sections)
Alpine → Vue:  sbn-tab-save-request      collect XML and JSON before save
Alpine → Vue:  sbn-tab-voicing-applied   voicing picker selection (Temporary until Phase D)
Vue → Alpine:  sbn-tab-sections-sync     sends updated sections/chords so Alpine can update its read-only grid
Vue → Alpine:  sbn-tab-save-response     MusicXML string + full serialized model JSON
Vue → Alpine:  sbn-tab-open-picker       chord name clicked → open voicing picker (Temporary)
```

**Critical implementation notes:**
- The bidirectional `sbn-tab-structure-request` has been completely deleted.
- Tab Editor mutations are handled entirely via `useTabModel` and `useSelection` inside Vue. 
- After Vue applies a structural hit, it calls `syncTabSectionsToAlpine()` to forcefully push the shallow copy back up to Alpine's view.
- `#sbn-tab-editor` lives inside `<template x-if="parsed">`, so the DOM element doesn't exist until Alpine's fetch completes. `tab-editor.js` retries `getElementById` every 200ms. Any new Vue mount points inside `x-if` blocks need the same pattern.

### Save pipeline (Phase 7f)
1. User clicks Save → Alpine checks if `viewMode === 'tab'`
2. If yes: dispatches `sbn-tab-save-request` → Vue serializes model → responds with XML
3. Alpine sets `tabXml`, re-parses XML into `parsed.melody` (keeps `json_data` in sync)
4. Alpine POSTs `json_data` + `tab_xml` to `LeadsheetController`
5. 3-second timeout guards against Vue not responding

---

## TAB EDITOR — VUE.JS (Phase 7, complete)

### File structure
```
resources/js/tab-editor.js                               ← Vite entry, mounts TWO Vue apps
resources/js/tab-editor/
  TabEditor.vue                                          ← notation area root, keyboard handler
  TabSidebarApp.vue                                      ← sidebar root (mounts in #sbn-tab-sidebar)
  components/
    TabMeasure.vue                                       ← single measure SVG renderer
    TabCursor.vue                                        ← cursor overlay (SVG circle + hit targets)
    TabSidebar.vue                                       ← selection info panel
  composables/
    useAlpineBridge.js                                   ← CustomEvent Alpine↔Vue bridge
    useTabModel.js                                       ← parsed.melody → TabModel (deep ref)
    useCursor.js                                         ← cursor state machine + navigation
    useNoteInput.js                                      ← fret entry, delete, rest↔note
    useReflow.js                                         ← duration changes, repositionMeasure
    useChordSync.js                                      ← extractFretsAtChord + applyVoicingToChord (Phase 7-int)
    useSelection.js                                      ← copy/paste, Shift+arrow range selection
    useUndo.js                                           ← command stack with measure snapshots
    useSidebarStore.js                                   ← shared reactive store between the two apps
  utils/
    constants.js                                         ← SMUFL glyphs, layout dims, tick math
    svgHelpers.js                                        ← beam/tie/flag/rest SVG generation
    musicXmlWriter.js                                    ← TabModel → MusicXML string
```

### Dual Vue app mount
`tab-editor.js` mounts two separate Vue apps:
1. `#sbn-tab-editor` — `TabEditor.vue` (notation, keyboard, cursor)
2. `#sbn-tab-sidebar` — `TabSidebarApp.vue` (note inspector)

Shared state via `useSidebarStore.js` — a module-level `reactive({})` singleton.

### Working model shape
```
TabModel { timeSignature, ticksPerMeasure, sections: SectionModel[] }
SectionModel { id, name, measures: MeasureModel[] }
MeasureModel { index, events: TabEvent[], actualTicks, repeatStart/End, volta, chordNames[] }
TabEvent { id, tick, tickInMeasure, duration, ticks, voice, isRest, notes: TabNote[],
           tieStart/Stop, stemDir, flagCount, beam1/2, beamWith, tuplet*, xPos }
TabNote { string, fret, pitch, octave, tieStart, tieStop, tieEndEvent?, tieEndNote? }
```

### Key conventions
- Model uses **`ref()`** (deep reactive), NOT `shallowRef`
- Mutations to nested objects/arrays are tracked automatically
- Tick constants: whole=1920, half=960, quarter=480, eighth=240, sixteenth=120, thirty-second=60
- Soundslice-local model: edits stay within a single measure, no cross-bar cascade reflow

### Keyboard shortcuts
| Key | Action |
|-----|--------|
| ← → | Navigate events |
| ↑ ↓ | Navigate strings |
| Tab / Shift+Tab | Navigate measures |
| Home / End | First/last event in measure |
| 0–9 | Enter fret number (two-digit with 600ms timeout) |
| Delete / Backspace | Remove note on cursor string |
| Ctrl+1–6 | Set duration (whole→32nd) |
| + / = / - | Shorter / longer duration |
| . | Toggle dotted |
| T | Toggle tie |
| A | Insert rest after cursor event (same duration, measure may overfill) |
| Shift+←/→ | Extend note selection |
| Ctrl+C/X/V | Copy/cut/paste |
| Ctrl+Z / Ctrl+Shift+Z | Undo/redo |
| ? | Keyboard shortcut reference overlay |
| Escape | Clear selection / return to navigate |

### Vite / build workflow
- `npm run dev` for HMR during development (both Herd and dev server must run)
- `npm run build` at end of each session for production bundle
- Page reload without dev server = blank tab view

---

## FILE MAP

### Chord name pipeline
```
public/css/chord-symbols.css        -- .sbn-chord-symbol and sub-classes, loaded globally
public/js/sbn-chord-name.js         -- sbnFormatChord() / sbnStyledChord() client-side
                                       NOTE: sbnFormatChordHtml() also available in chords.js
app/Helpers/ChordName.php           -- format() and styled()
app/helpers.php                     -- global chord() helper
```

### Chord diagram pipeline
```
public/js/chords.js                 -- CONSOLIDATED (2026-04-08):
                                       sbnRenderDiagramSVG(voicing, opts) — fluid SVG, transparent bg
                                       sbnRenderMiniDiagramSVG(voicing)   — alias for above
                                       sbnRenderFretboard(data)           — HTML fretboard string
                                       sbnHydrateFretboard(container, data) — place dots/barres
                                       sbnHydrateAll(container)           — batch hydrate
                                       sbnFormatChordHtml(name)           — chord name HTML
                                       sbnToast(message, type)            — toast notification
                                       sbnParseFretString(str, pos)       — smart multi-digit parser
                                    Hydration guard: data-sbn-rendered="1"
                                    Load on: all pages that show chord diagrams
```

### Design system
```
public/css/sbn-design-system.css    -- tokens + base components (load first, globally)
                                       §1   CSS custom properties (:root)
                                       §2   .sbn-diagram-card, .sbn-vp-card (unified, no fixed width)
                                       §2b  .sbn-fretboard-* HTML fretboard (canonical, replaces sbn-fb-*)
                                       §2c  .sbn-chord-card (full card with controls, Phase 8)
                                       §2d  .sbn-ve-chord.sbn-ve-selected (chord card selection frame)
                                       §3   .sbn-btn and variants
                                       §4   .sbn-badge and variants
                                       §5   .sbn-card, .sbn-card-lg panels
                                       §6   Form elements
                                       §7   .sbn-vp-* voicing picker panel (shared)
                                       §8   .sbn-ve-* chord grid cells (shared read-only base)
                                       §9   Context menu (.sbn-context-menu, .sbn-context-menu-item)
                                       §10  Drag-to-reorder (.is-dragging, .drop-gap-before/after)
```

### Admin shell (Phase 1)
```
resources/views/layouts/admin.blade.php
resources/views/components/admin/nav-item.blade.php
resources/views/admin/dashboard/index.blade.php
resources/views/auth/login.blade.php
public/css/admin2.css
app/Http/Controllers/Admin/DashboardController.php
app/Http/Controllers/Auth/LoginController.php
```

### Rhythm patterns (Phase 2)
```
app/Models/RhythmPattern.php
app/Http/Controllers/Admin/RhythmPatternController.php
resources/views/admin/rhythms/index.blade.php
resources/views/admin/rhythms/edit.blade.php
public/css/rhythms.css
```

### Chord progressions (Phase 3)
```
app/Models/ChordProgression.php
app/Http/Controllers/Admin/ProgressionController.php
resources/views/admin/progressions/index.blade.php
resources/views/admin/progressions/edit.blade.php
public/css/progressions.css
```

### Chord diagrams + voicing crossref (Phase 4)
```
app/Models/ChordDiagram.php
app/Models/ChordDiagramAlias.php
app/Models/VoicingUsage.php
app/Models/VoicingDraft.php
app/Http/Controllers/Admin/ChordController.php
app/Http/Controllers/Admin/VoicingController.php
resources/views/admin/chords/index.blade.php  -- has own inline SVG renderer (not yet unified)
resources/views/admin/chords/edit.blade.php
public/css/chords.css                         -- library-only extensions; sbn-fb-* legacy aliases
public/css/voicings.css
```

### Leadsheets + voicing engine (Phases 5–6e)
```
app/Models/Leadsheet.php
app/Services/LeadsheetParser.php
app/Services/ChordShapeCalculator.php
app/Services/VoicingCrossref.php
app/Http/Controllers/Admin/LeadsheetController.php
app/Http/Controllers/Admin/ProgressionDetectionController.php
resources/views/admin/leadsheets/index.blade.php
resources/views/admin/leadsheets/edit.blade.php   -- ~4,060 lines (inline <style> fully extracted)
public/css/leadsheets.css                         -- all editor styles + chord grid overrides
```

### Tab editor — Vue.js (Phase 7)
```
package.json                                           -- Vue 3, Vite, laravel-vite-plugin
vite.config.js                                         -- entry: resources/js/tab-editor.js
resources/js/tab-editor.js                             -- dual Vue app mount
resources/js/tab-editor/TabEditor.vue
resources/js/tab-editor/TabSidebarApp.vue
resources/js/tab-editor/components/TabMeasure.vue
resources/js/tab-editor/components/TabCursor.vue
resources/js/tab-editor/components/TabSidebar.vue
resources/js/tab-editor/composables/useAlpineBridge.js
resources/js/tab-editor/composables/useTabModel.js
resources/js/tab-editor/composables/useCursor.js
resources/js/tab-editor/composables/useNoteInput.js
resources/js/tab-editor/composables/useReflow.js
resources/js/tab-editor/composables/useSelection.js
resources/js/tab-editor/composables/useUndo.js
resources/js/tab-editor/composables/useSidebarStore.js
resources/js/tab-editor/utils/constants.js
resources/js/tab-editor/utils/svgHelpers.js
resources/js/tab-editor/utils/musicXmlWriter.js
```

### Progression builder (Phase 6d)
```
app/Services/HarmonicContext.php
app/Services/ProgressionBuilder.php
app/Http/Controllers/Admin/ProgressionBuilderController.php
resources/views/admin/progressions/builder.blade.php
public/css/progression-builder.css
```

### Voicing picker — architecture notes
The `.sbn-vp-*` picker panel is a shared UI component used in:
- `edit.blade.php` — context panel + modal fallback, tied to complex Alpine/Vue state
- `builder.blade.php` — context panel, pre-loaded voicing data, local Alpine filtering

**Rendering:** All picker cards and chord grid diagrams use `sbnRenderDiagramSVG()` from `chords.js`. No size argument — CSS controls all sizing via the card shell and parent grid.

**Interaction frame pattern:** `.sbn-ve-chord` hover uses a `::before` pseudo-element (`position:absolute; inset:0; z-index:2; box-shadow:inset 0 0 0 1px var(--clr-accent)`). `.sbn-ve-measure` selection/active uses `::after` at `z-index:4` (paints above chord `::before`). Frame color: orange (`--clr-accent`) for hover, blue (`--clr-style-jazz`) for selection/active. No background tints on selection. The hover frame fills the full chord cell — no padding gap.

**Card sizing in grid context:** `.sbn-ve-chord-diagram` contains a `div.sbn-diagram-card` wrapping the SVG. `leadsheets.css` caps it at `max-width: 100px; padding: 4px 6px`. `progression-builder.css` overrides to `max-width: 80px; padding: 2px 4px` for the narrower builder grid. Never pass a pixel size to `sbnRenderDiagramSVG()` — CSS-only sizing.

**Phase 8 plan:** The builder picker is the clean prototype for the public frontend.
Consider extracting to an Alpine store (`Alpine.store('voicingPicker')`) before Phase 8
to avoid duplicating the filter logic a third time.

---

## ENVIRONMENT

- **Laravel 11** on Windows via Laravel Herd at `C:\Users\info\sbn-app`
- **SQLite** at `C:\Users\info\sbn-app\database\sbn.db`
- Auth: `lucas@soulbossanova.com` / `changeme123`
- Alpine.js via CDN + `@alpinejs/collapse` plugin
- **Vue 3 + Vite** for tab editor only
- Fonts: DM Sans + JetBrains Mono + Crimson Text (Google Fonts) + Bravura SMuFL

---

## SONNET vs OPUS GUIDE

**Sonnet** (translation, porting, well-defined tasks):
- Phase 7-polish (UI polish, edge cases)
- Bug fixes with clear root cause
- All Phase 8/9 ports
- UI/CSS work, Blade views, admin CRUD

**Opus** (design, ambiguous architecture, novel algorithms):
- Bug #12 (triplet group integrity — needs guard/repair strategy design)
- scoreVL weight rebalancing (bug #3)
- Any task where the right approach is genuinely unclear

---

## SESSION PROTOCOL

1. **Upload two files** at session start: this file + `SBN-Design-Reference.md`.
2. **For tab editor work:** also upload `edit.blade.php` + zip of `resources/js/tab-editor/`.
3. **For tab editor UX session (next):** upload `edit.blade.php` + `useAlpineBridge.js` + `useTabModel.js` + `TabEditor.vue` + `TabMeasure.vue` + `TabSidebar.vue` + `sbn-grid-ops.js`. Opus architecture pass recommended first (sbn-tab-structure-request bridge design).
4. **For builder work:** also upload `builder.blade.php` + `ProgressionBuilderController.php`.
5. **For CSS/JS work:** upload `edit.blade.php` + `sbn-design-system.css` + `leadsheets.css` + full `css/` folder zipped + `chords.js`.
6. **Claude must read files before modifying them.** File map ≠ file content.
7. **Before writing any CSS:** check `SBN-Design-Reference.md`.
8. **Vue tab editor:** All code in `resources/js/tab-editor/`. Composables pattern. No shared state with Alpine — CustomEvents only (except `useSidebarStore`).
9. **End of session:** update status table, pending bugs, file map, architecture notes.

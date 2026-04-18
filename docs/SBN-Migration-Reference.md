# SBN Teaching Hub — Migration Reference

> **Purpose:** Working reference for Claude across sessions. Single source of truth for the Laravel project state.
> **Usage:** Upload at the start of every session alongside `SBN-Design-Reference.md`. Together they replace all prior context.

---

## CURRENT STATUS

**Last updated:** 2026-04-18 (Phase B complete + chord grid audio/visual polish)

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
| 7-phase-b | Vue-Native Chord Grid & Voicing Picker | **DONE** |
| 7-phase-c | Audio Playback Engine (Tone.js) | |
| 7-phase-d | Video Sync (Timeline Mapping) | |
| 7-phase-e | Full Feature Parity (Drag-drop across sections, repeat/volta editing) | |
| 8 | Public frontend (all student-facing pages) | |
| 9 | Courses, auth, payments, video integration | |

---

## PATH FORWARD

### Source-of-truth architecture (Phase B Complete)

**Key principle:** **One model, one reactive system, one undo stack.**
Vue (`TabEditor.vue` / `model.value`) is the sole source of truth for structure, harmony, notation, chord grid, and voicing picker. Alpine is a thin page shell: metadata inputs, save button, analysis panel, file import.

```
VUE (TabEditor.vue — sole source of truth)
  ├── model.value             ← sections, measures, chordNames, chordVoicings, tab events
  ├── viewMode                ← 'chords' | 'tab' | 'analysis'
  ├── ChordGridView.vue       ← chord grid renderer
  ├── VoicingPicker.vue       ← desktop panel + mobile modal (Teleport into #sbn-vp-slot)
  ├── ChordPicker.vue         ← chord name inline picker
  ├── [tab editor components] ← unchanged from Phase 7
  └── Unified undo stack      ← covers all ops in both views

ALPINE (edit.blade.php — thin shell)
  ├── Song metadata           ← title, composer, key, tempo, time signature
  ├── Analysis panel          ← reads from window.__sbnTabModel facade
  ├── HTTP save               ← reads from window.__sbnTabModel facade
  ├── alpineViewMode          ← one-way mirror of Vue's viewMode
  └── File import             ← drops/parses MusicXML → dispatches sbn-tab-init
```

### Historical Graveyard: The Bidirectional Mess (Do Not Repeat)
Prior to Phase A, Alpine owned structure (`parsed.sections`) and Vue owned notation. `useAlpineBridge.js` synchronized them through 12+ CustomEvents. *Why it failed:* Two independent reactive proxies cannot safely share dependency tracking. Every structural change required capturing snapshots in both domains, mutating Alpine, signaling Vue, and guarding against infinite loops. It resulted in stale renders, stuck flags, and double inits. **DO NOT mix Vue and Alpine reactivity. All complex interactions must stay centralized in Vue.**

### Upcoming Phases

**Phase C: Full feature parity**
Drag-and-drop across sections, repeat marker editing, volta editing — all handled natively inside Vue's reactive `model.value`. Deferred items from grid-interact:
- Cross-section drag-to-reorder (needs `moveMeasure` across sections in `useTabModel`)
- Tab sync for drag (`moveMeasure` currently only syncs chord names, not measure order in tab)
- Chord-level drag (reorder chords within / across measures — needs beat redistribution design)
- Repeat/volta editing UI (currently read-only rendered, no edit handles)

**Phase D: Audio playback**
Tone.js playback engine hooks into `model.value`. Playback cursor is a Vue reactive ref on a `requestAnimationFrame` loop synced to Tone.js transport.

**Phase E: Video sync**
`{ time, measureIndex }` mappings stored alongside the model. `VideoPlayer.vue` with bidirectional sync (play video → highlight measure, click measure → seek video).

**Phase 8: Public frontend**
`leadsheet-viewer.blade.php` (created in Phase B Step 10a — deferred, still TODO) is the seed: read-only chord grid + sidebar from `$leadsheet->json_data`, no Vue, no editing. When Phase 8 starts, this component gets a play button (Phase D), sidebar edu panel, and public card system.

---

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
| 2026-04-09 | Double-digit fret numbers not rendered | `chords.js` fret parser used `parseInt(c)` → changed to `parseInt(c, 16)` for hex encoding |
| 2026-04-09 | Inline `<style>` block re-appeared in `edit.blade.php` | Deleted lines 28–1053; restored `<link>` to `leadsheets.css` |
| 2026-04-15 | Analysis panel not showing on tab switch | `setViewMode()` dispatched wrong event name/shape; fixed to `sbn-tab-view-changed` + `{viewMode}` |
| 2026-04-18 | Chord playback highlight only lit first chord | `isPlayingCard` used even beat division; rewritten to use `chordOffset`/`chordDuration` beat window `[slotStart, slotEnd)` |
| 2026-04-18 | Chord positions evenly distributed despite precise XML data | `parseMeasure()` rewrote to sequential child-walk; stamps each `<harmony>` with `beatInMeasure = tickDivs / divisions`, derives `beats` from tick gaps |
| 2026-04-18 | Tab chord names showing as Tab1/Tab2 intermittently | Two bugs: (1) `_tabInitDone=true` blocked re-dispatch after identification; fixed by resetting `_tabInitDone` before file import dispatch. (2) `extractVoicingsFromTab` missing `beatInMeasure` — added |
| 2026-04-18 | Chord positions lost on save/reload | `exportAlpineSections()` discarded `chordOffsets`/`chordBeats`; now serializes `beatInMeasure`/`beats` on each chord object so round-trip preserves timing |
| 2026-04-18 | Orange chord names in chord grid | Root cause: `chord-symbols.css` line 17 sets `.sbn-chord-symbol { color: var(--clr-accent-dim) }` globally; fixed by scoping override `.sbn-ve-chord-name .sbn-chord-symbol { color: var(--clr-text) }` |
| 2026-04-18 | Add chord slot ignores beat grid layout | `addChordToMeasure` / `deleteChords` in `useChordGridOps.js` now call `_recomputeEvenOffsets(m)` after any chordNames splice |

---

## PENDING BUGS / IMPROVEMENTS

1. **6d-ui picker voicing count mismatch** — picker reports e.g. "10 voicings" but displays fewer. Likely `pickerCards()` grouping drops null-category voicings. **Sonnet.**
2. **scoreVL calibration** — ProgressionBuilder occasionally picks distant voicings. Common-tone bonus and fret-distance weighting need rebalancing. **Opus.**
3. **Dismissed drafts lost on reprocess** — `VoicingCrossref::clearLeadsheetReferences()` deletes all drafts including dismissed ones. Fix: preserve `status='dismissed'` rows. Low priority.
4. **Dotted note overfill after editing** — overfill indicator misbehaves when editing dotted notes in bars that have already been through `repositionMeasure`. Partially investigated; deferred.
5. **Bug #12: Triplet group integrity** — groups get torn apart when editing adjacent notes. Needs dedicated Opus session using `useTabModel.js`, `useNoteInput.js`, `useReflow.js` + test XML.
6. **Library index SVG renderer** — `chords/index.blade.php` has its own inline SVG renderer (~150 lines) separate from `chords.js`. Unify in future cleanup. **Sonnet.**
7. **Mobile voicing picker modal** — `VoicingPicker.vue` has a `variant="modal"` path for <1024px but untested. Deferred to Phase 8 (editor is desktop-only).

---

## LEADSHEET EDITOR ARCHITECTURE

`resources/views/admin/leadsheets/edit.blade.php` (~1,980 lines) — the central component.
Vue 3 (`TabEditor.vue`) mounts in `#sbn-editor-content` and owns the entire content area. Alpine manages the outer shell.

### View modes
Three tabs owned by Vue: `Chords` | `Tab` | `Analysis`. Vue's `viewMode` ref dispatches `sbn-tab-view-changed` → Alpine's `alpineViewMode` mirrors it one-way.

### Content area (Vue)
`TabEditor.vue` renders all three views:
- **Chords:** `ChordGridView.vue` — sections/measures/chord cards, row resize, context menu, voicing picker
- **Tab:** `TabMeasure.vue` notation SVG — existing tab editor, unchanged
- **Analysis:** triggers Alpine's `loadAnalysis()` via `sbn-tab-load-analysis` event

### Right panel (Alpine, view-mode-aware)
- **Chords/Tab:** `#sbn-vp-slot` — Vue Teleports `VoicingPicker.vue` / `VoicingOverview.vue` here
- **Analysis:** Alpine analysis panel (progression pills, detect button)
- **Tab:** `#sbn-tab-sidebar` — `TabSidebarApp.vue` (Note Inspector). **Must use `x-show`, NOT `x-if`** — `x-if` destroys the DOM node, unmounting Vue.

### Alpine ↔ Vue event protocol (7 surviving events)

| Event | Direction | Purpose |
|-------|-----------|---------|
| `sbn-tab-init` | Alpine → Vue | Initial data load on page load / file import |
| `sbn-tab-init-ack` | Vue → Alpine | Confirm Vue received init |
| `sbn-tab-save-request` | Alpine → Vue | Save button: ask Vue for serialized XML |
| `sbn-tab-save-response` | Vue → Alpine | Reply with MusicXML string |
| `sbn-tab-view-changed` | Vue → Alpine | Alpine mirrors viewMode; triggers analysis load |
| `sbn-tab-sections-sync` | Vue → Alpine | Structural change: invalidate analysis, update parsed.sections |
| `sbn-tab-identify-result` | Vue → Alpine | Tab chord identified — update chord name in parsed.sections |

**Deleted (Phase B):** `sbn-chords-changed`, `sbn-tab-voicing-applied`, `sbn-tab-open-picker`, `sbn-tab-open-chord-picker`, `sbn-tab-request-snapshot`, `sbn-tab-restore-snapshot`, `sbn-tab-structure-request`

### `window.__sbnTabModel` facade
Singleton in `utils/tabModelFacade.js`. Exposes live Vue model data to Alpine (and DevTools) without snapshot staleness:
```js
window.__sbnTabModel.getSections()       // exportAlpineSections() shape
window.__sbnTabModel.getChordVoicings()  // plain-object clone
window.__sbnTabModel.getRepeatMarkers()
window.__sbnTabModel.getVoltaEndings()
window.__sbnTabModel.getMeta()           // { title, composer, key, tempo, timeSignature }
```
Initialized once by `TabEditor.vue` via `initTabModelFacade({...})` after `useTabModel` setup. Getter-function pattern — always reads live reactive model, never a snapshot.

### Save pipeline
1. User clicks Save → Alpine `save()` 
2. If `viewMode === 'tab'`: dispatches `sbn-tab-save-request` → Vue serializes → `sbn-tab-save-response` with XML (3-second timeout guard)
3. Alpine reads structural data from `window.__sbnTabModel` facade (sections, chordVoicings, repeatMarkers, voltaEndings)
4. Constructs `finalJsonData = { ...this.parsed, sections, chordVoicings, repeatMarkers, voltaEndings, melody }`
5. POSTs to `LeadsheetController` with `json_data` + `tab_xml`

### What stays in Alpine / What moved to Vue

| Concern | Owner |
|---------|-------|
| Chord grid render | **Vue** (ChordGridView.vue) |
| Chord name picker | **Vue** (ChordPicker.vue) |
| Voicing picker (panel + modal) | **Vue** (VoicingPicker.vue) |
| Voicing overview | **Vue** (VoicingOverview.vue) |
| Voicing search API calls | **Vue** (utils/voicingApi.js) |
| Grid selection / clipboard / context menu | **Vue** (useGridSelection, useChordClipboard) |
| Row resize (−/+/§ per row) | **Vue** (rowShrink/rowGrow/splitSection in TabEditor) |
| Section collapse state | **Vue** (local ref per ChordSection; collapsedSections{} in TabEditor) |
| Section add/delete/rename | **Vue** (useTabModel: addSection, deleteSection, renameSection) |
| `viewMode` | **Vue** (Alpine mirrors one-way via sbn-tab-view-changed) |
| Undo / redo | **Vue** (one stack — useUndo.js) |
| Analysis view | Alpine (reads from window.__sbnTabModel facade) |
| Song meta (title/composer/key/tempo/time) | Alpine |
| Description, shortcode output | Alpine |
| HTTP save | Alpine |
| File import (drop/parse → sbn-tab-init) | Alpine |
| `identifyTabVoicings` (runs once on import) | Alpine |

---

## TAB EDITOR — VUE.JS (Phases 7 + B)

### File structure
```
resources/js/tab-editor.js                               ← Vite entry, mounts TabEditor.vue
resources/js/tab-editor/
  TabEditor.vue                                          ← root: view tabs, chord grid, tab notation, keyboard
  TabSidebarApp.vue                                      ← sidebar root (mounts in #sbn-tab-sidebar)
  components/
    TabMeasure.vue                                       ← single measure SVG renderer
    TabCursor.vue                                        ← cursor overlay
    TabSidebar.vue                                       ← Note Inspector panel
    ChordGridView.vue                                    ← chord grid container (Phase B)
    ChordSection.vue                                     ← section header + row layout (Phase B)
    ChordMeasure.vue                                     ← measure: volta, bar#, chord cards (Phase B)
    ChordCard.vue                                        ← chord name + voicing diagram (Phase B)
    ChordPicker.vue                                      ← inline chord name picker (Phase B)
    VoicingPicker.vue                                    ← desktop panel + mobile modal, Teleport (Phase B)
    VoicingOverview.vue                                  ← resting state: all song voicings (Phase B)
    ChordContextMenu.vue                                 ← right-click menu (Phase B)
  composables/
    useAlpineBridge.js                                   ← CustomEvent Alpine↔Vue bridge (stripped to 2 handlers)
    useTabModel.js                                       ← melody+sections → reactive TabModel
    useCursor.js                                         ← cursor state machine + navigation
    useNoteInput.js                                      ← fret entry, delete, rest↔note
    useReflow.js                                         ← duration changes, repositionMeasure
    useChordSync.js                                      ← extractFretsAtChord + applyVoicingToChord
    useSelection.js                                      ← copy/paste, Shift+arrow range selection
    useUndo.js                                           ← command stack with measure snapshots (covers all views)
    useSidebarStore.js                                   ← shared reactive store between TabEditor + TabSidebarApp
    useChordGridOps.js                                   ← all chord grid mutations (Pattern A + B) (Phase B)
    useGridSelection.js                                  ← chord grid selection: click/ctrl/shift/range (Phase B)
    useChordClipboard.js                                 ← chord grid copy/cut/paste (Phase B)
    useVoicingPicker.js                                  ← module-level singleton store for VoicingPicker (Phase B)
    useChordPicker.js                                    ← module-level singleton store for ChordPicker (Phase B)
  utils/
    constants.js                                         ← SMUFL glyphs, layout dims, tick math
    svgHelpers.js                                        ← beam/tie/flag/rest SVG generation
    musicXmlWriter.js                                    ← TabModel → MusicXML string
    chordFormat.js                                       ← wrappers for sbnFormatChordHtml / sbnRenderDiagramSVG (Phase B)
    voicingApi.js                                        ← AJAX wrappers for voicing search endpoints (Phase B)
    tabModelFacade.js                                    ← window.__sbnTabModel singleton (Phase B)
```

**Note:** `tab-editor.js` mounts only `TabEditor.vue` (into `#sbn-editor-content`). `TabSidebarApp.vue` is mounted separately into `#sbn-tab-sidebar` via the same entry file.

### Working model shape
```
TabModel { timeSignature, ticksPerMeasure, sections: SectionModel[], chordVoicings{} }
SectionModel { id, name, lineBreaks[], measures: MeasureModel[] }
MeasureModel { index, events: TabEvent[], actualTicks, repeatStart/End, volta, chordNames[],
               chordOffsets[], chordBeats[] }
  chordOffsets[i] — beat offset of chord i from measure start (quarter beats, 0-based)
  chordBeats[i]   — duration of chord i in quarter beats
  Both arrays are always in sync with chordNames[]. Set by parseMeasure() from MusicXML
  tick data, or by _recomputeEvenOffsets() when chords are added/removed manually.
TabEvent { id, tick, tickInMeasure, duration, ticks, voice, isRest, notes: TabNote[],
           tieStart/Stop, stemDir, flagCount, beam1/2, beamWith, tuplet*, xPos }
TabNote { string, fret, pitch, octave, tieStart, tieStop, tieEndEvent?, tieEndNote? }
```

### Chord timing model (beat-grid layout)

`parseMeasure()` in `edit.blade.php` walks measure children sequentially, tracking a note cursor in `divisions` (ticks). Each `<harmony>` element gets `beatInMeasure = tickDivs / divisions` and `beats` derived from the gap to the next harmony (or measure end). This gives 8th-note precision from MusicXML.

On save, `exportAlpineSections()` serializes `beatInMeasure`/`beats` back onto each chord object so timing survives a round-trip through `json_data`. On load, `useTabModel.js` reads these fields back into `chordOffsets[]`/`chordBeats[]`.

`ChordMeasure.vue` uses `chordPositionStyle(ci)` to absolutely position each `ChordCard` at `left: (offset/bpm*100)%` with `width: (dur/bpm*100)%`. A `sbn-ve-beat-grid` layer renders one dot per quarter-beat centred in its slot — purely visual metronome markers, not barlines. The active dot gets `beat-active` class + CSS pulse animation driven by `transportBeat`.

### Key conventions
- Model uses **`ref()`** (deep reactive), NOT `shallowRef`
- Tick constants: whole=1920, half=960, quarter=480, eighth=240, sixteenth=120, thirty-second=60
- Chord grid provide/inject: `TabEditor.vue` provides `model`, `globalIndexOf`, `chordGridOps`, `gridSelection`, `chordClipboard`, `chordPicker`, `voicingPicker`, `renameSection`, `addMeasureToSection`, `deleteSection`, `sectionCount`, `rowShrink`, `rowGrow`, `rowSplit`
- `useVoicingPickerStore` and `useChordPickerStore` are **module-level singletons** — both Vue apps share the same instance without provide/inject
- `VoicingPicker.vue` Teleports unconditionally into `#sbn-vp-slot` (no feature flag)

### Undo patterns
All chord-grid ops go through `useChordGridOps.js` via `useUndo.wrapCommand`:

**Pattern A — chord name / voicing mutation (fast, per-measure snapshot):**
Use when only `chordNames` or `chordVoicings` change in a known measure.
```js
undo.wrapCommand('Rename chord', [gi], () => { /* mutate */ });
```

**Pattern B — structural op (slow, full-model serialize/deserialize):**
Use for insert/delete bar, move bar, split section.
```js
undo.wrapCommand('Insert bar', [], () => { tabModel.insertMeasureAfter(si, mi); }, {
  serializeModel: tabModel.serializeModel,
  deserializeModel: tabModel.deserializeModel,
  afterApply: () => dispatchEvent(new CustomEvent('sbn-tab-sections-sync')),
});
```

### `chordVoicings` key management
Vue handles all key reindexing inline — no Alpine involvement needed:
- `insertMeasureAfter` / `deleteMeasure` / `moveMeasure` call `_reindexChordVoicingKeys` inline before returning
- `deleteChords` calls `_compactChordIndicesInMeasure` after removing a chord slot
- `setChordName` calls `_renameVoicingKey` (new key = `newName@gi.ci`)
- `pruneOrphanVoicings` is deleted — keys are always clean because every mutation reindexes inline

### Keyboard shortcuts (tab editor)
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
| A | Insert rest after cursor event |
| Shift+←/→ | Extend note selection |
| Ctrl+C/X/V | Copy/cut/paste |
| Ctrl+Z / Ctrl+Shift+Z | Undo/redo (unified — covers chord grid + tab + voicings) |
| ? | Keyboard shortcut reference overlay |
| Escape | Clear selection / return to navigate |

### Vite / build workflow
- `npm run dev` for HMR during development
- `npm run build` at end of each session for production bundle
- Page reload without dev server = blank tab view

---

## DESIGN SYSTEM

Four files establish the global design language. **Always upload `SBN-Design-Reference.md` alongside this file.**

```
public/css/sbn-design-system.css   ← tokens + base components, loaded FIRST
public/css/chord-symbols.css       ← chord name typography, loaded second
public/css/admin2.css              ← admin shell layout, loaded third (admin only)
public/js/chords.js                ← chord diagram renderers + toast
```

**Rule:** Module CSS files never define colors or base component shapes. They reference `--clr-*` variables only.

### Card system hierarchy
```
.sbn-diagram-card / .sbn-vp-card   ← DS §2: base shell (white bg, border, radius, flex column)
  ├─ chord library (.sbn-shapes-row grid)
  ├─ voicing picker (.sbn-vp-grid)
  ├─ chord grid (.sbn-ve-chord-diagram) — max-width: 100px; padding: 4px 6px in leadsheets.css
  └─ progression builder — max-width: 80px; padding: 2px 4px override
```
SVG diagrams: fixed `viewBox="0 0 80 95"` with `width="100%"`. Never pass pixel size to `sbnRenderDiagramSVG()`.

### Interaction frame pattern
`.sbn-ve-chord` hover: `::before` pseudo-element (`inset:0; z-index:2; box-shadow:inset 0 0 0 1px var(--clr-accent)`). Selection/active: `::after` at `z-index:4`. Hover = orange (`--clr-accent`), selection = blue (`--clr-style-jazz`).

### Phase 8 frontend portability
- **Already portable:** `sbn-design-system.css`, `chord-symbols.css`, `chords.js`
- **Needs extraction:** tab SVG classes (`.sbn-tab-note-text`, etc.) currently in `leadsheets.css` → move to `sbn-design-system.css` when Phase 8 starts
- **Public chord grid:** `leadsheet-viewer.blade.php` (Phase B Step 10a — TODO): read-only Alpine x-for loop over `$leadsheet->json_data`, same CSS, no Vue, no editing
- **Public tab viewer:** `TabViewer.vue` reusing `TabMeasure.vue` stripped of editing composables
- **Builder picker** is the clean prototype for the public voicing picker — consider extracting to `Alpine.store('voicingPicker')` before Phase 8

---

## FILE MAP

### Chord name pipeline
```
public/css/chord-symbols.css        -- .sbn-chord-symbol and sub-classes
public/js/sbn-chord-name.js         -- sbnFormatChord() / sbnStyledChord()
app/Helpers/ChordName.php           -- format() and styled()
app/helpers.php                     -- global chord() helper
```

### Chord diagram pipeline
```
public/js/chords.js                 -- CONSOLIDATED:
                                       sbnRenderDiagramSVG(voicing, opts)
                                       sbnRenderFretboard(data)
                                       sbnHydrateAll(container)
                                       sbnFormatChordHtml(name)
                                       sbnToast(message, type)
                                       sbnParseFretString(str, pos)
```

### Design system
```
public/css/sbn-design-system.css    -- §1 tokens, §2 cards, §2b fretboard, §2c chord-card (Phase 8)
                                       §2d selection frame, §3 buttons, §4 badges, §5 panels
                                       §6 forms, §7 voicing picker, §8 chord grid cells
                                       §9 context menu, §10 drag-to-reorder
```

### Admin shell
```
resources/views/layouts/admin.blade.php
resources/views/admin/dashboard/index.blade.php
public/css/admin2.css
```

### Rhythm patterns (Phase 2)
```
app/Models/RhythmPattern.php
app/Http/Controllers/Admin/RhythmPatternController.php
resources/views/admin/rhythms/
public/css/rhythms.css
```

### Chord progressions (Phase 3)
```
app/Models/ChordProgression.php
app/Http/Controllers/Admin/ProgressionController.php
resources/views/admin/progressions/
public/css/progressions.css
```

### Chord diagrams + voicing crossref (Phase 4)
```
app/Models/ChordDiagram.php  /  ChordDiagramAlias.php  /  VoicingUsage.php  /  VoicingDraft.php
app/Http/Controllers/Admin/ChordController.php  /  VoicingController.php
resources/views/admin/chords/index.blade.php   -- has own inline SVG renderer (not yet unified)
resources/views/admin/chords/edit.blade.php
public/css/chords.css  /  voicings.css
```

### Leadsheets + voicing engine (Phases 5–6e)
```
app/Models/Leadsheet.php
app/Services/LeadsheetParser.php  /  ChordShapeCalculator.php  /  VoicingCrossref.php
app/Http/Controllers/Admin/LeadsheetController.php
app/Http/Controllers/Admin/ProgressionDetectionController.php
resources/views/admin/leadsheets/index.blade.php
resources/views/admin/leadsheets/edit.blade.php   -- ~1,980 lines (Phase B complete)
public/css/leadsheets.css                         -- all editor + chord grid styles
```

### Tab editor + chord grid — Vue.js (Phases 7 + B)
```
package.json  /  vite.config.js
resources/js/tab-editor.js
resources/js/tab-editor/
  TabEditor.vue  /  TabSidebarApp.vue
  components/  (see file structure above)
  composables/  (see file structure above)
  utils/  (see file structure above)
public/build/  (compiled by npm run build)
```

### Progression builder (Phase 6d)
```
app/Services/HarmonicContext.php  /  ProgressionBuilder.php
app/Http/Controllers/Admin/ProgressionBuilderController.php
resources/views/admin/progressions/builder.blade.php
public/css/progression-builder.css
```

---

## ENVIRONMENT

- **Laravel 11** on Windows via Laravel Herd at `C:\Users\info\sbn-app`
- **SQLite** at `C:\Users\info\sbn-app\database\sbn.db`
- Auth: `lucas@soulbossanova.com` / `changeme123`
- Alpine.js via CDN + `@alpinejs/collapse` plugin
- **Vue 3 + Vite** for tab editor + chord grid
- Fonts: DM Sans + JetBrains Mono + Crimson Text (Google Fonts) + Bravura SMuFL

---

## SONNET vs OPUS GUIDE

**Sonnet** (translation, porting, well-defined tasks):
- All Phase C/D/E/8/9 feature implementation
- Bug fixes with clear root cause
- UI/CSS work, Blade views, admin CRUD
- Vue component additions following established patterns

**Opus** (design, ambiguous architecture, novel algorithms):
- Bug #12 (triplet group integrity — needs guard/repair strategy design)
- scoreVL weight rebalancing (bug #3)
- Phase C architecture decisions (cross-section drag, repeat/volta edit model)
- Any task where the right approach is genuinely unclear

---

## SESSION PROTOCOL

1. **Upload two files** at session start: this file + `SBN-Design-Reference.md`.
2. **For tab editor / chord grid work:** also upload `edit.blade.php` + zip of `resources/js/tab-editor/`.
3. **For builder work:** also upload `builder.blade.php` + `ProgressionBuilderController.php`.
4. **For CSS/JS work:** upload `leadsheets.css` + `sbn-design-system.css` + `chords.js`.
5. **Claude must read files before modifying them.** File map ≠ file content.
6. **Before writing any CSS:** check `SBN-Design-Reference.md`.
7. **Vue tab editor:** All code in `resources/js/tab-editor/`. Composables pattern. No shared state with Alpine — CustomEvents only (except `useSidebarStore`).
8. **End of session:** update status table, pending bugs, file map, architecture notes.

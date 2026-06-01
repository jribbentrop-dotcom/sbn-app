  j# SBN Admin — Chord / Tab Editor Reference

> **Purpose:** Complete reference for the admin leadsheet editor: architecture, Vue component tree, chord grid, voicing picker, tab notation, audio playback, video sync, keyboard shortcuts, design system, and all creation flows (blank, from progression, exercises, transcription).
> **Last updated:** 2026-05-23

---

## ARCHITECTURE OVERVIEW

### Source-of-truth split

```
VUE (TabEditor.vue — sole source of truth for editor content)
  ├── model.value             ← sections, measures, chordNames, chordVoicings, tab events
  ├── viewMode                ← 'chords' | 'tab' | 'analysis'
  ├── ChordGridView.vue       ← chord grid renderer
  ├── VoicingPicker.vue       ← desktop panel + mobile modal (Teleport into #sbn-vp-slot)
  ├── ChordPicker.vue         ← chord name inline picker
  ├── [tab editor components] ← notation SVG, cursor, sidebar
  └── Unified undo stack      ← covers all ops in both views

ALPINE (edit.blade.php — thin page shell)
  ├── Song metadata           ← title, composer, key, tempo, time signature
  ├── Analysis panel          ← reads from window.__sbnTabModel facade
  ├── HTTP save               ← reads from window.__sbnTabModel facade
  ├── alpineViewMode          ← one-way mirror of Vue's viewMode
  └── File import             ← drops/parses MusicXML → dispatches sbn-tab-init
```

**Key principle:** Vue owns all complex interactions. Alpine is a thin shell for metadata and HTTP. Do NOT mix Vue and Alpine reactivity — all complex state stays in Vue.

### What stays in Alpine vs Vue

| Concern | Owner |
|---------|-------|
| Chord grid render | **Vue** (ChordGridView.vue) |
| Chord name picker | **Vue** (ChordPicker.vue) |
| Voicing picker (panel + modal) | **Vue** (VoicingPicker.vue) |
| Voicing overview (resting state) | **Vue** (VoicingOverview.vue) |
| Voicing search API calls | **Vue** (utils/voicingApi.js) |
| Grid selection / clipboard / context menu | **Vue** (useGridSelection, useChordClipboard) |
| Row resize (−/+/§ per row) | **Vue** (rowShrink/rowGrow/splitSection in TabEditor) |
| Section collapse state | **Vue** (collapsedSections{} in TabEditor) |
| Section add/delete/rename | **Vue** (useTabModel: addSection, deleteSection, renameSection) |
| `viewMode` | **Vue** (Alpine mirrors one-way via sbn-tab-view-changed) |
| Undo / redo | **Vue** (useUndo.js — one stack) |
| Analysis view | Alpine (reads from window.__sbnTabModel facade) |
| Song meta (title/composer/key/tempo/time) | Alpine |
| Description, shortcode output | Alpine |
| HTTP save | Alpine |
| File import (drop/parse → sbn-tab-init) | Alpine |
| `identifyTabVoicings` (runs once on import) | Alpine |
| Fill voicings (POST fill-voicings, patch bridge) | Vue (VoicingOverview.vue) + Alpine |

---

## LEADSHEET EDITOR PAGE

`resources/views/admin/leadsheets/edit.blade.php` (~1,980 lines) — the central admin component.

### View modes
Three tabs owned by Vue: **Chords** | **Tab** | **Analysis**. Vue's `viewMode` ref dispatches `sbn-tab-view-changed` → Alpine's `alpineViewMode` mirrors it one-way.

### Content area (Vue)
`TabEditor.vue` renders all three views:
- **Chords:** `ChordGridView.vue` — sections/measures/chord cards, row resize, context menu, voicing picker
- **Tab:** `TabMeasure.vue` notation SVG — tab editor, keyboard entry, cursor
- **Analysis:** triggers Alpine's `loadAnalysis()` via `sbn-tab-load-analysis` event

### Right panel (Alpine, view-mode-aware)
- **Chords/Tab:** `#sbn-vp-slot` — Vue Teleports `VoicingPicker.vue` / `VoicingOverview.vue` here
- **Analysis:** Alpine analysis panel (progression pills, detect button)
- **Tab:** `#sbn-tab-sidebar` — `TabSidebarApp.vue` (Note Inspector). **Must use `x-show`, NOT `x-if`** — `x-if` destroys the DOM node, unmounting Vue.

### Alpine ↔ Vue event protocol (7 events)

| Event | Direction | Purpose |
|-------|-----------|---------|
| `sbn-tab-init` | Alpine → Vue | Initial data load on page load / file import |
| `sbn-tab-init-ack` | Vue → Alpine | Confirm Vue received init |
| `sbn-tab-save-request` | Alpine → Vue | Save button: ask Vue for serialized XML |
| `sbn-tab-save-response` | Vue → Alpine | Reply with MusicXML string |
| `sbn-tab-view-changed` | Vue → Alpine | Alpine mirrors viewMode; triggers analysis load |
| `sbn-tab-sections-sync` | Vue → Alpine | Structural change: invalidate analysis, update parsed.sections |
| `sbn-tab-identify-result` | Vue → Alpine | Tab chord identified — show confirm toast; on confirm calls `window.__sbnTabModel.setChordNameWithVoicing()` |

**`sbn-tab-chord-update` is NOT used for confirm flow.** The `sbn-tab-identify-result` handler calls `setChordNameWithVoicing` directly. `sbn-tab-chord-update` is kept only as a legacy direct-update path.

Both listeners are guarded with `window._sbnTabChordUpdateRegistered` to prevent duplicate registration on hot reload.

### `window.__sbnTabModel` facade
Singleton in `utils/tabModelFacade.js`. Exposes live Vue model data to Alpine without snapshot staleness:
```js
// Read
window.__sbnTabModel.getSections()       // exportAlpineSections() shape
window.__sbnTabModel.getChordVoicings()  // plain-object clone
window.__sbnTabModel.getRepeatMarkers()
window.__sbnTabModel.getVoltaEndings()
window.__sbnTabModel.getMeta()           // { title, composer, key, tempo, timeSignature }

// Write (Alpine → Vue, routes through chordGridOps with undo)
window.__sbnTabModel.setChordName(gi, ci, name)
window.__sbnTabModel.setChordNameWithVoicing(gi, ci, name, tabData)
  // tabData = { frets: '6-char string', position: number } from extractFretsAtChord()
  // writes chordName + positional voicing key `name@gi.ci` in one undo command
```
Initialized once by `TabEditor.vue` via `initTabModelFacade({...})` after `useTabModel` setup.
Write functions are registered separately after `chordGridOps` is created via `registerSetChordName()` / `registerSetChordNameWithVoicing()` — avoids circular init order.

### Save pipeline
1. User clicks Save → Alpine `save()`
2. If `viewMode === 'tab'`: dispatches `sbn-tab-save-request` → Vue serializes → `sbn-tab-save-response` with XML (3-second timeout guard)
3. Alpine reads structural data from `window.__sbnTabModel` facade (sections, chordVoicings, repeatMarkers, voltaEndings)
4. Constructs `finalJsonData = { ...this.parsed, sections, chordVoicings, repeatMarkers, voltaEndings, melody }`
5. POSTs to `LeadsheetController` with `json_data` + `tab_xml`

---

## TAB EDITOR — VUE.JS

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
    ChordGridView.vue                                    ← chord grid container
    ChordSection.vue                                     ← section header + row layout
    ChordMeasure.vue                                     ← measure: volta, bar#, chord cards
    ChordCard.vue                                        ← chord name + voicing diagram
    ChordPicker.vue                                      ← inline chord name picker
    VoicingPicker.vue                                    ← desktop panel + mobile modal, Teleport
    VoicingOverview.vue                                  ← resting state: all song voicings
    ChordContextMenu.vue                                 ← right-click menu
    TransportBar.vue                                     ← audio source toggle (Synth / Video)
    VideoPlayer.vue                                      ← YouTube iframe API wrapper, rAF loop
    VideoSyncEditor.vue                                  ← tap-to-mark UI + fine-tune sliders
  composables/
    useAlpineBridge.js                                   ← CustomEvent Alpine↔Vue bridge (stripped to 2 handlers)
    useTabModel.js                                       ← melody+sections → reactive TabModel
    useCursor.js                                         ← cursor state machine + navigation
    useNoteInput.js                                      ← fret entry, delete, rest↔note
    useReflow.js                                         ← duration changes, repositionMeasure
    useChordSync.js                                      ← extractFretsAtChord + applyVoicingToChord (beat-positioned, uses chordOffsets)
    useSelection.js                                      ← copy/paste, Shift+arrow range selection
    useUndo.js                                           ← command stack with measure snapshots
    useSidebarStore.js                                   ← shared reactive store between TabEditor + TabSidebarApp
    useChordGridOps.js                                   ← all chord grid mutations (Pattern A + B)
    useGridSelection.js                                  ← chord grid selection: click/ctrl/shift/range
    useChordClipboard.js                                 ← chord grid copy/cut/paste
    useVoicingPicker.js                                  ← module-level singleton store for VoicingPicker
    useChordPicker.js                                    ← module-level singleton store for ChordPicker
    useAudioEngine.js                                    ← tab audio playback (Tone.js)
    useChordAudio.js                                     ← chord grid audio playback
    useVideoSync.js                                      ← audioSource, isVideoMaster, mappings, videoBeat
  utils/
    constants.js                                         ← SMUFL glyphs, layout dims, tick math
    svgHelpers.js                                        ← beam/tie/flag/rest SVG generation
    musicXmlWriter.js                                    ← TabModel → MusicXML string
    chordFormat.js                                       ← wrappers for sbnFormatChordHtml / sbnRenderDiagramSVG
    voicingApi.js                                        ← AJAX wrappers for voicing search endpoints
    tabModelFacade.js                                    ← window.__sbnTabModel singleton
```

**Note:** `tab-editor.js` mounts `TabEditor.vue` (into `#sbn-editor-content`) and `TabSidebarApp.vue` (into `#sbn-tab-sidebar`) separately.

### Working model shape
```
TabModel { timeSignature, ticksPerMeasure, sections: SectionModel[], chordVoicings{} }
SectionModel { id, name, lineBreaks[], measures: MeasureModel[] }
MeasureModel { index, events: TabEvent[], actualTicks, repeatStart/End, volta, chordNames[],
               chordOffsets[], chordBeats[] }
  chordOffsets[i] — beat offset of chord i from measure start (quarter beats, 0-based)
  chordBeats[i]   — duration of chord i in quarter beats
  Both always in sync with chordNames[]. Set by parseMeasure() from MusicXML tick data,
  or by _recomputeEvenOffsets() when chords are added/removed manually.
TabEvent { id, tick, tickInMeasure, duration, ticks, voice, isRest, notes: TabNote[],
           tieStart/Stop, stemDir, flagCount, beam1/2, beamWith, tuplet*, xPos }
TabNote { string, fret, pitch, octave, tieStart, tieStop, tieEndEvent?, tieEndNote? }
```

### Chord timing model (beat-grid layout)
`parseMeasure()` in `edit.blade.php` walks measure children sequentially. Each `<harmony>` element gets `beatInMeasure = tickDivs / divisions` and `beats` from the gap to the next harmony (or measure end). Gives 8th-note precision from MusicXML.

On save, `exportAlpineSections()` serializes `beatInMeasure`/`beats` back onto each chord object so timing survives round-trip through `json_data`. On load, `useTabModel.js` reads these fields into `chordOffsets[]`/`chordBeats[]`.

`ChordMeasure.vue` uses `chordPositionStyle(ci)` to absolutely position each `ChordCard` at `left: (offset/bpm*100)%` with `width: (dur/bpm*100)%`. A `sbn-ve-beat-grid` layer renders one dot per quarter-beat — visual metronome markers, not barlines.

### Key conventions
- Model uses **`ref()`** (deep reactive), NOT `shallowRef`
- Tick constants: whole=1920, half=960, quarter=480, eighth=240, sixteenth=120, thirty-second=60
- Chord grid provide/inject: `TabEditor.vue` provides `model`, `globalIndexOf`, `chordGridOps`, `setChordName`, `inlineRenameTarget`, `triggerInlineRename`, `gridSelection`, `chordClipboard`, `chordPicker`, `voicingPicker`, `renameSection`, `addMeasureToSection`, `deleteSection`, `sectionCount`, `rowShrink`, `rowGrow`, `rowSplit`, `setBarsPerRow`
- `useVoicingPickerStore` and `useChordPickerStore` are **module-level singletons** — both Vue apps share the same instance without provide/inject
- `VoicingPicker.vue` Teleports unconditionally into `#sbn-vp-slot`

### Undo patterns
All chord-grid ops go through `useChordGridOps.js` via `useUndo.wrapCommand`:

**Pattern A — chord name / voicing mutation (fast, per-measure snapshot):**
```js
undo.wrapCommand('Rename chord', [gi], () => { /* mutate */ });
```

**Pattern B — structural op (slow, full-model serialize/deserialize):**
```js
undo.wrapCommand('Insert bar', [], () => { tabModel.insertMeasureAfter(si, mi); }, {
  serializeModel: tabModel.serializeModel,
  deserializeModel: tabModel.deserializeModel,
  afterApply: () => dispatchEvent(new CustomEvent('sbn-tab-sections-sync')),
});
```

### `chordVoicings` key management
- `insertMeasureAfter` / `deleteMeasure` / `moveMeasure` call `_reindexChordVoicingKeys` inline
- `deleteChords` calls `_compactChordIndicesInMeasure` after removing a chord slot
- `setChordName` calls `_renameVoicingKey` (new key = `newName@gi.ci`)
- Keys are always clean — no orphan pruning needed
- `ChordCard.vue` resolves voicing: `cv["name@gi.ci"] || cv["name"] || null` — positional key takes priority over global name key

### Inline chord rename
Both chord grid and tab view share a single `inlineRenameTarget = ref({ gi, ci, ts })` provided from `TabEditor.vue`.

- **Chord grid (`ChordCard.vue`):** watches `inlineRenameTarget`; swaps chord name `<span>` for `<input class="sbn-ve-chord-name-input">` when `gi/ci` match; commits via `chordGridOps.setChordName`
- **Tab view (`TabMeasure.vue`):** same watch; swaps chord label span for `<input class="sbn-ve-chord-name-input sbn-tab-chord-rename-input">` in the chord strip; commits via injected `setChordName`
- `triggerInlineRename(gi, ci)` is provided globally and used by both `ChordGridView` (context menu "Rename chord") and `TabEditor` (chord context menu "Rename chord")
- View switch (`setViewMode`) clears `inlineRenameTarget` to prevent stale open inputs
- Global `onKeydown` guard: returns early when `e.target.tagName === 'INPUT'` so numbers/backspace/delete reach the input

### Inline chord rename — source discrimination
`inlineRenameTarget` carries a `source` field (`'tab'` | `'chord'`) to prevent cross-editor bleed:
- `ChordCard.vue` watch fires only when `source !== 'tab'`
- `TabMeasure.vue` watch fires only when `source === 'tab'`
- All tab-triggered renames (double-click on chord label, voicing assign with empty name) set `source: 'tab'`

Tab rename UX: single-click on chord label starts a 220ms timer then opens rename; double-click cancels the timer and opens immediately (mirrors chord-grid double-click behaviour).

Empty chord card (`ChordCard.vue`): renders a `?` placeholder so the card is clickable; body-click immediately opens inline edit when the name is empty.

### Row layout and bars-per-row
`SectionModel.lineBreaks[]` is an array of per-row bar counts (e.g. `[4, 4, 3]`). **Never recomputed globally** — all structural mutations now touch only the affected row:

| Operation | `lineBreaks` effect |
|-----------|---------------------|
| Insert bar | `_growRowAt(sec, localMi)` increments the row containing the insertion point |
| Delete bar | `_shrinkRowAt(sec, localMi)` decrements (before splice); removes row entry if it hits 0 |
| Split section | `lineBreaks` array is sliced at the row boundary — each half keeps its original rows |
| `patchStructure` splitSection / deleteSection | Same slice logic; falls back to `_resetUniformLineBreaks` only if `newBreaks` is empty |

**Bars-per-row control:** each section header (both chord and tab view) has a `cols` number input (1–12). `@change` calls `setBarsPerRow(si, n)` which uniformly redistributes all rows in that section. Injected via `provide('setBarsPerRow', ...)`.

### Structural mutations and volta/repeat stamping
`_reindexGlobalMeasures()` in `useTabModel.js` updates `m.index` after any structural change but does **not** update volta/repeat flags (which are index-keyed in `voltaEndings` / `repeatMarkers` refs).

`_restampStructuralFlags()` is called after every `_reindexGlobalMeasures()` call. It:
1. Resets all `repeatStart/End`, `volta`, `voltaStart`, `voltaEnd` on every measure
2. Re-applies from `repeatMarkers.value` (keyed by `m.index`)
3. Re-applies from `voltaEndings.value` (keyed by `m.index.toString()`), walking forward with an `activeVolta` cursor; closes open brackets at the last volta measure if none was closed explicitly

All structural functions call both in sequence: `insertMeasureAfter`, `insertMeasureBefore`, `deleteMeasure`, `addSection`, `deleteSection`, `splitSection`, `deleteMeasuresByGlobalIndices`, `moveMeasure`, and the `patchStructure` splitSection/deleteSection paths.

### Volta serialization round-trip
`_buildVoltaEndingsFromModel()` serializes live measure volta flags → `{ gi: {type,number,text} }` map:
- `voltaStart` → emits `{ type:'start', number, text }` at that gi
- `voltaEnd && volta && !voltaStart` → emits `{ type:'stop' }` at that gi
- **Single-bar bracket** (`voltaStart && voltaEnd` on same measure) → emits both start at `gi` **and** stop at `"gi_stop"` (string key with `_stop` suffix)

`useTabModel` populate pass reads `ve[m.index + '_stop']` for the stop-entry fallback so single-bar brackets survive save/reload.

### Tab chord operations (context menu)
Right-clicking a chord slot in tab view or the measure background dispatches to `handleTabContextAction(actionId, measureIndex, chordIndex, clickBeat)`:

| Action | Handler |
|--------|---------|
| `renameChord` | `triggerInlineRename(gi, ci)` |
| `changeVoicing` | `voicingPickerStore.openForTab(...)` with current frets pre-filled |
| `addChordSlot` | `chordGridOps.addChordAtBeat(gi, clickBeat)` — snaps to 0.5-beat grid |
| `identifyChord` | `extractFretsAtChord()` → chord API → `_showIdentifyConfirm()` |
| `deleteChord` | `chordGridOps.deleteChords(gi, ci)` |

**Right-click on measure background** (no chord slot): `clickBeat` is computed from `(clientX - rect.left) / rect.width * bpm`. Slot lookup uses actual `chordOffsets[]` (last slot whose offset ≤ clickBeat). If `identifyChord` fires with no existing slot, a new slot is first created at `clickBeat` via `addChordAtBeat`.

### Identify-from-tab flow
1. `extractFretsAtChord(measure, ci, ticksPerMeasure)` — reads tab note events in the chord's beat window (uses `chordOffsets`/`chordBeats` when available); returns `{ frets, position }` where `frets` is a 6-char diagram string (position 0 = low E)
2. POST to `/admin/chord-shapes/identify-from-frets` → returns chord name
3. `_showIdentifyConfirm(oldName, newName, gi, ci, tabData)` dispatches `sbn-tab-identify-result` with `tabData` in detail
4. Alpine shows confirm toast; on confirm: `window.__sbnTabModel.setChordNameWithVoicing(gi, ci, newName, tabData)`
5. Vue writes chord name (with undo) + `chordVoicings["name@gi.ci"] = { frets, position, fingers: '000000' }`

**Note:** The voicing written is derived from actual tab frets, not the DB diagram library. It will not automatically match a DB-canonical voicing even if the frets are identical — the diagram shows exactly what was played.

### Import identifier policy
Two identifier paths run after every MusicXML import (`inferKeyFromChords` → `identifyTabVoicings` → `detectHarmonyMismatches`):

| Source | Behavior |
|--------|----------|
| **`Tab*` placeholder** (bar had no `<harmony>` element — fret content only) | Auto-applied: chord name + voicing key renamed immediately; logged as info/warn |
| **Written `<harmony>` element** (chord name from score) | Suggestions-only: `_harmonyMismatch = { written, suggested }` flag set; badge shown in editor for user review; never auto-renamed |

`VoicingCrossref::processLeadsheet()` is called on every `store()` and `update()` to keep `sbn_voicing_usage` in sync. A null-guard in `extractVoicings()` skips any remaining `Tab*` keys that weren't resolved.

### VoicingOverview — Song Voicings panel
`VoicingOverview.vue` renders the resting-state right panel (Chords/Tab view, no picker open). A `⋯` overflow menu in the header contains all actions:

| Menu item | Action |
|-----------|--------|
| **Clean unused** | `picker.cleanUnusedVoicings()` — removes entries not referenced by any chord slot |
| **Clear all** | Confirm dialog → `picker.clearAllVoicings()` — empties `chordVoicings`, undoable |
| **Fill voicings** | Toggles the fill panel (see below) |
| **Apply rhythm** | Toggles the apply-rhythm panel (see below) |

The fill panel and apply-rhythm panel are mutually exclusive — opening one closes the other.

#### Fill voicings panel
POST `admin/leadsheets/{id}/fill-voicings` → `LeadsheetController::fillVoicings()`.

#### Apply rhythm panel
POST `admin/leadsheets/{id}/apply-rhythm` (leadsheets) or `admin/exercises/{id}/apply-rhythm` (exercises) → `LeadsheetController::applyRhythm()` / `applyRhythmToExercise()`. Both delegate to `_applyRhythmCore()`.

Replaces `tab_xml` + `melody` + `rhythmPattern` in `json_data` for an existing leadsheet or exercise. Voicing resolution order per chord slot:
1. Positional key `"name@gi.ci"` (hand-edited)
2. Base-name key `"name"` (filled/imported)
3. Gap-fill via `ProgressionBuilder` (same builder as Fill voicings, user-selected style/extensions)

Gap-filled voicings are written back into `chordVoicings` so they persist.

**Time-stretch:** if the rhythm pattern's `time_signature` differs from the sheet's, stroke tick offsets are scaled so the pattern fills exactly one bar. A 2/4 pattern applied to a 4/4 bar doubles all durations (16ths → 8ths etc.) — the feel is identical, just the note values change.

**String assignment** (`RhythmMaterializer::_resolveFingerStrings`):
- **≤ 4 available notes:** thumb = lowest-pitch string (highest string#); fingers = remaining strings low→high pitch
- **> 4 available notes:** thumb = `max($availableBass)` as before; fingers = strings 4 (D), 3 (G), 2 (B) that are non-x
- **Strum patterns:** all non-muted strings on every stroke
- **Soft voice leading:** if the previous chord's finger strings are all available in the current voicing, reuse them unchanged; `VoicingMaterializer` threads `$prevFingerStrings` through the chord loop

**Frontend reload path** (critical): on success `VoicingOverview.vue` dispatches `sbn-rhythm-applied` → Alpine replaces `parsed` + `tabXml`, resets `_tabInitDone` / `_tabVueInitialized`, calls `_dispatchTabInit()` for a full Vue reload.

**Exercise vs leadsheet differences:**
- Exercise: reads `content_json` (array-cast), `time_sig`; writes to `sbn_exercises`
- Leadsheet: reads raw `json_data` string, `time_signature`; writes to `sbn_leadsheets`; runs `VoicingCrossref::processLeadsheet()` after save

Options: **Style** (Jazz / Latin / Pop / Blues — same presets as Machine Room), **Extensions** (Basic / Extended), **Keep existing voicings** checkbox.

Backend flow:
1. Parse `shortcode_content` → flat chord list; strip slash-bass (`G/D` → `G`) so builder gets a non-empty pool; store original name for voicing key
2. Map existing `chordVoicings` base-name entries to pinned slot indices (when Keep existing is on)
3. `HarmonicContext::buildFromChordSequence()` → `ProgressionBuilder::buildVoicings()` with `skip_numeral_upgrade: true` (respects written chord names, no jazz quality upgrade) and `voicing_style: auto`
4. Merge new base-name voicings into `json_data.chordVoicings`; save; run `VoicingCrossref::processLeadsheet()`
5. Return `{ voicings, filled, pinned }`

Frontend patch path (critical — direct model mutation does not work):
- `runFill` dispatches `sbn-chord-voicings-patch` → `useAlpineBridge` merges into `chordVoicings` ref → `useTabModel` watch fires `patchChordVoicings()` → `model.value.chordVoicings` updated reactively
- `sbn-voicings-filled` → Alpine merges into `parsed.chordVoicings` + calls `markDirty()` so next save persists the new keys into the shortcode

**Builder gotchas for fill context:**
- `dim` quality alias now includes `o7`/`dim7` — plain diminished triads (`Cdim`, `A#dim`) had no pool entries and caused Viterbi to return `[]` for the entire sequence
- `skip_numeral_upgrade: true` prevents jazz quality upgrade (`C` → `Cmaj7` etc.) mutating stored chord names
- `formatVoicing()` accesses `$v->diagram_data ?? null` (null-safe) to handle alias shapes

### Multi-bar clipboard and structural ops
Both chord and tab views support multi-bar selection for clipboard and structural operations.

**Chord view** — multi-bar selection (Shift-click / Ctrl-click across measures):
- `Ctrl+C` → `copySelection(sel)` — stores one clipboard entry per measure, preserving bar boundaries (`{ mode: 'measures', measures: [{chordNames, voicings}] }`)
- `Ctrl+V` → `pasteMeasure(targetGi)` — spreads N clipboard measures across N consecutive destination measures
- `Ctrl+X` → `cutSelection(sel, deleteChords)` — copy + clear slots
- `Delete`/`Backspace` with >1 bar selected → `deleteBars(selGis)` (structural delete)
- Context menu: "Copy N bars" / "Cut N bars" / "Insert N bars before/after" / "Delete N bars" when N > 1

**Tab view** — multi-bar selection (Shift-click across measures):
- `Ctrl+C` with >1 bar → `copyMeasures(measureObjects)` — stores full events + chordNames per measure (`mode: 'measures'`)
- `Ctrl+V` when clipboard is `mode:'measures'` → `preparePasteMeasures(allMeasures, startGi)` — replaces events in N consecutive destination measures
- `Ctrl+X` with >1 bar → copy + clear to whole rests (undoable)
- `Delete`/`Backspace` with >1 bar → structural delete (`deleteMeasuresByGlobalIndices`)
- Context menu: "Insert N bars after/before" / "Delete N bars"

**Single-bar / note-level operations are unchanged** when only one measure is selected.

### Applying voicings from tab view
`useVoicingPickerStore.applyVoicing()` has two paths:
- **`keyMatch` path** (picker opened from chord grid): sets chord name globally + applies tab frets with `skipIfTabExists: true` — will NOT overwrite an existing tab bar that already has notes
- **`tabSrc` path** (picker opened from tab view via `voicingPickerStore.openForTab(...)`): always writes through; uses specific `gi/ci` coords; if `!keyMatch && _tabSource && !oldName`, writes name directly into `m.chordNames[ci]` and keys voicing as `"newName@gi.ci"`

### Keyboard shortcuts (tab editor)
| Key | Action |
|-----|--------|
| ← → | Navigate events |
| ↑ ↓ | Navigate strings |
| Tab / Shift+Tab | Navigate measures |
| Home / End | First/last event in measure |
| 0–9 | Enter fret number (two-digit with 600ms timeout) |
| Delete / Backspace | Remove note on cursor string (single bar); structural delete of selected bars (multi-bar) |
| Ctrl+↑ / Ctrl+↓ | Shift note to adjacent string, transposing fret (±5; ±4 across the B↔G boundary). No-op at string 1/6 boundary or if new fret would be out of 0–24 range. |
| Ctrl+1–6 | Set duration (whole→32nd) |
| + / = / - | Shorter / longer duration |
| . | Toggle dotted |
| T | Toggle tie |
| A | Insert rest after cursor event |
| Shift+←/→ | Extend note selection (within measure) |
| Shift+↑/↓ | Select all events at the current beat (same `tickInMeasure`) — column-select across strings |
| Shift+click measure | Extend bar selection across measures |
| Ctrl+C/X/V | Copy/cut/paste (note-level, single bar, or multi-bar depending on selection) |
| Ctrl+Z / Ctrl+Shift+Z | Undo/redo (unified — covers chord grid + tab + voicings) |
| Space | Play/pause toggle (global — works in all contexts) |
| M | Create video sync point at current measure (global) |
| ? | Keyboard shortcut reference overlay |
| Escape | Clear selection / return to navigate |

---

## AUDIO PLAYBACK ENGINE

### File structure
```
resources/js/audio/
  engine/
    AudioEngine.js       ← main entry; manages Tone.js context + voice scheduling
    Scheduler.js         ← beat-accurate event queue
    PlaybackClock.js     ← clock source (Tone.js Transport)
    ToneClock.js         ← Tone.js clock adapter
    MediaElementClock.js ← clock adapter for HTML media elements (video sync)
    voices/
      PitchedSynth.js    ← guitar synth voice (Tone.js)
  adapters/
    chordVoicingsToEvents.js     ← chord grid → audio events
    chordProgressionToEvents.js  ← progression builder → audio events
    chordDiagramToEvents.js      ← single chord diagram → audio events
    rhythmPatternToEvents.js     ← rhythm pattern → audio events
    tabMeasureToEvents.js        ← tab melody → audio events
    pitchToMidi.js               ← pitch/octave string → MIDI number utility
```

### Composables (per-context wrappers)
- `resources/js/tab-editor/composables/useAudioEngine.js` — tab editor (melody + chords)
- `resources/js/tab-editor/composables/useChordAudio.js` — chord grid standalone playback
- `resources/js/leadsheet/composables/useAudioEngine.js` — public leadsheet viewer (Phase 8)
- `resources/js/rhythm/composables/useAudioEngine.js` — rhythm pattern admin

### Transport clock routing
```js
const transportPlaying = computed(() => {
    if (videoSync.isVideoMaster.value) return videoSync.videoPlaying.value;
    return isPlaying.value || isChordPlaying.value;
});

const transportBeat = computed(() => {
    if (videoSync.isVideoMaster.value) return videoSync.videoBeat.value ?? 0;
    if (isPlaying.value)      return currentBeat.value;
    if (isChordPlaying.value) return chordCurrentBeat.value;
    return currentBeat.value || chordCurrentBeat.value;
});
```

---

## TAB PLAYBACK VISUAL SYSTEM

### Metronome column (`TabMeasure.vue`)
- **Position:** `getXm(bSnapped / bpm)` where `bSnapped = Math.floor(beatInMeasure)` — strict quarter-beat grid
- **Size:** half-width 9px, height = `LAYOUT.stringSpacing * 5 + 8`, `y = LAYOUT.stringAreaTop - 4`, `rx = 3`
- **Style:** `.sbn-tab-metronome-col` — fill accent, opacity 0.1
- **Visibility:** only when `isPlaying && isPlayingMeasure`
- Moves strictly in quarter notes regardless of note duration

### Red note highlight
- `playingEventId` computed: finds voice-1 event where `tickInMeasure ≤ currentTick + 1`
- Watch applies/removes `.sbn-beat-active` class on `[data-event-id]` elements inside `svgEl`
- CSS: `.sbn-tab-note-text.sbn-beat-active { fill: #ef4444 !important }`

### Cursor ring visibility
`TabCursor.vue` accepts `isPlaying` prop — circle and pending digit hidden during playback. Hit targets stay active for mouse interaction.

### Seek-on-click (stopped state)
`seekToMeasure(gi)` in `TabEditor.vue`:
- **Playing:** `seekTab(beat) + seekChord(beat)` — immediate clock jump
- **Stopped:** `seekTab(beat) + seekChord(beat)` — updates `currentBeat` ref; no auto-start
- `useAudioEngine.play()` and `useChordAudio.play()` re-seek to `currentBeat` before starting

---

## VIDEO SYNC ARCHITECTURE (Phase D)

### Overview
Soundslice-style playback: when "Video audio" mode is active, the YouTube player is the clock — it supplies audio and drives the score cursor at 60fps via `requestAnimationFrame`. The synth engine stays idle. When "Synth audio" is selected, video pauses and synth engine drives playback as before.

All sync interpolation runs in **play-position** space (the repeat-expanded timeline) — see [SBN-Audio-Reference §5.1](SBN-Audio-Reference.md#51-repeat--volta-playback-model) for the gi vs play-position model. The mapping table is stored gi-keyed for authoring ergonomics; conversion happens at the boundary in `useVideoSync.mappingsByPosition`.

### Data model
```js
// Stored in leadsheet json_data.videoSync
videoSync: {
  videoId: string,
  videoType: 'youtube' | 'hosted',
  audioSource: 'synth' | 'video',
  mappings: [
    { measureIndex: number, videoTime: number },  // seconds
    // Duplicates per measureIndex ARE allowed and expected for repeated bars.
    // A bar that plays in both A1 and A2 of an AABA carries two entries; they
    // are paired with that gi's successive play positions in videoTime order.
  ]
}
```

### Audio Source Switch
UI toggle in `TransportBar.vue`: **Synth** / **Video audio**
- Disabled when `!hasVideo`
- Persists in `json_data.videoSync.audioSource`
- Computed `isVideoMaster = audioSource === 'video' && hasVideo`

### Transport actions (branch on isVideoMaster)
| Action | Video Master | Synth Master |
|--------|--------------|--------------|
| **Play/Pause** | `player.play()` / `player.pause()` | `playTab()` / `pauseTab()` |
| **Stop** | `player.pause(); player.seekTo(0)` | `resetTab(); resetChord()` |
| **Seek measure** | `player.seekTo(measureToVideoTime(gi))` once | `seekTab(pos * bpm); seekChord(pos * bpm)` where `pos = firstPositionForGi(seq, gi)` |
| **Seek specific pass** | n/a — pick the row in the sync editor table | `seekToPosition(mark.pos)` via the `seek-to-mapping` event from `VideoSyncEditor` |

### Mode switching mid-session
Watcher on `videoSync.audioSource`:
- **synth → video:** Pause synth, don't auto-start video
- **video → synth:** Pause video, seed synth from `videoSync.videoPlayPosition` (preserves *which pass* of a repeat the video was on — not just the gi)

### Tap-to-mark cursor (repeat-aware)
The tap cursor (`useVideoSync.tapCursor`) is a **play position**, not a gi. Each `M` press:
1. Resolves the cursor's gi via `seq[pos]` (exposed as `tapCursorGi`).
2. Appends a mapping for that gi at the current `videoTime` — never overwrites, so AABA's A2 pass gets its own entry alongside A1's.
3. Advances the cursor by one play position.

`TabEditor.vue` provides **two** related injections:
- `tapCursor` (computed → gi) — what measures key off for the tap-target highlight; mapped through the sequence so the highlight follows the cursor across repeats.
- `tapCursorPos` (raw play position) — what `VideoSyncEditor` reads to drive its +/- buttons and the numeric `Pos` field.

**Keyboard ownership smell:** the `M` key is bound both in `TabEditor.vue` (legacy: marks the currently-playing measure) and in `VideoSyncEditor.vue` (current: marks the tap-cursor gi). `TabEditor`'s handler self-guards with `!videoSidebarOpen.value` so only one fires at a time. Adding a third `M` consumer would re-introduce the double-mark bug — consider a small key-ownership registry if/when that happens.

### Components
| Component | Purpose |
|-----------|---------|
| `VideoPlayer.vue` | YouTube iframe API wrapper; rAF timeupdate; exposes `seekTo(t)`, `play()`, `pause()` |
| `useVideoSync.js` | Composable: mappings[], audioSource, isVideoMaster, `mappingsByGi`, `mappingsByPosition`, `videoPlayPosition`, `videoBeat`, `tapCursor`/`tapCursorGi`, bidirectional sync |
| `VideoSyncEditor.vue` | Tap-to-mark UI; per-pass row table with `1/2`, `2/2` labels; drag-to-nudge for single-mark bars; clicking a row uses `seek-to-mapping` to land on the right pass |
| `SyncPointBadge.vue` | Orange dot on the barline. Widens to a pill with `N·count` when a bar has multiple marks; drag-to-nudge disabled in that case (ambiguous which pass — per-pass editing happens in the editor table) |
| `TransportBar.vue` | Audio source toggle (Synth / Video audio) |

### Interpolation pipeline
1. `VideoPlayer.vue` emits `timeupdate` at ~60fps (rAF).
2. `useVideoSync.onVideoTimeUpdate(t)` calls `videoTimeToPlayPosition(t)` — binary search over `mappingsByPosition` (pos-keyed, sorted) + linear interpolation between adjacent marks.
3. The fractional play position lands in `videoPlayPosition`; `videoBeat = pos * beatsPerMeasure` feeds `transportBeat` in `TabEditor`.
4. The `transportBeat` watcher does `floor(beat/bpm)` → `giAtPosition(seq, pos)` → `playingMeasureIndex`. Repeated bars correctly re-highlight on each pass because `seq[pos]` resolves to the same gi.

### Mapping shape: `Map<gi, Array<VideoSyncMark>>`
Provided as `videoSyncMap` (when the sync sidebar is open). Each entry:
```ts
type VideoSyncMark = {
  videoTime: number;    // seconds in the source video
  pass: number;         // 1-based; earliest videoTime in this gi = pass 1
  pos: number;          // play position this mark is paired with
  mappingIdx: number;   // index into the live mappings array (stable handle for edit/remove)
};
```
Badges read this for the count indicator; the editor table reads it for per-pass labels and the delete-by-identity path.

### Followups (deferred)
- Shared sequence builder + `mappingsByPosition` tests + `VideoSyncMark` typedef — see [SBN-Audio-Reference §9 Video sync](SBN-Audio-Reference.md#video-sync).
- **Per-pass popover on the badge.** Currently a bar with multiple marks shows a count pill; per-pass editing requires opening the sync editor.
- **Distribute markers across a repeat.** `distributeMarkers()` interpolates linearly between adjacent gi-keyed marks. Works fine for through-composed sections but would produce a smooth ramp across a repeat boundary rather than the actual jump. Users who need pass-2 marks tap them manually for now.

---

## DESIGN SYSTEM

Four files establish the global design language. Always consult `SBN-Design-Reference.md` before writing CSS.

```
public/css/sbn-design-system.css   ← tokens + base components, loaded FIRST
public/css/chord-symbols.css       ← chord name typography, loaded second
public/css/admin2.css              ← admin shell layout, loaded third (admin only)
public/js/chords.js                ← chord diagram renderers + toast
```

**Rule:** Module CSS files never define colors or base component shapes — reference `--clr-*` variables only.

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

### Frontend portability (Phase 8 readiness)
- **Already portable:** `sbn-design-system.css`, `chord-symbols.css`, `chords.js`
- **Needs extraction:** tab SVG classes (`.sbn-tab-note-text`, etc.) currently in `leadsheets.css` → move to `sbn-design-system.css` when Phase 8 starts
- **Public chord grid:** read-only Alpine `x-for` loop over `$leadsheet->json_data`, same CSS, no Vue, no editing
- **Public tab viewer:** `TabViewer.vue` reusing `TabMeasure.vue` stripped of editing composables
- **Builder picker** is the clean prototype for the public voicing picker

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
public/css/sbn-design-system.css    -- §1 tokens, §2 cards, §2b fretboard, §2c chord-card
                                       §2d selection frame, §3 buttons (.sbn-btn-accent, .sbn-btn-danger),
                                       §4 badges, §5 panels, §6 forms, §7 voicing picker,
                                       §7b fill-voicings panel (.sbn-fill-*),
                                       §8 chord grid cells, §9 context menu, §10 drag-to-reorder
```

### Leadsheets + editor
```
app/Models/Leadsheet.php
app/Services/LeadsheetParser.php  /  ChordShapeCalculator.php  /  VoicingCrossref.php
app/Http/Controllers/Admin/LeadsheetController.php
app/Http/Controllers/Admin/ProgressionDetectionController.php
resources/views/admin/leadsheets/index.blade.php
resources/views/admin/leadsheets/edit.blade.php   -- ~1,980 lines
public/css/leadsheets.css                         -- all editor + chord grid styles
```

### Tab editor + chord grid — Vue.js
```
package.json  /  vite.config.js
resources/js/tab-editor.js
resources/js/tab-editor/  (see Tab Editor section above)
public/build/  (compiled by npm run build)
```

### Creation services
```
app/Services/LeadsheetScaffolder.php     -- scaffoldBlank() + scaffoldFromSequence()
app/Services/VoicingMaterializer.php     -- chord sequence → MusicXML + melody + chordVoicings
app/Services/RhythmMaterializer.php      -- expand(voicing, pattern, div, beats) → strokes[]
app/Services/NumeralResolver.php         -- isNumeral() + resolveSequenceItems()
app/Services/ChordSequenceParser.php     -- parse free-text chord sequence (barred/chordpro/space)
```

---

## KNOWN BUGS

### Active (to fix before Phase 8)

| # | Bug | Location | Notes |
|---|-----|----------|-------|
| B2 | **Chord diagram dismissal** — `VoicingCrossref::clearLeadsheetReferences()` deletes all drafts including dismissed ones | `app/Services/VoicingCrossref.php:clearLeadsheetReferences()` | Fix: preserve `status='dismissed'` rows on reprocess |

### Deferred (low priority)

| # | Bug | Notes |
|---|-----|-------|
| B3 | **Dotted note overfill after editing** — misbehaves when editing dotted notes in bars already through `repositionMeasure` | Partially investigated |

---

## IMPROVEMENTS (deferred)

| # | Item | Who |
|---|------|-----|
| I1 | `scoreVL` calibration — ProgressionBuilder occasionally picks distant voicings; common-tone bonus + fret-distance weighting need rebalancing | Opus |
| I2 | Mobile voicing picker modal — `VoicingPicker.vue` has `variant="modal"` path for <1024px but untested | Deferred to Phase 8 |
| I3 | Library index SVG renderer — `chords/index.blade.php` has own inline SVG renderer (~150 lines) separate from `chords.js`; unify | Sonnet |
| I4 | **Beaming polish around tuplets** — 16ths/8ths adjacent to a tuplet currently render as isolated flagged notes; requires coordinated changes in `recomputeBeams`, `renderBeams`, writer/parser | Opus |

---

## CREATION FLOWS

All paths land in the same editor. Storage contract is identical regardless of how the sheet was created.

### Paths at a glance

| Path | Status | Entry |
|------|--------|-------|
| Blank sheet | ✅ Shipped | "+ New leadsheet" → "Blank" |
| From Jazz Standards DB | ✅ Shipped | "+ New leadsheet" → "From progression" → Jazz Standards tab |
| From saved progression | ✅ Shipped | "+ New leadsheet" → "From progression" → Saved Progression tab |
| Rhythm-aware materialization | ✅ Shipped | Layout step of progression modal → Rhythm pattern select |
| Re-apply rhythm to existing sheet | ✅ Shipped | Song Voicings panel → ⋯ → Apply rhythm |
| Save as exercise (full) | ✅ Shipped | Edit page → "→ Save as Exercise" button |
| Save bar selection as exercise | ✅ Shipped | Right-click any bar or multi-bar selection → "Save bar(s) as exercise" |
| LLM song lookup | ✅ Shipped / ⚠️ Maintenance mode | "+ New leadsheet" → "From song lookup" |
| Audio transcription | ⚠️ Experimental | Embedded in song lookup modal |

**Primary workflow:** Jazz Standards DB for jazz/bossa standards; saved progressions for custom forms; blank sheet + manual entry for everything else.

### Blank sheet (L1)

**Backend:** `LeadsheetController@createBlank` → `LeadsheetScaffolder::scaffoldBlank()` → redirects to editor with empty grid.

**Modal fields:** title, composer (optional), key, tempo, time signature, section structure (N bars or named sections), optional pickup bar.

### From progression (L2 + L2.5)

2-step wizard modal. **Step 1 — Source:**

| Source | What you pick |
|--------|--------------|
| **Jazz Standards DB** | ~1,400 entries (Mike Oliphant dataset). Instant: zero API cost, zero hallucination, correct bar count + section labels. Primary path for jazz/bossa. |
| **Saved Progression** | Any `ChordProgression` row from the Progression Builder library. Numerals resolved via `NumeralResolver` in the selected key. |

**Step 2 — Layout fields:** key, tempo, time signature, bars per chord, build voicings now, voicing style (`popular` / `shell` / `drop2` / `archetype`), extension mode (`basic` / `extended`), rhythm pattern.

**Rhythm-aware materialization (L2.5):** `RhythmMaterializer::expand(voicing, pattern, divisions, beatsPerMeasure, prevFingerStrings)` converts a `RhythmPattern` + voicing into stroke events for one bar:
- **Time-stretch:** stroke tick offsets scale by `targetBarTicks / patternBarTicks` so any pattern time signature fills exactly one target bar
- **String assignment:** see "Apply rhythm panel" above for the ≤4 / >4 note rules and soft voice leading
- Thumb strokes → `max($availableBass)` — lowest-pitched non-muted bass string
- Strum patterns (`category` contains "strum"): all non-muted strings; thumb_pattern ignored
- `json_data.rhythmPattern` stores the full pattern body (not just slug) so reload works without re-fetch
- `VoicingMaterializer` passes `$prevFingerStrings` through the chord sequence for voice leading continuity

**Key services:**
- `app/Services/LeadsheetScaffolder.php` — `scaffoldBlank()` + `scaffoldFromSequence()`
- `app/Services/VoicingMaterializer.php` — `materialize($sequence, $timeSignature, ?$rhythm)`
- `app/Services/RhythmMaterializer.php` — `expand($voicing, $pattern, $divisions, $beats, $prevFingerStrings=[])`
- `app/Services/NumeralResolver.php` — `isNumeral()` + `resolveSequenceItems()`
- `app/Services/ProgressionBuilder.php` — `selectVoicingsForSequence()` (category filter, most-popular fallback)

**Voicing behavior notes (2026-05-14 hardening):**
- Basic mode: no option-tone shapes in Pass 1 even on jazz chords
- Extended mode: appends voiced extensions to stored chord name when no extension was authored (e.g. plain `Eb7` voiced as Lydian-dominant stored as `Eb7(9,#11)`)
- `hasExplicitQuality` regex matches `6` → `C6` not upgraded to `Cmaj7`
- Tonic-family widening respects explicit quality tokens — `Cm6` stays `m6`
- `ChordVoicingSearch::findAliasMatches` iterates `(shape × alias)` pairs (was dropping all but last alt-root)

### Exercises

Exercises live in `sbn_exercises` (separate table from `sbn_leadsheets`). Exercise edit page uses the same `TabEditor.vue` via `exerciseEditor()` Alpine function. Full detail (sbn-sheet tag, SheetMiniPlayer, API, LessonPalette) in [SBN-Course-Reference.md §9](SBN-Course-Reference.md).

**Full-copy:** "→ Save as Exercise" button → `Admin\ExerciseController::createFromLeadsheet()` — copies the entire leadsheet.

**Bar-slice:** right-click any bar or multi-bar selection (only shown on leadsheets, not exercises) → "Save bar(s) as exercise" → title prompt → `POST /admin/exercises/from-leadsheet/{id}/slice`.

Slice logic (`ExerciseController::createFromLeadsheetSlice`):
- Accepts `measure_indices[]` (gi values from the Vue model).
- Iterates `content_json.sections[].measures[]` **positionally** (no `index` field in stored JSON) to find the selected bars; rebases each measure's event `tick` and `measureIdx` to start at 0.
- If `videoSync` is present: filters mappings to the selected gi range, offsets `measureIndex` by `-firstGi` and `videoTime` by `-firstVideoTime`. Stores the raw offset as `videoSync.videoTimeOffset`.
- `useVideoSync.setVideoSync()` adds `videoTimeOffset` back to every `videoTime` in memory so all interpolation runs on absolute YouTube timestamps unchanged. `getVideoSync()` subtracts it back out on save — the stored format always has times relative to the slice start plus the offset field.

### LLM song lookup (L3) — maintenance mode

Used for pop/rock not in the Jazz Standards DB. Pipeline: title + optional artist hint → `SongLookup.php` (Claude API + web search) → `IntermediateAnalysis` → `AnalysisToLeadsheet.php` → same `json_data` shape → editor. Not the focus of new investment.

Key services: `SongLookup.php`, `AnalysisToLeadsheet.php`, `RhythmHintMapper.php`.

### Audio transcription (L3a) — experimental

Produces a leadsheet from a YouTube URL. Embedded in the song lookup modal. Functional but imperfect. Full pipeline, known problems, and improvement roadmap in [Audio-Transcription-Architecture.md](Audio-Transcription-Architecture.md).

Six-stage summary: yt-dlp download → ffmpeg WAV → Python (`basic-pitch` + `librosa`) beat/pitch analysis → PHP melody reconstruction (range filter, beat-boundary clamping, no-dots grid) → optional Gemini pass → assembly.

### Storage contract

All paths produce the same shape — the editor has no creation-path-specific code:

```php
shortcode_content  // canonical [sbn_leadsheet]…[/sbn_leadsheet] body
json_data          // { sections, chordVoicings, melody, repeatMarkers, voltaEndings, videoSync?, rhythmPattern? }
tab_xml            // MusicXML for tab/melody (empty skeleton when no voicings built)
title, composer, song_key, tempo, time_signature, rhythm, measure_count, slug
```

`chordVoicings` is always a JSON object (`{}`), never an array — the editor's `cv["name@gi.ci"] || cv["name"]` lookup requires object access.

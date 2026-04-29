# SBN Teaching Hub — Admin Reference

> **Purpose:** Complete functional documentation for the SBN admin section. Covers all implemented modules, their architecture, data models, and the design system. Use this as the reference when building the public frontend.
> **Last updated:** 2026-04-29 (Phase B chord editing complete — inline rename, tab chord ops, identify-from-tab with voicing write)

---

## ADMIN MODULES AT A GLANCE

| Module | Status | Entry Point |
|--------|--------|-------------|
| Admin shell (auth, layout, sidebar, dashboard) | Done | `admin/dashboard/index.blade.php` |
| Rhythm patterns (CRUD) | Done | `admin/rhythms/` |
| Chord progressions (CRUD + occurrences) | Done | `admin/progressions/` |
| Chord diagrams + aliases + voicing crossref | Done | `admin/chords/` |
| Chord name styling component | Done | `public/js/sbn-chord-name.js` |
| Leadsheet model + parser + CRUD | Done | `admin/leadsheets/` |
| Leadsheet visual editor (chord grid + tab) | Done | `admin/leadsheets/edit.blade.php` |
| Shape calculator + voicing search + crossref | Done | `app/Services/` |
| Progression detection engine | Done | `app/Services/ProgressionDetectionController.php` |
| Analysis panel (in leadsheet editor) | Done | `edit.blade.php` (Alpine) |
| Enhanced voicing picker (context panel) | Done | `VoicingPicker.vue` |
| Reverse voicing lookup (identify_from_frets) | Done | `ChordShapeCalculator.php` |
| HarmonicContext + ProgressionBuilder services | Done | `app/Services/` |
| Progression builder admin UI | Done | `admin/progressions/builder.blade.php` |
| Tab viewer + design system restructure | Done | `TabEditor.vue` |
| Interactive tab editor (Vue 3 / Vite) | Done | `resources/js/tab-editor/` |
| Tab ↔ chord sync + `<harmony>` in writer | Done | `useChordSync.js` / `musicXmlWriter.js` |
| Vue-native chord grid + voicing picker | Done | `ChordGridView.vue` / `VoicingPicker.vue` |
| Audio playback engine (Tone.js) | Done | `resources/js/audio/` |
| Video sync (Soundslice-style, YouTube) | Done | `VideoPlayer.vue` / `useVideoSync.js` |

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

---

## LEADSHEET EDITOR

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
- Chord grid provide/inject: `TabEditor.vue` provides `model`, `globalIndexOf`, `chordGridOps`, `setChordName`, `inlineRenameTarget`, `triggerInlineRename`, `gridSelection`, `chordClipboard`, `chordPicker`, `voicingPicker`, `renameSection`, `addMeasureToSection`, `deleteSection`, `sectionCount`, `rowShrink`, `rowGrow`, `rowSplit`
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

### Data model
```js
// Stored in leadsheet json_data.videoSync
videoSync: {
  videoId: string,           // YouTube ID or hosted URL
  videoType: 'youtube' | 'hosted',
  audioSource: 'synth' | 'video',
  mappings: [
    { measureIndex: number, videoTime: number },  // seconds
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
| **Seek measure** | `player.seekTo(measureToVideoTime(gi))` once | `seekTab(beat); seekChord(beat)` |

### Mode switching mid-session
Watcher on `videoSync.audioSource`:
- **synth → video:** Pause synth, don't auto-start video
- **video → synth:** Pause video, seed synth position from current measure

### Components
| Component | Purpose |
|-----------|---------|
| `VideoPlayer.vue` | YouTube iframe API wrapper; rAF timeupdate; exposes `seekTo(t)`, `play()`, `pause()` |
| `useVideoSync.js` | Composable: mappings[], audioSource, isVideoMaster, videoBeat, bidirectional sync |
| `VideoSyncEditor.vue` | Tap-to-mark UI + mapping table with +/- 5sec fine-tune sliders |
| `TransportBar.vue` | Audio source toggle (Synth / Video audio) |

### Fine-tune slider
Each mapping row: range slider, ±5 seconds, step 0.1, real-time via `onFineTune()` → `addMapping()`.

### 60fps Video Sync
`VideoPlayer.vue` uses `requestAnimationFrame` (replaced 250ms `setInterval`) — seeks happen once on Play or click-to-seek, then rAF drives smooth cursor motion.

### Fractional measure indexing
`videoTimeToMeasureIndex(time)` in `useVideoSync.js` returns fractional measure index for smooth cursor. `videoBeat = measureIndex * beatsPerMeasure` drives metronome column + chord highlights.

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

### Rhythm patterns
```
app/Models/RhythmPattern.php
app/Http/Controllers/Admin/RhythmPatternController.php
resources/views/admin/rhythms/
public/css/rhythms.css
```

### Chord progressions
```
app/Models/ChordProgression.php
app/Http/Controllers/Admin/ProgressionController.php
resources/views/admin/progressions/
public/css/progressions.css
```

### Chord diagrams + voicing crossref
```
app/Models/ChordDiagram.php  /  ChordDiagramAlias.php  /  VoicingUsage.php  /  VoicingDraft.php
app/Http/Controllers/Admin/ChordController.php  /  VoicingController.php
resources/views/admin/chords/index.blade.php   -- has own inline SVG renderer (not yet unified)
resources/views/admin/chords/edit.blade.php
public/css/chords.css  /  voicings.css
```

### Leadsheets + voicing engine
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

### Progression builder
```
app/Services/HarmonicContext.php  /  ProgressionBuilder.php
app/Http/Controllers/Admin/ProgressionBuilderController.php
resources/views/admin/progressions/builder.blade.php
public/css/progression-builder.css
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
| I4 | **Beaming polish around tuplets** — 16ths/8ths adjacent to a tuplet currently render as isolated flagged notes; engraved notation bridges them with a single primary beam across the whole beat (tuplet keeps its own inner bracket/"3" label and secondary beams). Requires coordinated changes in `recomputeBeams` (shared `beamWith` across tuplet + non-tuplet), `renderBeams` (detect tuplet sub-span, draw "3" only there), and writer/parser (primary beam bridges tuplet; secondary beam tags only where appropriate) | Opus |

---

## ENVIRONMENT

- **Laravel 11** on Windows via Laravel Herd at `C:\Users\info\sbn-app`
- **SQLite** at `C:\Users\info\sbn-app\database\sbn.db`
- Auth: `lucas@soulbossanova.com` / `changeme123`
- Alpine.js via CDN + `@alpinejs/collapse` plugin
- **Vue 3 + Vite** for tab editor + chord grid
- Fonts: DM Sans + JetBrains Mono + Crimson Text (Google Fonts) + Bravura SMuFL

### Vite / build workflow
- `npm run dev` for HMR during development
- `npm run build` at end of each session for production bundle
- Page reload without dev server = blank tab view

---

## SONNET vs OPUS GUIDE

**Sonnet** (translation, porting, well-defined tasks):
- All Phase 8/9 feature implementation
- Bug fixes with clear root cause (B2, B3, I3)
- UI/CSS work, Blade views, admin CRUD
- Vue component additions following established patterns

**Opus** (design, ambiguous architecture, novel algorithms):
- I1 (scoreVL weight rebalancing)
- I4 (beaming polish around tuplets — cross-cutting change across beam model, renderer, writer, parser)
- Phase 8 architecture decisions (public chord grid, tab viewer, auth)
- Any task where the right approach is genuinely unclear

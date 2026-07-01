  j# SBN Admin — Chord / Tab Editor Reference

> **Purpose:** Complete reference for the admin leadsheet editor: architecture, Vue component tree, chord grid, voicing picker, tab notation, audio playback, video sync, keyboard shortcuts, design system, and all creation flows (blank, from progression, exercises, transcription).
> **Last updated:** 2026-06-29

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

### Header bar
- **Arrangement switcher** — dropdown when >1 version, plain label when only one. Navigates to `?v={slug}`.
- **Actions menu** — Import MusicXML → Melody / Chords, Generate Chords tab from voicings, Swap layers, Merge sheets…, Save as Exercise, **Clone arrangement** (copies current version as a new draft), **Delete arrangement** (hidden when only one version or when it is the default).
- Routes: `POST /leadsheets/{ls}/clone-version?v={slug}` → `cloneVersion()`; `DELETE /leadsheets/{ls}/versions/{version}` → `deleteVersion()`. Delete nulls out detection cache `version_id` rows rather than orphaning them; refuses if the version is the default or the only one.

### View modes
Five tabs owned by Vue: **Grid** | **Chords** | **Melody** | **Analysis** | **🎬 Video**. Vue's `viewMode` ref dispatches `sbn-tab-view-changed` → Alpine's `alpineViewMode` mirrors it one-way.

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
window.__sbnTabModel.setTempo(bpm)
  // Updates model.value.tempo + calls AudioEngine.setTempo() — fires on @change (blur/Enter), NOT @input
window.__sbnTabModel.setTimeSignature(timeSig)
  // Rescales chordOffsets/chordBeats in bridge sections proportionally, then sets timeSignature.value
  // — triggers useTabModel watch → buildModel() → correct ticksPerMeasure + chord grid layout
  // fires on @change (blur/Enter) only
```
Initialized once by `TabEditor.vue` via `initTabModelFacade({...})` after `useTabModel` setup.
Write functions are registered separately after `chordGridOps` is created via `registerSetChordName()` / `registerSetChordNameWithVoicing()` / `registerSetTempo()` / `registerSetTimeSignature()` — avoids circular init order.

**Gotcha — tempo/time-sig inputs use `@change` not `@input`:** `@input` would drive the transport BPM on every keystroke while typing. `@change` fires only on blur or Enter. The `markDirty()` call stays on `@input` so the save button activates immediately.

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
    constants.js                                         ← SMUFL glyphs, layout dims, tick math (LAYOUT.xPaddingClef for time-sig gutter)
    svgHelpers.js                                        ← beam/tie/flag/rest SVG generation (renderRepeatStart accepts xOffset param)
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
               chordOffsets[], chordBeats[], pickup, pickupBeats }
  chordOffsets[i] — beat offset of chord i from measure start (quarter beats, 0-based)
  chordBeats[i]   — duration of chord i in quarter beats
  Both always in sync with chordNames[]. Set by parseMeasure() from MusicXML tick data,
  or by _recomputeEvenOffsets() when chords are added/removed manually.
  pickup      — boolean; true if this is an anacrusis/pickup bar
  pickupBeats — number|null; quarter-beat count for the pickup (e.g. 0.5 for one 8th note
                in 4/4). null = full bar. Drives: beat-grid width, audio adapter timing,
                playback cursor position, xPos right-alignment of tab notes.
TabEvent { id, tick, tickInMeasure, duration, ticks, voice, isRest, notes: TabNote[],
           tieStart/Stop, stemDir, flagCount, beam1/2, beamWith, tuplet*, xPos }
TabNote { string, fret, pitch, octave, tieStart, tieStop, tieEndEvent?, tieEndNote? }
```

### Chord timing model (beat-grid layout)
`parseMeasure()` in `edit.blade.php` walks measure children sequentially. Each `<harmony>` element gets `beatInMeasure = tickDivs / divisions` and `beats` from the gap to the next harmony (or measure end). Gives 8th-note precision from MusicXML.

**Unit invariant:** `beatInMeasure` and `beats` are always in **quarter-beat units**, not raw `<beats>` count. For compound meters (6/8, 12/8 etc.) the measure length in quarter beats = `<beats> * (4 / <beat-type>)` — e.g. 6/8 → 3 quarter beats, not 6. `parseMeasure()` reads `<beat-type>` and computes `measureQuarterBeats` for the last chord's end position. `exportAlpineSections()` and `parseShortcodeClient()` use `tsBeats * (4 / tsBeatType)` for the same reason. Breaking this invariant causes chord cards to render at 200%+ width in compound meters.

**Stale-data clamp (shipped 2026-06-14):** Songs imported before the compound-meter fix had `beats` stored in raw numerator units (e.g. 6 for a full 6/8 bar instead of 3). `useTabModel.js` now clamps each chord's duration to `min(raw, bpm - offset)` on load so old data is silently corrected without a DB migration. The clamp is always correct because no chord should extend past its barline.

On save, `exportAlpineSections()` serializes `beatInMeasure`/`beats` back onto each chord object so timing survives round-trip through `json_data`. On load, `useTabModel.js` reads these fields into `chordOffsets[]`/`chordBeats[]`.

`ChordMeasure.vue` uses `chordPositionStyle(ci)` to absolutely position each `ChordCard` at `left: (offset/bpm*100)%` with `width: (dur/bpm*100)%`. A `sbn-ve-beat-grid` layer renders one dot per quarter-beat — visual metronome markers, not barlines.

**Gotcha — time sig changes must rescale existing offsets:** `chordOffsets` and `chordBeats` are stored in quarter-beat units relative to the *original* bar length. If the user changes the time sig (e.g. 4/4 → 2/4), the Vue `buildModel()` re-runs with the new `tpm` but reads the stale offset values from `sections.value` — cards overflow past the barline. Fix: `registerSetTimeSignature()` computes `scale = newQBpm / oldQBpm` and multiplies every `beatInMeasure` and `beats` in `sections.value` before setting `timeSignature.value`. The rescale is proportional — 4 chords in a 4/4 bar become 4 chords in a 2/4 bar at half spacing.

### Enharmonic chord name spelling (shipped 2026-06-14)

### The enharmonic spelling core (two rules, one authority)

The app derives every chord's flat/sharp spelling from **one** decision function, which combines two accidental rules in priority order:

1. **Key-related accidentals (dominant).** When a key is known, the whole chart follows the key's flat/sharp family. House style is **flats by default**: only the genuine sharp keys (G, D, A, E, B, F#, C# and their relative minors) spell with sharps. The flat keys *and* the neutral keys **C major / A minor** all use flats — so a `Bb` chord in a C-major song stays `Bb`, never `A#`.
2. **Chord-related accidentals (fallback).** When no key is known, the chord's own root + quality decides (minor/dominant qualities lean flat), via the root+quality lean. A sharp root the author explicitly typed (`F#m7`) is preserved.
3. **Slash-bass override.** A slash bass whose interval above the root is a flat-side alteration/minor chord tone — b9(1), b3(3), b5(6), b13(8), b7(10) — is **always spelled flat, regardless of key**. So `C7/Bb` stays `Bb` even in a sharp key like D (it's the dominant's ♭7, not an A♯). Major/perfect-interval basses (e.g. the major 3rd in `D7/F#`) still follow the key. Encoded as `FLAT_BASS_INTERVALS` (PHP) / `_FLAT_BASS_INTERVALS` (JS); the bass is spelled relative to the *re-spelled* root.

| Primitive | PHP | JS twin |
|-----------|-----|---------|
| Combined decision | `HarmonicContext::useFlatsFor($root, $quality, $key='')` | `_useFlatsFor()` (private) in `public/js/sbn-chord-name.js` |
| Key rule | `HarmonicContext::spellingUsesFlats($key)` (sharp-key allowlist) | `_keyUsesFlats()` |
| Chord rule | `ChordShapeCalculator::useFlatsForQuality($root, $quality)` | `_useFlatsForQuality()` |
| Re-spell a name | `HarmonicContext::reSpellChordName($name, $key='')` | `window.sbnSpellChordName(name, key)` |

`window.sbnReSpellChord(name, key)` is kept as a back-compat alias (it no-ops when `key` is empty, preserving its old contract); new code should call `window.sbnSpellChordName`.

Chord names travel through these layers; each applies the core:

| Layer | Where | What it does |
|-------|-------|--------------|
| MusicXML import | `MusicXMLParser._reSpellNote()` in `edit.blade.php` | Re-spells root + bass immediately after parsing `root-alter`/`bass-alter` — new imports produce `D/F#` not `D/Gb` in a D-major song |
| Model load | `useTabModel.js` chord-name extraction | Calls `window.sbnReSpellChord(name, key)` on every chord name when building the Vue model — fixes stale names already in `json_data` on the fly. The song key is stashed on `model.value.songKey` for later edits. |
| **Chord entry (voicing picker)** | `useVoicingPickerStore.js` `spellName()` | Re-spells every newly-picked/inverted chord name through `window.sbnSpellChordName(name, model.songKey)` before it is written — stops new sharps from being introduced as you edit, and spells inversion bass notes key-aware |
| Viewer voicing lookup | `LeadsheetViewerService::buildChordCards()` | Calls `HarmonicContext::reSpellChordName()` before `searchByName()` — ensures the DB query finds the right chord diagram even if the stored name is misspelled |
| Display (JS) | `window.sbnSpellChordName(name, key)` in `public/js/sbn-chord-name.js` | Shared client-side helper; mirrors `HarmonicContext::reSpellChordName()` |

Natural notes (no accidental) pass through unchanged. Re-spelling only touches the root letter and slash-bass note — quality suffixes and extensions (`m7`, `b5`, etc.) are left intact.

**Gotcha — re-spelling is NOT applied in `sbnFormatChord` / `sbnFormatChordHtml`:** these are pure presentational formatters with no key context. Re-spelling must happen upstream (at model load or data layer), not in the display function.

**Internal `dom7` quality → display `7`:** the backend names dominant voicings with the internal quality `dom7` (e.g. `findAliasMatches` / `transposeShapes` in `ChordVoicingSearch.php` emit `Cdom7`, `Cdom9`). All three chord-name formatters normalize this at the display layer so it renders conventionally: `dom7`→`7`, `dom7(9)`→`7(9)`, `dom9`→`9`, `dom13`→`13`, bare `dom`→`7` (`dim`/`dim7`/`domino` untouched). Fixed in **all three** so every name source is covered: `sbnFormatChordHtml` (`public/js/chords.js`, the active editor formatter), `sbnFormatChord` (`public/js/sbn-chord-name.js`), and the Vue fallback (`tab-editor/utils/chordFormat.js`). The stored/internal quality stays canonical `dom7` — only display changes.

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

### Transpose sheet (backend, whole-arrangement — shipped 2026-07-01)

Transpose shifts an entire arrangement by a signed semitone interval. It runs
**server-side** (not through the Vue undo stack) because a leadsheet stores its two
notation layers as separate MusicXML strings on a `LeadsheetVersion`, and only one
layer is loaded into Vue at a time — a client-only transpose could reach just the
active layer (the original "only one tab gets transposed" bug).

**Trigger:** Actions menu → "Transpose sheet…" dispatches `sbn-transpose`; the Vue
modal (`TabEditor.vue`, `onConfirmTranspose`) collects semitones and POSTs to
`/admin/leadsheets/{leadsheet}/transpose` (route `leadsheets.transpose`), then
`window.location.reload()`s. `Shift+T` opens the same modal.

**Endpoint** `LeadsheetController::transpose()` — resolves the **active version**
(`?v=slug`, else default) exactly like `update()`; runs everything in a DB
transaction; dual-writes to the leadsheet legacy columns only when the active
version *is* the default. Scope = active version only; other versions untouched.

**What gets transposed (all via `App\Services\TabXmlTransposer`):**
- `melody_tab_xml` + `chord_tab_xml` (if present) — each fretted `<note>`: `<fret>`
  shifted + clamped 0–24; `<pitch>` step/alter/octave shifted via
  `transposePitchStep()` and re-spelled for the target key. DOMDocument write path
  (lossless — beams/ties/durations/divisions preserved).
- `json_data.melody[]` — **the melody staff renders from this, not from the XML**, so
  it MUST be transposed too (missing this was the "melody tab unchanged" bug). Frets
  clamped, pitch/octave shifted via the same `transposePitchStep()` helper. json_data
  pitch is a single string (`"C#"`, `"Bb"`), not step/alter.
- `json_data` chord names — `sections[].measures[].chords[].name` **and** top-level
  `measures[].chords[].name` (both exist). Written with **indexed `for` loops**, not
  nested `foreach ... as &$ref` — PHP by-ref foreach does NOT write back through
  multiple levels of nested array access (this was the "chord grid names unchanged"
  bug). Spelling via `HarmonicContext::reSpellChordName($name, $targetKey)`.
- `json_data.chordVoicings` — each `frets` hex string via `transposeVoicingFrets`
  (octave-folds the whole voicing ±12 to stay in 0–24, preserving chord shape — do
  NOT per-string clamp voicings); `position` recomputed via `fretsToPosition`.
- `json_data.key`, version `song_key` (+ leadsheet `song_key` if default) via
  `transposeKey`.

**Gotcha:** the leadsheet legacy table (`sbn_leadsheets`) has **only `tab_xml`**, no
`chord_tab_xml` column — that layer is version-only. Do not dual-write chord XML to
the leadsheet (it throws + rolls back the whole transaction).

**Enharmonic spelling** always flows through the PHP authority
(`HarmonicContext::reSpellChordName` / `spellingUsesFlats`), the twin of JS
`window.sbnSpellChordName` — flats by default; genuine sharp keys spell sharp. See
"The enharmonic spelling core" above. The JS `transpose.js` helpers
(`transposeChordName`, `transposeVoicingFrets`, `fretsToPosition`, `transposeKey`)
still exist and drive the **exercise** transpose path (no leadsheet id → client-side
`transposeSheet` + Pattern B undo).

**Undo (inverse transpose):** backend transpose is not on the Ctrl+Z stack (the
reload wipes it). On success the client stashes a one-shot `sbn_transpose_undo`
marker in `sessionStorage` (leadsheetId, versionSlug, forward semitones, newKey, ts)
before reloading; after reload `edit.blade.php` reads+**clears** the marker (guards
`ts < 60s`) and shows `sbnConfirmToast("Transposed to <key>.", "Undo", …)` whose
callback POSTs `semitones: -N` and reloads **without** re-stashing (prevents an
undo/redo loop). Marker is cleared on first read so a plain refresh never shows a
phantom undo. Caveats (accepted): 8s toast window (no durable Actions-menu entry);
not bit-exact for notes that hit the 0/24 fret clamp on the way out (rare, silent —
the controller clamps melody frets without counting).

### Pickup bar ops (Pattern A)
- `togglePickup(gi)` — flips `m.pickup`; clears `pickupBeats` when unmarking
- `setPickupBeats(gi, beats)` — sets exact quarter-beat count; marks `pickup:true`. Pass `null` to unmark. UI: right-click → beat-count row (1…N buttons matching time sig + ✕ clear)
- `pickup` / `pickupBeats` flow: `buildModel()` reads from section data → `patchChordNames()` re-syncs on chord change → `exportAlpineSections()` serializes back to Alpine
- MusicXML import (Alpine editor): `parseMeasure()` detects `implicit="yes"` on `<measure>` → sets `pickup:true`, `pickupBeats` from actual note-cursor ticks; chord `beats` capped to pickup length. **Gotcha:** `const isImplicit` must be declared *before* the `chords.length > 0` block that uses it — `const` has no hoisting (TDZ). Declaring it after caused a ReferenceError on every MusicXML import.
- MusicXML server-side (`TabXmlParser.php`): detects pickup by comparing measure 1's total note-tick sum against `$tpm`; if less and > 0, sets `pickup: true`, `pickupBeats` (quarter-beat float), and `actualTicks`. Does NOT require `implicit="yes"` — works purely from tick arithmetic (2026-06-19).
- `useReflow.repositionMeasure()` uses `pickupBeats*480` as capacity, right-aligns xPos: `xPos = (tpm-capacityTicks)/tpm + tickInMeasure/tpm`
- Audio adapters (`tabMeasureToEvents`, `chordVoicingsToEvents`): build `positionBeatStart[]` accumulating `m.pickupBeats ?? beatsPerMeasure` per position — no silence gap
- Playback: `playPositionBeatTable` (TabEditor) accumulates per-position beat starts; `measureBeatStartMap` (provided) lets TabMeasure/ChordMeasure compute true beat-within-measure; metronome cursor starts at `pickupXOffset = (globalBpm - pickupBeats) / globalBpm` so it aligns with the right-aligned note

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
Two identifier paths run after every MusicXML import (`inferKeyFromChords` → `identifyTabVoicings` → `detectHarmonyMismatches`). Both POST `tuning` to the identify-voicings endpoint so `VoicingCrossref` uses the correct open-string values for pitch-class extraction.

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

**Time-stretch:** if the rhythm pattern's `time_signature` differs from the sheet's, stroke tick offsets are scaled so the pattern fills exactly one bar. A 2/4 pattern applied to a 4/4 bar doubles all durations (16ths → 8ths etc.) — the feel is identical, just the note values change. `VoicingMaterializer` passes `(int)($tpm / $divisions)` as `$beatsPerMeasure` to `RhythmMaterializer::expand()` — this is the quarter-beat bar length, not the raw `<beats>` numerator. Passing the raw numerator causes wrong scaling for compound/cut meters (e.g. 6/8 would compute `6 * 480 = 2880` ticks instead of the correct `3 * 480 = 1440`).

**String assignment** (`RhythmMaterializer::_resolveFingerStrings`):
- **≤ 4 available notes:** thumb = lowest-pitch string (highest string#); fingers = remaining strings low→high pitch
- **> 4 available notes:** thumb = `max($availableBass)` as before; fingers = strings 4 (D), 3 (G), 2 (B) that are non-x
- **Strum patterns:** all non-muted strings on every stroke
- **Soft voice leading:** if the previous chord's finger strings are all available in the current voicing, reuse them unchanged; `VoicingMaterializer` threads `$prevFingerStrings` through the chord loop

**Layer routing:** Apply Rhythm writes to whichever tab layer is currently active (`activeTabLayer`). On the Chord layer it replaces `chord_tab_xml`; on the Melody layer it replaces `melody_tab_xml`. The confirm dialog names the layer. `tabXml` in Alpine is a computed alias that reads/writes the active layer, so the reload path is unchanged.

**Frontend reload path** (critical): on success `VoicingOverview.vue` dispatches `sbn-rhythm-applied` → Alpine replaces `parsed` + `tabXml` (active-layer alias), resets `_tabInitDone` / `_tabVueInitialized`, calls `_dispatchTabInit()` for a full Vue reload.

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

**Tie preservation on copy/paste:** `cloneEvent()` in `useSelection.js` preserves `tieStart`/`tieStop` boolean flags. Runtime cross-references (`tieEndEvent`, `tieStartEvent`, etc.) are stripped and rebuilt by `relinkTiesGlobally()`, which is called after every `wrapCommand()` mutation (not only undo/redo). This means ties render correctly immediately after paste.

### Applying voicings from tab view
`useVoicingPickerStore.applyVoicing()` has two paths:
- **`keyMatch` path** (picker opened from chord grid): sets chord name globally + applies tab frets with `skipIfTabExists: true` — will NOT overwrite an existing tab bar that already has notes
- **`tabSrc` path** (picker opened from tab view via `voicingPickerStore.openForTab(...)`): always writes through; uses specific `gi/ci` coords; if `!keyMatch && _tabSource && !oldName`, writes name directly into `m.chordNames[ci]` and keys voicing as `"newName@gi.ci"`

### Voicing picker — alias grouping (dim7 / m6 / m7b5)

`VoicingPicker.vue` collapses alias and inversion results into a single face card per physical shape to avoid flooding the grid.

**Two-pass grouping** (order-independent):
1. Pass 1 — `dim_inversion: true` results group by `id` into `dimMap`. Face card = root-position inversion; the other 3 inversions go to `alts[]`.
2. Pass 2 — `alias_match: true` results:
   - If `id` is already in `dimMap` (same physical shape) → folded into that group's `alts[]` (dom7(b9) readings appear alongside the 3 dim inversions in the popover).
   - If `rootless: true` and NOT in `dimMap` → rendered as a normal primary card (dim-derived dom7(b9) voicings in a dom7(b9) search are legitimate primaries, not cross-quality aliases).
   - Otherwise → grouped by `id` into `aliasMap` (m6/m7b5 cross-quality aliases). Rendered whenever the group has a `face` — **including standalone aliases with `alts.length === 0`** (e.g. a lone m6↔m7b5 reading like `Dm6` ⇒ `m7b5-drop3-roote-inv2`). The backend already excludes primary-result ids from the alias query (`$seenIds` → `excludeIds` in `findAliasMatches`), so an aliasMap face never duplicates a primary; the earlier `alts.length > 0` filter wrongly dropped these solitary cross-quality voicings.

**Face card badge** — blue circle showing the alt count, **only rendered when `alts.length > 0`** (a standalone alias face has no badge/popover). Clicking opens an inline popover that spans all 3 grid columns and overlays following cards (`z-index: 20; margin-bottom: -200px`). The popover shows each alt as a mini card (72 px wide, 56 px SVG) with its chord name. Selecting a popover card calls `picker.applyVoicing(alt)` normally.

**Key fields used** (all passed through from API → store → grouping):
- `dim_inversion` — set by `findDiminishedPickerResults` on the 4 inversion slots
- `alias_match` — set by `findAliasMatches` and `findDiminishedPickerResults` dom readings
- `rootless` — set on dim-derived dom7(b9) rootless voicings
- `id` — DB shape id; the shared key that collapses a shape's readings into one group

### Keyboard shortcuts (tab editor)
| Key | Action |
|-----|--------|
| ← → | Navigate events |
| ↑ ↓ | Navigate strings |
| Tab | Cycle view tabs: Grid → Chords → Melody (skips absent layers) |
| Home / End | First/last event in measure |
| 0–9 | Enter fret number (two-digit with 600ms timeout) |
| Delete / Backspace | Remove note on cursor string (single bar); structural delete of selected bars (multi-bar) |
| Ctrl+↑ / Ctrl+↓ | Shift note(s) to adjacent string, transposing fret (±5; ±4 across B↔G). Works on cursor note **and** on multi-note/multi-bar selections — all selected events shift together. No-op at boundary or if any fret would go out of 0–24. |
| Ctrl+1–6 | Set duration (whole→32nd) |
| + / = / - | Shorter / longer duration |
| . | Toggle dotted |
| T | Toggle tie on cursor note; when a **multi-note selection** is active, ties **all** selected notes simultaneously. |
| Ctrl+S | Quick save (same as the Save button; works anywhere in the editor) |
| A | Insert rest after cursor event |
| Shift+←/→ | Extend note selection (within measure) |
| Shift+↑/↓ | Select all events at the current beat (same `tickInMeasure`) — column-select across strings |
| Shift+click measure | Extend bar selection across measures |
| Ctrl+C/X/V | Copy/cut/paste (note-level, single bar, or multi-bar depending on selection) |
| Ctrl+Z / Ctrl+Shift+Z | Undo/redo (unified — covers chord grid + tab + voicings) |
| Space | Play/pause toggle (global — works in all contexts) |
| V | Toggle video sidebar |
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

## TIME SIGNATURE + CLEF DISPLAY (2026-06-15)

`TabMeasure` accepts two new props: `showClef: Boolean` and `timeSignature: String`. When `showClef` is true (only on the globally first measure, `si===0 && ri===0 && li===0`):

- Renders Bravura SMuFL time-sig glyphs (U+E080+digit) for numerator and denominator stacked in the string zone gutter.
- `LAYOUT.xPaddingClef = 52` pushes note content right to clear the gutter. All other section-first measures still use `LAYOUT.xPaddingFirst = 22`.
- `getXm()` uses `xPaddingClef` when `showClef`, `xPaddingFirst` when `isFirstOfSection`, else `xPadding`.
- `renderRepeatStart(sT, sB, sH, xOffset)` gains an `xOffset` param (default 0). When `showClef && m.repeatStart`, offset = `xPaddingClef - 14` so the repeat barline clears the time sig.
- All 5 render sites pass `:show-clef` and `:time-signature`: `SheetMiniPlayer`, `LeadsheetViewer`, `StageSectionsGrid`, `TabEditor`, `Cinema.vue → StageSectionsGrid`.
- `StageSectionsGrid` uses `measure.index === 0` (not section index) since it renders only the active section at a time.

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
| `VideoSyncEditor.vue` | Tap-to-mark UI; per-pass row table with `1/2`, `2/2` labels; clicking a row uses `seek-to-mapping` to land on the right pass. **Transport row** (⏮ 10s / ◀ 2s / ⏯ / 2s ▶ / 10s ⏭) mirrors the keyboard nudge shortcuts (← → Shift+← Shift+→ Space) as clickable buttons. |
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

**Exercise save payload** (sent by Alpine `save()` for `itemType === 'exercises'`): `title`, `composer`, `key_center`, `bpm_default`, `time_sig`, `rhythm`, `type`, `measure_count` (= `allMeasures.length`), `popularity`, `content_json`, `shortcode_content`, `tab_xml`, `description`, `harmony_notes`, `form_notes`, `voicing_notes`. Note: exercises have no `difficulty`, `genre`, or `tags` DB columns — those meta fields are hidden in the editor when `itemType === 'exercises'`.

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

**Note (2026-06-20):** The "Include Deep Research" checkbox and `assistant` mode have been removed from the lookup modal — the ResearchPanel Vue component that consumed that data no longer exists. The modal only uses `quick` mode for the AI Search path.

### Audio transcription (L3a) — experimental

Produces a leadsheet from audio. Embedded in the song lookup modal. Functional but imperfect. Full pipeline, known problems, and improvement roadmap in [Audio-Transcription-Architecture.md](Audio-Transcription-Architecture.md).

**Input sources (switchable via tab in the modal):**
- **YouTube search** — pick a video from search results
- **YouTube URL** — paste any YouTube URL directly; regex extracts the 11-char video ID
- **Local file** — upload an MP3/WAV/M4A/OGG/FLAC (max 100 MB); title defaults to filename; no `youtube_id` is stored

Six-stage summary: yt-dlp download (or local file) → ffmpeg WAV → Python (`basic-pitch` + `librosa`) beat/pitch analysis → PHP melody reconstruction (range filter, beat-boundary clamping, no-dots grid) → optional Gemini pass → assembly.

No duration cap — the 90-second `librosa.load()` limit was removed 2026-06-19.

### Storage contract

All paths produce the same shape — the editor has no creation-path-specific code:

```php
shortcode_content  // canonical [sbn_leadsheet]…[/sbn_leadsheet] body
json_data          // { sections, chordVoicings, melody, repeatMarkers, voltaEndings, videoSync?, rhythmPattern? }
tab_xml            // MusicXML for tab/melody (empty skeleton when no voicings built)
title, composer, song_key, tempo, time_signature, rhythm, measure_count, slug
```

`chordVoicings` is always a JSON object (`{}`), never an array — the editor's `cv["name@gi.ci"] || cv["name"]` lookup requires object access.

---

## §11 — VoicingCrossref matching engine

`App\Services\VoicingCrossref` matches leadsheet voicings (chord name + fret string) to library shapes in `sbn_chord_diagrams`. Called by the "Reprocess" button in the admin editor and by batch reprocess. Results land in `sbn_voicing_refs` (matched) or `sbn_voicing_drafts` (unmatched).

### Match pipeline (per voicing)

1. **Main loop** — queries DB shapes for the base quality, tries exact → subset → superset → fragment against each transposition. Also tries the E-string-swapped target (see below).
2. **Inversion fallback** — for slash-inversion chords, retries root-position shapes.
3. **Dim7 symmetry pass** (`o7` and `dim` qualities) — the °7 chord is symmetric at m3 intervals (C#°7 = E°7 = G°7 = Bb°7). When wrap-around prevents a match, retries with all 3 partner roots. Also handles **dim triads (°)** — they share the same inversion structure and are subsets of every o7 family member.
4. **Dom7(b9) dim7 pass** — every o7 / dim shape transposed so the dominant's b9 is a chord tone is a valid rootless reading. Tries all 4 symmetric roots. Also applies fragment check (dom root added as open bass below the dim shape). Handles **dim triads** here too: `Bdim` = rootless `E7(b9)`, dim root = voicing root directly.
5. **True slash fallback** — for `/bass` chords not resolvable as inversions.

### E-string swap

Strings 1 (low E) and 6 (high e) are both tuned to E — same fret = same pitch. A voicing with the root on string 6 and string 1 muted (e.g. `xx9769` = Db7(b9)) is equivalent to the mirrored form with root on string 1 (`9x976x`). `swapEStrings()` pre-computes this alternative before the main loop so both forms are tested at no extra DB cost.

Only fires when exactly one E string is sounding — safe against voicings that use both E strings.

### Dim triad as rootless dom7(b9)

A dim triad (quality=`dim`) routes through both the o7 symmetry pass AND the dom7(b9) pass:

- **o7 pass**: tries all 3 partner roots (subset match covers the 3-note triad inside the 4-note °7 shape).
- **Dom7(b9) pass**: `dimRootPc = voicingRoot` (not `+1` like for dom7 — the triad root IS the dim family root). Fragment check handles the case where the dom root is added as open bass below the triad (e.g. `0x310x` = open-E + Fdim triad = E7(b9)).

### SyncedPlayer resolution (`resolveCard`)

`SyncedPlayerController::resolveCard()` trusts stored voicing fret positions directly (leadsheets are curated). It calls `synthesizeMinimalCard()` to build a card from stored frets, then:

- If stored `fingers` are all-zeros → enriches with `pickBestVoicing()` from DB matches (Pass 1 exact, Pass 2 open-bass, Pass 3 E-string swap on string 6).
- Always computes `interval_labels` from the final `diagram_data`.
- Falls back to first DB match only when no voicing is stored at all.

### VoicingMaterializer finger derivation

At import time (`LeadsheetController` → `VoicingMaterializer`), `diagram_data` is passed through the selections chain. `VoicingMaterializer` derives a `fingers` string from `diagram_data.positions[].finger` and stores it in `chordVoicings` only when non-trivial (not all-zeros). This ensures curated position data survives the MusicXML round-trip.

---

## §12 — Alternate tuning support (2026-06-20)

### Overview

The editor supports alternate guitar tunings stored as `parsed.tuning` (a string) inside `json_data` / `content_json`. Currently two values are recognised:

| Value | String 6 open | String 6 MIDI |
|---|---|---|
| `'standard'` (default) | E2 | 40 |
| `'drop-d'` | D2 | 38 |

### Detection on import

`MusicXMLParser.getTuning()` reads `<staff-tuning line="1"><tuning-step>` from the imported MusicXML. A `D` step → `'drop-d'`; anything else → `'standard'`. The result is set on `this.tuning` in the constructor (before `parse()` runs) so all internal helpers use it immediately.

### Persistence

`tuning` lives on the `parsed` object and is spread into `finalJsonData` on save. No DB column needed — it round-trips inside `json_data`/`content_json`.

### Data flow

```
MusicXMLParser.parse() → parsed.tuning
  → sbn-tab-init detail.tuning
    → useAlpineBridge tuning ref
      → TabEditor destructures tuning
        → modelToMusicXml(model, { tuning })   // writes correct <staff-tuning>
        → tabModelToEvents(model, { tuning })   // MIDI fallback for fret-only notes
        → POST identify-voicings { tuning }     // chord identifier uses correct open-string PCs
```

### Key files

| File | What changed |
|---|---|
| `edit.blade.php` — `MusicXMLParser` | `getTuning()`, `_openStringMidi()`, `_openPC()`, `_stringFretToMidi()`, `_pcSetToFretString()` all tuning-aware; `_dispatchTabInit` sends `tuning` |
| `audio/adapters/pitchToMidi.js` | `stringFretToMidi(string, fret, tuning)`, `noteToMidi(note, tuning)` |
| `audio/adapters/tabMeasureToEvents.js` | reads `ctx.tuning`, passes to `noteToMidi` |
| `tab-editor/utils/musicXmlWriter.js` | `TUNING_STANDARD` / `TUNING_DROP_D` / `getTuningTable()`; `pitchFromStringFret(string, fret, tuning)`; `modelToMusicXml` accepts `meta.tuning` |
| `tab-editor/composables/useAlpineBridge.js` | `tuning` ref, populated from `sbn-tab-init` |
| `tab-editor/TabEditor.vue` | destructures `tuning`; passes to writer + events; "Drop D" badge in tab bar |
| `app/Services/VoicingCrossref.php` | `TUNING_DROP_D`, `tuningArray()`; `identifyVoicingsBatch`, `identifyFromFrets`, `identifyFromPcSetFull`, `isRootRelocatedFragmentMatch`, `classifyExtraNotes`, `identifyFromFretsWithContext` all accept `$tuning` |
| `app/Http/Controllers/Admin/LeadsheetController.php` | reads `tuning` from request, passes to `identifyVoicingsBatch` |

### Adding new tunings

1. Add a value to `getTuning()` in `MusicXMLParser`
2. Add the open-string MIDI map to `pitchToMidi.js` and `musicXmlWriter.js`
3. Add `TUNING_*` constant + branch in `VoicingCrossref::tuningArray()`
4. Add `TUNING_*` array in `_pcSetToFretString` inside `edit.blade.php`

---

## §13 — ProgressionBuilder voice-leading engine (audit 2026-06-21)

### Overview

`ProgressionBuilder` runs a two-pass Viterbi search (Phase D + Phase E) over a chord lattice to pick voicings that minimise a weighted cost function. Phase E adds a second Viterbi pass that activates when named guide-tone resolutions fire (jazz/latin only). The June 2026 audit fixed two silent correctness bugs and one UI regression that had suppressed guide-tone arrows in the chord grid.

### Bug 1 — Hex fret-decode in `getVoicingMidiNotes`

`ProgressionBuilder::getVoicingMidiNotes()` decodes fret strings (e.g. `"xa9aax"`) to MIDI pitches. It was casting each character with `(int)`, which silently converts `'a'`→0, `'c'`→0, etc. Frets ≥ 10 are stored as lowercase hex (`a`=10, `b`=11, `c`=12, …), so every voicing above fret 9 produced wrong pitches. This corrupted the tone-presence checks that determine whether a named guide-tone resolution fires, starving Phase E of valid resolutions across the whole corpus.

**Fix:** `ctype_digit($ch) ? (int)$ch : hexdec($ch)` — matching the decode logic already used by `positionHintCost` (L3180) and `VoicingMaterializer`.

### Bug 2 — `same_voice` definition: pitch-rank → nearest-voice

Phase E's named resolutions (e.g. `vl.dom.b7_to_3`) require the source tone and target tone to move as the "same voice". The original implementation compared tones at the same index after `sort()` (pitch-rank). On guitar, the target's 3rd often sits below where the dominant's b7 was — ranks cross — so the canonical b7→3 resolution never fired.

**Fix:** `same_voice` now means the closest note pair by signed interval — fire if the source tone → target tone is within the expected semitone motion (±2 semitones of the specified interval). Updated in:
- `ProgressionBuilder::testSameVoiceMotion()`
- `docs/Phase-E-Extension-Table.yaml` (`same_voice_definition` field)
- §8.1 of `docs/Builder-Refactor-Spec.md`

### Guide-tone resolution widening (2026-06-21)

Two named resolutions were minor-tonic-only and missed major contexts shown in the source musicology:

- `vl.dom.b9_to_5` and `vl.dom.b13_to_5` had `target: Im` — widened to `target: any_tonic` (sheet shows them resolving into Cmaj7).
- `vl.dom.b13_to_5` renamed → `vl.dom.b13_to_9` (the target tone is the 9th of the tonic, not the 5th — the ID was wrong).

Updated in `docs/Phase-E-Extension-Table.yaml` and `resources/js/lib/guideToneResolution.js` (`RESOLUTION_TONES` map).

### UI fix — spurious guide-tone arrows

`findResolutionPairsFromFired` (in `guideToneResolution.js`) previously fell back to the heuristic score-VL path when `firedIds` was empty, producing arrows on Pass-1 progressions, pop/classical chords, and repeated/pinned voicings where no named resolution actually fired.

**Fix:** return `[]` immediately when `firedIds` is empty. The heuristic path in `findResolutionPairs` is still available for explicit opt-in callers (e.g. `GuideToneArrowBridge.vue` calls it directly).

### `pass2_eligible` DB setting

`sbn_builder_settings.pass2_eligible` controls which categories enter the Phase E dual-Viterbi search. It had been set to `["latin"]` (jazz disabled), silently preventing Phase E from running on jazz progressions even when `extensions: true` was passed. Restored to `["jazz","latin"]`.

**Gotcha:** `BuilderSettings` caches under key `builder_settings_cache` (TTL 1 hour). Direct DB writes require `Cache::forget('builder_settings_cache')` to take effect immediately.

**Gotcha:** `ProgressionBuilder.php:417` hard-ANDs the caller's `extensions` option with `isPass2Eligible($category)`. The regression suite (`phase-e:regress`) can't validate jazz when the DB disables it, even when passing `extensions=true`. Open: suite should override eligibility per-run rather than relying on the DB setting.

### Enharmonic spelling — known builder gap

`ProgressionBuilder::transposeBassNote` still calls `HarmonicContext::spellingUsesFlats($root)` (root-only, no quality context). The full pipeline uses `ChordShapeCalculator::useFlatsForQuality($root, $quality)` (Phase 2, 2026-05-31), which also considers whether any minor/diminished interval of the quality lands on a flat-side pitch class. For most cases this makes no difference, but natural-root minor chords (e.g. E minor bass notes) may misspell. Low risk until minor-key progressions with non-root bass notes are in scope.

### Key files

| File | Role |
|---|---|
| `app/Services/ProgressionBuilder.php` | `getVoicingMidiNotes` (hex fix), `testSameVoiceMotion` (nearest-voice), `transposeBassNote` (known gap) |
| `app/Services/Builder/PhaseE/ExtensionTable.php` | loads `Phase-E-Extension-Table.yaml`; drives named resolutions |
| `docs/Phase-E-Extension-Table.yaml` | authoritative for all extensions, avoid tones, named resolutions, `same_voice_definition` |
| `docs/Builder-Refactor-Spec.md` | §8.1 `same_voice` definition, §13 Phase E spec |
| `resources/js/lib/guideToneResolution.js` | `RESOLUTION_TONES` map, `findResolutionPairsFromFired` (empty-guard fix) |
| `resources/js/Components/ChordGrid/GuideToneArrowBridge.vue` | calls `findResolutionPairs` directly (heuristic path, still valid) |

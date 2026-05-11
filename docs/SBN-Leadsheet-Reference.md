# SBN Leadsheet Viewer — Reference

Living reference for the leadsheet viewer system as it works **today**. Update this doc when behavior changes; do not append phase narratives. For migration history see the now-deleted `docs/Phase-9-Leadsheet-Viewer.md`.

For visual tokens, brand colors, fonts, and design-system load order see [SBN-Design-Reference.md](SBN-Design-Reference.md).

---

## 1. Overview

Three public surfaces share one leadsheet data model:

| Surface | Route | Controller method | Page component |
|---------|-------|-------------------|----------------|
| **Song teaser** | `/library/songs/{slug}` | `SongLibraryController::show()` | `Pages/Library/Songs/Show.vue` |
| **Classic viewer** | `/library/songs/{slug}/viewer` | `SongLibraryController::viewer()` | `Pages/Library/Songs/Viewer.vue` |
| **Cinema view** | `/library/songs/{slug}/cinema` | `SongLibraryController::cinema()` | `Pages/Leadsheet/Cinema.vue` |

The classic viewer and cinema view both consume the same `LeadsheetViewerService::enrich()` enrichment pipeline and the same `chordCards` Inertia prop shape.

---

## 2. Data model

- [`Leadsheet`](../app/Models/Leadsheet.php) — `slug`, `title`, `composer`, `song_key`, `tempo`, `time_signature`, `rhythm`, `description`, `harmony_notes`, `form_notes`, `voicing_notes`, `measure_count`, `popularity`.
- `parsed_data` accessor — decodes the `json_data` column (see §3) and returns an array.
- `getChordNames()` — returns a flat deduplicated list of chord names used across all measures.

---

## 3. Leadsheet JSON data shape (`json_data`)

Stored as `json_data` (raw column). Read via `parsed_data` accessor (decoded array). Frontend receives it as `leadsheet.jsonData`.

```ts
interface LeadsheetJson {
  sections: LeadsheetSection[];
  chordVoicings: Record<string, ChordVoicing>;  // keyed by "ChordName@gi.ci" or bare "ChordName"
  videoSync?: VideoSync;
}

interface LeadsheetSection {
  id: string;
  name: string;
  measures: LeadsheetMeasure[];
  lineBreaks?: number[];           // measure indices that start a new row
}

interface LeadsheetMeasure {
  chordNames?: string[];           // preferred — flat list of chord names in this bar
  chords?: Array<{ name: string; beats: number; beatInMeasure: number }>;  // legacy shape
  globalIndex?: number;            // assigned at read-time, not stored
}

interface ChordVoicing {
  frets: string;       // 6-char fret string e.g. "x32000"
  fingers?: string;    // 6-char finger string
  position?: number;   // barre position
}

interface VideoSync {
  videoId: string;
  videoType: 'youtube';
  mappings: Array<{ measureIndex: number; videoTime: number }>;
}
```

### Voicing key shapes

`chordVoicings` uses **two key shapes** for the same song:

- `"AMaj7@5.0"` — per-slot override for measure gi=5, chord-slot ci=0
- `"AMaj7"` — song-wide fallback when no per-slot override exists

Any code that looks up a voicing by key must try the per-slot key first, then fall back to the bare name. Frontend helper pattern: `cv[fullKey] ?? cv[bareName]`. Backend: `LeadsheetViewerService::pickBestVoicing()`.

---

## 4. Controller data contract

### 4.1 Classic viewer — `viewer()`

```php
Inertia::render('Library/Songs/Viewer', [
    'leadsheet' => [
        'id', 'slug', 'title', 'composer', 'songKey', 'tempo',
        'timeSignature', 'rhythm', 'jsonData',   // parsed_data accessor
        'harmonyNotes', 'formNotes', 'voicingNotes',
    ],
    'chordCards'        => $enriched['chordCards'],   // ChordDiagramData[] keyed by voicing key
    'eduChordQualities' => $edu->allChordQualities(), // Record<slug, {title, blurb}>
    'progressions'      => $progressions,             // ProgressionRef[]
]);
```

### 4.2 Cinema view — `cinema()`

```php
Inertia::render('Leadsheet/Cinema', [
    'leadsheet' => [
        'id', 'slug', 'title', 'composer', 'songKey',
        'tempo', 'timeSignature', 'jsonData',
    ],
    'chordCards' => $enriched['chordCards'],
    'classicUrl' => route('library.songs.viewer', ['leadsheet' => $leadsheet->slug]),
]);
```

### 4.3 `chordCards` enrichment

Built by `LeadsheetViewerService::enrich($leadsheet, $search)`. For each unique chord name in `chordVoicings`:

1. Call `ChordVoicingSearch::searchByName($chordName)` — DB lookup by name.
2. `pickBestVoicing($matches, $targetFrets)` — prefers exact fret-string match; falls back to first result.
3. If no DB match: `synthesizeMinimalCard($chordName, $voicing, $search)` — builds a stub `ChordDiagramData` from the fret string with `popularity`/`difficulty` absent.
4. Result stored under both the per-slot key (`"ChordName@gi.ci"`) and the bare-name key (`"ChordName"`).

The `root_note` field on each card is set to the **transposed** root used in the song (not the shape's native root). This is what drives the library link URL.

---

## 5. `ChordDiagramData` type

Used by `ChordCard.vue`, `ChordDiagram.vue`, and the EduPanel. Matches the chord library DB schema.

```ts
interface ChordDiagramData {
  id: number;
  slug: string;
  name: string;
  root_note: string;         // transposed root
  original_root?: string;    // shape's native root (from DB)
  quality: string;           // canonical slug: 'maj7', 'm7', 'dom7', etc.
  quality_label: string;
  extensions?: string | null;
  voicing_category: string;
  category_label: string;
  inversion?: string;
  inversion_label?: string;
  diagram_data: {
    positions: Array<{ string: number; fret: number; finger?: number }>;
    barres: Array<{ fret: number; from: number; to: number }>;
    muted: number[];
    open: number[];
  };
  popularity?: number | null;
  difficulty?: number | null;  // 1–5 stars
}
```

---

## 6. Classic viewer architecture

```
Pages/Library/Songs/Viewer.vue
  └─ <PublicLayout>
       └─ <LeadsheetViewer :leadsheet :chordCards :eduChordQualities :progressions>
            ├─ <ChordGridView :read-only="true" :density>   (reused from tab-editor)
            │    └─ ChordSection → ChordMeasure → ChordCard
            ├─ <EduPanel :chord-cards :edu-chord-qualities :progressions>
            └─ <TransportBar>                               (reused from tab-editor)
```

### 6.1 `LeadsheetViewer` provides

| Provided key | Type | Purpose |
|---|---|---|
| `model` | `Ref<TabModel>` | `useTabModel` ref — sections/measures/chords |
| `gridSelection` | `UseGridSelection` | Selection state for EduPanel |
| `beatsPerMeasureRef` | `ComputedRef<number>` | Chord-card window math |
| `playingMeasureIndex` | `Ref<number>` | Active-measure highlight during playback |
| `transportBeat` | `ComputedRef<number>` | Sub-measure beat highlight |
| `seekToMeasure` | `(gi: number) => void` | Click-to-play from chord card |
| `globalIndexOf` | `(si, mi) => number` | Measure → global index lookup |
| `readOnly` | `true` | Suppresses all editing UI in descendants |

**Not provided** (read-only mode): `chordPicker`, `voicingPicker`, `chordClipboard`, `undo`, `noteInput`, `renameSection`, `addMeasureToSection`, `deleteSection`. Every `inject()` in chord-grid components must have a `null` default.

### 6.2 Mode toggle (3-way)

`mode` ref: `'no-chords' | 'chords' | 'tab'`. Persisted to `localStorage` under key `sbn.leadsheet.mode`.

| Mode | ChordMeasure density | Tab rendered |
|------|---------------------|--------------|
| `chords` | `full` | no |
| `no-chords` | `compact` | no |
| `tab` | — (grid hidden) | yes |

Tab button only shown when `hasTab` (useTabModel's `hasData` — at least one note with `string` + `fret`).

Legacy `sbn.leadsheet.density` key: read once on mount, migrated to new key (`compact` → `no-chords`).

### 6.3 Audio model

Two playback paths share one `AudioEngine` singleton:

```js
const chordAudio = useChordAudio(model);
const tabAudio   = useAudioEngine(model);
```

`loadAllEvents()` builds a combined sorted event array from both `tabModelToEvents` and `chordVoicingsToEvents` and calls `engine.load(combined)`. Events are mode-agnostic.

`onTransportToggle` dispatches to the active path based on `mode`. Mode-swap mid-playback: pause active path → seek both paths to current beat → resume in new path.

Cross-source coordination: the `'playStarted'` engine event clears `isPlaying` in each composable — no extra wiring needed when the EduPanel card plays a single chord.

### 6.4 Selection — `selectionData`

Single computed merges both modes:

- Tab mode: `tabSelection` ref (`{ gi }`) → `{ gi, ci: 0, section, measure }`
- Chord mode: last entry in `gridSelection.selection.value` → `{ gi, ci, section, measure }`

Mode-swap handoff:
- Tab → chord: `gridSelection.handleClick(tabSelection.gi, 0, syntheticEvent)`, clear `tabSelection`
- Chord → tab: `tabSelection = { gi: lastSelection.gi }`, `gridSelection.clearSelection()`

`selectionKey` computed: `"${chordName}@${gi}.${ci}"` — used to look up `chordCards` and `eduChordQualities`. Falls back to bare chord name via `_lookupWithFallback`.

### 6.5 EduPanel

Props: `currentChord`, `currentSectionId`, `selectionKey`, `song`, `progressions`, `chordCards`, `eduChordQualities`.

- Renders `<ChordCard :chord="activeCard" :show-root="true" />` for the selected chord.
- `activeCard` = `_lookupWithFallback(chordCards, selectionKey)` — tries per-slot key, then bare name.
- Chord-quality blurb from `eduChordQualities[qualitySlug]` (keyed by `quality` field on the card).
- Progressions: shows all song progressions when none carry section attribution (all `sectionId` are `null` — the `sbn_progression_occurrences` table does not track section). Filter activates only when at least one progression has a non-null `sectionId`.

### 6.6 Transport placement

`.sbn-leadsheet-transport` inside `.sbn-leadsheet-main` flex column, `position: sticky; bottom: 20px; margin-top: auto`. Short songs: sits at bottom of content. Long songs: sticks near viewport bottom while scrolling. Spacebar toggles playback globally from the viewer.

---

## 7. Read-only prop contract

`readOnly: { type: Boolean, default: false }` on:

- `ChordGridView`, `ChordSection`, `ChordMeasure` — suppresses edit UI
- `TabMeasure`, `TabCursor` — suppresses context menus, input ring, pending-digit overlay

**Rule:** all guards live in JS handlers, never as template ternaries. A `@contextmenu="readOnly ? null : handler"` does not work in Vue 3 — it evaluates the ternary and passes `null` as the listener (not inert). Correct pattern: `@contextmenu="onContextMenu"` with `if (props.readOnly) return;` at the top of the handler.

---

## 8. Cinema view architecture

Full-page dark surface, no nav chrome (`PublicLayout` not used).

```
Pages/Leadsheet/Cinema.vue
  ├─ <StageTopBar>          — title, classic-link, theme toggle
  ├─ <StageHeroNow ref="heroRef">
  │    ├─ <VideoPlayer>     — YouTube IFrame API, controls hidden
  │    └─ <ChordCard>       — current chord: diagram + popularity + difficulty
  ├─ <StageSectionsGrid>    — section tabs, full-width bar grid (horizontal scroll)
  └─ <StageTransportDeck>   — play/pause, prev/next, loop/count/click toggles
```

### 8.1 CSS design tokens (`--stage-*`)

Set on `.leadsheet-stage[data-theme="dark"|"light"]`. Dark is default.

| Token | Dark value |
|-------|-----------|
| `--stage-bg` | `#0a0a0b` |
| `--stage-bg-1` | `#111114` |
| `--stage-bg-2` | `#18181d` |
| `--stage-accent` | `#ff7a1a` |
| `--stage-accent-rgb` | `255, 122, 26` |
| `--stage-accent-2` | `#fff` |
| `--stage-line` | `rgba(255,255,255,0.08)` |
| `--stage-text` | `rgba(255,255,255,0.92)` |
| `--stage-font-chord` | `'Crimson Text', Georgia, serif` |
| `--stage-font-mono` | `'JetBrains Mono', monospace` |

Light overrides: white background, dark text, orange accent preserved. Stored in `localStorage` under key `cinema-theme`.

### 8.2 Video sync

`videoSync` from `jsonData.videoSync`:

```ts
interface VideoSync {
  videoId: string;
  videoType: 'youtube';
  mappings: Array<{ measureIndex: number; videoTime: number }>;
}
```

**Video → bar/beat** (`videoTimeToMeasureIndex`): binary search + linear interpolation between sync points. Fractional part × `beatsPerMeasure` = current beat.

**Bar → video time** (`measureIndexToVideoTime`): inverse of above. Called by `onSeekBar` to seek the YouTube player when the user clicks a bar in the grid.

**Fallback clock**: when no video or no mappings, `setInterval` at `(60/tempo)*1000` ms advances beat/bar. The fallback never runs when the video is master.

### 8.3 `StageHeroNow`

Props: `hasVideo`, `videoId`, `videoType`, `currentChordName`, `nextChordName`, `currentBarNum`, `sectionLabel`, `romanNumeral`, `currentChordCard`, `beatsUntilNext`, `currentBeat`, `beatsPerMeasure`.

Exposes via `defineExpose`: `play()`, `pause()`, `seekTo(seconds)` — proxied to the inner `VideoPlayer` ref.

`currentChordCard` is the `ChordDiagramData` for the current chord. `ChordCard` uses `onChordClick` if provided; otherwise clicking navigates to `/library/chords/{slug}?root={root_note}` in a new tab.

### 8.4 `StageSectionsGrid`

- Section tabs at top; one section rendered at a time (`activeSectionIndex`).
- `watch(currentBarIndex)` auto-advances `activeSectionIndex` during playback.
- One `ClassicChordCard` per chord in each measure bar.
- Horizontal scroll within `.stage-sec-body`.
- Clicking a bar calls `$emit('seek-bar', measure.globalIndex)`.
- `getVoicingAt(measure, ci)` — looks up `chordVoicings["${name}@${gi}.${ci}"]` then `chordVoicings[name]`.

### 8.5 `ClassicChordCard`

[`resources/js/Components/ChordDiagram/ClassicChordCard.vue`](../resources/js/Components/ChordDiagram/ClassicChordCard.vue)

Props: `chordName`, `voicing` (`ChordVoicing | null`), `dotColor`.

Chord name black (`--sbn-chord-color: #000000`). Diagram: calls `window.sbnRenderDiagramSVG(voicing, { dotColor, showFingers: true })` if available; falls back to `<NeonChordDiagram>`; shows `.classic-chord-empty` placeholder when no voicing.

### 8.6 Multi-chord bars

`currentChordIndex` computed in `Cinema.vue` splits the beat clock proportionally:

```js
const beatsPerChord = beatsPerMeasure / chordCount;
currentChordIndex = Math.min(chordCount - 1, Math.floor(currentBeat / beatsPerChord));
```

This drives which chord is shown in the hero and which `ChordCard` is highlighted in the grid.

### 8.7 `VideoPlayer` — hidden controls

`playerVars`: `controls: 0, rel: 0, modestbranding: 1, iv_load_policy: 3, disablekb: 1`. The YouTube title overlay cannot be suppressed via API; it fades naturally after a few seconds of playback.

---

## 9. `ChordCard` navigation

[`resources/js/Components/Library/ChordCard.vue`](../resources/js/Components/Library/ChordCard.vue)

- If `onChordClick` prop is provided: calls it on card click.
- Otherwise (default): if `chord.slug` exists, opens `/library/chords/{slug}?root={root_note}` in a new tab.
- `root_note` on the enriched card is the transposed root — the URL points to the exact voicing used in the song.
- Card has `cursor: pointer` via `.sbn-chord-card--clickable` when it has a slug or click handler.

---

## 10. Fret-string ↔ `diagram_data` conversion

Known tech debt: conversion exists in four places. Do not add a fifth.

| Location | Direction | Notes |
|----------|-----------|-------|
| `LeadsheetViewerService::fretStringToDiagramData()` | string → object | PHP, used in `synthesizeMinimalCard` |
| `Components/Library/ChordDiagram.vue::diagramDataToFretString()` | object → string | TS, used for matching |
| `audio/adapters/chordVoicingsToEvents.js::parseFretChar()` | per-character | JS audio adapter |
| `ChordShapeCalculator` / `ChordVoicingSearch` | internal | PHP, positional math |

Planned consolidation: `app/Services/ChordFretString.php` + `resources/js/utils/fretString.ts`. Not yet done.

---

## 11. Edu content service

[`app/Services/EduContentService.php`](../app/Services/EduContentService.php) — backed by [`config/edu/chord-qualities.php`](../config/edu/chord-qualities.php).

- `allChordQualities()` — returns all `Record<slug, { title, blurb }>` in one shot.
- Config keys match canonical quality slugs from `ChordVoicingSearch::parseChordName()`.
- Future: replace config lookup with `edu_topics` Eloquent query; public method signatures stay the same.

Quality slugs: `maj`, `min`, `aug`, `dim`, `5`, `sus4`, `sus2`, `add9`, `maj7`, `m7`, `dom7`, `m7b5`, `o7`, `maj6`, `m6`, `mMaj7`, `aug7`, `7sus4`.

---

## 12. CSS hooks

| File | What lives there |
|------|-----------------|
| `public/css/sbn-design-system.css` | `.sbn-ve-*` chord grid, `.sbn-transport-*`, `.sbn-chord-card`, `.sbn-card-*`, density animation system |
| `resources/js/Components/Leadsheet/LeadsheetViewer.vue` (scoped) | Viewer two-column layout, `.sbn-leadsheet-main`, `.sbn-leadsheet-transport` sticky placement |
| `resources/js/Pages/Leadsheet/Cinema.vue` (scoped) | `.leadsheet-stage`, all `--stage-*` token assignments, light theme overrides |
| `resources/js/Components/Cinema/StageHeroNow.vue` (scoped) | `.stage-hero`, `.stage-hero-card`, `.stage-beat-row`, `stagePulse` keyframe |
| `resources/js/Components/Cinema/StageSectionsGrid.vue` (scoped) | `.stage-sec-*`, `.classic-chord-card` |

---

## 13. Files index

**Controllers / services**
- `app/Http/Controllers/Library/SongLibraryController.php` — `show()`, `viewer()`, `cinema()`, `apiSearch()`, `apiViewerData()`
- `app/Services/LeadsheetViewerService.php` — `enrich()`, `pickBestVoicing()`, `synthesizeMinimalCard()`, `getVoicingShapePattern()`
- `app/Services/ChordVoicingSearch.php` — `searchByName()`, `parseChordName()`
- `app/Services/EduContentService.php`
- `config/edu/chord-qualities.php`

**Pages**
- `resources/js/Pages/Library/Songs/Show.vue` — song teaser
- `resources/js/Pages/Library/Songs/Viewer.vue` — classic viewer wrapper (thin Inertia page)
- `resources/js/Pages/Leadsheet/Cinema.vue` — cinema orchestrator

**Components — classic viewer**
- `resources/js/Components/Leadsheet/LeadsheetViewer.vue`
- `resources/js/Components/Leadsheet/EduPanel.vue`
- `resources/js/tab-editor/components/ChordGridView.vue` (readOnly prop)
- `resources/js/tab-editor/components/ChordSection.vue` (readOnly prop)
- `resources/js/tab-editor/components/ChordMeasure.vue` (readOnly + density props)
- `resources/js/tab-editor/components/TabMeasure.vue` (readOnly prop)
- `resources/js/tab-editor/components/TabCursor.vue` (readOnly prop)
- `resources/js/tab-editor/components/TransportBar.vue`

**Components — cinema view**
- `resources/js/Components/Cinema/StageTopBar.vue`
- `resources/js/Components/Cinema/StageHeroNow.vue`
- `resources/js/Components/Cinema/StageSectionsGrid.vue`
- `resources/js/Components/Cinema/StageTransportDeck.vue`
- `resources/js/Components/ChordDiagram/ClassicChordCard.vue`
- `resources/js/Components/ChordDiagram/NeonChordDiagram.vue`

**Shared**
- `resources/js/Components/Library/ChordCard.vue`
- `resources/js/Components/Library/ChordDiagram.vue`
- `resources/js/tab-editor/components/VideoPlayer.vue`
- `resources/js/types/leadsheet.ts`

---

## 14. Open items

- **Density rework (compact mode):** `sbn-ve-measure-content` `min-height` in compact mode still keeps measures tall. Real fix: shrink min-height, ignore `lineBreaks`, tighten measure padding. Deferred.
- **Cinema video controls:** YouTube title overlay fades naturally but cannot be suppressed via API.
- **Fret-string consolidation:** four locations (§10). Planned as `ChordFretString.php` + `fretString.ts` but not yet done.
- **Tab reflow on resize:** tab view uses static row layout; does not reflow on viewport resize. Acceptable; revisit if flagged.
- **`edu_topics` DB table:** `EduContentService` is config-backed. DB migration deferred.
- **Cinema video sync editor:** sync mapping UI (mapping `measureIndex ↔ videoTime` in the admin) is not yet built. Mappings must currently be hand-authored in the JSON.

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

- [`Leadsheet`](../app/Models/Leadsheet.php) — `slug`, `title`, `composer`, `song_key`, `tempo`, `time_signature`, `rhythm`, `description`, `harmony_notes`, `form_notes`, `voicing_notes`, `measure_count`, `popularity`, `cover_image_path`, `status`.
- `parsed_data` accessor — decodes the `json_data` column (see §3) and returns an array.
- `getChordNames()` — returns a flat deduplicated list of chord names used across all measures.

### 2.1 Draft / publish status

`status` is `'draft'` or `'publish'` (column added 2026-05-20, defaults to `draft`). A library "Song" *is* a `Leadsheet` record, so this one column gates public visibility for the whole song library.

- `Leadsheet::published()` scope — `where('status', 'publish')`. Used by every public-facing query: the song library index + its filter lists, and the "songs using this chord/progression/rhythm" lists on the chord/progression/rhythm library pages, and related-songs on the public course page.
- Single-record public routes (`show`, `viewer`, `cinema`, `apiViewerData` in `SongLibraryController`) call `abortIfDraft()` → 404 on drafts.
- `SongLibraryController::apiSearch()` is **not** scoped — admins can reference unpublished songs when building lessons.
- Toggled from the admin leadsheet index via a clickable status badge → `POST /api/admin/leadsheets/{leadsheet}/status` (`LeadsheetController::updateStatus`).
- The Exercises tab shares `sbn_leadsheets` but has no status UI (different controller).

### 2.2 Cover image & canonical song cross-link

`cover_image_path` (column added 2026-05-19) stores a bare filename; images live in `public/images/songs/`. Assigned from the admin leadsheet index.

Wherever a song is **linked from another page** (the "songs using this chord/progression/rhythm" lists), use the one canonical pattern — do not hand-roll song-row markup:

- **Backend** — `Leadsheet::toLinkArray()` returns the compact payload `{id, slug, title, styleSlug, coverImagePath}`. It is the single source of truth, built from two accessors:
  - `style_slug` — maps `rhythm` → design-system style slug (`bossa`/`samba`/`jazz`/`latin`/`blues`/`pop`/`classical`).
  - `cover_image_url` — `/images/songs/{file}` or `null`.
  `ChordLibraryController`, `ProgressionLibraryController`, and `RhythmLibraryController` all map their song lists through `toLinkArray()`.
- **Frontend** — `Components/Library/SongLink.vue` renders one row: cover thumbnail (or style-colored gradient fallback) + title + an `sbn-cat-badge` style-category badge. Styled app-wide via `.sbn-song-link` in `sbn-design-system.css`. Title + category only — no composer/key (that detail lives on the richer `SongCard`).

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

1. **Re-spell** `$chordName` via `HarmonicContext::reSpellChordName($name, $leadsheet->song_key)` — corrects enharmonic mismatches from old MusicXML imports (e.g. `"D/Gb"` → `"D/F#"` in a D-major song) so the DB query finds the right chord diagram.
2. Call `ChordVoicingSearch::searchByName($chordName)` — DB lookup by name.
3. `pickBestVoicing($matches, $targetFrets)` — prefers exact fret-string match; falls back to first result.
4. If no DB match: `synthesizeMinimalCard($chordName, $voicing, $search)` — builds a stub `ChordDiagramData` from the fret string with `popularity`/`difficulty` absent.
5. Result stored under both the per-slot key (`"ChordName@gi.ci"`) and the bare-name key (`"ChordName"`).

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

`mode` ref: `'no-chords' | 'chords' | 'tab'`. Persisted to `localStorage` under key `sbn.leadsheet.mode`. Toggle button labels (UI only — the stored `mode` values are unchanged for localStorage-migration compatibility): `no-chords` → "Analysis", `chords` → "Chords", `tab` → "Tab".

| Mode | ChordMeasure density | Tab rendered | Chord card content |
|------|---------------------|--------------|---------------------|
| `chords` | `full` | no | chord name + diagram |
| `no-chords` ("Analysis") | `full` | no | giant light-grey Roman numeral (see below) |
| `tab` | — (grid hidden) | yes | — |

Tab button only shown when `hasTab` (useTabModel's `hasData` — at least one note with `string` + `fret`).

Legacy `sbn.leadsheet.density` key: read once on mount, migrated to new key (`compact` → `no-chords`). Analysis mode keeps full chord-card spacing (it swaps content, not density) — the old "compact" grid-shrink behavior no longer exists.

**Analysis mode (Roman numerals).** `LeadsheetViewerService::enrich()` computes `chordNumerals` (keyed `"${chordName}@${gi}.${ci}"`, same per-slot-then-bare-name lookup convention as `chordCards`/`chordVoicings`) via `ProgressionDetector::chordToNumeral()`, cached per chord name. `LeadsheetViewer` provides `showNumerals` (`computed(() => mode.value === 'no-chords')`) and `chordNumerals` (plain object, no `.value`) — both `inject('key', null)`-defaulted so the admin tab editor is unaffected. `ChordCard.vue` branches its template on `showNumerals`: the numeral branch strips the `/bass` suffix (`numeral.split('/')[0]` — inversions still read as their base scale-degree function) and renders via `formatNumeralHtml()` (`resources/js/tab-editor/utils/chordFormat.js`), which mirrors `formatChordHtml`'s convention — base numeral normal size, quality/extension suffix (`m7`, `°`, `maj7`, …) as `<sup>`, unicode accidentals (`♭`/`♯`). Font: `'Crimson Text', Georgia, serif` (the numeral font must be one of the four families actually loaded in `app.blade.php` — DM Sans, JetBrains Mono, Crimson Text 400/600, Fraunces; there is no Cormorant Garamond despite it looking plausible for a serif numeral).

### 6.3 Audio model

Two playback paths share one `AudioEngine` singleton, which only holds **one loaded event list at a time**:

```js
const chordAudio = useChordAudio(model);
const tabAudio   = useAudioEngine(model);
```

`loadAllEvents()` loads **only the events for the currently-active `mode`** — Tab mode loads `tabModelToEvents()` output only; Chords/Analysis mode loads `chordVoicingsToEvents()` output only. (Earlier this combined both into one array and loaded them together, which meant pressing play in Chords mode also sounded every tab note simultaneously — fixed 2026-07-04.) A `_eventsLoadedForMode` guard forces a reload whenever `mode` changes or a play/seek call finds the loaded events don't match the active mode: `if (!_eventsLoaded || _eventsLoadedForMode !== mode.value) await loadAllEvents();`

Both adapters are called with `voice: 'nylon'` so playback uses real nylon-string guitar samples (`NylonSampler`, `public/audio/nylon/{E2,A2,D3,G3,B3,E4}.mp3`, a `Tone.Sampler` pitch-shifting between six recorded anchor notes) rather than the `PitchedSynth` oscillator — the admin tab editor still uses `'pitched'` (untouched). `engine.init()` must be called with a non-empty `samplesBaseUrl` (e.g. `/audio/rhythm-samples/`, the *percussion* sampler's own base path) purely to arm the shared `NylonSampler` singleton's lazy `init()` gate inside `AudioEngine.init()` — passing `''`/omitting it silently leaves the nylon sampler's samples unloaded even if events are tagged `voice: 'nylon'`.

`onTransportToggle` dispatches to the active path based on `mode`. Mode-swap mid-playback: pause active path → seek both paths to current beat → resume in new path (which now also reloads events for the new mode, see above).

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

Props: `currentChord`, `currentSectionId`, `selectionKey`, `song`, `progressions`, `chordCards`, `eduChordQualities`, `hoveredProgressionId`, `skillNodes`, `relatedTheory`. Emits `progression-hover`.

**Layout (2026-07-04 redesign):** all sections are `<details class="vC-panel">` accordions matching the course-player convention (`PracticePanel.vue`), in order: Song info (composer/performer/key/style/tempo/time — collapsed by default), Current chord (open), Progressions (open), Skill nodes, Related theory.

- Renders `<ChordCard :chord="activeCard" :show-root="true" />` for the selected chord.
- `activeCard` = `_lookupWithFallback(chordCards, selectionKey)` — tries per-slot key, then bare name.
- Chord-quality blurb from `eduChordQualities[qualitySlug]` (keyed by `quality` field on the card).
- Progressions: shows all song progressions. Each list entry emits `progression-hover` (id, or `null` on leave) so `LeadsheetViewer` can intensify the matching bars in the grid; the entry hovered (`hoveredProgressionId`) gets an `is-active` marker.
- **Skill nodes:** icon-only tile grid (`sbn-edu-skill-grid`), one `<a href="/skills#{slug}">` tile per linked `SkillNode`, reusing `SkillIcon.vue` (icon-path → icon-key → branch-default fallback), title attr for hover tooltip. Sourced from `Leadsheet::skillNodes()` (reverse morphToMany via `sbn_skill_node_content`).
- **Related theory:** neutral-titled (non-orange) expander list, full-width flush layout so embedded widgets get maximum width. Backed by `EduContentService::conceptsForLeadsheet()` — see below.

**`onPanelToggle` guard:** the top-level accordion's `@toggle.capture` handler that closes sibling `<details>` must check `if (!opened.classList?.contains('vC-panel')) return;` before doing anything — nested `<details>` (a "Learn more" expander, or a Related-theory item) also fire `toggle` and bubble up via capture; without this guard, opening a nested expander force-closes every top-level panel.

**`EduContentService::conceptsForLeadsheet(Leadsheet, array $progressionSlugs = [])`** resolves up to `MAX_RELATED_CONCEPTS = 4` topics for Related theory, in priority order: skill-node-linked concepts (`SKILL_NODE_CONCEPTS` map) → progression-slug substring match (`PROGRESSION_CONCEPTS`) → genre fallback (`GENRE_CONCEPTS`, only if nothing found above) → difficulty gate (`DIFFICULTY_GATED_CONCEPTS`, advanced concepts require `difficulty >= 3`) → dedupe/cap → resolve to `EduTopic` objects. This widens "Related theory" beyond the old chord-quality-only lookup (which showed near-identical generic content for e.g. Canarios vs. Summertime) by using curriculum data the skill-node graph already links to the song.

### 6.6 Detected-progression bracket boxes

Each `ProgressionRef` carries a `ranges` array — one entry per occurrence — built by `LeadsheetViewerService::fetchProgressions()` from `sbn_progression_occurrences`. Each range is:

```ts
{
  sectionId:     string,
  startMeasure:  number,   // section-relative, 0-based
  length:        number,   // in measures
  startChord:    number,   // ci within startMeasure where progression begins
  endChord:      number,   // ci within endMeasure where progression ends (inclusive)
  endChordStart: number,   // first ci in endMeasure belonging to this progression
}
```

**One continuous box per progression per row-segment (2026-07-04 — replaces the old per-chord `.in-progression` tint).** `ChordSection.vue` injects `progressionsList` / `hoveredProgressionId` / `beatsPerMeasureRef` (all `null`-defaulted — viewer-only, `LeadsheetViewer.vue` is the only provider) and computes `progressionBoxesByRow` — a `Map<rowIndex, box[]>` where each `box` is `{ progId, left, width, color }` as **row-relative percentages**, using the same beat-fraction math as `ChordMeasure`'s own `chordPositionStyle()` (no `getBoundingClientRect()` needed — `.sbn-ve-measure { flex: 1 1 0 }` gives deterministically equal-width flex children, so measure `mi`'s left/width within a row of `N` measures is `mi/N` / `1/N`).

A progression whose bars wrap across a row break gets **one box per row it touches** — `progressionBoxesByRow`'s per-row walk flushes an open segment (`pushBox`) whenever the next bar in the range isn't found in the current row's gi set, then starts a new segment for the next row.

Rendered as an absolutely-positioned `<div class="sbn-ve-prog-box">` per box inside `.sbn-ve-row`, styled via a `--prog-color` CSS custom property (from `getCategoryColor(prog.category)`) rather than per-chord background tinting:

```css
.sbn-ve-prog-box {
    position: absolute;
    top: 26px;    /* matches .sbn-ve-row's own padding-top (repeat/volta bracket headroom) */
    bottom: 18px; /* matches the measure hover-frame's geometry exactly */
    border: 1.5px solid color-mix(in srgb, var(--prog-color, var(--clr-accent)) 45%, transparent);
    background: color-mix(in srgb, var(--prog-color, var(--clr-accent)) 8%, transparent);
    pointer-events: none;
}
.sbn-ve-prog-box.is-hovered { /* border-color/background intensify to full var(--prog-color) / 16% */ }
```

**`top: 26px` is load-bearing** — the box is a child of `.sbn-ve-row` (which has its own `padding-top: 26px`), not `.sbn-ve-measure-content` like the hover frame; using `top: 0` (matching the hover frame literally) renders the box visibly too high.

**Classic viewer accent override:** `.sbn-leadsheet-viewer` overrides `--clr-accent` / `--clr-accent-bg` / `--clr-accent-border` / `--clr-accent-dim` to blue (`#3b82f6`) so all accent-colored UI on the viewer page (toggle buttons, EduPanel borders, chord focus ring, hover frame) is blue rather than the global orange.

### 6.7 Transport placement (shared with Cinema — see §8.8)

`<TransportDeck>` (prev/play/next, click-seek section-tinted track, `<RateSlider>` ±20% speed, no loop — `show-loop="false"`, since the Tone.js engine has no loop concept yet) sits inside `<HoverRevealDeck variant="score">`, itself inside `.sbn-leadsheet-main`. `position: sticky; bottom: 12vh` (`8vh` on mobile) — floats above the very bottom of the viewport rather than flush against it.

**Hover-reveal, not scroll-based.** The deck is invisible until hovered; the mouseenter/mouseleave listeners live on `.sbn-leadsheet-main` itself (the whole score column), not on the deck (which can't host a meaningful hover target while translated off-screen). `useHoverRevealTransport()` composable just holds the boolean; `HoverRevealDeck` is presentational only, taking `visible` as a prop.

Bar-vs-beat adapter: the audio engine is beat-indexed, `TransportDeck` works in bars — `currentBar`/`totalBars` are derived (`Math.floor`/`Math.round` against `beatsPerMeasure`), and `onPrevBar`/`onNextBar`/`onDeckSeekBar` translate back to `seekToMeasure`/`onTransportSeek` beat calls.

**Speed slider is a UI convention, not a shared mechanism.** `RateSlider` looks identical on both views but drives different backends: Viewer's `onRateChange(rate)` computes `engine.setTempo(baseTempo * rate)` on the real Tone.js clock (continuous BPM); Cinema's `setRate(rate)` is a multiplier on either `VideoPlayer.setPlaybackRate()` or the fallback `setInterval` tick length. Do not assume the two are interchangeable when touching either.

Spacebar toggles playback globally from the viewer (unrelated to hover-reveal).

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
  ├─ <StageTopBar>          — Songs→style→difficulty→title breadcrumb (§8.8),
  │                           Options menu (display mode + backing track),
  │                           pinned <ViewToggle> back to Classic
  ├─ <StageHeroNow ref="heroRef">
  │    ├─ <VideoPlayer>     — YouTube IFrame API (controls hidden) or self-hosted <video>
  │    ├─ <ChordCard>       — current chord: diagram + popularity + difficulty
  │    └─ #overlay slot     — <HoverRevealDeck variant="video"><TransportDeck>,
  │                           self-hosted video only (§8.8)
  ├─ <StageSectionsGrid>    — section tabs, full-width bar grid (horizontal scroll);
  │                           chords/tab view is a `view` prop now (lifted to StageTopBar's
  │                           Options menu), not an internal toggle
  └─ (no static transport deck below the grid — see §8.8)
```

### 8.1 CSS design tokens (`--stage-*`)

Set on `.leadsheet-stage[data-theme="dark"|"light"]`. Dark is default.

| Token | Dark value |
|-------|-----------|
| `--stage-bg` | `#0a0a0c` |
| `--stage-bg-1` | `#111117` |
| `--stage-bg-2` | `#16161e` |
| `--stage-accent` | `#ff7a1a` |
| `--stage-accent-rgb` | `255, 122, 26` |
| `--stage-accent-2` | `#ffb347` |
| `--stage-line` | `rgba(255,255,255,0.08)` |
| `--stage-text` | `#e8e4dc` |
| `--stage-font-chord` | `'Crimson Text', Georgia, serif` |
| `--stage-font-mono` | `'JetBrains Mono', monospace` |

Light overrides: white/blue-tinted surfaces, dark text, orange accent preserved. Stored in `localStorage` under key `cinema-theme`.

Orange halo glows removed from video card and hero card (`box-shadow` outer glow stripped). Page-level scrim retains only the bottom purple gradient; top orange scrim removed.

### 8.2 Video sync

`videoSync` from `jsonData.videoSync`:

```ts
interface VideoSync {
  videoId: string;
  videoType: 'youtube' | 'hosted';
  mappings: Array<{ measureIndex: number; videoTime: number }>;
  // Duplicates per measureIndex are expected for repeated bars (AABA).
}
```

Cinema interpolates in **play-position** space — see [SBN-Audio-Reference §5.1](SBN-Audio-Reference.md#51-repeat--volta-playback-model). On load, Cinema reads `jsonData.repeatMarkers` into per-measure `repeatStart` / `repeatEnd` flags via `normalizeMeasure`, builds `expandedSequence` with `expandMeasureSequenceWithPass(flatBars)` (replaces `expandMeasureSequence`), and projects raw mappings onto play positions through `mappingsByPosition`.

`expandMeasureSequenceWithPass` returns `{ sequence, passAtPosition }` — `passAtPosition[playPos]` is the repeat-block pass number (1, 2, …) at that position. Cinema stores `currentPlayPosition` (updated by both video clock and fallback clock) and derives `activeVoltaPass = passAtPosition[currentPlayPosition] ?? 1`, passed down to `StageSectionsGrid` to show/hide volta endings.

**Video → bar/beat** (`videoTimeToPlayPosition` → `giAtPosition`): binary search over pos-keyed mappings + linear interpolation. Fractional part × `beatsPerMeasure` = current beat. The bar that highlights is `seq[floor(pos)]`, so a repeated bar correctly re-lights on each pass.

**Bar → video time** (`measureIndexToVideoTime`): resolves gi → its first play position → `playPositionToVideoTime`. Clicking a repeated bar always seeks to its first occurrence (start of phrase). Matches the tab editor's `seekToMeasure` semantics.

**Fallback clock**: when no video or no mappings, `setInterval` at `(60/tempo)*1000` ms advances beat/bar. The fallback never runs when the video is master. **Not repeat-aware** — it walks gi linearly and loops at `totalBars`. Acceptable while video is the primary playback mode; see Open items if/when silent practice mode needs repeats.

### 8.3 `StageHeroNow`

Props: `hasVideo`, `videoId`, `videoType`, `currentChordName`, `nextChordName`, `currentBarNum`, `sectionLabel`, `romanNumeral`, `currentChordCard`, `beatsUntilNext`, `currentBeat`, `beatsPerMeasure`.

Exposes via `defineExpose`: `play()`, `pause()`, `seekTo(seconds)` — proxied to the inner `VideoPlayer` ref.

`currentChordCard` is the `ChordDiagramData` for the current chord. `ChordCard` uses `onChordClick` if provided; otherwise clicking navigates to `/library/chords/{slug}?root={root_note}` in a new tab.

### 8.4 `StageSectionsGrid`

Props: `sections`, `currentBarIndex`, `fractionalPlayPosition`, `playing`, `chordVoicings`, `activeVoltaPass`, `tabModel`, `tabHasData`, `timeSignature`.

**View toggle** (top-right): `'chords'` (default) | `'tab'`. Tab button only shown when `tabHasData` is true (song has note data with string+fret). Persists in local `view` ref.

Each view is `container (.stage-{sec,tab}-scroll, overflow-x:auto) > track (.stage-strip-track, position:relative) > measures`. The track being `position:relative` is load-bearing: it makes each measure's `offsetLeft` track-relative (= the container's scroll-content origin), which is what the follow-cam reads.

**Chords view** — one `ClassicChordCard` per chord slot per measure bar.

**Tab view** — `TabMeasure` components for the active section, `barsPerRow=4` (standard width). `TabMeasure` wrapped in `.stage-tab-measure-wrap` for click-to-seek and active highlight. Chord names scaled to 26px via scoped `:deep()` override.

**Follow-cam (rigid scrollLeft driver — rearchitected 2026-07-03, commit 708cb20).** The camera does NOT chase with a lerp (that trailed a moving playhead and drifted notation off-screen on longer songs). Instead:
- `rebuildLayout()` caches per-**visible**-bar `{offset, width}` from the active track (filters out `.stage-sec-measure--hidden` so the index aligns with `playheadX()`'s visible-only walk — otherwise every bar after a collapsed volta maps to the wrong card).
- `playheadX()` = `bar.offset + frac × bar.width`, where the bar is picked by `currentBarIndex` (gi, repeat/volta-aware) and `frac` is the sub-bar remainder of `fractionalPlayPosition`.
- Each `requestAnimationFrame` tick, `applyFollow()` sets `container.scrollLeft = anchorScrollLeft()` **directly** (no smoothing). Direct assignment is rigid — no feedback loop, so it can't drift or lag — while staying native scroll. `PLAYHEAD_ANCHOR = 0.4` puts the active bar left-of-center (lookahead to the right); clamped to `[0, scrollWidth − clientWidth]` so the last bar rests at the right edge with no empty void past the score.
- Re-anchors (one-shot `applyFollow()` after `rebuildLayout()`) on: `playing` toggle, `view` switch, `activeVoltaPass` flip (deferred 380ms for the 0.35s collapse animation), `currentBarIndex` seek while paused, and window `resize`.
- Scrollbar is hidden during playback via `.stage-strip--playing` (a two-class selector, `.stage-{sec,tab}-scroll.stage-strip--playing::-webkit-scrollbar`, to outrank the per-view `::-webkit-scrollbar {height:4px}` rules by specificity) — a moving auto-scrollbar is a false affordance while the cam drives; it returns on pause for manual panning.

**Shared behaviour:**
- Clicking any bar/measure emits `seek-measure(globalIndex)`.
- Volta bars: `isMeasureVisible(measure)` hides measures whose `volta.number ≠ activeVoltaPass`. Hidden measures collapse via `max-width: 0 + min-width: 0 + margin: 0` transition (no flex gap left behind).

**Tab model provided by Cinema.vue** — `useTabModel` built from `jsonData` refs. Full provide contract: `model`, `beatsPerMeasureRef`, `playingMeasureIndex` → `currentBarIndex`, `transportBeat` (cumulative), `transportPlaying`, `seekToMeasure`, `gridSelection`, `globalIndexOf`, `inlineRenameTarget`, plus null stubs for all editor-only injects.

`getVoicingAt(measure, ci)` — looks up `chordVoicings["${name}@${gi}.${ci}"]` then `chordVoicings[name]`.

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

### 8.8 Shared top-bar / transport components (Viewer ↔ Cinema)

Unified 2026-07-04 so the two views feel like one app with a mode switch, not two separate builds. All four live in `resources/js/Components/Leadsheet/` (not `Cinema/`) since both pages consume them:

| Component | Role | Notes |
|---|---|---|
| `Breadcrumb.vue` | Songs → style → difficulty → title trail, gradient band | `size="lg"` variant (taller, fully-rounded) used by both the classic Viewer and `StageTopBar` (which renders the shared `.sbn-breadcrumb .sbn-breadcrumb--cat .sbn-breadcrumb--lg` classes directly, not a copy) |
| `TopBarMenu.vue` | Generic "Options ▾" dropdown, click-outside + Escape to close | Holds whatever secondary switches a page has (arrangement, display mode, backing track) so the top bar doesn't keep growing new pinned buttons |
| `ViewToggle.vue` | Pinned Classic/Cinema two-segment pill | Only pinned control; the "primary switch experience" action, per its own design doc discussion |
| `TransportDeck.vue` | prev/play/next, click-seek section-tinted track, `<RateSlider>`, optional Loop | Was Cinema-only (`StageTransportDeck.vue`); made host-agnostic — CSS reads `var(--stage-accent, var(--clr-accent))` etc. so it looks right on both the plain Viewer surface and Cinema's per-style `--stage-*` palette. `showLoop` prop (default true) lets Viewer omit Loop until its audio composables actually support it. |
| `RateSlider.vue` | ±20% speed slider (0.8×–1.2×), same look both places | Drives *different* backends — see §6.7's "not a shared mechanism" note |
| `HoverRevealDeck.vue` | Presentational show/hide wrapper for the transport deck | Takes `visible` prop (not self-hosted hover) + `variant="score"\|"video"` for sticky-in-column vs. absolute-over-video positioning |
| `useHoverRevealTransport.js` (composable) | Just a `transportHovered` ref + enter/leave setters | Bind the handlers to the **larger host container** (`.sbn-leadsheet-main` / `.stage-video-card`), never to `HoverRevealDeck` itself — it's invisible until revealed and can't host a meaningful hover target while hidden |

**Cinema's video overlay only renders for self-hosted video** (`videoType !== 'youtube'`) — YouTube's own iframe chrome already covers play/seek, and layering a second transport on top of it would fight the native controls. YouTube/no-video songs on Cinema rely on Space/←/→ keyboard shortcuts only; there's no fallback static deck.

`StageHeroNow` exposes the overlay via a scoped slot (`#overlay="{ visible }"`) so Cinema.vue's `<HoverRevealDeck>` gets the hover state without `StageHeroNow` needing to know anything about the deck's internals.

---

## 9. `ChordCard` navigation

[`resources/js/Components/Library/ChordCard.vue`](../resources/js/Components/Library/ChordCard.vue)

- If `onChordClick` prop is provided: calls it on card click.
- Otherwise (default): if `chord.slug` exists, opens `/library/chords/{slug}?root={root_note}` in a new tab.
- `root_note` on the enriched card is the transposed root — the URL points to the exact voicing used in the song.
- Card has `cursor: pointer` via `.sbn-chord-card--clickable` when it has a slug or click handler.

---

## 10. Fret-string ↔ `diagram_data` conversion

✅ **Consolidated (2026-07-02).** The conversion now lives in **one authority per
language**, kept in sync by hand:

- **JS/TS:** [`resources/js/utils/fretString.ts`](../resources/js/utils/fretString.ts) —
  `parseFretChar` / `fretToChar` (per-char hex codec), `diagramDataToFretString`,
  `fretStringToDiagramData`. Tested in `resources/js/utils/__tests__/fretString.test.ts`.
- **PHP:** [`app/Services/ChordFretString.php`](../app/Services/ChordFretString.php) —
  the same four functions, mirroring the TS module.

**The fret-string contract** (documented in both files): 6 chars, one per diagram
string 1→6 (low E → high e), `x`=muted, `0`=open, `1`–`9`/`a`–`f` = fret in **hex**
(so frets 10–15 stay single-char).

Sites now routed through the shared code:

| Location | Uses | Notes |
|----------|------|-------|
| `LeadsheetViewerService::fretStringToDiagramData()` | PHP `ChordFretString` | private method delegates |
| `Components/Library/ChordDiagram.vue` | TS `diagramDataToFretString` | |
| `audio/adapters/chordVoicingsToEvents.js` | JS `parseFretChar` | |
| `tab-editor/composables/useChordSync.js` | JS `parseFretChar`/`fretToChar` | char codec only; tab↔diagram string-index mapping stays local |
| `tab-editor/utils/transpose.js` | JS `parseFretChar`/`fretToChar` | codec only; octave-fold arithmetic stays local |

**Deliberately NOT folded in** (behaviour differs — would change output):

- `ChordShapeCalculator` / `ChordVoicingSearch` (PHP) — internal positional math, not the string↔object conversion.
- `useVoicingPickerStore.js::_diagramDataToFrets()` and the identical helper in
  `Components/Course/PracticePanel.vue` — these **expand barres** across strings, which
  the shared `diagramDataToFretString` does not. Folding them in requires adding barre
  support to the shared function first; left as a known follow-up rather than a silent
  behaviour change.

---

## 11. Edu content service

[`app/Services/EduContentService.php`](../app/Services/EduContentService.php) — backed by [`config/edu/chord-qualities.php`](../config/edu/chord-qualities.php).

- `allChordQualities()` — returns all `Record<slug, { title, blurb }>` in one shot.
- Config keys match canonical quality slugs from `ChordVoicingSearch::parseChordName()`.
- Future: replace config lookup with `edu_topics` Eloquent query; public method signatures stay the same.
- `conceptsForLeadsheet(Leadsheet, array $progressionSlugs = [])` (2026-07-04) — powers EduPanel's "Related theory" section; see §6.5 for the priority-order resolution (skill nodes → progressions → genre fallback → difficulty gate) and the four backing static maps (`SKILL_NODE_CONCEPTS`, `PROGRESSION_CONCEPTS`, `GENRE_CONCEPTS`, `DIFFICULTY_GATED_CONCEPTS`).

Quality slugs: `maj`, `min`, `aug`, `dim`, `5`, `sus4`, `sus2`, `add9`, `maj7`, `m7`, `dom7`, `m7b5`, `o7`, `maj6`, `m6`, `mMaj7`, `aug7`, `7sus4`.

---

## 12. CSS hooks

| File | What lives there |
|------|-----------------|
| `public/css/sbn-design-system.css` | `.sbn-ve-*` chord grid (section headers now full-bleed + colorless, shared by admin editor/tab view/chords view), `.sbn-page` / `.sbn-page-detail` / `.sbn-page-stage` container widths, `.sbn-breadcrumb` (+ `--cat`/`--brand`/`--lg` variants), `.sbn-chord-card`, `.sbn-card-*`, density animation system |
| `resources/js/Components/Leadsheet/LeadsheetViewer.vue` (scoped) | Viewer two-column layout, `.sbn-leadsheet-main`, `deckAccentStyle` (feeds `--stage-accent`/`--stage-gradient*` from the song's category color onto `HoverRevealDeck`) |
| `resources/js/Components/Leadsheet/TransportDeck.vue` (scoped) | `.tdeck-*` — shared Viewer/Cinema transport deck, translucent + blurred background |
| `resources/js/Components/Leadsheet/HoverRevealDeck.vue` (scoped) | `.sbn-hover-deck--score` / `--video` positioning variants |
| `resources/js/Pages/Leadsheet/Cinema.vue` (scoped) | `.leadsheet-stage`, all `--stage-*` token assignments, light theme overrides |
| `resources/js/Components/Cinema/StageHeroNow.vue` (scoped) | `.stage-hero`, `.stage-hero-card`, `.stage-video-card` (overlay host), `.stage-beat-row`, `stagePulse` keyframe |
| `resources/js/Components/Cinema/StageSectionsGrid.vue` (scoped) | `.stage-sec-*`, `.classic-chord-card` |
| `resources/js/Components/Cinema/StageTopBar.vue` (scoped) | Options-menu panel styling only — band itself comes from the shared `.sbn-breadcrumb*` classes |

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
- `resources/js/tab-editor/components/TransportBar.vue` — **admin tab editor only now**; the public Viewer uses the shared `TransportDeck.vue` (§8.8)

**Components — cinema view**
- `resources/js/Components/Cinema/StageTopBar.vue`
- `resources/js/Components/Cinema/StageHeroNow.vue`
- `resources/js/Components/Cinema/StageSectionsGrid.vue`
- `resources/js/Components/ChordDiagram/ClassicChordCard.vue`
- `resources/js/Components/ChordDiagram/NeonChordDiagram.vue`

**Shared (Viewer ↔ Cinema — §8.8)**
- `resources/js/Components/Breadcrumb.vue`
- `resources/js/Components/Leadsheet/TopBarMenu.vue`
- `resources/js/Components/Leadsheet/ViewToggle.vue`
- `resources/js/Components/Leadsheet/TransportDeck.vue`
- `resources/js/Components/Leadsheet/RateSlider.vue`
- `resources/js/Components/Leadsheet/HoverRevealDeck.vue`
- `resources/js/composables/useHoverRevealTransport.js`
- `resources/js/composables/useBreadcrumb.ts` — `songBreadcrumbSegments()`, `styleLabel()`
- `resources/js/Components/Library/ChordCard.vue`
- `resources/js/Components/Library/ChordDiagram.vue`
- `resources/js/tab-editor/components/VideoPlayer.vue`
- `resources/js/types/leadsheet.ts`

---

## 14. Traps and invariants

Things that are non-obvious, have already caused bugs, or will silently misbehave if violated.

### Audio events must be loaded before play

`useChordAudio.play()` and `useAudioEngine` both call `engine.play()` on the singleton. The engine plays whatever events are currently queued — if you haven't called `engine.load()` first, it silently does nothing. Always call `loadAllEvents()` (or `ensureEventsLoaded()`) before the first play. Pattern:

```js
async function onTransportToggle() {
  if (!_eventsLoaded) await loadAllEvents();
  // then play
}
```

`loadAllEvents()` is idempotent via `_eventsLoaded` flag. Do not rely on `engine.isInited` — that only tracks `AudioContext` init, not whether events are queued.

### `useTabModel` falls through on chord-only songs

`buildModel()` originally early-returned `model.value = null` when `melody` was empty. The fix makes it fall through when `sections` has measures:

```js
// Correct guard — do not revert to the simpler `if (!mel) return`
const hasSections = (sections.value || []).some(s => (s.measures || []).length > 0);
if ((!mel || !mel.length) && !hasSections) { model.value = null; return; }
```

Most public songs are chord-only (no MusicXML melody). Reverting this guard makes the entire viewer render nothing for those songs with no error.

### `sbn_progression_occurrences` has no `section_id` column

`ProgressionRef.sectionId` is always `null`. The DB table does not track which section a progression occurrence belongs to — it was deferred and never added. EduPanel handles this by showing all song progressions when all `sectionId` values are null. Do not add section-filtering logic against this field without first adding and backfilling the column.

### Read-only guards must be in JS, not template ternaries

```vue
<!-- WRONG — Vue 3 evaluates the ternary and passes null as the listener value -->
<div @contextmenu="readOnly ? null : onContextMenu">

<!-- CORRECT -->
<div @contextmenu="onContextMenu">
// inside onContextMenu:
if (props.readOnly) return;
```

This was a real regression during Phase 9 Step 1. The template ternary compiles to a listener that is always attached (just pointing at `null`), so the browser's default context menu fires instead of being suppressed.

### `TabMeasure` `bars-per-row` must be `row._intendedCount`, not `row.length`

`TabMeasure` computes `baseWidth` as `(LAYOUT.measureWidth * 4) / barsPerRow`. Passing `row.length` on a partial last row (e.g. 3 bars when the song ends) produces oversized SVGs — the last row's tab numbers and note positions render larger than every other row. Always pass `row._intendedCount` (the full intended row width, set by `tabMeasureRows()`).

### Every `inject()` in shared chord-grid components needs a `null` default

`ChordGridView`, `ChordSection`, `ChordMeasure`, and `ChordCard` all use `inject()` for editor services (`chordPicker`, `voicingPicker`, `chordClipboard`, etc.). The viewer does not provide these. Without a default, `inject()` returns `undefined` and any `?.` call silently no-ops while any direct call throws.

Rule: whenever you add a new `inject()` to a component that is also used in the viewer, add `inject('key', null)` and guard usage with `?.`. Audit the full inject list in §6.1 "Not provided" before adding new injects to shared components.

### New Inertia props must be forwarded by `Pages/Library/Songs/Viewer.vue`, not just the controller

`Viewer.vue` is a thin Inertia page wrapper: it declares its own `defineProps` and must **explicitly bind each one** in its template to `<LeadsheetViewer>`. A prop returned by `SongLibraryController::viewer()` reaching the Inertia payload does **not** automatically reach `LeadsheetViewer.vue` — if `Viewer.vue` itself doesn't declare + bind it, it silently vanishes with no error, no warning, nothing rendered. This bit `skillNodes` / `relatedTheory` / `chordNumerals` in the same session they were added (2026-07-04): the controller and `LeadsheetViewer.vue`'s own prop list were both updated correctly, and it still "did nothing" until `Viewer.vue` was found to be the missing link. Whenever threading a new prop through this stack, update three places: controller `render()` payload → `Viewer.vue` `defineProps` + template binding → `LeadsheetViewer.vue` `defineProps`.

---

## 15. Open items

- **Density rework (compact mode):** `sbn-ve-measure-content` `min-height` in compact mode still keeps measures tall. Real fix: shrink min-height, ignore `lineBreaks`, tighten measure padding. Deferred.
- **Cinema video controls:** YouTube title overlay fades naturally but cannot be suppressed via API.
- **Fret-string consolidation:** ✅ DONE (2026-07-02) — `ChordFretString.php` + `fretString.ts` are the shared authorities; five sites routed through them. See §10 for the two barre-aware helpers deliberately left as a follow-up.
- **Tab reflow on resize:** tab view uses static row layout; does not reflow on viewport resize. Acceptable; revisit if flagged.
- **`edu_topics` DB table:** `EduContentService` is config-backed. DB migration deferred.
- **Cinema fallback clock repeat-awareness:** ✅ DONE (2026-07-02) — the silent (no-video) fallback now counts in play-position space and derives the highlighted gi via `giAtPosition(expandedSequence.value, pos)`, looping/ending at `expandedSequence.length` instead of `totalBars`. `onSeekBar`'s no-video branch syncs `currentPlayPosition` via `firstPositionForGi` so play resumes from the seeked bar. Repeated bars now re-light on each pass.
- **Cinema-side bar-click on repeated bars always lands on pass 1:** `measureIndexToVideoTime(gi)` uses `firstPositionForGi`. Match the tab editor's "click a sync editor row to seek a specific pass" idea (a Cinema popover or right-click menu) once badge popovers ship there.
- **Cinema fretboard view:** `StageSectionsGrid` view toggle is `'chords' | 'tab'`; fretboard (`'fretboard'`) is the planned third option. Model: `ChordProgressionViewer`'s embedded fretboard SVG as a standalone component fed from `chordCards` + active section measures.

# Phase 9 — Leadsheet Classic Viewer (Implementation Plan)

**Status:** In progress — Steps 0–8 shipped (viewer renders, audio works, EduPanel shows full chord card with quality blurb, layout matches library style, transport bar polished and container-sticky, cinema toggle placeholder present). Step 9 deferred (hover color not working). Steps 10–11 outstanding.
**Prerequisite phase:** Phase 7 (DONE — Songs Show teaser exists at `/library/songs/{slug}`)
**Successor phase:** Phase 10 (Cinema view) — design TBD, do not block on it
**Owner phase doc:** This document supersedes the Phase 9 section in `docs/Frontend-Migration-Plan.md` for the duration of the build. Update the migration plan's Phase 9 entry to "DONE" when this work ships.

---

## 0. As-built status (Steps 0–4)

This section captures what *actually shipped* during Steps 0–4 and the technical decisions that emerged during implementation. The original step-by-step plan (§5 below) remains the source of truth for upcoming steps; this section is the diff between plan and reality.

### 0.1 Files that exist now
- `app/Http/Controllers/Library/SongLibraryController.php` — `viewer()` method added (alongside existing `show()`)
- `routes/web.php` — `library.songs.viewer` route at `/library/songs/{leadsheet:slug}/viewer`
- `resources/js/Pages/Library/Songs/Viewer.vue` — thin Inertia page wrapper inside `PublicLayout`
- `resources/js/Components/Leadsheet/LeadsheetViewer.vue` — embeddable viewer component
- `resources/js/Components/Leadsheet/EduPanel.vue` — sidebar with current-chord block + section progressions
- `resources/js/types/leadsheet.ts` — `LeadsheetJson`, `LeadsheetSection`, `LeadsheetMeasure`, `ChordVoicing`, `VideoSync`, `ProgressionRef`
- `public/css/sbn-design-system.css` — chord-grid + tab classes extracted from `leadsheets.css`; density rules (`.is-compact` / `.is-full`) on `.sbn-ve-chord-diagram` with 200ms `opacity` + `max-height` transition; `.sbn-ve-measure-num { display: none; }` (was lost during extraction; restored)

### 0.2 Read-only viewer mode (Step 1) — final shape
`readOnly` prop added to `ChordGridView`, `ChordSection`, `ChordMeasure`. Behaviors when `readOnly === true`:
- `ChordGridView`: skips `<ChordPicker>` and `<ChordContextMenu>` rendering. The grid-click handler still clears selection but does nothing else.
- `ChordSection`: collapse button, name input, add/delete section buttons, row-resize controls all hidden. Section name renders as `<span>` not `<input>`.
- `ChordMeasure`: same context-menu signature as before; the `readOnly` short-circuit lives in the `onCardContextMenu` handler — **not** in the template (`@contextmenu="ternary"` returns the function instead of invoking it; this was a Step 1 regression; resolved by gating in JS).

### 0.3 Inject defaults (R4) — final shape
All editor-only injects in `ChordCard`, `ChordGridView`, `ChordSection`, `ChordMeasure` now have explicit `null` defaults (e.g. `inject('chordPicker', null)`). Use sites already gate on falsy values via `?.`. The viewer deliberately does **not** provide: `chordPicker`, `voicingPicker`, `chordClipboard`, `chordGridOps`, `renameSection`, `addMeasureToSection`, `deleteSection`, `sectionCount`, `rowShrink`, `rowGrow`, `rowSplit`. **Pattern for future phases:** if a child component starts using an inject, add a `null` default at the inject site so viewer mode keeps working.

### 0.4 Density toggle (Step 2) — current behavior
- `density` prop on `ChordMeasure` (default `'full'`); class `.is-compact` / `.is-full` applied to the measure root
- CSS hides `.sbn-ve-chord-diagram` via `opacity: 0; max-height: 0` under `.is-compact`; `max-height: 500px` (not 100px) under `.is-full` to avoid clipping taller diagrams
- `transition: opacity 200ms ease, max-height 200ms ease` on `.sbn-ve-chord-diagram` for smooth swap
- `ChordSection` bumps `barsPerRow` from 4 → 6 in compact mode, **but only in the no-`lineBreaks` fallback path**. Real songs with `lineBreaks` use the same row layout in both modes — diagrams just disappear. **This is a known limitation; Step 2b will rework compact mode to ignore `lineBreaks` and tighten measure sizing for real density.**

### 0.5 `useTabModel` patch — chord-only build path
`useTabModel.buildModel()` previously early-returned on empty melody, leaving `model.value = null`. The viewer needs to render chord-only leadsheets (most public songs have no MusicXML melody), so the early return now falls through if `sections.value` defines any measures. The existing extension loop + chord-extraction passes populate measures from sections.

```js
// Was:
if (!mel || !mel.length) { model.value = null; return; }
// Is:
const hasSections = (sections.value || []).some(s => (s.measures || []).length > 0);
if ((!mel || !mel.length) && !hasSections) { model.value = null; return; }
```

**Risk for admin:** none observed. Admin leadsheets always carry melody data; the new path only activates for chord-only inputs.

### 0.6 `LeadsheetViewer` audio wiring — required pieces
The composables alone don't produce sound — `useChordAudio.play()` calls `engine.play()` with no events queued. Audio works because the viewer replicates `TabEditor.loadAllEvents()`:

```js
import { getAudioEngine } from '@/audio/engine/AudioEngine.js';
import { chordVoicingsToEvents } from '@/audio/adapters/chordVoicingsToEvents.js';

const engine = getAudioEngine();   // singleton (R1 confirmed)
let _eventsLoaded = false;

async function ensureEventsLoaded() {
  if (!model.value || _eventsLoaded) return;
  await engine.init({ bpm: model.value.tempo ?? 120 });
  engine.load(chordVoicingsToEvents(model.value, { startBeat: 0 }));
  engine.setTempo(model.value.tempo ?? 120);
  _eventsLoaded = true;
}
```

`ensureEventsLoaded()` is called before `chordPlay()` in `onTransportToggle` and `seekToMeasure`. **For Phase 9b** (tab toggle): also call `tabModelToEvents` and merge into the same `engine.load()` call — the engine accepts a single combined array.

### 0.7 `LeadsheetViewer` provides — final list

| Provided key | Purpose | Source |
|--------------|---------|--------|
| `model` | The `useTabModel` ref | `tabModel.model` |
| `gridSelection` | Selection state for EduPanel current-chord | `useGridSelection(model)` |
| `beatsPerMeasureRef` | Chord-card window math | computed from `model.ticksPerMeasure / 480` |
| `playingMeasureIndex` | Active-measure highlight during playback | `watch(transportBeat)` derives `Math.floor(beat / bpm)` |
| `transportBeat` | Sub-measure beat highlight | `computed(() => currentBeat.value ?? 0)` |
| `seekToMeasure` | Click-to-play from a chord card | local function: `seek(gi * bpm) + play()` |
| `globalIndexOf` | `(si, mi) → gi` lookup | walks `model.value.sections` |
| `readOnly` | Suppresses editing UI in descendants | always `true` |

The viewer reads `props.leadsheet.tempo` and assigns it to `model.value.tempo` after `buildModel()` since `useTabModel` does not store tempo on the model.

### 0.8 Inertia data contract — final controller payload

`SongLibraryController::viewer()` returns:

```php
return Inertia::render('Library/Songs/Viewer', [
    'leadsheet' => [
        'id'            => $leadsheet->id,
        'slug'          => $leadsheet->slug,
        'title'         => $leadsheet->title,
        'composer'      => $leadsheet->composer,
        'songKey'       => $leadsheet->song_key,
        'tempo'         => $leadsheet->tempo,
        'timeSignature' => $leadsheet->time_signature,
        'rhythm'        => $leadsheet->rhythm,
        'jsonData'      => $leadsheet->parsed_data,    // accessor-decoded array
        'harmonyNotes'  => $leadsheet->harmony_notes,
        'formNotes'     => $leadsheet->form_notes,
        'voicingNotes'  => $leadsheet->voicing_notes,
    ],
    'progressions' => $progressions,                   // ProgressionRef[]
]);
```

**Note:** the doc originally said `jsonData => $leadsheet->json_data`. Implementation uses `parsed_data` (the accessor that returns a decoded array). The model should expose `parsed_data` with type-cast handling; `json_data` is the raw column.

**`ProgressionRef.sectionId` is hard-coded to `null`** because `sbn_progression_occurrences` does not carry section attribution (R3 fallback (c)). Don't filter by section without verifying the column exists first.

### 0.9 EduPanel filter logic — final shape
The naive `progressions.filter(p => p.sectionId === currentSectionId)` would return `[]` for every chord click (since all `sectionId` values are currently `null`). The fix: only apply the filter when **at least one** progression actually carries section attribution; otherwise show all. `EduPanel.vue` exposes `isFilteredBySection` to drive the heading copy ("Progressions in this section" vs. "in this song").

### 0.10 Known issues / deferred work

**Done since first writing this section:**
- ✅ **Step 4b — Layout rework:** library-style content rhythm landed; viewer no longer full-bleed.
- ✅ **Step 5 — Chord card in EduPanel:** controller does DB voicing lookup via `ChordVoicingSearch::searchByName`, frontend renders `<LibraryChordCard>` for the selected chord. See §0.11 for the data-shape adapter.
- ✅ **Step 6 — Edu content service:** `EduContentService` + `config/edu/chord-qualities.php` shipped. Inline blurb logic in `EduPanel.vue` replaced with `qualityByKey` lookup against `eduChordQualities` Inertia prop.
- ✅ **Step 7 — Transport polish:** `TransportBar` has design-system styling, cross-browser range sliders, sticky-in-container placement, and click-to-seek behavior without auto-playing when stopped. See §0.13 and Step 7.

**Still outstanding:**
- **Density rework:** compact mode is currently cosmetic — diagrams hide but `.sbn-ve-measure-content { min-height: 150px }` keeps each measure tall, and `lineBreaks` defeat the row-count change. Real density work: shrink measure min-height in compact mode, ignore `lineBreaks` to use uniform 8-bar rows, tighten chord-card padding/font-size.
- **Step 9 — Density toggle UI:** localStorage persistence implemented, but hover color change deferred (CSS selector not working reliably).
- ✅ **Step 10 — Teaser CTA:** "Open viewer" button added to `Pages/Library/Songs/Show.vue`.
- **Step 11 — Final styling + mobile pass:** not yet done.
- **Tab panel (Phase 9b):** unchanged from plan — not in current scope.

### 0.11 Voicing-key matching — bare-name fallback (Step 5 fixup)

`model.value.chordVoicings` uses **two key shapes**, sometimes for the same song:
- `"AMaj7@5.0"` — per-slot voicing override for measure-5, slot-0
- `"AMaj7"`     — song-wide fallback used when no per-slot override exists

The grid component already mirrors this with `cv[fullKey] || cv[bareName]` ([tab-editor/components/ChordCard.vue:122-124](resources/js/tab-editor/components/ChordCard.vue#L122-L124)). The viewer's controller initially skipped any voicing key without `@gi.ci`, which dropped ~65% of voicings on real-world leadsheets (e.g. desafinado: 28 of 43 keys are bare-name).

**Fix in two places:**
1. `SongLibraryController::viewer()` builds `chordCards` entries for both key shapes — bare names get stored under `chordCards["AMaj7"]`, per-slot overrides stay under `chordCards["AMaj7@5.0"]`.
2. `EduPanel.vue` exposes a `_lookupWithFallback(map, key)` helper that tries the per-slot key first, then strips `@gi.ci` and tries the bare name. Used for both `chordCards` and `qualityByKey` lookups.

**Pattern to remember:** any future map keyed by leadsheet voicing keys must accept both shapes, or call `_lookupWithFallback`.

### 0.12 Tech debt: scattered fret-string ↔ diagram_data conversion

The leadsheet stores chord voicings as 6-char fret strings (`"x32000"`) while the chord library DB stores them as structured `{positions, open, muted, barres}` objects. Step 5 introduced a third location for converting between these representations (`SongLibraryController::fretStringToDiagramData` + `diagramDataMatches`).

**Current locations of the same concept:**
- `SongLibraryController::fretStringToDiagramData()` (PHP, fret-string → diagram_data)
- `Components/Library/ChordDiagram.vue::diagramDataToFretString()` (TS, diagram_data → fret-string)
- `audio/adapters/chordVoicingsToEvents.js::parseFretChar()` (JS, per-character parser)
- Various spots inside `ChordShapeCalculator` and `ChordVoicingSearch` (PHP, internal positional math)

**Cleanup plan (low priority — not blocking Phase 9 close):**
1. New `app/Services/ChordFretString.php` with `toFretString(array $diagramData): string` and `fromFretString(string $frets): array`
2. `diagramDataMatches()` becomes a string `===` comparison after canonicalization through `toFretString`
3. `synthesizeMinimalCard()` calls `fromFretString` instead of inlining
4. JS side: a single source `resources/js/utils/fretString.ts` with both directions

Estimated ~100 lines deleted, single canonical encoder. Worth doing during the next chord-rendering touch — Phase 9b (tab panel toggle), Phase 10 (cinema view), or whenever a chord-encoding bug surfaces.

### 0.13 Transport bar — as-built specs (Step 7)

Step 7 is complete. The viewer reuses `resources/js/tab-editor/components/TransportBar.vue`; styling lives in `public/css/sbn-design-system.css` and viewer placement lives in `resources/js/Components/Leadsheet/LeadsheetViewer.vue`.

**Transport component behavior:**
- Play/pause is driven by `onTransportToggle()` in `LeadsheetViewer.vue`.
- Seeking through the slider calls `onTransportSeek(beat)`.
- Clicking a chord card calls `seekToMeasure(gi)`:
  - When transport is already playing, it seeks and resumes playback.
  - When transport is stopped, it seeks only; it does **not** auto-play.
- Space key toggles playback globally from the viewer, regardless of focus.
- The transport bar label displays measure number only, not `bar:beat`.

**Placement model:**
- The transport bar is rendered inside `.sbn-leadsheet-main`, not as a viewport-fixed sibling.
- `.sbn-leadsheet-main` is a flex column and stays tight to content; no artificial `min-height`.
- `.sbn-leadsheet-transport` uses `position: sticky; bottom: 20px; margin-top: auto`.
- Short leadsheets: the transport sits at the bottom of the leadsheet container.
- Long leadsheets: the transport sticks to the bottom of the viewport while the user scrolls through the container.
- Mobile uses `bottom: 12px` and removes the old fixed-width/fixed-position assumptions.

**Visual styling:**
- `.sbn-transport-bar` is a pill-shaped surface with border, soft shadow, responsive padding, and design-system colors.
- `.sbn-transport-play` is the visual focal point: large circular accent button with hover/active scaling and alternate `is-playing` styling.
- `.sbn-transport-stop` uses secondary button styling and participates in the same 44px mobile hit-target rules.
- `.sbn-transport-time` and `.sbn-transport-bpm` use monospace pill styling.
- Seek, tempo, and mixer sliders share cross-browser range styling:
  - WebKit thumb via `::-webkit-slider-thumb`
  - Firefox thumb via `::-moz-range-thumb`
  - Shared slim track sizing and accent thumb color
  - Visible focus/hover treatment
- Mixer controls remain compact and only render when `showMixer` is enabled.

**Important CSS hooks:**
- Global transport styles: `public/css/sbn-design-system.css`, section `11. TRANSPORT BAR (.sbn-transport-*)`
- Viewer wrapper styles: `LeadsheetViewer.vue` scoped CSS:
  - `.sbn-leadsheet-main`
  - `.sbn-leadsheet-transport`
  - `.sbn-leadsheet-transport.is-visible`
  - `.sbn-leadsheet-transport.is-hidden`

**Deferred / known unrelated issue:**
- Chord-card click scaling was explored but deferred. Playback scaling on `.sbn-ve-chord.is-active` remains experimental CSS and should be revisited separately from transport polish.

---

## 1. Goal

Build the public-facing leadsheet viewer ("classic view"): the page that renders when a user opens a song from the song library. The viewer reuses the Vue components originally built for the admin tab editor, wrapped in a clean two-column public layout with a contextual education panel on the right.

The viewer must work in **two contexts**:
1. **Standalone page** — `/library/songs/{slug}/viewer`, full `PublicLayout`.
2. **Embeddable component** — drop into a course lesson (Phase 11) without layout assumptions.

This is achieved by separating the **page wrapper** (`Pages/Library/Songs/Viewer.vue`) from the **embeddable component** (`Components/Leadsheet/LeadsheetViewer.vue`).

---

## 2. Scope

### In scope
- Standalone viewer page at `/library/songs/{slug}/viewer`
- Embeddable `LeadsheetViewer.vue` component
- Two-column layout: chord grid (main) + edu panel (sidebar)
- Reused chord grid components from the admin tab editor in **read-only viewer mode**
- Reused transport bar with **design-system polish pass**
- Education panel with: current chord info (linked to chord library) + section progressions (linked to progression library)
- Chord-name-only ↔ chord-name-plus-diagram density toggle
- Cinema view toggle UI (placeholder — disabled, ready for Phase 10)
- Teaser page promotion: add prominent "Open viewer" button to existing `Pages/Library/Songs/Show.vue`
- CSS extraction prerequisite (move tab SVG classes from admin-only CSS to design system)

### Out of scope (deferred)
- Tab panel toggle (chord ↔ tab view switcher) — Phase 9b after classic ships
- Video player integration — partial: viewer transport must remain master clock when video is later added; full video sync work is Phase 10 / Phase D2
- Real `edu_topics` DB table — Phase 9 ships with a static config-file stub; DB-backed table is its own mini-phase
- Cinema view itself — Phase 10
- Course-lesson embed wiring — Phase 11 (the `LeadsheetViewer` component must support it, but no course-side code in this phase)
- Real Stripe / paid-content gating — Phase 12

### Explicit non-goals
- **Do not** consult the legacy WordPress code under `sbn-course-player(legacywp)/` for layout. The data shape there does not match current `json_data`. Frontend design is new.
- **Do not** revive any Alpine.js code path. The Vue `ChordGridView` family is the sole renderer.
- **Do not** introduce Pinia or any global store. Component-local refs + `provide/inject` (matching the existing tab-editor pattern) only.
- **Do not** fork `ChordGridView` / `ChordSection` / `ChordMeasure` for viewer use. Add a `readOnly` prop instead.

---

## 3. Architecture

```
┌────────────────────────────────────────────────────────────────┐
│ Pages/Library/Songs/Viewer.vue          (standalone page)      │
│  ├─ <PublicLayout>                                             │
│  │   └─ <LeadsheetViewer :leadsheet="..." />                   │
│                                                                │
│ Pages/Library/Songs/Show.vue            (existing teaser)      │
│  └─ adds "Open viewer" link → /library/songs/{slug}/viewer     │
│                                                                │
│ Components/Leadsheet/LeadsheetViewer.vue                       │
│  ├─ <ChordGridView :read-only="true" :density="density">       │
│  ├─ <Components/Leadsheet/EduPanel>                            │
│  └─ <TransportBar>                                             │
│                                                                │
│ Components/Leadsheet/EduPanel.vue                              │
│  ├─ Current-chord block (chord name → /library/chords/{slug})  │
│  └─ Section-progressions block (→ /library/progressions/{slug})│
│                                                                │
│ Composables (reused as-is from tab-editor):                    │
│  useTabModel, useReflow, useCursor, useChordAudio,             │
│  useAudioEngine, useChordGridOps (read-only mode)              │
└────────────────────────────────────────────────────────────────┘
```

**Component reuse table:**

| Component | Source | Phase 9 treatment |
|-----------|--------|-------------------|
| `ChordGridView.vue` | tab-editor | Add `readOnly` prop → suppresses `ChordPicker`, `ChordContextMenu`, drag handles, voicing picker injection. Keeps reflow + click-to-play. |
| `ChordSection.vue` | tab-editor | Add `readOnly` prop → suppresses add/delete section buttons, rename input becomes static label. |
| `ChordMeasure.vue` | tab-editor | Add `readOnly` prop + `density` prop (`'compact' \| 'full'`). Hides chord-context-menu trigger. |
| `TransportBar.vue` | tab-editor | No new props. Polish pass (design-system styling) only. |
| `TabMeasure.vue`, `TabCursor.vue` | tab-editor | **Not used in Phase 9.** Wired in Phase 9b. |
| `ChordPicker`, `ChordContextMenu`, `VoicingPicker`, `VideoSyncEditor` | tab-editor | **Not used.** Read-only mode skips their inject points. |

**Composables reuse:**

| Composable | Phase 9 use |
|-----------|-------------|
| `useTabModel` | Source of truth for sections/measures/chords. Constructed from leadsheet `json_data`. |
| `useReflow` | Row-wrapping + responsive measure layout. Reused as-is. |
| `useChordGridOps` | **Read-only:** the viewer skips the mutation entry points (no add/move/delete). |
| `useChordAudio` | Click-to-play per chord card. Reused as-is. |
| `useAudioEngine` | Singleton check required (see §6 risks). |
| `useCursor`, `useSelection`, `useGridSelection` | Selection still needed — drives "current chord" in EduPanel — but no editing operations. |
| `useChordPickerStore`, `useVoicingPickerStore`, `useChordClipboard`, `useUndo`, `useNoteInput`, `useSidebarStore`, `useAlpineBridge` | **Not provided** in viewer mode. Read-only branches must short-circuit before reaching these injects. |

---

## 4. Routing & data

### Route

Add to `routes/web.php` (public group, alongside the existing `library.songs.show` route):

```php
Route::get('/library/songs/{leadsheet:slug}/viewer', [SongLibraryController::class, 'viewer'])
    ->name('library.songs.viewer');
```

### Controller

Add `viewer()` method to `app/Http/Controllers/Library/SongLibraryController.php`:

```php
public function viewer(Leadsheet $leadsheet)
{
    $progressions = ChordProgression::query()
        ->join('sbn_progression_occurrences as o', 'sbn_chord_progressions.id', '=', 'o.progression_id')
        ->where('o.leadsheet_id', $leadsheet->id)
        ->select(
            'sbn_chord_progressions.id',
            'sbn_chord_progressions.slug',
            'sbn_chord_progressions.name',
            'sbn_chord_progressions.category',
            'sbn_chord_progressions.numerals',
            'o.section_id'           // for EduPanel section filtering
        )
        ->distinct()
        ->orderBy('sbn_chord_progressions.name')
        ->get()
        ->map(fn ($p) => [
            'id'              => $p->id,
            'slug'            => $p->slug,
            'name'            => $p->name,
            'category'        => $p->category,
            'numeralsDisplay' => $p->numerals_display,
            'sectionId'       => $p->section_id,
        ]);

    return Inertia::render('Library/Songs/Viewer', [
        'leadsheet' => [
            'id'            => $leadsheet->id,
            'slug'          => $leadsheet->slug,
            'title'         => $leadsheet->title,
            'composer'      => $leadsheet->composer,
            'songKey'       => $leadsheet->song_key,
            'tempo'         => $leadsheet->tempo,
            'timeSignature' => $leadsheet->time_signature,
            'rhythm'        => $leadsheet->rhythm,
            'styleSlug'     => $this->rhythmToStyleSlug($leadsheet->rhythm),
            'jsonData'      => $leadsheet->json_data,    // full grid + voicings
            'harmonyNotes'  => $leadsheet->harmony_notes,
            'formNotes'     => $leadsheet->form_notes,
            'voicingNotes'  => $leadsheet->voicing_notes,
        ],
        'progressions' => $progressions,
    ]);
}
```

Note on `progression_occurrences.section_id`: confirm this column exists. If section attribution isn't tracked at occurrence level, fall back to passing all progressions and filtering client-side by chord-name match (best effort) — see §6 risk register.

### Existing teaser page promotion

In `resources/js/Pages/Library/Songs/Show.vue` (currently the Phase 7 teaser), add a prominent button:

```vue
<Link :href="route('library.songs.viewer', song.slug)" class="sbn-btn sbn-btn-primary sbn-btn-lg">
  Open in viewer →
</Link>
```

Place it near the top of the teaser, above the chord-name strip. Do not remove the existing teaser content.

---

## 5. Implementation steps

Order is sequential. Each step ends with a definition-of-done checkpoint.

### Step 0 — Prerequisite: CSS extraction ✅ DONE

Move tab SVG / chord-grid utility classes that the viewer needs from `public/css/leadsheets.css` (admin-only) into `public/css/sbn-design-system.css`.

**Classes to extract (audit `leadsheets.css` for the actual list):**
- `.sbn-tab-note-text`, `.sbn-tab-metronome-col`, `.sbn-beat-active`
- Any `.sbn-ve-grid`, `.sbn-ve-row`, `.sbn-ve-section*`, `.sbn-ve-measure*` styles that the chord grid reads
- `.sbn-transport-*` (currently inline in admin pages — move to design system)

**Done when:**
- [ ] Classes used by `ChordGridView` / `TransportBar` resolve via `sbn-design-system.css` alone
- [ ] Loading the admin leadsheet edit page still renders correctly (no double-import regressions)
- [ ] `git grep` for the moved selectors finds them only in `sbn-design-system.css`

### Step 1 — Read-only viewer mode on chord grid components ✅ DONE

Add a `readOnly` boolean prop (default `false`) to:
- `ChordGridView.vue`
- `ChordSection.vue`
- `ChordMeasure.vue`

**Behavior when `readOnly === true`:**
- `ChordGridView`: do not render `<ChordPicker>` or `<ChordContextMenu>`. Bare-grid clicks still clear selection.
- `ChordSection`: section-name input becomes a `<span>`. Add/delete section buttons not rendered. Bar-count badge stays.
- `ChordMeasure`: no right-click context menu binding. No drag handles. Click on chord card emits selection + click-to-play (preserved).

`useChordGridOps` and friends remain injected — keep call sites that *read* state (selection, current chord). Mutation entry points must become inert under `readOnly`. Easiest pattern: `provide('readOnly', readOnly)` from `LeadsheetViewer` and short-circuit at the top of each mutation function.

**Done when:**
- [ ] Admin tab editor still works identically (props default to mutating mode)
- [ ] A throwaway test harness rendering `<ChordGridView read-only />` shows the grid with no pickers, no menus, no drag affordances

### Step 2 — Density prop on `ChordMeasure` ✅ DONE (toggle wires through; full density rework deferred to Step 2b — see §0.10)

Add `density: { type: String, default: 'full' }` to `ChordMeasure.vue`. Two values: `'full'` (chord name + diagram) and `'compact'` (chord name only).

CSS approach: a single class on the measure root (`is-compact` / `is-full`). Diagram element gets `display: none` under `.is-compact`. Use a CSS `transition` on `max-height` + `opacity` for an elegant swap.

**Done when:**
- [ ] Toggling `density` between values animates smoothly without layout thrash
- [ ] Reflow is recalculated on density change (fewer pixels per measure → more measures per row)

### Step 3 — `LeadsheetViewer.vue` component ✅ DONE (see §0.6, §0.7 for as-built shape)

New file: `resources/js/Components/Leadsheet/LeadsheetViewer.vue`

**Props:**
```ts
interface Props {
  leadsheet: {
    id: number
    slug: string
    title: string
    composer: string | null
    songKey: string | null
    tempo: number | null
    timeSignature: string | null
    rhythm: string | null
    styleSlug: string
    jsonData: LeadsheetJson      // full json_data shape
    harmonyNotes: string | null
    formNotes: string | null
    voicingNotes: string | null
  }
  progressions: ProgressionRef[]
  embedded?: boolean             // when true, skip top header band
}
```

**Layout (two columns):**
```
┌────────────────────────────────┬─────────────────────────┐
│ Header (title, composer,       │                         │
│ key, tempo, density toggle,    │                         │
│ cinema-view link [disabled])   │                         │
├────────────────────────────────┤    EduPanel             │
│                                │                         │
│       ChordGridView            │   • Current chord       │
│       (read-only)              │   • Section progs       │
│                                │                         │
│                                │                         │
├────────────────────────────────┴─────────────────────────┤
│                  TransportBar (sticky)                    │
└──────────────────────────────────────────────────────────┘
```

Mobile (< 768px): stack to single column. EduPanel collapses below grid. TransportBar stays sticky-bottom.

**Internal state:**
- `density` ref (`'full' | 'compact'`) — drives `ChordMeasure` prop
- `tabModel` from `useTabModel(leadsheet.jsonData)`
- `currentChordRef` — derived from `useGridSelection`; passed to EduPanel
- `currentSectionId` — derived likewise; passed to EduPanel for progression filtering
- Audio/transport state from existing composables (`useChordAudio`, `useAudioEngine`)

**Provides** (matching tab-editor pattern):
- `chordGridOps`, `gridSelection`, `chordAudio`, `audioEngine`
- `readOnly` (always `true`)
- **Does not provide:** `chordPicker`, `voicingPicker`, `chordClipboard`, `undo` — read-only branches in the grid components must not require them

**Done when:**
- [ ] Renders existing songs correctly (sections, measures, chord names, voicings)
- [ ] Click-to-play works on chord cards
- [ ] Transport bar play/pause/seek/tempo all functional
- [ ] Selection updates `currentChord` and `currentSection`
- [ ] Component works when dropped into any parent without `PublicLayout`

### Step 4 — `Pages/Library/Songs/Viewer.vue` (standalone page) ✅ DONE

Thin wrapper page. Renders `<LeadsheetViewer>` inside `<PublicLayout>`.

**Responsibilities:**
- `defineOptions({ layout: PublicLayout })`
- Inertia `<Head>` with title `${song.title} - ${composer ?? 'SBN'}`
- OG/meta tags using `description` field
- Renders `<LeadsheetViewer :leadsheet="leadsheet" :progressions="progressions" />`
- No business logic — purely a layout wrapper

**Done when:**
- [ ] Page loads at `/library/songs/{slug}/viewer`
- [ ] SEO meta visible in page source
- [ ] Persistent app shell (mega menu, AudioPlayerSlot from Phase 1) survives navigation in/out of the viewer

### Step 4b — Layout rework ✅ DONE (density rework deferred — see §0.10)

**COMPLETED** - Layout and density have been fully reworked to match the public site design patterns.

**Layout Changes:**
- **Max-width container**: Changed from full-bleed to `max-width: 1400px` with `padding: 40px 20px 80px` (matches song/rhythm/chord libraries)
- **Normal page scroll**: Removed fixed height constraints, footer now reachable
- **Fixed transport bar**: Positioned at bottom of viewport with `position: fixed` and proper z-index
- **Library structure**: Two-column layout with 280px sticky sidebar and main content area (24px gap)
- **Responsive behavior**: 1024px breakpoint moves sidebar to top, 768px mobile adjustments
- **Frame styling**: Thin curved borders (`1px solid var(--clr-border)`) with no shadows
- **Header restructure**: Removed title/meta, added "Back to Library" link, kept density controls

**Density Changes:**
- **Same row structure**: Bars per row now consistent regardless of density (no more 6-bar uniform rows)
- **Compact line height**: Reduced from 150px to 80px in compact mode while maintaining same grid structure
- **Chord repositioning**: Names move up and center when diagrams disappear
- **Enhanced animations**: 
  - Staged transitions: diagrams fade first (150ms), then height changes (300ms)
  - Reverse for fade-in: height expands first, then diagrams fade in
  - Scale transforms and proper z-index stacking to prevent artifacts
- **Consistent sizing**: Chord names maintain 20px size in both modes

**Additional Polish:**
- **White background**: Changed from design system variable to white
- **EduPanel styling**: Matching thin curved border frame, no internal lines
- **Hover frame**: Adjusted positioning for new layout (known issue: slight offset persists)

**Technical Implementation:**
- `LeadsheetViewer.vue`: Complete layout restructure with library-matching container
- `ChordSection.vue`: Simplified rows computation to always respect lineBreaks
- `sbn-design-system.css`: Comprehensive density animation system with staged timing

**Result:** Viewer now provides consistent visual rhythm with other library pages while maintaining smooth density transitions and professional animations.

---

### Step 5 — `EduPanel.vue` component (REVISED — full chord card) ✅ DONE (with bare-name fallback fix; see §0.11)

`resources/js/Components/Leadsheet/EduPanel.vue` already exists from Steps 3–4 (text-only blurb). This step **replaces the text-only chord block with a full chord card** rendered via the existing `Components/Library/ChordCard.vue` (the same card the public chord library uses), and finalizes the scope of the panel.

**Why a chord card, not just text:** the user is studying a song. Seeing the *exact voicing* the player is rendering — fretboard diagram, finger numbers, fret position — is more useful than reading a paragraph. The blurb supplements the card; it doesn't replace it.

**Data shape mismatch — the core implementation challenge.**

The leadsheet stores per-slot voicings as a flat 6-char fret string:

```js
// model.value.chordVoicings keyed as "ChordName@globalMeasureIndex.chordSlot"
{
  "Cmaj7@0.0": { frets: "x32000", fingers: "x32010", position: 0 }
}
```

The library `ChordCard` requires a full `ChordDiagramData` object (see [ChordDiagram.vue](resources/js/Components/Library/ChordDiagram.vue) line 4):

```ts
interface ChordDiagramData {
  id: number;
  slug: string;
  name: string;
  root_note: string;
  quality: string;
  quality_label: string;
  extensions?: string | null;
  voicing_category: string;
  category_label: string;
  // ...
  diagram_data: {
    positions: Array<{ string: number; fret: number; finger?: number }>;
    barres: Array<{ fret: number; from: number; to: number }>;
    muted: number[];
    open: number[];
  };
  popularity?: number | null;
  difficulty?: number | null;
  // ...
}
```

We need an **adapter** from the leadsheet's flat fret string to the rich `ChordDiagramData` the card expects. Three approaches considered:

- **(A) Client-side fret-string parser.** Convert `"x32000"` → `{ open: [3,5], muted: [6], positions: [...] }` in JS. Pros: no controller change. Cons: missing all the metadata (root_note, quality, popularity, difficulty, finger numbers in many cases).
- **(B) New `EduChordCard.vue`.** Slimmed-down card that accepts `{ frets, name }` and renders just the diagram + name + audio. Pros: minimal data. Cons: visual divergence from the library card; user wanted the "full chord card."
- **(C) Server-side enrichment.** Controller looks up the leadsheet's voicings against the chord DB via `ChordVoicingSearch::searchByName()`, picks the best match by fret-string equivalence, and ships full `ChordDiagramData` as a parallel map. Pros: visual parity, real metadata, audio works as in the library. Cons: more controller code; ambiguity when no DB match exists.

**Decision: hybrid — (C) preferred, (A) as fallback.** The controller does the DB lookup for the common case (≈90% of voicings have a matching DB shape). When no DB match is found, the controller emits a synthetic minimal `ChordDiagramData` with just `name` + parsed `diagram_data` from the fret string + `quality_label` from the chord-name parser. The card still renders; metadata fields (popularity, difficulty) are simply absent.

#### Implementation

**5.1 Server-side: enriched chord-card map**

Extend `SongLibraryController::viewer()` to build a `chordCards` map keyed by the same `"chordName@gi.ci"` key the leadsheet uses:

```php
public function viewer(Leadsheet $leadsheet, ChordVoicingSearch $search)
{
    // ... existing leadsheet + progressions code ...

    $voicings = $leadsheet->parsed_data['chordVoicings'] ?? [];
    $chordCards = [];

    foreach ($voicings as $key => $voicing) {
        // Key shape: "ChordName@gi.ci"
        if (!preg_match('/^(.+)@\d+\.\d+$/', $key, $m)) continue;
        $chordName = $m[1];

        $matches = $search->searchByName($chordName);
        $best = $this->pickBestVoicing($matches, $voicing['frets'] ?? null);

        if ($best) {
            $chordCards[$key] = $best;  // already-serialized ChordDiagramData
        } else {
            $chordCards[$key] = $this->synthesizeMinimalCard($chordName, $voicing);
        }
    }

    return Inertia::render('Library/Songs/Viewer', [
        'leadsheet'    => [...],
        'progressions' => $progressions,
        'chordCards'   => $chordCards,            // NEW
    ]);
}
```

**Helpers required on the controller (or extracted to a service):**
- `pickBestVoicing(array $matches, ?string $targetFrets): ?array` — prefers exact fret-string match; falls back to first match if none. Returns the serialized `ChordDiagramData` shape.
- `synthesizeMinimalCard(string $chordName, array $voicing): array` — parses the fret string into the `{ positions, barres, muted, open }` shape and returns a stub `ChordDiagramData` (see schema in §0.x). Used when no DB match exists.

**Performance note:** `searchByName` is a DB hit per unique chord name. For a 32-bar song with ~10 unique chords this is fine. If it ever becomes slow, cache results per request keyed by chord name (most leadsheets repeat chords across slots).

**5.2 Frontend: render the card in EduPanel**

Update `EduPanel.vue`:
- Add a `chordCards` prop: `Record<string, ChordDiagramData>` keyed by `"chordName@gi.ci"`
- Add a `selectionKey` prop: `string | null` — the active selection key from the parent (computed in `LeadsheetViewer` from `gridSelection.selection.value` last entry)
- In the current-chord block, when `selectionKey && chordCards[selectionKey]` exists:
  - Render `<LibraryChordCard :chord="chordCards[selectionKey]" :show-root="true" />`
  - Below the card: chord-quality blurb (existing inline logic, to be replaced in Step 6)
  - Below the blurb: "View in chord library →" link

```vue
<script setup>
import LibraryChordCard from '@/Components/Library/ChordCard.vue';
// ... existing imports ...

const props = defineProps({
  currentChord:     { type: String, default: null },
  currentSectionId: { type: String, default: null },
  selectionKey:     { type: String, default: null },        // NEW
  song:             { type: Object, required: true },
  progressions:     { type: Array,  required: true },
  chordCards:       { type: Object, default: () => ({}) }, // NEW
});

const activeCard = computed(() =>
  props.selectionKey ? (props.chordCards[props.selectionKey] ?? null) : null
);
</script>
```

**5.3 LeadsheetViewer plumbing**

`LeadsheetViewer.vue` needs to:
- Accept `chordCards` prop (passed from `Pages/Library/Songs/Viewer.vue`)
- Compute `selectionKey` from `gridSelection.selection.value` last entry: `"${chordName}@${gi}.${ci}"`
- Pass both to `<EduPanel>`

```ts
const selectionKey = computed(() => {
  const sel = gridSelection.selection.value;
  if (!sel.length) return null;
  const last = sel[sel.length - 1];
  const found = _findInModel(last.gi);
  if (!found) return null;
  const name = found.measure.chordNames?.[last.ci];
  if (!name) return null;
  return `${name}@${last.gi}.${last.ci}`;
});
```

**5.4 Audio behavior**

The library `ChordCard` plays the chord on click via the same `AudioEngine` singleton the leadsheet uses. **Confirmed compatible** — both call `engine.init()` (idempotent) and `engine.load()` (replaces queued events). One subtlety: clicking the EduPanel card mid-playback will **replace** the leadsheet's queued events with the single-chord arpeggio. Acceptable behavior — when the user clicks the card, they want to hear the chord; resuming song playback re-loads via `ensureEventsLoaded()`'s idempotent path.

**Edge case:** if the leadsheet is currently playing and the user clicks the EduPanel card, song playback should pause. The `'playStarted'` event listener in the library `ChordCard` (line 84–85) already handles cross-source coordination by clearing `isPlaying`; the leadsheet's transport bar will reflect this via the engine's `'ended'` and `'playStarted'` events. Verify during smoke-test.

**5.5 Card sizing in the sidebar**

The library `ChordCard` is sized for a card grid (~160–180px wide). The EduPanel sidebar is ~320px. Two options:
- **Default size** — use as-is; the card sits centered in the sidebar with whitespace around it. Visually consistent with the library.
- **`mini` prop** — the card already supports `mini` for compact display; not what we want here.

**Decision: default size, centered, with the chord name + diagram filling the natural width.** The card has its own internal padding; let the sidebar provide outer breathing room.

#### Done when:
- [ ] Selecting a chord on the grid renders the matching DB chord card in the sidebar (diagram, name, popularity tier, difficulty stars when present)
- [ ] Clicking the play button on the EduPanel card plays the chord audio
- [ ] Empty selection state shows "Click a chord to learn more"
- [ ] Songs with chords that have no DB voicing match still render a minimal card (synthesized `diagram_data` from the fret string)
- [ ] No JS errors when a leadsheet has zero `chordVoicings` entries (e.g. names-only chord chart) — the card area can show a "no voicing assigned" placeholder
- [ ] Audio interaction between EduPanel card and main transport behaves predictably (clicking card pauses song; pressing transport play resumes from current position)

---

### Step 6 — Edu content service (chord-quality blurbs) ✅ DONE

The EduPanel currently has a 7-branch inline `if/else` chord-quality detector with hardcoded blurb strings. This step extracts that data to a config-file-backed service so:
1. Blurbs become editable without touching component code
2. The eventual `edu_topics` DB table is a one-file swap of the service's data source
3. Other surfaces (chord library Show pages, Top10 pages, course lessons) can reuse the same blurbs

**Note:** the *chord card* itself (diagram, name, audio) lives in Step 5. This step is purely about the **text blurb** that sits below the card.

#### Implementation

**6.1 Config file**

New file: `config/edu/chord-qualities.php`

```php
<?php
// Source of truth for chord-quality educational blurbs displayed in the
// leadsheet viewer EduPanel. Keys match the canonical quality slugs produced
// by ChordVoicingSearch::parseChordName(). When the edu_topics DB table lands,
// EduContentService swaps to an Eloquent lookup; this file becomes the seeder.
return [
    'maj'    => ['title' => 'Major',                    'blurb' => '...'],
    'min'    => ['title' => 'Minor',                    'blurb' => '...'],
    'maj7'   => ['title' => 'Major 7',                  'blurb' => '...'],
    'm7'     => ['title' => 'Minor 7',                  'blurb' => '...'],
    'dom7'   => ['title' => 'Dominant 7',               'blurb' => '...'],
    'm7b5'   => ['title' => 'Half-diminished (m7♭5)',   'blurb' => '...'],
    'dim'    => ['title' => 'Diminished',               'blurb' => '...'],
    'o7'     => ['title' => 'Diminished 7',             'blurb' => '...'],
    'aug'    => ['title' => 'Augmented',                'blurb' => '...'],
    'aug7'   => ['title' => 'Augmented 7',              'blurb' => '...'],
    'mMaj7'  => ['title' => 'Minor-Major 7',            'blurb' => '...'],
    'sus4'   => ['title' => 'Suspended 4',              'blurb' => '...'],
    'sus2'   => ['title' => 'Suspended 2',              'blurb' => '...'],
    'maj6'   => ['title' => 'Major 6',                  'blurb' => '...'],
    'm6'     => ['title' => 'Minor 6',                  'blurb' => '...'],
    'add9'   => ['title' => 'Add 9',                    'blurb' => '...'],
    '7sus4'  => ['title' => '7 sus 4',                  'blurb' => '...'],
    '5'      => ['title' => 'Power chord',              'blurb' => '...'],
    // Extend as content is authored. Unknown qualities fall back to a generic
    // "no info yet" placeholder in the EduPanel.
];
```

**Why these slugs:** matches the canonical output of `ChordVoicingSearch::parseChordName()` (see `app/Services/ChordVoicingSearch.php` line 26 onwards). This means the same parser the chord library and admin voicing picker use also drives the EduPanel — single source of truth for chord-quality identification.

**6.2 Service**

New file: `app/Services/EduContentService.php`

```php
<?php

namespace App\Services;

/**
 * Source of truth for educational text content shown alongside chords,
 * progressions, rhythms, and other study aids.
 *
 * Currently config-file backed. Future: replace the config lookup with an
 * Eloquent query against the edu_topics table. Public method signatures will
 * not change.
 */
class EduContentService
{
    /**
     * Look up a chord quality blurb by canonical quality slug.
     *
     * @param  string  $qualitySlug  e.g. 'maj7', 'm7b5', 'dom7'
     * @return array{title:string,blurb:string}|null
     */
    public function chordQuality(string $qualitySlug): ?array
    {
        return config("edu.chord-qualities.$qualitySlug");
    }

    /**
     * Bundle all chord-quality blurbs in one shot — used when the consumer
     * needs offline lookup over a known set (e.g. an Inertia payload that
     * surfaces blurbs for every chord on the page).
     *
     * @return array<string, array{title:string,blurb:string}>
     */
    public function allChordQualities(): array
    {
        return config('edu.chord-qualities', []);
    }
}
```

**6.3 Controller wiring**

`SongLibraryController::viewer()` injects `eduChordQualities` as a flat Inertia prop:

```php
public function viewer(Leadsheet $leadsheet, ChordVoicingSearch $search, EduContentService $edu)
{
    // ... existing code ...

    return Inertia::render('Library/Songs/Viewer', [
        // ... existing props ...
        'eduChordQualities' => $edu->allChordQualities(),    // NEW
    ]);
}
```

The whole map ships in one go (≈18 entries, ~3KB). Cheaper and simpler than a per-chord lookup; the EduPanel does a client-side hash lookup.

**6.4 EduPanel integration**

`EduPanel.vue`:
- Add `eduChordQualities` prop: `Record<string, { title: string; blurb: string }>`
- Replace the inline 7-branch `if/else` `chordQualityInfo` computed with a parser call + map lookup:
  ```ts
  // Use the same client-side parser the rest of the frontend uses to derive
  // canonical quality slugs. (If we don't have a JS parser yet, write a thin
  // mirror of the PHP one — small surface area.)
  const qualitySlug = computed(() => parseChordQuality(props.currentChord));
  const chordQualityInfo = computed(() =>
    qualitySlug.value ? props.eduChordQualities[qualitySlug.value] ?? null : null
  );
  ```

**Open question — chord-name parser on the frontend:** PHP's `ChordVoicingSearch::parseChordName` is the canonical source. We need a JS equivalent. Two paths:
- **(a)** Port a minimal subset (the `QUALITY_PATTERNS` map) to JS. ~30 lines.
- **(b)** Have the controller pre-compute a `qualityByKey` map alongside `chordCards` (since it already calls `parseChordName` to look up voicings).

**Recommendation: (b).** The controller already does the parse for chord-card lookup; emit `qualityByKey: Record<"chordName@gi.ci", string>` and the EduPanel does a single hash lookup. No JS parser to maintain. Update the Step 5 controller spec to also build this map.

#### Done when:
- [ ] At least 8 chord qualities have written blurbs (recommend all 18 listed above before shipping)
- [ ] EduPanel displays the correct blurb for every chord-quality the leadsheet contains
- [ ] Unknown qualities (rare extensions) show a graceful "no info yet" placeholder, not a crash
- [ ] `EduContentService` is the **only** file that knows where the data lives (controllers and components consume the service interface)
- [ ] Header comment in `EduContentService.php` documents the future DB-backed migration path

### Step 7 — Transport bar polish ✅ DONE

Step 7 shipped as a design-system polish pass plus viewer-specific placement refinement. The shared `TransportBar.vue` component remains reusable; the visual treatment lives in `public/css/sbn-design-system.css`, and the public viewer placement lives in `LeadsheetViewer.vue`.

**As-built behavior:**
- `TransportBar.vue` is still the shared component used by the tab editor and the public leadsheet viewer.
- Viewer playback is controlled by `LeadsheetViewer.vue`:
  - `onTransportToggle()` loads chord events and toggles play/pause.
  - `onTransportSeek()` seeks to an absolute beat.
  - `seekToMeasure()` seeks to the clicked measure and only auto-plays if playback was already running.
- Global spacebar playback toggle is wired in the viewer.
- Transport label now displays the measure number only, not a `bar:beat` label.

**As-built placement:**
- The viewer transport is inside `.sbn-leadsheet-main`.
- `.sbn-leadsheet-main` is a flex column and stays tight to its content.
- `.sbn-leadsheet-transport` uses `position: sticky`, `bottom: 20px`, and `margin-top: auto`.
- This gives the intended dual behavior:
  - Short leadsheets: bar sits at the bottom of the leadsheet container.
  - Long leadsheets: bar sticks near the bottom of the viewport while scrolling.
- Mobile overrides reduce the sticky bottom offset to `12px`.

**As-built styling:**
- `.sbn-transport-bar`
  - Pill-shaped rounded surface.
  - Design-system surface color, border, padding, and soft shadow.
  - Responsive wrapping on narrow widths.
- `.sbn-transport-play`
  - Large circular accent button.
  - Hover/active scaling.
  - `is-playing` state styled as a neutral pause state.
- `.sbn-transport-time` / `.sbn-transport-bpm`
  - Monospace pill treatment.
  - Tabular numeric rhythm for stable labels.
- Range inputs
  - Shared styling for seek, tempo, and mixer sliders.
  - Separate WebKit and Firefox thumb rules.
  - Accent-colored thumb, slim neutral track, hover/focus affordances.
- Mixer controls
  - Compact row layout.
  - Same slider styling as the primary controls.
- Mobile
  - Controls wrap.
  - Primary seek slider takes full width.
  - Touch targets meet the 44px minimum.

**Verification:**
- [x] Transport bar looks intentional and matches the visual language of the rest of the viewer.
- [x] Range sliders styled consistently via WebKit and Firefox-specific rules.
- [x] Play button is the visual focal point of the strip.
- [x] Mobile responsive rules present for ≤ 768px and ≤ 480px.
- [x] Keyboard focus styling retained for range inputs.
- [x] No new design tokens introduced.
- [x] `npm run build` passes.

**Notes / follow-up:**
- Admin tab editor still uses the same transport styles; do a final visual regression pass there before Phase 9 close.
- Chord-card scale selection/playback experiments are not part of Step 7 and are deferred.

### Step 8 — Cinema view toggle placeholder

In `LeadsheetViewer` header, add a view-toggle UI element:

```vue
<div class="sbn-leadsheet-view-toggle">
  <button class="is-active" disabled>Classic</button>
  <button disabled title="Cinema view — coming in Phase 10">Cinema</button>
</div>
```

Both buttons disabled. Keeps the visual affordance present so Phase 10 just removes the disabled state.

**Done when:**
- [x] Toggle UI visible and styled
- [x] Buttons disabled, tooltip explains "coming soon"

### Step 9 — Density toggle UI

In `LeadsheetViewer` header, add a density toggle (icon buttons):

```vue
<div class="sbn-leadsheet-density-toggle">
  <button :class="{ active: density === 'full' }" @click="density = 'full'" title="Show diagrams">▦</button>
  <button :class="{ active: density === 'compact' }" @click="density = 'compact'" title="Names only">≡</button>
</div>
```

Persist preference in `localStorage` under key `sbn.leadsheet.density` so user choice carries across songs.

**Done when:**
- [x] Toggle changes density immediately with smooth animation
- [x] Choice persists across page reloads
- [x] Reflow recalculates correctly in compact mode (more measures per row)

**Deferred:** Hover color change on chord names (CSS selector not working reliably)

### Step 10 — Promote teaser with viewer link

Edit existing `resources/js/Pages/Library/Songs/Show.vue`:
- Add a primary "Open in viewer →" `<Link>` near the top, above existing teaser content
- Keep the rest of the teaser (chord-name strip, mini rhythm preview, progressions list, description, song meta) intact

**Done when:**
- [x] Teaser still renders all existing Phase 7 content
- [x] "Open viewer" CTA is visually prominent (primary button, sized large)
- [x] CTA links to the new viewer route

**Implementation details:**
- Filtered out duplicate chord voicings of identical types to prevent repetitive overview cards.
- Wired standard chord card diagrams directly to individual chord detail routes.
- Unified architectural spacing for both progressions and rhythms utilizing identically colored badges.
- Injected native SVG rhythmic performance models for interactive step verification.

### Step 11 — Styling pass + mobile pass

Dedicated review:
- Two-column desktop layout looks balanced (suggested ratio: grid 65–70%, edu panel 30–35%)
- Mobile breakpoint stacks cleanly (grid full width, edu panel below, transport sticky)
- Spacing rhythm matches other library pages (`Index.vue`, progression `Show.vue`)
- All chord-card / measure / section styles render correctly outside the admin context
- Dark-mode review (if applicable to design system)

**Done when:**
- [ ] Visual parity with the rest of the library section
- [ ] Tested at 360px, 768px, 1024px, 1440px
- [ ] No admin-only CSS imports leak into the public bundle

---

## 6. Risks & open questions

### R1 — AudioEngine singleton
The viewer assumes `useAudioEngine` returns a single shared engine across components. **Verify** before Step 3 that it is a singleton (or that multiple instantiations are harmless). Risk: if a course lesson page later mounts multiple `<LeadsheetViewer>` instances, audio would conflict. **Action:** confirm singleton pattern in `composables/useAudioEngine.js`; if not, refactor before Step 3.

### R2 — Persistent AudioPlayerSlot conflict
Phase 1 added a persistent `<AudioPlayerSlot>` in `PublicLayout`. The viewer's `TransportBar` is a separate UI. **Decision needed:** when the viewer is active, does the persistent player hide? Pause? Coexist?

**Recommendation:** the persistent player surfaces *background* audio (a course track playing in the nav). The leadsheet transport is *foreground* audio (the song being studied). They're separate concerns. Hide the persistent slot only if its source is the same leadsheet (unlikely). Document this in the component's header comment.

### R3 — Section attribution in progression occurrences
The controller assumes `sbn_progression_occurrences.section_id` exists for filtering progressions per section. **Verify** column exists. If not, options:
- (a) Return all song progressions; filter client-side by chord-name match against current section's chords (best-effort; works for distinctive progressions like ii-V-I)
- (b) Add a migration to backfill `section_id` on occurrences (out of scope for Phase 9; punt to a later cleanup)
- (c) Show all song-level progressions in the EduPanel regardless of section (acceptable fallback)

**Default:** (a) if column missing, (c) only as last resort. Decide in Step 5.

### R4 — Read-only inject contract
Components that currently do `inject('chordPicker')` will get `undefined` in viewer mode if we don't provide it. **Action:** at every inject site in the chord-grid components, add a guard:
```js
const chordPicker = inject('chordPicker', null);
// usage: chordPicker?.open(...)
```
Trace every `inject(...)` in `ChordGridView`, `ChordSection`, `ChordMeasure`, `ChordCard` and apply the guard. This is the single most error-prone area of the refactor.

### R5 — Reflow on container resize
The viewer's two-column layout means the chord grid lives in a narrower container than the admin editor. `useReflow` already handles this, but **verify** the resize observer is attached to the right element (the grid container, not `window`). If broken, fix it in `useReflow` rather than working around it in the viewer.

### R6 — Cinema-view contract leak
The cinema-view toggle placeholder hints at a future state shape. Avoid naming the future route concretely (don't put `/library/songs/{slug}/cinema` in code yet) until Phase 10's design lands.

### R7 — Tab data leakage
Current `LeadsheetViewer` does not render tab. But `useTabModel` parses tab data and exposes it. If a chord triggers any tab-related side effect (cursor, audio event), it might fail silently when no tab is rendered. **Action:** in Step 3, smoke-test with a leadsheet that has tab data and one that doesn't; ensure both render the chord grid identically.

---

## 7. Definition of done (Phase 9)

- [ ] Step 0 complete (CSS extracted)
- [ ] Steps 1–11 each meet their per-step done criteria
- [ ] All existing songs in the database render in the viewer without error
- [ ] At least 5 representative songs spot-checked: simple (12-bar blues), bossa standard, jazz with complex sections, song with voltas/repeats, song with `null` melody (no tab)
- [ ] Audio playback works end-to-end: play, pause, resume, seek, tempo change, click-to-play single chord
- [ ] EduPanel surfaces correct chord-quality blurb and at least one linked progression for each spot-checked song
- [ ] Density toggle animates smoothly and persists to localStorage
- [ ] Cinema-view button placeholder visible and disabled
- [ ] Existing Songs/Show.vue teaser unchanged except for added "Open viewer" CTA
- [ ] Admin tab editor still works (regression check — same components)
- [ ] Mobile pass at 360px confirmed
- [ ] No admin-only CSS leaks into public bundle (`vite build` audit)
- [ ] `docs/Frontend-Migration-Plan.md` Phase 9 entry marked DONE with a "What was built" subsection
- [ ] `project_sbn.md` memory updated to reflect Phase 9 DONE, Phase 9b (tab toggle) NEXT

---

## 8. Future phases unblocked by this work

- **Phase 9b** — Tab panel toggle inside the viewer (chord ↔ tab view switch). Reuse `TabMeasure` / `TabCursor` in read-only mode; same `readOnly` prop pattern from Step 1.
- **Phase 10** — Cinema view. The disabled toggle button becomes active. `LeadsheetViewer` is composable enough that cinema view can be a sibling page using the same data.
- **Phase 11** — Course player. Embed `<LeadsheetViewer :embedded="true">` directly inside lesson content slots.
- **Edu content DB** — Replace the config-file stub with an `edu_topics` table + admin CRUD. Single point of change: `EduContentService`.

---

## 9. Files touched (summary)

**New:**
- `app/Services/EduContentService.php`
- `config/edu/chord-qualities.php`
- `resources/js/Components/Leadsheet/LeadsheetViewer.vue`
- `resources/js/Components/Leadsheet/EduPanel.vue`
- `resources/js/Pages/Library/Songs/Viewer.vue`

**Modified:**
- `routes/web.php` — add `library.songs.viewer` route
- `app/Http/Controllers/Library/SongLibraryController.php` — add `viewer()` method
- `resources/js/Pages/Library/Songs/Show.vue` — add "Open viewer" CTA
- `resources/js/tab-editor/components/ChordGridView.vue` — `readOnly` prop
- `resources/js/tab-editor/components/ChordSection.vue` — `readOnly` prop
- `resources/js/tab-editor/components/ChordMeasure.vue` — `readOnly` + `density` props
- `resources/js/tab-editor/components/TransportBar.vue` — styling polish only (no API change)
- `resources/js/tab-editor/components/ChordCard.vue` — inject guards (R4)
- `public/css/sbn-design-system.css` — extracted tab classes + transport polish + viewer-specific layout
- `public/css/leadsheets.css` — remove the extracted classes (admin still works)

**No-touch:** all other tab-editor composables, the `AudioEngine` stack, all other controllers, the existing Songs `Show.vue` teaser content (only the CTA is added).

# Phase 9 — Leadsheet Classic Viewer (Implementation Plan)

**Status:** In progress — Steps 0–4 shipped (viewer renders, audio works, EduPanel populates). Steps 5–11 outstanding.
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
- **Layout (Step 0.10a):** the viewer currently uses `min-height: calc(100vh - 80px)` and a full-bleed two-column layout. This is **inconsistent with the rest of the library**, which uses the design system's max-width container + normal scroll + visible footer. Before Step 5, rework the viewer to match library Index/Show layout patterns (reference: `Pages/Library/Progressions/Show.vue`). Transport bar likely becomes `position: fixed` rather than sticky-inside-the-viewer.
- **Density (Step 2b):** compact mode is currently cosmetic — diagrams hide but `.sbn-ve-measure-content { min-height: 150px }` keeps each measure tall, and `lineBreaks` defeat the row-count change. Real density work: shrink measure min-height in compact mode, ignore `lineBreaks` to use uniform 8-bar rows, tighten chord-card padding/font-size.
- **Step 5 — `EduPanel` chord-quality blurbs:** currently inline in `EduPanel.vue` (~7 if-branches). Step 6 promotes this to `app/Services/EduContentService.php` + `config/edu/chord-qualities.php` config file, controller injects via `eduChordQualities` Inertia prop.
- **Step 7 — Transport polish:** unstyled (still browser-default sliders/buttons). Highest user-visible polish gap.
- **Step 8 — Cinema toggle placeholder:** present in viewer header.
- **Step 9 — Density toggle UI:** present in viewer header. **`localStorage` persistence not yet wired** (mentioned in plan; not implemented).
- **Step 10 — Teaser CTA:** "Open viewer" button on `Pages/Library/Songs/Show.vue` — not yet added.
- **Tab panel (Phase 9b):** unchanged from plan — not in current scope.

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

### Step 4b — Layout rework + density rework (NEXT)

Two related issues surfaced during Step 4 review:

**Layout:** the viewer is currently full-bleed (`min-height: calc(100vh - 80px)`, two columns spanning the viewport). This is inconsistent with the rest of the public site, which uses the design system's max-width container with normal page scroll and visible footer. Rework the viewer to:
- Sit inside a max-width content container (match `Pages/Library/Progressions/Show.vue`)
- Allow normal page scroll — the footer should be reachable
- Move the transport bar to `position: fixed` at the bottom of the viewport, OR keep it inline at the bottom of the viewer content (decide based on visual review)
- Header (title/composer/key/tempo + density + cinema toggle) inline above the two columns, not a full-width band

**Density rework:** current implementation hides the chord diagram but leaves measure height and row count unchanged, so compact mode looks like "tall measures with empty space where the diagram was." Full density needs to:
- Reduce `.sbn-ve-measure-content { min-height }` in compact mode (currently 150px)
- Tighten chord-card padding and font size in compact mode
- Ignore `lineBreaks` in compact mode and use uniform 8-bar (or similar) rows — the per-section `lineBreaks` are tuned for the diagram-included layout
- Verify `useReflow` (if it does any container-width measurement) recomputes on density change

Order: layout first, density second — row-count math depends on container width, so doing density on the current full-bleed layout would need redoing after layout shrinks.

**Done when:**
- [ ] Viewer layout matches the visual rhythm of the rest of the library section
- [ ] Footer is reachable via normal scroll
- [ ] Compact mode produces a visibly more compact chord chart (more measures per row, shorter rows, smaller per-chord footprint)
- [ ] Switching density is smooth, no layout thrash, no measure clipping

---

### Step 5 — `EduPanel.vue` component

New file: `resources/js/Components/Leadsheet/EduPanel.vue`

**Props:**
```ts
interface Props {
  currentChord: string | null      // e.g. "Cmaj7", null when nothing selected
  currentSectionId: string | null
  song: { title, composer, songKey, tempo, timeSignature, rhythm }
  progressions: ProgressionRef[]
}
```

**Sections (top → bottom):**

1. **Header / song info** — title, composer, key, tempo, time sig, rhythm-style chip. Always visible.

2. **Current-chord block:**
   - When `currentChord` is null: show "Click a chord to learn more" placeholder
   - When set: show parsed root + quality (use existing `chord()` helper / `ChordSerializer` parse output)
   - Inline blurb from edu-content stub (see §5.6) — chord-quality description (e.g. "Major 7th: a major triad with an added major 7th interval...")
   - "View in chord library →" button linking to `/library/chords/{slug}` (slug derived from chord name; same scheme used in chord library)

3. **Section progressions block:**
   - Title: "Progressions in this section" (or "Progressions in this song" if `currentSectionId` is null)
   - Filter `progressions` by `sectionId === currentSectionId` (when section attribution is available; otherwise show all)
   - Each entry: name + roman numerals + category chip, links to `/library/progressions/{slug}`
   - Empty state: "No detected progressions in this section."

**Done when:**
- [ ] Selecting a chord updates the current-chord block
- [ ] Changing section updates the progressions list
- [ ] All links route correctly to existing library pages
- [ ] Empty / no-selection states render cleanly

### Step 6 — Edu content stub

New file: `config/edu/chord-qualities.php`

```php
<?php
return [
    'major'        => ['title' => 'Major', 'blurb' => '...'],
    'minor'        => ['title' => 'Minor', 'blurb' => '...'],
    'major-7th'    => ['title' => 'Major 7', 'blurb' => '...'],
    'dominant-7th' => ['title' => 'Dominant 7', 'blurb' => '...'],
    'minor-7th'    => ['title' => 'Minor 7', 'blurb' => '...'],
    'half-dim'     => ['title' => 'Half-diminished (m7♭5)', 'blurb' => '...'],
    'diminished'   => ['title' => 'Diminished', 'blurb' => '...'],
    'minor-major-7' => [...],
    // extend as content is written
];
```

Loader: `app/Services/EduContentService.php`

```php
class EduContentService
{
    public function chordQuality(string $qualitySlug): ?array { ... }
}
```

`SongLibraryController::viewer()` calls this service to inject `eduChordQualities` into the Inertia props (as a flat array — small enough to bundle without per-chord lookup).

**Future migration path:** when the `edu_topics` DB table lands, replace the config-file source with an Eloquent lookup. The `EduContentService` interface stays the same. Document this clearly in the service file's header comment.

**Done when:**
- [ ] At least 8 chord qualities have written blurbs
- [ ] EduPanel displays the right blurb for the selected chord
- [ ] Service is the only place that knows the data source (controllers + components consume the service contract)

### Step 7 — Transport bar polish

Visual-only pass on `TransportBar.vue`. No prop / API changes.

**Polish targets:**
- Container: pill-shaped, design-system surface color, drop shadow, sticky bottom on viewer page
- Play button: large primary-color circular button, prominent ▶/⏸ glyph
- Stop button: secondary styling, smaller
- Range sliders: consistent thumb size, design-token colors, clear hover/focus states
- Time label: monospace, larger
- Tempo control: clearer label, bigger number
- Mixer: only shown when applicable; clean track labels
- Mobile: hit targets ≥ 44px, sliders full-width, labels stack

Add styles to `sbn-design-system.css` under a new `/* Transport bar */` section. Do not introduce new color tokens — reuse existing design-system variables.

**Done when:**
- [ ] Looks intentional rather than browser-default
- [ ] Mobile usability checked at 360px width
- [ ] Admin tab editor's transport bar inherits the polish (it's the same component) — verify nothing regressed there

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
- [ ] Toggle UI visible and styled
- [ ] Buttons disabled, tooltip explains "coming soon"

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
- [ ] Toggle changes density immediately with smooth animation
- [ ] Choice persists across page reloads
- [ ] Reflow recalculates correctly in compact mode (more measures per row)

### Step 10 — Promote teaser with viewer link

Edit existing `resources/js/Pages/Library/Songs/Show.vue`:
- Add a primary "Open in viewer →" `<Link>` near the top, above existing teaser content
- Keep the rest of the teaser (chord-name strip, mini rhythm preview, progressions list, description, song meta) intact

**Done when:**
- [ ] Teaser still renders all existing Phase 7 content
- [ ] "Open viewer" CTA is visually prominent (primary button, sized large)
- [ ] CTA links to the new viewer route

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

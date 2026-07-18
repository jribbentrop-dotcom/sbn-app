# SBN Teaching Hub — Edu Content Reference

> **Purpose:** Single source of truth for the Edu Content System — theory topics,
> the `<sbn-widget>` interactive pipeline, the widget registry, and how edu
> content surfaces in the leadsheet and course player. Load at the start of any
> session that touches edu content or widgets.
> **Rule:** Theory content lives in **markdown files**, not a DB table. Interactive
> illustrations are **slug-addressable Vue widgets** embedded via `<sbn-widget>`.

---

## 1. Architecture Overview

The Edu Content System delivers reusable theory explainers (the triad, drop-2
voicings, the circle of fifths…) and interactive widgets. Content is authored
once and consumed in two in-context surfaces: the leadsheet **EduPanel** and the
course **PracticePanel**.

```
resources/edu/*.md           ← content source (markdown + YAML frontmatter)
        │
   EduContentService         ← parses files → EduTopic value objects
        │
   body_html (rendered)      ← CommonMark, html_input: allow (keeps <sbn-widget>)
        │
   mountSbnNodes.ts          ← walks v-html, mounts widgets on <sbn-widget> tags
        │
   resources/js/edu/widgets/ ← the Vue widget components + registry.ts
```

**Locked decisions (2026-05-16):**

- Storage = markdown files in `resources/edu/{concepts,qualities,glossary}/`.
  **Not** a DB table — the old `edu_topics` table idea is cancelled.
- Interactives = a Vue widget registry, embedded via `<sbn-widget slug="…">`
  tags in topic bodies, also usable standalone.
- Surfaces = in-context panels only. No `/theory` or `/glossary` browse pages
  this phase (they remain addable later).

---

## 2. Content Files

Topics live under `resources/edu/`, one file per topic, in three type
subdirectories:

| Type | Directory | Purpose |
|---|---|---|
| `concept` | `concepts/` | A theory topic; may embed `<sbn-widget>` tags. |
| `quality` | `qualities/` | One per chord quality (18 files). |
| `glossary` | `glossary/` | Short term definitions. |

A `qualities/*.md` file is the **same shape** as a `concepts/*.md` file —
frontmatter + a markdown body that may embed `<sbn-widget>`.

### Frontmatter

```yaml
---
slug: drop2                       # addressable id; matches the filename
title: Drop 2 Voicings
summary: One-line blurb — used as the EduPanel blurb.
related: [triad, voice-leading]   # concept slugs; related[0] is the surfaced one
see_also: [tritone]
description: …                    # quality files only — distinct styled prose
usage: …                          # quality files only — distinct styled prose
---
```

**Authoring rule:** distinct-styled prose → a frontmatter field; richer or
interactive content → the markdown body (the only home for `<sbn-widget>`,
for any topic type).

---

## 3. EduContentService & EduTopic

`app/Services/Edu/EduContentService.php` parses the markdown files into
`EduTopic` value objects (symfony/yaml frontmatter + CommonMark with
`html_input: allow`, which is essential so embedded `<sbn-widget>` tags survive
rendering). Results are cached with `Cache::rememberForever` (skipped in local).

Key methods:

| Method | Returns |
|---|---|
| `topic(string $type, string $slug)` | `EduTopic` or null |
| `topics(string $type)` | all topics of a type, keyed by slug |
| `qualityTopic(string $slug)` | quality `EduTopic`, null for unknown/non-quality |
| `allChordQualities()` | all quality topics incl. their `related` field |

`EduTopic` (`app/Services/Edu/EduTopic.php`) carries the parsed fields plus:

- `hasWidgets` — parse-time boolean, computed by `EduTopic::bodyHasWidgets()`
  (regex `/<sbn-widget[\s/>]/`). All `<sbn-widget>` consumers gate on this
  flag rather than re-scanning the HTML.
- `toArray()` — the shape sent to the frontend (`has_widgets` key).

The `edu:clear-cache` artisan command flushes the parsed-topic cache.

---

## 4. The `<sbn-widget>` Pipeline

Widgets are mounted by `resources/js/lib/mountSbnNodes.ts` — the **same**
custom-tag mounter used for `<sbn-chord>`, `<sbn-rhythm>`, etc. (Phase 11b).
Do **not** invent a second mounter.

`<sbn-widget>` is the no-fetch branch:

1. Read the `slug` attribute; look it up in the widget registry.
2. Unknown slug → render a visible placeholder + `console.warn` (never blanks
   the page).
3. Every attribute **except `slug`** becomes a prop, JSON-decoded when it
   parses as JSON: `highlight="C"` → string `"C"`, `count="3"` → number `3`,
   `flags="[1,2]"` → array. Wired by `widgetPropsFromAttrs()`.
4. Lazy-import the component, `createApp(mod.default, props)`, mount on the el.

Embed in any topic body:

```html
<sbn-widget slug="drop2-visualizer" quality="dom7" />
```

---

## 5. Widget Registry

`resources/js/edu/widgets/registry.ts` is the single source of truth for
embeddable widgets. Each entry is a **raw lazy-import thunk**:

```ts
export const eduWidgets = {
  'triad-builder':            () => import('./TriadBuilder.vue'),
  'circle-of-fifths':         () => import('./CircleOfFifths.vue'),
  'drop2-visualizer':         () => import('./Drop2Visualizer.vue'),
  'voice-leading':            () => import('./VoiceLeading.vue'),
  'caged-system':             () => import('./CagedWidget.vue'),
  'chord-function':           () => import('./ChordFunctionWidget.vue'),
  'chord-tones':              () => import('./ChordTonesWidget.vue'),
  'note-duration':            () => import('./DurationWidget.vue'),
  'interval-explorer':        () => import('./IntervalWidget.vue'),
  'pentatonic-scales':        () => import('./PentatonicWidget.vue'),
  'chord-quality-brightness': () => import('./ChordQualityBrightness.vue'),
  'chord-quality-tree':       () => import('./ChordQualityTree.vue'),
  'chord-extensions':         () => import('./ChordExtensionsWidget.vue'),
  'scale-positions':          () => import('./ScalePositionsWidget.vue'),
  'time-signature':           () => import('./TimeSignatureWidget.vue'),
  'repeat-signs':             () => import('./RepeatSignsWidget.vue'),
  'note-durations':           () => import('./NoteDurationsWidget.vue'),
  'triplets':                 () => import('./TripletWidget.vue'),
  'tab-diagram':              () => import('./TabDiagramWidget.vue'),
  'basic-chords':             () => import('./BasicChordsWidget.vue'),
} as const;
```

Raw thunks — **not** `defineAsyncComponent` — because `mountSbnNodes` does
`await import()` then `createApp(mod.default)`, never resolving through Vue's
template layer. Adding an interactive = one Vue component + one line here.

`isEduWidget(slug)` narrows a string to a registered `EduWidgetSlug`.

### Widget conventions

Every widget follows these rules (see `TriadBuilder.vue` as the reference):

- `<script setup lang="ts">`, self-contained — no store, no tab-editor imports.
- Pure data/interval math; props optional, JSON-decoded from tag attributes.
- Design-system CSS tokens only (see §7); scoped styles with a short class
  prefix (`tb-`, `cof-`, `d2-`, `vl-`).
- Own lazy chunk via the registry thunk.

**All widgets share a deep dark theme** — `background: #0f0f17` on the root element, `padding: 1.75rem 1.5rem 1.5rem`, `gap: 1rem+`. Consistent design tokens:

- **Header**: DM Mono `0.65rem`, `letter-spacing: 0.15em`, `text-transform: uppercase`, `color: #ffffff` (full white).
- **Badge/mode pills**: DM Mono `0.6rem`, `rgba(255,255,255,0.08)` bg, `rgba(255,255,255,0.12)` border, `color: rgba(255,255,255,0.4)` resting; active = `rgba(255,255,255,0.92)` bg + `#0f0f17` text.
- **Body/explanation text**: `system-ui` (not serif) at `0.82–0.85rem`, `line-height: 1.6`, `color: #ffffff`.
- **Secondary labels** (step counters, axis labels, bar labels): DM Mono, `color: rgba(255,255,255,0.35–0.45)`.
- **SVG structural lines** (strings, frets, axes): `rgba(255,255,255,0.08–0.22)`.
- Large display elements (chord symbols, time-signature numerals) use Cormorant Garamond; all body/explanation uses `system-ui`.

---

## 6. Current Widgets

| Slug | Component | Teaches |
|---|---|---|
| `triad-builder` | `TriadBuilder.vue` | "The Four Triads" — Major/Minor/Diminished/Augmented badges, dots animate on switch. |
| `circle-of-fifths` | `CircleOfFifths.vue` | 12-segment SVG donut; clickable keys show relative minor. Thin white outline ring + dividers. |
| `drop2-visualizer` | `Drop2Visualizer.vue` | Closed / Drop 2 / Drop 3 / Shell voicings — dots slide to new positions; bottom-gap scales with semitone distance so the visual space shows the drop; shell shows missing 5th as gap between 3rd and 7th. |
| `voice-leading` | `VoiceLeading.vue` | Fretboard excerpt (open position), Classical / Jazz tabs. B7→E (classical) and B7♭9→Emaj7 (jazz) — dots slide on fretboard on Resolve, chord-function labels inside colored dots. |
| `caged-system` | `CagedWidget.vue` | Five CAGED shapes for C major — animated camera pan, mini strip, swipe + arrow nav. |
| `chord-function` | `ChordFunctionWidget.vue` | All 7 diatonic degrees in major and minor, colour-coded by function (Tonic / Subdominant / Dominant). |
| `chord-tones` | `ChordTonesWidget.vue` | Chord-tone stacks from triad → 13th for maj/min/dom, with toggleable altered option tones. |
| `note-duration` | `DurationWidget.vue` | Dotted and tied note durations — bar-grow reveal, tabbed Dotted / Tied. |
| `interval-explorer` | `IntervalWidget.vue` | All 12 intervals — upper dot draggable on pitch axis (mouse + touch), snaps to semitone. |
| `pentatonic-scales` | `PentatonicWidget.vue` | Diatonic ↔ pentatonic toggle — dropped degrees shrink and strike through. |
| `chord-quality-brightness` | `ChordQualityBrightness.vue` | maj7 → dom7 → m7 → m7♭5 → dim7 brightness spectrum, hue-tinted chrome. |
| `chord-quality-tree` | `ChordQualityTree.vue` | Two triads branch into five 7th-chord qualities — Triad/7th-chord step toggle. |
| `chord-extensions` | `ChordExtensionsWidget.vue` | Stack thirds beyond the 7th across all five qualities, option tones on dominant. |
| `scale-positions` | `ScalePositionsWidget.vue` | Five pentatonic boxes across the neck — position switcher. |
| `time-signature` | `TimeSignatureWidget.vue` | 2/4, 3/4, 4/4, 6/8, 5/4, 7/8 — beat pattern visualiser with feel label. |
| `repeat-signs` | `RepeatSignsWidget.vue` | Repeat barlines, volta brackets, D.S. al Coda, D.C. al Fine — animated SVG staff diagrams. |
| `note-durations` | `NoteDurationsWidget.vue` | Whole → sixteenth notes — note shape, beat value, bar visualisation. |
| `triplets` | `TripletWidget.vue` | Quarter and eighth triplets — comparison toggle with normal notes. |
| `tab-diagram` | `TabDiagramWidget.vue` | Fretboard → tab → chord diagram animation showing the 90° rotation relationship (Am). |
| `basic-chords` | `BasicChordsWidget.vue` | The 8 essential open chords (E Em A Am D Dm C G) — 4-column pill nav, finger numbers in dots, major=amber/minor=blue. |

### Shared pitch-dot visual language

`TriadBuilder` and `Drop2Visualizer` share one visual vocabulary:

- A **dot** = one note; note label inside, role label beside it.
- **Vertical position is literal pitch** — higher on screen = higher pitch.
- **Color = scale-degree role**, fixed via `--clr-role-*` tokens (see §7).
- Dots **build up on load** — root → third → fifth (→ seventh), staggered, with a spring pop-in on each arrival.
- **Badge pills** (not dropdowns) select the active state. Switching quality/mode: the relevant dot slides to its new position (spring easing, slight overshoot) and briefly pulses to mark the voice that moved.
- `prefers-reduced-motion` disables all animations.

`CircleOfFifths` is a clickable 12-segment SVG donut (polar geometry, no charting library); each segment shows a major key + its relative minor. Selected segment fills white (`rgba(255,255,255,0.88)`) and persists after mouse-leave (`.cof-seg.cof-seg--selected` specificity beats `:hover`). Major labels sit at r=152 (near the outer edge at r=180); minor labels at r=118 (close to majors). Hover fills `rgba(255,255,255,0.12)`.

### Voice-leading widget

`VoiceLeading` renders a self-contained SVG fretboard (open position, frets 0–4, same visual style as the CAGED widget) with animated voice-leading:

- **Classical tab**: B7 (x2120x) → E (022100). Moving voices: D♯ rises a semitone (leading tone 3→1), A falls to G♯ (♭7→3). Bass strings appear on resolve.
- **Jazz tab**: B7♭9 (x2121x) → Emaj7 (021100, drop-3 voicing — str1 and str5 omitted). D♯ stays (3→maj7 same fret), A→G♯ moves, C→B moves (♭9→5).
- Dots show **chord-function labels** inside colored circles (1=amber, 3=blue, b7/maj7=purple, b9=pink). Color map: `FUNC_COLOR`.
- Animation: each dot is a `<g :style="transform: translate(x,y)">` with `transition: transform 0.5s` — dots slide to new fret positions on chord change.
- "Resolve →" / "← Back" toggle button; tab switch resets to chord 1.
- String convention: `str 0` = low E, `str 5` = high e (matches CAGED/Fretboard primitive).

### Mobile stroke-width fix (2026-07-16)

`RepeatSignsWidget.vue` (staff lines), `edu/fretboard/Fretboard.vue` and `BasicChordsWidget.vue` (string lines), and `VoiceLeading.vue` (fretboard strings) all draw thin SVG lines at sub-1 `stroke-width` (0.75–0.8) inside a fluid `width:100%` viewBox with no `vector-effect` — on narrow mobile screens the scaled-down stroke could render under a physical pixel and vanish. Same root cause as the chord-diagram bug in `SBN-Chord-Library-Reference.md` § SVG renderer. **Fix:** added `vector-effect="non-scaling-stroke"` to all four (locks the stroke to a constant on-screen pixel width regardless of scale), plus bumped `RepeatSignsWidget`'s staff lines from 0.75→1.

---

## 7. Design Tokens

Widgets use `public/css/sbn-design-system.css` tokens only — no hardcoded
colors beyond a matching fallback. Key tokens:

| Token | Value | Use |
|---|---|---|
| `--clr-accent` | `#f39c12` | Brand accent (orange). |
| `--clr-accent-dim` | `#e67e22` | Hover / pressed accent. |
| `--clr-role-root` | `#f39c12` | Chord-tone role: root. |
| `--clr-role-third` | `#3b82f6` | Chord-tone role: third. |
| `--clr-role-fifth` | `#10b981` | Chord-tone role: fifth. |
| `--clr-role-seventh` | `#8b5cf6` | Chord-tone role: seventh. |

The `--clr-role-*` group is the shared chord-tone palette — one fixed hue per
scale degree, used by every chord-construction widget.

---

## 8. Surfaces — Where Edu Content Appears

### Leadsheet EduPanel (`resources/js/Components/Leadsheet/EduPanel.vue`)

Clicking a chord surfaces a **"Learn more"** `<details>` expander. The chain:

```
selected chord → its quality → quality.related[0] → that concept's body_html
              → <sbn-widget> tags inside it mount on first expand
```

- `relatedConcept` resolves **only `related[0]`** — the first slug.
- `SongLibraryController` passes `eduRelatedConcepts` (every concept referenced
  by any quality's `related`) + `eduChordQualities`.
- `mountSbnNodes` fires **once per concept per panel lifetime**, only on the
  first `<details>` open, and only when `has_widgets` is true.

Example wiring: `drop2` is `related[0]` in `qualities/dom7.md`, so dominant 7th
chords surface the drop-2 widget.

### Course PracticePanel (`resources/js/Components/Course/PracticePanel.vue`)

Lessons carry a nullable `concept_slug` column (`sbn_lessons`). Set it in the
admin lesson editor's "Edu concept" card. `CourseController::player` resolves
the concept and passes `lessonConcept`; the panel shows an expander above the
transport, mounts widgets on first open, resets on lesson change.

### Chord library (`resources/js/Pages/Library/Chords/Show.vue`)

`ChordLibraryController::show` passes `qualityTopic`; the page renders
`body_html` via `mountSbnNodes` when `has_widgets`. Currently dormant — no
quality body embeds a widget yet.

### Theory browse page (`resources/js/Pages/Library/Theory/Index.vue`)

Public route `GET /theory` (name `theory.index`) — no props from the backend.
The full widget catalog (`resources/js/edu/widgets/catalog.ts`) is frontend-static:
each entry has `slug`, `title`, `summary`, and `tags[]`. The page mounts widgets
live via `createApp(mod.default).mount(el)` — same pattern as `mountSbnNodes`,
no second mounter. Cards are a 3-column CSS grid; tag-pill sidebar filter + text
search are client-side. "Music Theory" link added to the Resources column of the
MegaMenu Explore panel. To add a widget to the page: create the component,
register it in `registry.ts`, add one entry to `catalog.ts`.

---

## 9. Dev Harness

`/dev/edu/{type}/{slug}` renders a topic's `body_html` and runs `mountSbnNodes`
over it, so embedded `<sbn-widget>` tags become live components. Route is gated
to `local` + `testing` environments only — not a product surface.

- `{type}` is the **singular** key: `concept`, `quality`, `glossary`.
- `{slug}` is the topic's frontmatter slug.

Examples: `/dev/edu/concept/triad`, `/dev/edu/concept/circle-of-fifths`,
`/dev/edu/concept/drop2`.

---

## 10. Status

- **System + pipeline:** complete. `EduContentService` (file-backed), the
  `<sbn-widget>` mounter, the widget registry, and all four surfaces are wired.
- **Widgets (2026-06-01):** 20 widgets built and registered across harmony, guitar, rhythm, and notation categories. All backed by a concept markdown file in `resources/edu/concepts/`. See §6 for full table.
- **Theory browse page:** `GET /theory` live — widget grid with tag filter sidebar, live-mounted widgets, MegaMenu wired under Explore → Resources.
- **Content:** 18 quality files + 20 concept files. New content scales as files, no code changes.
- **Lesson editor palette:** widget list is server-sourced from `EduContentService::topics('concept')` — adding a concept `.md` file automatically surfaces it in the admin palette. All 20 widgets have a backing concept file.

---

## 11. Adding Content & Widgets — Quick Recipes

**A new theory topic:** create `resources/edu/concepts/{slug}.md` with
frontmatter + body. Run `edu:clear-cache` if cached. View at
`/dev/edu/concept/{slug}`.

**Surface a concept on a chord quality:** prepend the concept slug to that
quality file's `related` list (EduPanel uses `related[0]`).

**A new widget:** create `resources/js/edu/widgets/{Name}.vue` following the
§5 conventions, add one line to `registry.ts`, embed `<sbn-widget slug="…">`
in a topic body. `npm run build` must be clean; verify on the harness.

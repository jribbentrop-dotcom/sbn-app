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
  'triad-builder':    () => import('./TriadBuilder.vue'),
  'circle-of-fifths': () => import('./CircleOfFifths.vue'),
  'drop2-visualizer': () => import('./Drop2Visualizer.vue'),
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
  prefix (`tb-`, `cof-`, `d2-`).
- Own lazy chunk via the registry thunk.

---

## 6. Current Widgets

| Slug | Component | Teaches |
|---|---|---|
| `triad-builder` | `TriadBuilder.vue` | Triad construction — root/third/fifth stacked in thirds. |
| `circle-of-fifths` | `CircleOfFifths.vue` | The 12 keys arranged by fifths, with relative minors. |
| `drop2-visualizer` | `Drop2Visualizer.vue` | The drop-2 voicing technique — drop the 2nd voice an octave. |

### Shared pitch-dot visual language

`TriadBuilder` and `Drop2Visualizer` share one visual vocabulary so a learner
who understands one understands all chord-construction widgets:

- A **dot** = one note; label inside, role/interval label beside it.
- **Vertical position is literal pitch** — higher on screen = higher pitch.
- **Color = scale-degree role**, fixed via `--clr-role-*` tokens (see §7).
- Dots **build up on load** — root → third → fifth (→ seventh), staggered.
- Changing state animates the dots: triad quality changes slide the third/fifth;
  the drop-2 button arcs the 2nd-from-top voice down an octave (same dot,
  continuous motion). `prefers-reduced-motion` skips to the final state.

`CircleOfFifths` is a clickable 12-segment SVG donut (polar geometry, no
charting library); each segment shows a major key + its relative minor.

**Guitar-aware data layer:** `Drop2Visualizer` voices carry a `typicalString`
field even though the current render is abstract pitch dots. A future polish
pass can add a fretboard / string-lane diagram without restructuring the logic.

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
  `<sbn-widget>` mounter, the widget registry, and all three surfaces are wired.
- **Widgets:** `triad-builder`, `circle-of-fifths`, `drop2-visualizer` built,
  data-complete, registered, and using design tokens.
- **Content:** ongoing — 18 quality files + a growing set of concept/glossary
  topics. New content scales as files, no code changes.
- **Deferred:** a visual polish pass for the chord-construction widgets — a
  fretboard / guitar-string-lane diagram so the cramped closed voicing vs. the
  easy-to-grab drop-2 voicing is shown on a real fretboard. The widget data
  layers are already guitar-aware (`typicalString`); this is render-only work.

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

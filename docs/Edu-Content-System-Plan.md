# Edu Content System — Plan

Educational content for SBN: short, reusable theory explainers ("TRIAD — the
triad is the foundation of Western harmony…") plus interactive illustrations
(circle of fifths, triad builder, drop2). Sourced once, consumed in many places.

This is the last content piece of the frontend migration, before Phase 12
(Auth + Payments).

---

## 1. Decisions (locked)

| Decision | Choice | Rationale |
|---|---|---|
| **Storage** | Markdown files in repo (`resources/edu/`) | Version-controlled, IDE-authored, no admin UI to build. Diffable, reviewable. |
| **Interactives** | Widget registry — each is a slug-addressable Vue component | Embeddable inside any topic *and* usable standalone on library pages. Max reuse. |
| **Browse surfaces** | In-context panels only (for now) | Content surfaces in course practice panel + leadsheet edu panel. No `/theory` or `/glossary` routes yet — but the data model below does not block adding them later. |

**Non-goals (this phase):** dedicated `/theory` / `/glossary` pages, admin
editor, search. The model is designed so these can be added later without
re-authoring content.

---

## 2. Content model

### 2.1 File layout

```
resources/edu/
  concepts/
    triad.md
    drop2.md
    circle-of-fifths.md
    voice-leading.md
  qualities/
    maj7.md
    m7.md
    dom7.md
    ...
  glossary/
    cadence.md
    tritone.md
    ...
```

Three **types**, one per subdirectory:

- `concept` — a theory topic. Can be long-ish, can embed widgets.
- `quality` — a chord-quality blurb (replaces `config/edu.php` chord-qualities
  + the hard-coded `qualityEdu` map in `Chords/Show.vue`). Keyed by canonical
  quality slug so existing lookups keep working.
- `glossary` — a short term definition. One paragraph, optional `see_also`.

Type is derived from the directory — no need to repeat it in frontmatter.

### 2.2 File format

Markdown with YAML frontmatter:

```markdown
---
slug: triad
title: The Triad
summary: Three notes stacked in thirds — the atom of Western harmony.
related: [drop2, voice-leading]
see_also: []          # glossary cross-links (optional)
---

The triad is the foundation of Western harmony. Stack two thirds and you
get three notes: root, third, fifth...

<sbn-widget slug="triad-builder" />

A **major triad** has a major third on the bottom...
```

- `slug` — unique within its type; also the lookup key. Required.
- `title` — display heading. Required.
- `summary` — one-line plain-text hook. Used in panels / future card grids /
  glossary lists. Required.
- `related` — slugs of other edu topics. Optional.
- `see_also` — glossary slugs. Optional.
- Body — Markdown. May contain `<sbn-widget slug="...">` tags (see §4).

### 2.3 Why not a DB

The `EduContentService` docstring currently promises a future `edu_topics`
table. We are **not** doing that. Markdown files win here: content is small,
authored by one person, benefits from git review, and needs no runtime
mutation. The service interface (§3) stays stable either way, so a DB swap
remains possible if authoring ever moves to non-devs.

---

## 3. Backend: EduContentService rewrite — ✅ DONE (Task 1)

> **Status:** shipped. Steps 1–4 complete. Plan below kept for reference;
> see §3.4 for what actually landed and corrections to the original plan.

`app/Services/EduContentService.php` already has the right shape. Rewrite the
internals from `config()` lookups to filesystem reads. **Public method
signatures do not change** — `Chords/Show.vue` and `EduPanel.vue` consumers keep
working.

### 3.1 Parsing

- One parser: read file, split frontmatter (YAML) from body (Markdown).
- Render Markdown body → HTML at parse time. Use a Composer Markdown lib
  (`league/commonmark` — already common in Laravel projects; check
  `composer.json` first, add if missing).
- **Preserve `<sbn-widget>` tags through rendering** — CommonMark passes
  through raw HTML by default; verify the `allow_unsafe_links` / HTML-input
  config does not strip them.

### 3.2 Caching

- Parsing every file on every request is wasteful. Cache the parsed topic
  set with `Cache::rememberForever('edu.topics', ...)`, keyed so it can be
  busted.
- Add `php artisan edu:clear-cache` (or hook into `cache:clear`). Document it.
- In `local` env, skip the cache (or key on file mtime) so authoring is live.

### 3.3 Service API

Keep existing methods, add a few:

```php
// existing — keep working, now file-backed
chordQuality(string $slug): ?array            // {title, blurb} from qualities/{slug}.md
allChordQualities(): array
chordQualities(array $slugs): array

// new
topic(string $type, string $slug): ?EduTopic  // any topic, full body_html
topics(string $type): array                   // all of a type (for future index pages)
glossary(): array                             // all glossary entries, sorted
```

`EduTopic` is a lightweight value object / array: `slug, type, title, summary,
body_html, related, see_also`.

**Compatibility note:** `chordQuality()` historically returns `{title, blurb}`.
Map `quality` topics so `blurb` = rendered body (or summary — decide per how
`EduPanel` renders it; it currently shows `.blurb` as a plain paragraph, so
keep `blurb` = plain summary text, and expose full `body_html` via `topic()`).

### 3.4 What actually shipped (Task 1)

Corrections to the original plan:

- **Gotcha was wrong.** `config/edu.php` never existed — but
  `config/edu/chord-qualities.php` *did*, with 18 populated blurbs already
  rendering. This was a like-for-like source swap, not "first render."
  Verified byte-identical output for all 18 qualities.
- **`qualityEdu` shape differs.** `EduPanel` consumes `{title, blurb}`;
  `Chords/Show.vue`'s `qualityEdu` consumes `{description, usage}` — a
  different shape. Task 1 migrated only the `{title, blurb}` data
  (`summary` = old config blurb). The `description`/`usage` migration is
  **deferred to Step 9**, where the shape reconciliation is decided.

Landed files:

- `resources/edu/qualities/*.md` — 18 files (`summary` = old config blurb).
- `resources/edu/concepts/` — `triad.md` (embeds `<sbn-widget
  slug="triad-builder" />`), `voice-leading.md`.
- `resources/edu/glossary/` — `cadence.md`, `tritone.md`.
- `app/Services/Edu/EduTopic.php` — immutable value object with
  `toArray`/`fromArray` for cache round-tripping.
- `app/Console/Commands/EduClearCache.php` — `php artisan edu:clear-cache`.
- `EduContentService.php` — file parser (`symfony/yaml` frontmatter +
  CommonMark with `html_input: allow` so `<sbn-widget>` survives).
  `Cache::rememberForever('edu.topics')`, skipped in `local`.
- `composer.json` — `league/commonmark` promoted to direct require.
- Deleted `config/edu/chord-qualities.php` + empty `config/edu/`.

Numeric slug gotcha (`5.md`) handled — quoted in YAML + scalar coercion.

**Open items before Task 2 — resolved:**
- ✅ `tests/Feature/EduContentServiceTest.php` added (parser, `<sbn-widget>`
  pass-through, the 18-quality migration, numeric-slug coercion).
- ✅ Edu work committed scoped in `be98a8e`, separate from the unrelated
  `phase-b-chord-grid` working-tree changes.

---

## 4. Widget registry (interactives)

### 4.1 Registry

`resources/js/edu/widgets/registry.ts`:

```ts
export const eduWidgets = {
  'triad-builder':    () => import('./TriadBuilder.vue'),
  'circle-of-fifths': () => import('./CircleOfFifths.vue'),
  'drop2-visualizer': () => import('./Drop2Visualizer.vue'),
} as const;

export type EduWidgetSlug = keyof typeof eduWidgets;
```

> **Correction (Task 2).** Entries are raw `() => import()` thunks, **not**
> `defineAsyncComponent`. `mountSbnNodes` never resolves components through
> Vue's template layer — it does `await import()` then `createApp(mod.default)`
> for every node type. The mounter mirrors that exact pattern, so the registry
> hands it a thunk, not an async component wrapper. `defineAsyncComponent` is
> for template resolution / Suspense; handing one straight to `createApp` is
> the awkward path. Each thunk still produces its own lazy chunk.

Async imports → each widget is its own lazy chunk; a panel showing one topic
never loads all widgets.

### 4.2 Rendering topic bodies (`<sbn-widget>` → Vue)

Topic `body_html` is HTML-with-custom-tags. This is exactly the problem Phase
11b solved with `mountSbnNodes.ts` (walks `v-html` content, mounts Vue
components on custom tags). **Reuse that pattern** — do not invent a second one.

- Add `sbn-widget` handling to the existing `mountSbnNodes` walker (or a thin
  `mountEduWidgets.ts` sibling if 11b's file is course-specific).
- A widget tag `<sbn-widget slug="triad-builder" />` → look up `eduWidgets[slug]`
  → mount it in place. Unknown slug → render a small visible placeholder, log a
  warning. Never hard-fail a page.
- Optional: pass extra attributes as props (`<sbn-widget slug="circle-of-fifths"
  highlight="C" />`), JSON-decoded.

### 4.3 First widgets (build in this order)

1. **`triad-builder`** — simplest, validates the whole pipeline. Pick root +
   quality, see the three notes stack on a staff or fretboard.
2. **`circle-of-fifths`** — interactive SVG wheel; click a key to highlight
   relatives / show the key signature.
3. **`drop2-visualizer`** — animate a closed voicing → drop the 2nd voice an
   octave. Heaviest; do last.

Widgets are self-contained Vue 3 `<script setup>` + TS components. They may
reuse existing chord/fretboard render components where it helps, but must not
depend on tab-editor state.

### 4.3a What actually shipped (Task 2)

Scope: registry + `mountSbnNodes` extension + `triad-builder` only —
`circle-of-fifths` and `drop2-visualizer` deferred.

Landed files:

- `resources/js/edu/widgets/registry.ts` — raw `() => import()` thunks (see
  the §4.1 correction) + an `isEduWidget()` type guard.
- `resources/js/edu/widgets/TriadBuilder.vue` — self-contained; pure interval
  math, no tab-editor dependency. Its own lazy chunk.
- `resources/js/lib/mountSbnNodes.ts` — added a no-fetch `<sbn-widget>` branch
  alongside `<sbn-youtube>`/`<sbn-song>`. Unknown slug → visible placeholder +
  `console.warn`. Non-`slug` attributes become props via `widgetPropsFromAttrs`
  (JSON-decoded when parseable, raw string otherwise).
- `resources/js/Pages/Dev/EduHarness.vue` + `routes/web.php` — a dev/test-only
  route `/dev/edu/{type}/{slug}` that renders a topic body through the walker.
  Gated to the `local` **and** `testing` environments (the latter so the
  Feature test can reach it); never registered in production.
- `tests/Feature/EduHarnessRouteTest.php` — guards the route contract.

Notes:

- The route gate is `environment(['local', 'testing'])`, not `local` alone —
  a `local`-only route 404s under `php artisan test`, making a route test
  impossible. The harness is read-only and harmless, so widening the gate is
  the right call.
- Server-side render is test-covered; the client-side mount (`createApp` on
  the `<sbn-widget>` element) runs in the browser and is verified manually via
  the harness route.

### 4.4 Standalone use

Because widgets are a plain registry, any future Inertia page (`/theory/...`)
or library page can `import { eduWidgets }` and drop a widget in directly —
no markdown needed. The registry is the single source of truth.

---

## 5. Consumption surfaces (this phase)

### 5.1 Leadsheet Edu Panel

`EduPanel.vue` already wires `eduChordQualities` + `qualityByKey` and renders a
`.blurb`. After §3, that data comes from markdown `qualities/*.md` instead of
config. Behavior unchanged; source swapped.

Enhancement (optional, low-risk): when a chord quality's topic has a `related`
concept, show a "Learn more" link/expander pulling that concept's `summary`.

### 5.2 Course Practice Panel

The course player practice panel should be able to surface a concept topic
(e.g. a lesson about drop2 voicings shows the `drop2` explainer + its widget).

- Author lessons reference an edu topic by slug.
- The practice panel renders `topic('concept', slug).body_html` through the
  `mountSbnNodes` pipeline → text + embedded widgets appear inline.

### 5.3 Chords/Show.vue cleanup

`Chords/Show.vue` currently hard-codes `theoryMap` / `qualityEdu` / `voicingEdu`
/ `inversionEdu` inline. Migrate `qualityEdu` content into `qualities/*.md` and
read it via the service (passed through the Inertia payload like `EduPanel`
already gets `eduChordQualities`). Leave `theoryMap` (intervals/tension) as code
for now — it is structured data, not prose; out of scope.

---

## 6. Inertia payload pattern

Edu content is server-rendered text. Controllers that need it (SongLibrary,
Course) call `EduContentService` and pass a small map in the Inertia props —
mirroring how `SongLibraryController` already passes `eduChordQualities`.

- Panels receive `{ slug → {title, summary, body_html} }` for the topics that
  page could show.
- `body_html` is rendered with `v-html` + `mountSbnNodes` on the client.
- No new JSON API endpoints. (If `/theory` pages come later, they get their
  own controller + Inertia page — not an API.)

---

## 7. Build order

| Step | Work | Depends on |
|---|---|---|
| 1 | ✅ `resources/edu/` dirs + seed markdown files | — |
| 2 | ✅ `league/commonmark` direct require | — |
| 3 | ✅ Rewrite `EduContentService` → file-backed parser + cache + `edu:clear-cache` | 1, 2 |
| 4 | ✅ Verified existing consumers behave identically (18 blurbs byte-identical) | 3 |
| 5 | Widget registry `registry.ts` + extend `mountSbnNodes` for `<sbn-widget>` | — |
| 6 | Build `triad-builder` widget; embed in `triad.md`; verify end-to-end render | 5 |
| 7 | Build `circle-of-fifths` + `drop2-visualizer` widgets | 6 |
| 8 | Wire concept topics into Course Practice Panel | 3, 5 |
| 9 | Migrate `Chords/Show.vue` `qualityEdu` to service-fed props | 3 |
| 10 | Author remaining content (ongoing, no code) | 3 |

Steps 1–4 are a self-contained, shippable slice (storage + service swap, zero
visible change). Steps 5–7 add interactivity. 8–9 are wiring. 10 is forever.

---

## 8. Definition of done

- `resources/edu/` is the single source of educational prose; `config/edu.php`
  is gone (or empty).
- `EduContentService` reads markdown, caches, and all current consumers behave
  identically to before the swap.
- A topic body containing `<sbn-widget slug="...">` renders text + a live
  interactive component in both the leadsheet edu panel and course practice
  panel.
- Adding a new topic = drop a `.md` file. Adding a new interactive = add one
  Vue component + one registry line.
- No `/theory` or `/glossary` routes yet — but nothing in the model blocks
  adding them as a thin Inertia page over `EduContentService::topics()`.

---

## 9. Scalability notes (why this holds up)

- **Content scales with files, not code.** A glossary of 200 terms is 200
  markdown files and zero code changes.
- **Interactives scale with the registry.** One line per widget; lazy-loaded so
  page weight stays flat.
- **Future browse pages are additive.** `topics()` / `glossary()` already
  return everything needed for index pages — `/theory` becomes one controller +
  one Inertia page when wanted.
- **Future DB migration stays open.** The service interface is the contract;
  swapping the markdown reader for Eloquent touches one class.
</content>
</invoke>

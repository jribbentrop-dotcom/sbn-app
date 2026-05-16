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

## 5. Consumption surfaces — Task 3 plan (steps 8–9)

Task 3 is the first time edu content reaches real product surfaces. Three
sub-tasks below; **3.0 (model + content reconciliation) must land first** —
3.1/3.2/3.3 all depend on it.

### 5.0 Quality file model — one shape for all topics

A `qualities/*.md` file is the **same shape as a `concepts/*.md` file** —
frontmatter + a markdown body that may embed `<sbn-widget>`. Qualities add two
optional structured frontmatter fields. No per-type special-casing.

```markdown
---
slug: maj7
title: Major 7
summary: Lush, sophisticated — a major triad plus the major seventh.
description: Add the major seventh to a major triad and you get a lush...
usage: The signature sound of bossa nova and smooth jazz...
related: [voice-leading]
---

<sbn-widget slug="triad-builder" quality="maj7" />

(optional richer prose / interactives go in the body)
```

Field → consumer mapping:

| Field | Type | Consumer | Notes |
|---|---|---|---|
| `summary` | frontmatter, 1 line | `EduPanel` `.blurb` | unchanged behavior |
| `description` | frontmatter string | `Chords/Show.vue` `.sbn-chord-identity-description` | kept separate from `usage` because Show renders them as two distinct styled spans |
| `usage` | frontmatter string | `Chords/Show.vue` `.sbn-chord-identity-usage` | — |
| body (`body_html`) | markdown + `<sbn-widget>` | any surface, via `mountSbnNodes` | the **only** home for interactive/graphical elements; empty for most qualities today |

**Rule:** prose that needs distinct styling → frontmatter field. Anything
richer or interactive → markdown body + `<sbn-widget>`. This holds for
`concept`, `quality`, and `glossary` alike — interactives never get a
type-specific mechanism.

### 5.0a Slug reconciliation (do first) — audited, scope shrank

**Audit result (verified 2026-05-17): all three slug sets already match.**
The earlier worry about `min`-vs-`m7` / `aug`-vs-`aug7` mismatches was wrong —
those are four *distinct* canonical keys, all present in every set:

| Source | Count | Slugs |
|---|---|---|
| `ChordDiagram::CHORD_QUALITIES` | 18 | the canonical authority |
| `resources/edu/qualities/*.md` | 18 | **identical** to canonical (Task 1's old config already used canonical keys) |
| `Chords/Show.vue` `qualityEdu` | 17 | canonical **minus `7sus4`** — its only gap |

`Chords/Show.vue` looks edu up by `props.chord.quality`, which is the raw
`ChordDiagram->quality` DB column — model-constrained to `CHORD_QUALITIES`
keys. So every consumer is already keyed on canonical slugs.

**Consequence — no mapping table, no normalization layer, no superset work.**
The 18 `qualities/*.md` files *are* the complete canonical set. The only data
gap is the missing `7sus4` entry in `qualityEdu`.

**Audited 17→18 mapping (set-compared 2026-05-17).** The 17 `qualityEdu` keys
are a clean, exact subset of the 18 filenames — every key matches a filename
literally, no normalization:

```
qualityEdu keys (17): 5 add9 aug aug7 dim dom7 m6 m7 m7b5 mMaj7
                      maj maj6 maj7 min o7 sus2 sus4
qualities/*.md  (18): …all 17 above… + 7sus4   ← the only file with no source
```

So `17 verbatim + 1 fresh = 18` holds exactly. No 19th file; no `qualityEdu`
key is orphaned. 8.1 will not hit a missing-file surprise.

Revised 5.0a scope:

- Add `description` + `usage` frontmatter to all 18 `qualities/*.md`. For 17 of
  them, migrate the prose verbatim from `Chords/Show.vue`'s `qualityEdu`.
- **Author one new `7sus4` `description`/`usage` pair** — `qualityEdu` lacks it
  but the canonical set and the `.md` set both have the slug. **This entry has
  no legacy provenance** — mark it newly authored (a frontmatter comment or the
  commit message) so a future reviewer does not hunt for a `qualityEdu` source
  that never existed. Match the tone of
  the existing 17.
- No `EduContentService` slug-normalization API — nothing needs one.

### 5.1 Leadsheet Edu Panel

`EduPanel.vue` already wires `eduChordQualities` + `qualityByKey` and renders a
`.blurb` — unchanged after Task 1's source swap.

Enhancement (this task): when the active quality's topic has a `related`
concept, show a "Learn more" expander. It pulls that concept's `body_html` and
renders it through `mountSbnNodes` — so a related concept with a `<sbn-widget>`
shows the live interactive *inside the leadsheet panel*. This is the first
product surface (after the dev harness) to mount a widget.

- `SongLibraryController::viewer` / `apiViewerData` already pass
  `eduChordQualities`. Add a second prop — the related `concept` topics keyed
  by slug — so the panel has the bodies it needs offline.
- Keep it collapsed by default; the panel is narrow.

### 5.2 Course Practice Panel

The course practice panel surfaces a `concept` topic (e.g. a drop2 lesson shows
the `drop2` explainer + its widget).

- Lessons reference an edu topic by slug (author-side; how the slug is stored
  on the lesson is a Course-system detail — confirm against
  `docs/SBN-Course-Reference.md` before implementing).
- The panel renders `topic('concept', slug).body_html` through `mountSbnNodes`
  → text + embedded widgets inline.
- The course controller calls `EduContentService::topic()` and passes the
  topic in the Inertia payload (§6 pattern).

### 5.3 Chords/Show.vue migration

`Chords/Show.vue` hard-codes `theoryMap` / `qualityEdu` / `voicingEdu` /
`inversionEdu` inline.

- **Migrate `qualityEdu` only.** Its `{description, usage}` per quality now
  comes from the `qualities/*.md` frontmatter (5.0). Delete the inline
  `qualityEdu` const.
- `ChordLibraryController`'s Show action passes **no edu props today** — add
  one: the quality topic for the chord being shown (`description`, `usage`,
  and `body_html`). Render `description`/`usage` in the existing two spans;
  render `body_html` (if non-empty) through `mountSbnNodes` below them.
- Graceful fallback: quality with no file / no `description` → the identity
  section falls back to `theoryMap`'s `typical_context`, exactly as the
  current `v-else-if="theory"` branch already does. No new empty states.
- **Leave `theoryMap`, `voicingEdu`, `inversionEdu` as inline code.**
  `theoryMap` is structured data (intervals/tension), not prose. `voicingEdu`/
  `inversionEdu` key off voicing category / inversion, not quality — out of
  scope for this task; revisit only if a future task moves them.

### 5.4 New service method

`chordQuality()` keeps returning `{title, blurb}` (compat — do not touch).
Add:

```php
qualityTopic(string $slug): ?EduTopic   // full quality topic: title, summary,
                                        // description, usage, body_html
```

`EduTopic` gains two optional fields: `description`, `usage` (null for
non-quality topics). `toArray`/`fromArray` updated for cache round-trip.

Test additions (8.0) — guard the nullable-field path specifically, since
`fromArray` is the easiest place to silently drop it:

- a `quality` topic exposes non-null `description`/`usage`;
- a `concept`/`glossary` topic has both `null`;
- `qualityTopic()` on a non-quality slug returns `null` outright;
- `description`/`usage` survive the `toArray` → `fromArray` round-trip.

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
| 5 | ✅ Widget registry `registry.ts` + `<sbn-widget>` branch in `mountSbnNodes` | — |
| 6 | ✅ `triad-builder` widget + `/dev/edu` harness; end-to-end verified | 5 |
| 7 | Build `circle-of-fifths` + `drop2-visualizer` widgets | 6 |
| **8.0** | **Task 3 — quality model + slug reconciliation** (§5.0, §5.0a): union-superset `qualities/*.md`, add `description`/`usage` frontmatter, `EduTopic` + `qualityTopic()` service method (§5.4) | 6 |
| **8.1** | **Task 3 — Chords/Show.vue migration** (§5.3): `ChordLibraryController` Show passes quality topic; Vue renders `description`/`usage` spans + optional `body_html` via `mountSbnNodes`; delete inline `qualityEdu` | 8.0 |
| **8.2** | **Task 3 — EduPanel "Learn more"** (§5.1): related-concept expander, `body_html` via `mountSbnNodes`; `SongLibraryController` passes concept topics | 8.0 |
| **8.3** | **Task 3 — Course Practice Panel** (§5.2): lesson references concept slug; panel renders `topic()` body via `mountSbnNodes` | 8.0, 8.1 done as warm-up |
| 9 | (folded into 8.0–8.3) | — |
| 10 | Author remaining content (ongoing, no code) | 3 |

Steps 1–4 are a self-contained, shippable slice (storage + service swap, zero
visible change). Steps 5–7 add interactivity. **Step 8 (Task 3) is the wiring:
8.0 first — everything depends on it — then 8.1/8.2/8.3, which are independent
of each other and could be separate commits.** Step 10 is forever.

Task 3 ordering note: 8.1 (Chords/Show.vue) before 8.2/8.3 is recommended — it
exercises `qualityTopic()` and the static-prop path before the harder
selection-driven panels.

---

## 8. Definition of done

- `resources/edu/` is the single source of educational prose; the old
  `config/edu/chord-qualities.php` is gone (✅ Task 1).
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

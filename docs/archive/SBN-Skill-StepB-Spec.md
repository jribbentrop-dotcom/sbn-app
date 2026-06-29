> ⚠️ **ARCHIVED / SHIPPED 2026-06-29.** Built; as-built folded into
> `docs/SBN-Skill-System-Plan.md` → "Step B — student-facing 'Skills this builds' panel".
> Kept for history only; the Plan is the source of truth.

# Skill System — Step B Spec: "Skills this builds" on detail pages

> **For:** Sonnet (implementer). **Status:** ready to build. **Prereq:** Step A is shipped
> (see `SBN-Skill-System-Plan.md` → "Node ↔ Content Links"). This spec is self-contained;
> read the named files before editing.

## Goal

On the four library detail pages — **rhythm, progression, chord (voicing), song** — show a panel
listing the **skill nodes that content builds**. Pure read of the reverse relations Step A already
created. No new tables, no migrations, no admin changes. This is the first *student-facing* surface
for the skill system's content links.

## What already exists (do NOT rebuild)

- **Reverse relations** (Step A) return `Illuminate\Database\Eloquent\Collection` of `SkillNode`:
  - `RhythmPattern::skillNodes()`        — morphToMany over `sbn_skill_node_content`
  - `ChordProgression::skillNodes()`     — morphToMany over `sbn_skill_node_content`
  - `Leadsheet::skillNodes()`            — morphToMany over `sbn_skill_node_content`
  - `ChordDiagram::skillNodes()`         — **returns a plain Collection** (resolved by
    `voicing_category` via `whereJsonContains`, NOT a relation — see Step A). Call it as a method
    `$diagram->skillNodes()`; the other three are relations so `$model->skillNodes` (property) also works.
- **`SkillNode`** fields you need per node: `slug`, `title`, `branch`, `grade`, `icon_key`, `icon_path`.
- **`resources/js/Components/Skill/SkillIcon.vue`** — 3-tier icon (icon_path → icon_key → branch
  fallback). **Reuse it.** Check its props before using (likely `:node` or `:icon-key`/`:branch`).
- **`Account/Skills.vue`** and **`Components/Course/CourseSkillTracker.vue`** — existing skill-card
  styling to match visually. Read them for the card/pill look; do not import wholesale.

## The four surfaces (verified)

| Content | Controller method | Inertia page |
|---|---|---|
| Rhythm | `Library/RhythmLibraryController@show` | `Library/Rhythms/Show.vue` |
| Progression | `Library/ProgressionLibraryController@show` | `Library/Progressions/Show.vue` |
| Chord/voicing | `Library/ChordLibraryController@show` | `Library/Chords/Show.vue` |
| Song | `Library/SongLibraryController@show` | `Library/Songs/Show.vue` |

All are Inertia (`Inertia::render`), already build prop arrays, and already carry sibling/related
panels (e.g. Progression `show()` passes `$songs`, `$siblings`). **Add a `skills` prop alongside the
existing ones — match the local style of each controller** (they differ; don't impose a shared helper
unless it's clean).

These routes are behind the **beta `auth` gate** (all `/library/*` requires login — see
`reference: project_beta_gate_licensing` / `bootstrap/app.php` `redirectGuestsTo`). So every viewer is
authenticated. **Decide:** do you flag each node with the current user's completion status (done /
not), mirroring `CourseController::courseSkills()`? Recommended **yes** — it's cheap and makes the
panel feel alive ("you've built 2 of 4 of these"). See "Completion flag" below.

## Backend — per controller `show()`

Add a serialized `skills` array. Canonical shape (one entry per node):

```php
// Songs: $leadsheet->skillNodes (property, relation)
// Rhythm/Progression: $model->skillNodes (property, relation)
// Chord:  $diagram->skillNodes()  ← METHOD, returns Collection (category-resolved)
$completed = $request->user()
    ? $request->user()->skillNodes()->wherePivot('status', 'completed')
        ->pluck('sbn_skill_nodes.slug')->flip()
    : collect();

$skills = $model->skillNodes->map(fn ($n) => [   // ->skillNodes() for ChordDiagram
    'slug'      => $n->slug,
    'title'     => $n->title,
    'branch'    => $n->branch,
    'grade'     => $n->grade,
    'icon_key'  => $n->icon_key,
    'icon_path' => $n->icon_path,
    'completed' => $completed->has($n->slug),
])->values();
```

Pass `'skills' => $skills` into the existing `Inertia::render(...)` array. **Sort** by `grade` then
`title` for stable display. For chords, the resolver already returns category order — re-sort to
match.

### Completion flag

`User::skillNodes()` exists (BelongsToMany with progress pivot, status/completed_at — see
`app/Models/User.php`). The `flip()`-into-lookup pattern above is copied from
`CourseController::courseSkills()` — follow it exactly so behavior is consistent across the app.

## Frontend — one shared component

Build **`resources/js/Components/Skill/SkillsBuiltPanel.vue`** and drop it into all four Show pages.
Props:

```ts
defineProps<{
  skills: Array<{ slug:string; title:string; branch:string; grade:number|null;
                  icon_key:string|null; icon_path:string|null; completed:boolean }>
  heading?: string   // default "Skills this builds"
}>()
```

Behavior:
- If `skills.length === 0` → render nothing (`v-if`). Most content has no links yet (Step A seeded
  only a handful) — an empty panel everywhere would look broken.
- Each node → a card/pill: `SkillIcon` + title + a small grade badge (`G{grade}`) + a "✓ built"
  state when `completed`. Link each to the node's eventual landing page **if it exists yet** — it
  does NOT (Phase 2, not built). So for now: **render as non-link** (or link to `/account/skills`
  with the node anchored, your call — simplest is non-link). Leave a `// TODO: link to node landing
  page when built` comment.
- Optional header line when any completed: "You've built N of {total}." Nice-to-have, not required.

**CSS:** per `SBN-Design-Reference.md`, card frame/hover/badge styling must be **global classes in
`public/css/sbn-design-system.css`**, NOT scoped `<style>` (scoped `[data-v-*]` breaks
`[data-theme]` theme-switching). Reuse existing skill-card tokens from `Account/Skills.vue` if they're
already global; only add new global classes if needed. This is a hard constraint — see the design-ref.

## Placement on each Show page

Put the panel where related/sibling content already lives (these pages have a clear secondary-column
or below-the-fold "related" region). Read each Show.vue and match its existing panel rhythm — don't
invent a new layout slot. Songs: place near the existing chord/progression panels (it's the
"equipment trains these skills" read, the headline use case).

## Out of scope (do NOT do)

- No node **landing page** (Phase 2, separate task).
- No **admin** changes (Step A's editor is done).
- No new **migrations/tables**.
- No **student skill-tree** render (deferred, gated on multi-style-tile decision).
- Don't touch the Viewer/Cinema pages — Show pages only.

## Done = verified

1. Each of the 4 Show pages shows the panel when the content has linked nodes, nothing when it
   doesn't. Use the Step A seed to test live: rhythm `gilberto-rhythm` → `two-four-feel`; progression
   `ii-v-to-relative-major` → `ii-v-i-major`; song `the-girl-from-ipanema-1` → `two-four-feel` +
   `ii-v-i-major`; chord — open any **shell** voicing (e.g. `maj7-shell-roote`) → `shell-voicings`,
   any **drop2** → `drop2-voicings`.
2. Completion ✓ shows for a logged-in user who has self-reported one of those nodes done
   (toggle one at `/account/skills`, reload a detail page).
3. Theme switch (modern/vintage) doesn't break the panel styling (proves CSS is global, not scoped).
4. No N+1 — eager-load where the relation is a true relation; the chord resolver is one extra query,
   fine.

## Reference trail

- `SBN-Skill-System-Plan.md` → "Node ↔ Content Links (Step A)" — the data model + why voicings differ.
- `CourseController::courseSkills()` — the completion-flag pattern to copy.
- `SBN-Song-Library-Reference.md`, `SBN-Progression-Library-Reference.md` — the Show-page contracts.
- `SBN-Design-Reference.md` — the global-CSS-not-scoped constraint.

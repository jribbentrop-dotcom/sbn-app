# SBN Skill System — Planning & As-Built Reference

> Status: **v1 SHIPPED (uncommitted) 2026-06-23.** Data model, models, seeder, and admin table
> editor all built — see "v1 Implementation (As-Built)". Deferred work (style classes, repertoire,
> graph viz, student UI) tracked in "Open Decisions" and "Post-v1 Roadmap".  
> Next step: `php artisan migrate --seed`, eyeball the seeded prerequisite edges, then curate the
> remaining branches in the admin editor.

---

## Core Concept

Replace large monolithic courses with **atomic skill nodes** that students accumulate like RPG character stats. Progress through the skill graph unlocks **style classes** (emergent musical identity). **Repertoire pieces** function as equipment — practical tools that increase real-world musical competence.

The system is a **recommendation and visualization engine**, not a hard-lock gate. All content remains accessible; the skill tree guides without blocking.

---

## v1 Scope Lock (2026-06-23)

The full vision is months of curatorial work. To make this shippable, v1 is deliberately trimmed.
Locked decisions:

1. **Course ↔ skill node is many-to-many.** A course teaches multiple nodes; a node is taught by
   multiple courses (e.g. both *Bossa Basics* and *Bossa Chords II* touch shell voicings). This
   resolves TBD #6. Requires a `course_skill_node` pivot (not in the original sketch — added below).
2. **Style classes are deferred out of v1 entirely.** Auto-award-by-weighted-threshold is a tuning
   problem (same class as the builder VL weight-tuning that keeps blocking on a regression suite).
   Ship the graph + soft gating first; style classes are a cosmetic fast-follow, not the foundation.
3. **v1 completion = self-report only.** `quiz` and `watch` need infrastructure that doesn't exist
   (there is no video-progress/completion model yet — current video sync is playback, not tracking).
   Self-report is buildable today; the others come later.
4. **v1 admin = table-based editor**, not graph visualization. A filterable node table + per-node
   prerequisite editor delivers ~90% of the curation value. Build force-directed graph viz only once
   the node data exists to justify it.
5. **Skill nodes are their own table, NOT tags.** A node has structure tags can't model (branch,
   prerequisite edges, completion type, per-user progress). But a node may *borrow* the existing tag
   cloud to discover content — see "Tag System Integration" below.

**The hard part of v1 is content, not code.** The migrations are ~a day; populating ~50 nodes with
correct prerequisite edges is the real (curatorial) cost. Estimate accordingly.

---

## v1 Implementation (As-Built) — 2026-06-23

All four locked decisions are implemented. Files below are the source of truth; this section is the map.

### Schema — `database/migrations/2026_06_23_000002_create_skill_system_tables.php`

Four tables (deferred tables intentionally NOT created — see migration docblock):

| Table | Purpose | Key columns / constraints |
|---|---|---|
| `sbn_skill_nodes` | the nodes | `slug` (unique), `title`, `branch` (indexed), `sub_branch`, `description`, `completion_type` (default `self_report`), `content_tag_slug` (nullable, indexed — the tag bridge), `sort_order` |
| `sbn_skill_node_prerequisites` | directed edges | composite PK `(skill_node_id, requires_skill_node_id)`; both FKs cascade; reverse index on `requires_skill_node_id` for "what this unlocks" |
| `sbn_course_skill_node` | many-to-many course↔node | composite PK `(course_id, skill_node_id)`; both FKs cascade |
| `sbn_user_skill_progress` | per-user progress | `status` (default `in_progress`), `completed_at`; `unique(user_id, skill_node_id)` so no dup rows |

`content_tag_slug` has **no FK** — it soft-references `sbn_tags.slug` (a non-PK column) and a node may
carry a slug seeded later; resolution happens in app code via the existing tag pivot.

### Models

- **`app/Models/SkillNode.php`** (new) — `$table = 'sbn_skill_nodes'`, `$guarded = ['id']`.
  - Relations: `prerequisites()` / `unlocks()` (both directions of the same self-referencing edge
    table, swapped pivot keys), `courses()` (BelongsToMany), `userProgress()` (with status/completed_at pivot).
  - Tag bridge: `contentTag()` returns the `SbnTag` or null; `associatedContent()` returns
    `['rhythmPatterns'=>…, 'chordProgressions'=>…, 'leadsheets'=>…]` (empty collections when no tag —
    callers can always iterate). Reuses `SbnTag`'s existing morph relations, no hand-rolled pivot query.
  - Constants: `BRANCHES` (6 canonical), `COMPLETION_SELF_REPORT`. Scope: `scopeInBranch()`.
- **`app/Models/Course.php`** — added `skillNodes()` (inverse BelongsToMany).
- **`app/Models/User.php`** — added `skillNodes()` (with progress pivot), matching the `courses()` style.

### Seeder — `database/seeders/SkillNodeSeeder.php` (registered in `DatabaseSeeder`)

14 starter nodes across the two best-specified branches (**Harmony** 10, **Rhythm** 4) plus their
prerequisite edges. Two-pass build (upsert all nodes, then wire edges) so prereqs can be declared
before their target appears. Fully idempotent: `updateOrCreate` on slug, `insertOrIgnore` on edges.
`two-four-feel` carries `content_tag_slug => 'samba'` (a tag already populated by the categories
migration) so the tag bridge returns real rows on day one. This is a **slice**, not the full taxonomy.

### Admin editor (v1 = table, not graph)

- **`app/Http/Controllers/Admin/SkillNodeController.php`** — full CRUD, modeled on `AdminFretboardController`
  (`uniqueSlug`, `validated`, flash messages). `index()` uses `withCount(['prerequisites','unlocks','courses'])`
  grouped by branch. Store/update `sync()` both pivots. Self-prerequisite blocked **twice** (view
  excludes current node + controller filters server-side). `completion_type` hardcoded to `self_report`
  (not a form field) per the v1 lock.
- **Routes** — 6 RESTful routes in the `admin` group (`routes/web.php`), bound as `{skillNode}`.
- **Views** — `resources/views/admin/skill-nodes/index.blade.php` (per-branch tables; the Req/Unlocks/Courses
  counts *are* the curriculum-gap view — 0 unlocks = dead end, high count = load-bearing) and
  `edit.blade.php` (plain Blade form, canonical `sbn-editor-card`/`sbn-form-group` markup, native
  `<select multiple>` for prereqs + courses).
- **Nav** — `Skill Nodes` link added to the admin sidebar (`layouts/admin.blade.php`) after Courses.

### Known v1 gaps (deliberate deferrals, not bugs)

- **No cycle detection** — the form allows A→B and B→A. Harmless for a 14-node hand-curated graph;
  add a topological check at the moment something actually *traverses* the graph (student "recommended
  next"), not before.
- **Native multi-selects** — unglamorous but fine for admin-only v1; a tag-style picker is polish.
- **No prod migration path yet** — per deploy notes, create-table migrations don't run on the server
  (`sbn.db` is untracked + scp'd). Shipping to prod needs these tables created another way.

---

## The RPG Analogy

| RPG Concept | SBN Equivalent |
|---|---|
| Character stats | Skill nodes (Harmony, Rhythm, Technique…) |
| Equipment / weapons | Repertoire pieces |
| Character class | Style identity (Bossa Player, Jazzer, Blues Man…) |
| Quests / missions | Courses / learning paths |
| Level | Overall progression |
| Open world | Full content always accessible |

---

## Node Types (confirmed)

### 1. Skill Nodes
Atomic teachable concepts. The core of the graph.

- Belong to a branch and sub-branch
- Can require other skill nodes (prerequisites)
- Completed by: watching/reading lesson content + optional quiz
- Teach a concept independent of any style or song

### 2. Repertoire Nodes
Songs and pieces. Practical application — the "equipment."

- Require skill nodes to attempt
- Can optionally require other repertoire nodes
- Have an acquisition type:
  - **Native** — your own arrangement, sold directly in SBN
  - **External** — link to Musicnotes / Sheet Music Plus (affiliate potential)
  - **Self-reported** — student already owns it, marks manually
- Completion contributes toward style class progression
- Can have prestige tiers (common → rare → legendary)

### 3. Style Classes
Emergent identity, automatically awarded when threshold of relevant nodes is met.

- Not chosen by the student — earned through accumulation
- Multiple classes can be held simultaneously
- Examples: Bossa Rhythm Player → Bossa Comper → Bossa Nova Guitarist
- Triggered automatically by the system (no teacher action needed)

---

## Skill Taxonomy (first draft)

### Rhythm
- Pulse & subdivision
- 2/4 feel (bossa / samba)
- 3/4 / waltz feel
- Syncopation
- Polyrhythm
- Comping patterns

### Harmony
- Intervals
- Triads
- Shell voicings (3+7)
- Drop 2 voicings
- Drop 3 voicings
- Chord inversions
- ii-V-I in major
- ii-V-I in minor
- Modal harmony
- Tritone substitution
- Reharmonization
- Chord melody

### Melody
- Scale patterns
- Pentatonic
- Arpeggio shapes
- Improvisation over changes
- Motivic development

### Technique
- Fingerpicking basics
- Right hand independence
- Thumb independence
- CAGED system
- Barre chords
- Position shifting
- Legato / slurs
- Tone production

### Ear Training
- Interval recognition
- Chord quality recognition
- Rhythm dictation
- Melodic dictation

### Reading & Theory
- Standard notation basics
- Leadsheet reading
- Nashville number system
- Rhythm notation

---

## Style Classes (examples)

| Class | Required skill clusters |
|---|---|
| Bossa Rhythm Player | 2/4 feel + thumb independence + comping patterns |
| Bossa Comper | Above + shell voicings + Drop 2 + ii-V-I major |
| Bossa Nova Guitarist | Above + chord melody + repertoire threshold |
| Jazz Line Player | ii-V-I lines + arpeggio shapes + improvisation over changes |
| Fingerstyle Player | Fingerpicking basics + thumb independence + tone production |

---

## Key Design Principles

**Soft gating only** — content is always accessible. The tree recommends, warns of missing prerequisites, but never blocks. A "missing prerequisites" indicator motivates rather than frustrates.

**Concept-based not topic-based** — "Drop 2 voicings" is one canonical node consumed by many paths. No duplication across styles.

**Graph not tree** — skills have cross-cutting dependencies. Bossa Nova and Jazz chord melody both draw from the same Harmony nodes. The underlying data model is a directed graph.

**Curation is the product** — for repertoire, the value is not the sheet music (outsourced where needed) but the pedagogical context: *why* this piece, *what* it trains, *where* it sits in the learning graph.

---

## UI / UX Direction

### Phase 1 — Admin only (v1 scope) — ✅ BUILT (see "v1 Implementation (As-Built)")
- ✅ **Table-based** node editor (per-branch tables, per-node prerequisite + course editor)
- ✅ Tag and link nodes (teaches / requires relationships) via `<select multiple>`
- ✅ Map existing courses to skill nodes via the `sbn_course_skill_node` pivot (many-to-many)
- ✅ Curriculum gaps surfaced as Req/Unlocks/Courses counts on the index
- ⏳ Graph visualization — still a **fast-follow**, built once node data justifies it

### Phase 2 — Student facing (later)
- Simplified tree view (not full graph complexity)
- Student profile showing acquired skills, repertoire, style classes
- Recommended next nodes based on current progress
- Drag-and-drop path planning

---

## Open Decisions (TBD)

| # | Question | Status |
|---|---|---|
| 2 | Student UI: simplified tree vs full graph view | Decide at Phase 2 |
| 6 | Do existing courses map 1:1 to skill nodes, or can one course teach multiple? | **Resolved: many-to-many (`course_skill_node` pivot)** |
| 7 | Where does repertoire ownership live in the user profile? | TBD (post-v1; repertoire is not in v1) |
| — | Quiz format and completion threshold | Deferred (v1 = self-report only) |
| — | Style class trigger thresholds (how many nodes = class awarded?) | Deferred out of v1 |
| — | Affiliate link strategy for external repertoire | Deferred (repertoire post-v1) |

---

## Laravel Data Model

### v1 tables — ✅ BUILT

These shipped with `sbn_` prefix in the 2026-06-23 migration. The authoritative column list is in
"v1 Implementation (As-Built) → Schema"; the sketch below is kept for intent. (As-built deltas vs the
original sketch: `sbn_` prefix, added `slug` + `sort_order` + `content_tag_slug`, composite PKs on the
two pivots, `unique(user_id, skill_node_id)` on progress.)

```
sbn_skill_nodes
  id, slug, title, branch, sub_branch, description
  completion_type: [self_report]   # v1: self_report only; watch/quiz added later
  content_tag_slug                 # optional tag bridge (→ sbn_tags.slug)
  sort_order

sbn_skill_node_prerequisites
  skill_node_id, requires_skill_node_id          # composite PK

sbn_course_skill_node            # many-to-many course↔node (resolves TBD #6)
  course_id, skill_node_id                       # composite PK

sbn_user_skill_progress
  id, user_id, skill_node_id, status, completed_at   # unique(user_id, skill_node_id)
```

### Deferred tables (post-v1 — NOT built; sketch only)

```
repertoire_nodes
  id, title, composer, difficulty_tier
  acquisition_type: [native, external, self_reported]
  external_url, affiliate_url

repertoire_prerequisites
  repertoire_node_id, requires_skill_node_id
  repertoire_node_id, requires_repertoire_node_id (optional)

style_classes
  id, title, description

style_class_requirements
  style_class_id, skill_node_id, weight

user_repertoire
  user_id, repertoire_node_id, acquisition_type, acquired_at

user_style_classes
  user_id, style_class_id, awarded_at
```

---

## Tag System Integration (resolved 2026-06-23)

The existing tag system (`sbn_tags` + polymorphic `sbn_taggables`) is a **flat, edgeless label
cloud** already attached to progressions, rhythms, and leadsheets. Two separate questions:

- **Are skill nodes tags?** No. Tags have no hierarchy, no edges, no completion/progress. A node
  needs all three. Nodes keep their own `sbn_skill_nodes` table.
- **Can a node use tags to find content?** Yes — this is the real win. A node carries an optional
  `content_tag_slug` (nullable, → `sbn_tags.slug`). When set, the node inherits every progression /
  rhythm / leadsheet already tagged with that slug, through the existing `sbn_taggables` pivot — no
  new per-content-type linking, no re-tagging.

**Caveat:** tag granularity ("samba", a vibe) rarely matches skill granularity ("Drop 2 voicings", a
technique). Many nodes will have no matching tag. So `content_tag_slug` is a *fast-start convenience*
where a tag aligns, **not** the universal content-linking mechanism — a direct content-link pivot is
still expected post-v1.

---

## Existing SBN Integration Points

- **Tag system** — used as a node's optional content-discovery bridge via `content_tag_slug`
  (see "Tag System Integration"). Nodes are NOT themselves tags.
- **Course/lesson structure** — existing courses get mapped to skill nodes over time
- **Chord/tab database** — skill nodes can link directly to relevant leadsheets and exercises
- **PDF pipeline** — skill node completion could trigger downloadable reference sheets

---

## Post-v1 Roadmap (not built)

In rough priority order, once the taxonomy is curated:

1. **Curate remaining branches** — Melody, Technique, Ear Training, Reading & Theory nodes + edges,
   via the admin editor. This is the real work; everything else is gated on having a populated graph.
2. **Map existing courses** to nodes through the pivot (already editable in the admin form).
3. **Student-facing progress** — surface `sbn_user_skill_progress` (self-report toggle on lesson/course
   pages), then "recommended next nodes". *This* is when cycle detection / topological traversal earns
   its place (see v1 gaps).
4. **Style classes** — the deferred tables + auto-award logic. Treat thresholds as a tuning problem.
5. **Repertoire nodes** — the deferred tables + acquisition types + affiliate links.
6. **Graph visualization** — admin force-directed view, once node count justifies it.

---

*Document created during brainstorming session — June 2026. v1 implemented & documented as-built 2026-06-23.*
*Continue: `migrate --seed`, review seeded edges, curate remaining branches in the admin editor (Post-v1 Roadmap #1).*

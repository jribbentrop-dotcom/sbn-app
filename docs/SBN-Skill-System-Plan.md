# SBN Skill System — Planning & As-Built Reference

> Status: **v1 SHIPPED + all 6 branches curated, courses mapped (uncommitted) 2026-06-23.**
> Data model, models, seeder, and admin table editor built — see "v1 Implementation (As-Built)".
> `migrate --seed` has run; the graph is now 35 nodes across all six branches with 38 prerequisite
> edges (no cycles, no dangling refs — verified), and `sbn_course_skill_node` is populated for 16 of
> 17 published courses (see "Course → Node Mapping"). Deferred work (style classes, repertoire, graph
> viz, student UI) tracked in "Open Decisions" and "Post-v1 Roadmap".
> Next step: review the Melody/Technique/Ear Training/Reading & Theory nodes and edges in the admin
> editor (they're seeded from the taxonomy first draft, not yet content-evidenced the way Harmony/
> Rhythm are — see caveat in "Course → Node Mapping"), then start closing the curriculum gaps the
> mapping surfaced (Ear Training has zero course coverage; several Technique/Melody/Reading nodes do
> too).

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

**35 nodes across all six branches** (Harmony 10, Rhythm 4, Melody 5, Technique 8, Ear Training 4,
Reading & Theory 4) plus 38 prerequisite edges. Two-pass build (upsert all nodes, then wire edges) so
prereqs can be declared before their target appears. Fully idempotent: `updateOrCreate` on slug,
`insertOrIgnore` on edges. `two-four-feel` carries `content_tag_slug => 'samba'` (a tag already
populated by the categories migration) so the tag bridge returns real rows on day one.

**Two different confidence levels, by design:** Harmony and Rhythm (the original 14) were curated
against actual lesson content — see "Course → Node Mapping" below for the evidence. Melody, Technique,
Ear Training, and Reading & Theory (the 21 added 2026-06-23) are seeded from the taxonomy first draft
only — titles, branches, and musically-sound prerequisite edges, but **not yet checked against lesson
content**. Treat them as a structural placeholder, not a curated graph, until someone does the same
content pass on them that Harmony/Rhythm already got.

**Deliberate cross-branch edges:** the graph is not six independent trees. `arpeggio-shapes` (melody)
requires `triads` (harmony); `improvisation-over-changes` (melody) requires `ii-v-i-major` (harmony);
`interval-recognition` / `chord-quality-recognition` (ear training) require `intervals` / `triads`
(harmony); `rhythm-dictation` (ear training) requires `pulse-subdivision` (rhythm);
`melodic-dictation` (ear training) requires `scale-patterns` (melody); `rhythm-notation` /
`leadsheet-reading` (reading & theory) require `pulse-subdivision` / `triads`. Each is a real
pedagogical dependency, not an arbitrary link — see "Graph not tree" in Key Design Principles.

Verified after seeding (2026-06-23): no duplicate slugs, no dangling prerequisite or course-pivot
references, no self-prerequisites, no two-node cycles.

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

### Course → Node Mapping (curated 2026-06-23)

`sbn_course_skill_node` is populated for 15 of the 16 published courses, against the 14 Harmony/Rhythm
nodes plus the new Melody/Technique branches where lesson titles or content gave clear evidence. Method:
read every lesson's `title` + `section_title` for the course (high precision — these are the actual
authored unit headers), then spot-checked candidate body-text matches for context before including them,
to avoid mapping on incidental keyword mentions (e.g. "triad" used once to explain what a 7th chord is
on top of isn't "this course teaches triads"). Course 5 (*Choro: The Ancestor of Bossa Nova*) is left
**unmapped** — it's pure repertoire with no lesson content that maps cleanly to any current node; that's
a real gap, not an oversight, and shows up correctly in the admin index's Courses=0 column if a Choro
node is ever added.

Mapping by course (id → node slugs):

| Course | Nodes |
|---|---|
| 1 Bossa Nova Basics | two-four-feel, pulse-subdivision, syncopation |
| 2 Easy Bossa Nova Songs | two-four-feel, pulse-subdivision, syncopation, ii-v-i-major, ii-v-i-minor |
| 3 Bossa Nova Chords | intervals, shell-voicings, drop2-voicings |
| 4 Bossa Nova Rhythm | pulse-subdivision, syncopation, two-four-feel, comping-patterns |
| 5 Choro: The Ancestor of Bossa Nova | *(none — see above)* |
| 6 Gilberto plays Jobim | two-four-feel, syncopation, tritone-substitution |
| 7 Latin Side of Pat Metheny | chord-inversions, drop2-voicings, drop3-voicings, triads, syncopation, pulse-subdivision |
| 8 Latin Side of Wes Montgomery | tritone-substitution, comping-patterns, ii-v-i-major, syncopation, pulse-subdivision, two-four-feel |
| 9 Right Hand Technique for Nylon Guitar | pulse-subdivision, syncopation, two-four-feel, fingerpicking-basics, right-hand-independence, thumb-independence, arpeggio-shapes |
| 10 The Clave: Latin Rhythm 101 | pulse-subdivision, syncopation, two-four-feel |
| 11 Melody Playing on Nylon Guitar | scale-patterns, legato-slurs, intervals |
| 12 Music Theory Basics | intervals, triads, pulse-subdivision, syncopation, two-four-feel, scale-patterns, standard-notation-basics, rhythm-notation |
| 68 Solo Guitar Style of Joe Pass | shell-voicings, ii-v-i-major, ii-v-i-minor, chord-melody |
| 69 Diminished Chords — Secret Weapon of Bossa Nova | tritone-substitution, ii-v-i-major, ii-v-i-minor, chord-inversions, intervals |
| 70 Chord Progressions & Voice Leading | triads, chord-inversions, ii-v-i-major |
| 71 The Pentatonic Scale: Five Positions | scale-patterns, pentatonic-scale |
| 72 Intervals: The Building Blocks of Harmony | intervals, interval-recognition |
| 73 The CAGED System | caged-system, scale-patterns, arpeggio-shapes |

**Curriculum gaps this surfaced** (nodes with zero course coverage — legitimate targets for Post-v1
Roadmap #1/#2, not bugs): the entire **Ear Training** branch except `interval-recognition`, which
Course 72 now closes (no course teaches recognition/dictation as a named skill yet otherwise, even
though e.g. Course 9's right-hand work implicitly trains rhythm feel); **Melody**'s
`improvisation-over-changes` and `motivic-development`; **Technique**'s `barre-chords`,
`position-shifting`, `tone-production` (note: `caged-system` is now closed by Course 73 — see below);
**Reading & Theory**'s `leadsheet-reading` and `nashville-number-system`. None of these are mistakes
— they reflect that the current catalog doesn't have dedicated content for them yet.

**Course 73 (2026-06-23)** — built from `SKALEN - CAGED.musicxml` (overview, all 5 shapes) and four
dedicated deep-dive files `SKARPAKK - Die A-Form.musicxml`, `SKARPAKK - Die C-Form.musicxml`,
`SKARPAKK - Die E-Form.musicxml`, `SKARPAKK - Die G-Form.musicxml` (all in `Partituren/Theorie/`).
6 lessons: overview of 5 shapes, then Maj7/min7/dom7 deep-dives for A, C, E, and G shapes, then a
connecting-the-shapes-up-the-neck capstone. **Deferred:** no `SKARPAKK` source file exists for the
D-shape deep-dive; Lesson 1 covers the D-shape briefly from the overview file, but a full
maj7/min7/dom7 lesson for it waits until dedicated source material is ready. Closes `caged-system`
(Technique); also teaches `scale-patterns` and `arpeggio-shapes` (Melody). Consequential: `barre-chords`
and `position-shifting` now have `caged-system` as a closed prerequisite — they remain open nodes and
are good candidates for the next two Technique courses.

**Course 72 (2026-06-23)** — built from `INTERVALLE - Übersicht.musicxml` and `INTERVALLE -
Beispiele.musicxml` (exported from MuseScore via its own batch CLI export, not a custom parser — see
`SBN-MusicXml-Course-Workflow.md` for why that's now the recommended path for bulk `.mscz` conversion).
5 lessons: the eight core intervals, a song-anchor ear-training device (classical/traditional/jazz
mnemonics — minor third/Greensleeves, major third/When the Saints Go Marching In, P4/Bridal Chorus,
P5/Twinkle Twinkle, m6/The Entertainer, M6/My Bonnie, octave/Somewhere Over the Rainbow), diatonic
thirds and sixths, and tenths. The `Beispiele` file (pop/rock riffs) was deliberately **not** used —
it has no embedded song titles and Lucas didn't have time to attribute them in this pass; it's parked
for a follow-up lesson once attributed. The song-anchor list is also flagged in-lesson as a first pass
— seconds, sevenths, and the tritone still need anchors, and a bossa-nova-specific anchor for each
interval is a good Phase 2 addition once a clean candidate is identified per interval.

### Known v1 gaps (deliberate deferrals, not bugs)

- **No cycle detection** — the form allows A→B and B→A. Harmless for a 14-node hand-curated graph;
  add a topological check at the moment something actually *traverses* the graph (student "recommended
  next"), not before.
- **Native multi-selects** — unglamorous but fine for admin-only v1; a tag-style picker is polish.
- **Prod deploy** — handled by `scripts/deploy_db.sh`: it scp's the whole local `sbn.db` up (so the
  four `sbn_*` tables AND seeded nodes ride along — no manual table creation, no remote seeding),
  preserving prod user tables via a dump/restore list. ⚠️ **`sbn_user_skill_progress` is NOT in that
  script's preserve list.** Harmless now (empty on prod), but once students self-report progress on
  prod, the next `deploy_db.sh` run will WIPE it. **Before shipping roadmap #3 (student progress), add
  `sbn_user_skill_progress` to the `TABLES` array in `scripts/deploy_db.sh`.**

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
- **Skill node landing page** (discussed 2026-06-23, not yet built) — a per-node page (node title,
  description, "where this is taught" list pulled from the `courses()` pivot, tag-bridged content via
  `content_tag_slug`) that deep-links into the exact lesson subsection via the existing
  `<h2 id="section-{slug}">` anchors, e.g. `/learn/bossa-nova-chords-ii/play/{lesson}#section-shell-voicings`.
  This is the answer to "courses are too big, a skill is buried inside one" **without** restructuring
  the course catalog into many tiny courses (considered and explicitly rejected — see TBD entry below).

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
| — | Split large courses into per-skill micro-courses (e.g. Bossa Nova Chords II → separate Shell/Drop 2/Drop 3 courses) for "chewable bite" discoverability? | **Discussed & rejected 2026-06-23.** The many-to-many pivot already lets one course teach many nodes without forcing 1:1 alignment; splitting would 3x content-ops overhead (excerpt/description/image/SEO per micro-course) and fragment the narrative path. Chosen alternative: build the skill node landing page (Phase 2, above) as the addressable "chewable" entry point, deep-linking into the existing course via section anchors instead of relocating the content. |

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

1. **Curate remaining branches** — ✅ structurally done 2026-06-23 (21 nodes + edges added for Melody,
   Technique, Ear Training, Reading & Theory — see "Seeder" above). ⏳ Still needs the same
   content-evidence pass Harmony/Rhythm got before it's trustworthy, not just structurally plausible.
2. **Map existing courses** to nodes through the pivot — ✅ done 2026-06-23 for 15/16 published courses,
   see "Course → Node Mapping". Revisit once branches above are content-verified, and whenever a new
   course ships.
3. **Student-facing progress** — surface `sbn_user_skill_progress` (self-report toggle on lesson/course
   pages), then "recommended next nodes". *This* is when cycle detection / topological traversal earns
   its place (see v1 gaps). ⚠️ **First** add `sbn_user_skill_progress` to the `TABLES` preserve list in
   `scripts/deploy_db.sh`, or the next content deploy will wipe real user progress.
4. **Style classes** — the deferred tables + auto-award logic. Treat thresholds as a tuning problem.
5. **Repertoire nodes** — the deferred tables + acquisition types + affiliate links.
6. **Graph visualization** — admin force-directed view, once node count justifies it.

---

*Document created during brainstorming session — June 2026. v1 implemented & documented as-built 2026-06-23.*
*2026-06-23 (later same day): all 6 branches seeded (35 nodes, 38 edges), 15/16 courses mapped to nodes
(see "Course → Node Mapping"). Considered and rejected splitting courses into per-skill micro-courses;
chose a skill-node landing page with deep-link anchors instead (see Phase 2 + TBD table).*
*2026-06-23 (later): Course 73 "The CAGED System" imported (6 lessons); closes caged-system, scale-patterns,
arpeggio-shapes. 16/17 courses now mapped. barre-chords and position-shifting are the next Technique gap.*
*Continue: content-evidence pass on Melody/Technique/Ear Training/Reading & Theory nodes; close the
curriculum gaps the mapping surfaced (Ear Training has no course coverage at all); build the skill-node
landing page when ready to go student-facing.*

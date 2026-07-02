# SBN Skill System — Planning & As-Built Reference

> Status: **v1 SHIPPED + expansion + student UI + admin dashboard rebuild.**
> Data model, models, seeder, and admin table editor built — see "v1 Implementation (As-Built)".
> Graph is now **57 nodes** across all six branches with **60 prerequisite edges** (no cycles, no
> dangling refs — verified), and `sbn_course_skill_node` has **98 pivot rows** covering all 20
> published courses (see "Course → Node Mapping"). Self-report progress works end-to-end
> (`/account/skills`, course-detail page, in-lesson `CourseSkillTracker`). **`grade` now 100% populated
> across all 5 tiers** (G1=13, G2=19, G3=17, G4=3, G5=5) and READ by `SkillGradeService` (level computed
> from node completion). **Style dimension built** (`sbn_skill_node_style`). Skill-tree (pillar 6):
> positions schema + auto-layout + admin drag editor BUILT; **student-facing render SHIPPED 2026-07-02**
> at `/account/skills/tree` — see "Student Skill Tree (Shipped 2026-07-02)" below and
> `SBN-Skill-Tree-Design-Brief.md` §8/§9 for the as-built design. `icon_key` set on 50 nodes; `icon_path`
> (custom SVG) on 0.
> **Node ↔ content links BUILT 2026-06-29** (Step A): rhythms/progressions/songs via polymorphic pivot
> `sbn_skill_node_content`; chord voicings via a `voicing_categories` column (category, not per-diagram);
> exercises excluded (course-only). Reverse relations on each content model power "skills this builds".
> Admin search+chips picker. See "Node ↔ Content Links".
> **Bossa/samba rhythm ladder added 2026-06-29** (from cross-referencing `Skill Nodes.docx` brainstorm —
> see `docs/archive/SBN-Skill-Nodes-Brainstorm-Crossref.md`): 3 new rhythm nodes (`bossa-syncopated-push`,
> `alternating-bass-patterns`, `partido-alto-groove`) wired between `two-four-feel` and
> `brazilian-rhythm-styles`/`clave-systems`. Graph is now **61 nodes** / **69 prerequisite edges**.
> Draft courses 77/78/79 (previously zero-mapped) and courses 1/4 mapped to relevant nodes —
> `sbn_course_skill_node` now has **118 pivot rows** covering **all 24 courses**. `the-basic-8` and
> `foundational-scales` descriptions clarified to make explicit they cover open chords / open scale
> shapes (no new beginner-tier nodes needed — confirmed existing nodes already serve that role).
> Drop Chord System granularity reviewed and left as-is (one node per voicing type; decision confirmed,
> not revisited).
> **Admin dashboard rebuilt 2026-06-29** (spec now `docs/archive/SBN-Admin-Dashboard-Spec.md`): stale migration-progress card
> removed; `/admin` now shows Totals (9 tiles incl. courses/lessons/skill-nodes), Recently Edited feed
> (last 12 across 7 content types), and a Content Health card (10 gap counts, each linking to the new
> `/admin/skill-nodes/coverage` drill-down). `ContentHealthService` owns all gap queries; coverage page
> lists offending rows per section with collapsible `<details>` panels. `sbn_chord_progressions` gains
> `updated_at` (migration 2026_06_29_000003, backfilled from `created_at`). Orphan `dashboard.blade.php`
> deleted.
> **Remaining vs full vision** (see "Vision → Reality Reconciliation"): student skill-tree render,
> player-class/style-class tables (pillar 5), repertoire tables, technique sub-curriculum (PIMA,
> rest/free stroke, posture, etc. — pending course 9 rewrite, see brainstorm crossref recommendation #1).
> Tracked in "Open Decisions" / "Post-v1 Roadmap".
> **Course 9 technique rewrite APPLIED 2026-06-29** (recommendation #1): 9 new foundational lessons
> (posture/setup/tuning, PIMA naming, rest vs. free stroke, nail/flesh tone, right-hand arpeggio
> patterns, thumb independence/bass lines, damping, hammer-ons/pull-offs, slides/vibrato) + 4 new
> technique nodes (`guitar-posture-setup`, `pima-finger-assignment`, `rest-stroke-free-stroke`,
> `hand-damping-control`, ids 65–68, grades 1/1/1/2) with prereq edges (posture→pima→rest/free;
> damping requires right-hand- + thumb-independence). All 4 mapped to course 9 (now 13 node mappings).
> The 3 existing repertoire-study lessons (Villa-Lobos étude, Tárrega/Sor, João Gilberto batida)
> resequenced to the end (sort_order 9–11), content/title/slug untouched. Course 9 now has 12 lessons;
> graph is **65 nodes**. Applied via `scripts/apply_course9_rewrite.py` against a healthy `sbn.db`
> (the truncation noted earlier was resolved/restored before this run — verified `integrity_check: ok`,
> 54,210,560 bytes). Full draft: `docs/Course-9-Technique-Rewrite-Full-Draft-2026-06-29.md`. The script
> is idempotent and also lives in `SkillNodeSeeder.php` for fresh-seed parity.

---

## Core Concept

Replace large monolithic courses with **atomic skill nodes** that students accumulate like RPG character stats. Progress through the skill graph unlocks **style classes** (emergent musical identity). **Repertoire pieces** function as equipment — practical tools that increase real-world musical competence.

The system is a **recommendation and visualization engine**, not a hard-lock gate. All content remains accessible; the skill tree guides without blocking.

---

## Vision → Reality Reconciliation (2026-06-25)

The full RPG/FIFA-style vision has **six pillars**. This section is the honest map of what's
load-bearing vs aspirational, so we sequence the remaining work against the real data model — not the
brainstorm. (DB-verified 2026-06-25.)

| # | Vision pillar (RPG/FIFA analogy) | Status | Notes |
|---|---|---|---|
| 1 | **5 difficulty grades** = character level | ⚠️ exists but **disconnected** | Courses carry a `level` string; nodes carry a `grade` int (1–4). Neither talks to the other. |
| 2 | **Nodes = glue between grade leaps / keys to advance** | ❌ designed, not built | This is the "Option B" grade-threshold logic (below) — deferred as a tuning problem. The keystone of pillar 1. |
| 3 | **Nodes interconnected + categorised** (rhythm/harmony/…) | ✅ **real** | 57 nodes, 60 prereq edges, 6 branches, 98 course mappings. The solid spine. |
| 4 | **Nodes related to styles** (walking-bass→jazz, partido-alto→bossa) | ❌ **no data** | Nodes have `branch`/`sub_branch` = musical *category*, NOT style. No node↔style link, no genre on nodes. **Biggest mismatch with the vision.** |
| 5 | **Character class / player style** (Jazz / Bossa / Classical / Pop player) | ❌ deferred | `style_classes` table never built. Cannot exist until pillar 4 gives it data. |
| 6 | **Complex skill-tree visualisation** | ❌ not built | The gamification payoff. Flagged "take to Opus first" (SVG vs canvas, hand-laid x/y). |

**The dependency order that falls out of this** (and the agreed build sequence):

1. **Style dimension first** (closes pillar 4). ✅ **BUILT 2026-06-25.** `sbn_skill_node_style` pivot
   (`skill_node_id`, `style`, `weight` 1–3), migration `2026_06_25_000001`. `SkillNode::STYLES` =
   `[bossa-nova, jazz, classical, pop]` (same controlled vocab courses use in `genres` — **deliberately
   NOT the freeform `sbn_tags` cloud**, which is vibe-flavoured and morphed to content, not nodes).
   `SkillNode::styleWeights()` / `syncStyles()` accessors. `SkillNodeStyleSeeder` tags 34 of 57 nodes
   (jazz 23, bossa 19, classical 13, pop 7); foundational nodes (intervals/triads/meter/scales/notation)
   left untagged = neutral. Admin editor exposes a per-style weight selector + index shows Grade/Styles
   columns. Weight semantics: 1=touches, 2=toolkit, 3=definitional. This drives pillars 5 + tree colour.
2. **Grade-threshold logic** (closes pillar 2 → activates pillar 1). ✅ **BUILT 2026-06-25.**
   `App\Services\SkillGradeService`: a grade is *cleared* at ≥70% of its nodes done (`THRESHOLD`, the
   one tunable knob); *level* = highest grade G where every grade 1..G is cleared (no skipping), capped
   at the highest grade that actually has nodes. Ungraded nodes count toward nothing (neutral). Empty
   grades are vacuously cleared. Surfaced on `/account/skills` as a level badge + per-grade progress bars
   with a threshold tick; the Vue page recomputes the level **live** as the student toggles (mirrors the
   service; server payload supplies threshold + labels). Verified: empty→L0, all g1+g2→L2 (doesn't skip
   to L3 despite g3 having nodes). Grade chosen as **% of each grade's nodes** rule (2026-06-25). Course
   `level` string is NOT the source (only 5/20 set) — nodes are the only viable grade signal.
3. **Skill-tree viz** (pillar 6) — only after 1+2, so it has edges + grade tiers + style clusters +
   completion to show. Building it first means rebuilding it.
4. **Style classes** (pillar 5) — rides on pillar 4's data once thresholds are a solved pattern from #2.

**Honest caveat:** pillars 2 and 5 are *the same unsolved tuning problem* — "how many of which nodes =
you've earned this grade / class?" The schema is mechanical; the threshold *design* is the genuinely
hard part, which is exactly why both were deferred. Everything else here is plumbing.

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
| 9 Right Hand Technique for Nylon Guitar | *(rewrite drafted 2026-06-29, not yet applied — see status block above)*: guitar-posture-setup, pima-finger-assignment, rest-stroke-free-stroke, tone-production, right-hand-independence, thumb-independence, hand-damping-control, legato-slurs, fingerpicking-basics, pulse-subdivision, syncopation, two-four-feel, arpeggio-shapes |
| 10 The Clave: Latin Rhythm 101 | pulse-subdivision, syncopation, two-four-feel |
| 11 Melody Playing on Nylon Guitar | scale-patterns, legato-slurs, intervals |
| 12 Music Theory Basics | intervals, triads, pulse-subdivision, syncopation, two-four-feel, scale-patterns, standard-notation-basics, rhythm-notation |
| 68 Solo Guitar Style of Joe Pass | shell-voicings, ii-v-i-major, ii-v-i-minor, chord-melody |
| 69 Diminished Chords — Secret Weapon of Bossa Nova | tritone-substitution, ii-v-i-major, ii-v-i-minor, chord-inversions, intervals |
| 70 Chord Progressions & Voice Leading | triads, chord-inversions, ii-v-i-major |
| 71 The Pentatonic Scale: Five Positions | scale-patterns, pentatonic-scale |
| 72 Intervals: The Building Blocks of Harmony | intervals, interval-recognition |
| 73 The CAGED System | caged-system, scale-patterns, arpeggio-shapes |
| 74 Diatonic Chords & the Nashville Number System | nashville-number-system, leadsheet-reading |
| 75 Arpeggio Shapes: The Five Chord Qualities | arpeggio-shapes |
| 76 Approach Notes & Enclosures | motivic-development, improvisation-over-changes |

**Curriculum gaps this surfaced** (nodes with zero course coverage — legitimate targets for Post-v1
Roadmap #1/#2, not bugs): the entire **Ear Training** branch except `interval-recognition`, which
Course 72 now closes (no course teaches recognition/dictation as a named skill yet otherwise, even
though e.g. Course 9's right-hand work implicitly trains rhythm feel); **Melody**'s
`improvisation-over-changes` (Course 76 contributes toward it but doesn't fully close it alone —
its other prerequisites `arpeggio-shapes` and `ii-v-i-major` are now also covered by courses 75 and
existing courses respectively, so a student completing those three courses satisfies the node);
**Technique**'s `barre-chords`, `position-shifting`, `tone-production` (note: `caged-system` is now
closed by Course 73); **Reading & Theory**'s `standard-notation-basics` and `rhythm-notation`
(note: `nashville-number-system` and `leadsheet-reading` are now closed by Course 74). None of these
are mistakes — they reflect that the current catalog doesn't have dedicated content for them yet.

**Courses 74–76 (2026-06-23)** — three intermediate courses imported together.
- **74 Diatonic Chords & the Nashville Number System** — source: `THEORIE - Stufenvierklänge.musicxml`
  (34 measures, m1-22 used; m11-18 skipped due to pitch/chord-symbol mismatch artifact; m23-34 deferred
  as better suited for course 69). 4 lessons: diatonic ladder, chords in pairs, ii-V-I cadence, secondary
  ii-V to vi. Closes `nashville-number-system` and `leadsheet-reading` (Reading & Theory).
- **75 Arpeggio Shapes: The Five Chord Qualities** — source: four `ARPEGGIOS - Vierklänge (*-Form).musicxml`
  files (15 measures each, all 60 measures verified pitch-by-pitch). 6 lessons: intro to the five
  qualities, then A/C/E/G-shape 2-octave arpeggios, then synthesis. Closes `arpeggio-shapes` (Melody).
  Uses different roots per shape than the CAGED course (by design — different source files).
- **76 Approach Notes & Enclosures** — source: `JAZZ - Umspielungen.musicxml` (221 real measures,
  m1-105 used as lesson content; m106-221 practice drills summarised structurally, not transcribed
  measure-by-measure). 6 lessons: single approach notes, melodies you know, longer forms, basic
  enclosure, multi-step enclosures, drills. Closes `motivic-development`; contributes toward
  `improvisation-over-changes` (which also requires arpeggio-shapes + ii-v-i-major, now both covered
  by courses 75 and existing courses). Real-tune references all use `[NOTATION: ...]` placeholders.

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
  preserving prod user tables via a dump/restore list. ✅ **`sbn_user_skill_progress` was added to that
  script's `TABLES` preserve list 2026-06-25** (it's per-student self-report data — without it the next
  deploy would overwrite real progress with the empty local table). Caveat: the restore connection
  doesn't enable SQLite FK enforcement, so deleting a node locally would leave preserved progress rows
  orphaned (dangling, not erroring) — fine for the add-only seeding done so far, but worth knowing
  before any node deletion.

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

## Grade ↔ Skill Node Bridge (decided 2026-06-24)

The 5 difficulty grades (`basic` → `advanced`) and the skill node graph are currently two separate
systems. **Design decision: nodes are the fine-grained step structure *inside* each grade transition.**
Grades are the big chapters; nodes are the sentences within them.

**Chosen model (Option B — nodes define grade thresholds):**
Each grade boundary is defined by a set of skill nodes that must be completed to "enter" that grade.
Concretely: a student is considered **grade 3 (Intermediate)** when they have completed the threshold
node set for grade 3. The grade is soft-computed from node progress, not a manually assigned field.

Implementation sketch (not yet built):
- Add a nullable `grade` int (1–5) column to `sbn_skill_nodes` — the grade this node *belongs to*
  (i.e. mastering it is part of reaching that grade). A node may span two grades; `grade` is its
  primary placement.
- Add a `grade_min` / `grade_max` pair (nullable ints) as an alternative if placement range matters
  more than a single assignment (e.g. `shell-voicings` sits at grade 2–3).
- Grade threshold = a sufficient subset of that grade's nodes being completed. Exact threshold
  percentages are a tuning problem (defer, same class as style class thresholds).
- The grades page (`/grades`) currently shows content filtered by a fixed `difficulty` int on each
  content row. Once node-progress exists it can add a "your level" indicator derived from node
  completion — no schema change to content rows needed.

This is **not yet built** — the column additions + threshold logic are the next schema step after
the icon system is settled.

---

## Icon System (decided 2026-06-24)

Skill nodes need icons for the tree view, node landing pages, and inline badges wherever nodes appear
around the app.

**Two-tier approach:**

### Tier 1 — Branch icons (Heroicons, permanent)
Six branch-level icons using inline Heroicons SVGs (same style as the mega menu: `stroke-width="1.5"`,
`stroke="currentColor"`, 24×24). These are meaningful, consistent, and done:

| Branch | Heroicon |
|---|---|
| Harmony | `musical-note` |
| Rhythm | `clock` (or `adjustments-horizontal`) |
| Melody | `microphone` |
| Technique | `hand-raised` |
| Ear Training | `speaker-wave` |
| Reading & Theory | `book-open` |

### Tier 2 — Per-node icons (placeholder → custom)
Individual nodes need purpose-drawn icons (`tritone-substitution`, `drop2-voicings`, etc. don't exist
in any general icon library). **Two-phase plan:**

**Phase A (now):** Add two nullable columns to `sbn_skill_nodes`:
```
icon_key   TEXT NULL   -- Heroicon name used as placeholder, e.g. "musical-note"
icon_path  TEXT NULL   -- Custom SVG path, e.g. "images/skills/shell-voicings.svg" — takes priority
```
A small Vue component (`SkillIcon.vue`) renders: if `icon_path` is set → `<img>`, else → inline
Heroicon SVG looked up by `icon_key`, else → branch icon fallback. Nodes within the same branch
default to the branch icon until a specific one is assigned.

**Phase B (design task):** Custom per-node SVG icons created in Canva or Illustrator, exported as
SVGs, dropped into `public/images/skills/`, and the `icon_path` column updated. No code change
required — the component already handles it.

This means the frontend tree and node landing pages can be built now using Heroicon placeholders,
and the icon upgrade is a pure design/content task that doesn't touch any Vue components.

---

## Open Decisions (TBD)

| # | Question | Status |
|---|---|---|
| 2 | Student UI: simplified tree vs full graph view | Decide at Phase 2 |
| 6 | Do existing courses map 1:1 to skill nodes, or can one course teach multiple? | **Resolved: many-to-many (`course_skill_node` pivot)** |
| 7 | Where does repertoire ownership live in the user profile? | TBD (post-v1; repertoire is not in v1) |
| — | Quiz format and completion threshold | Deferred (v1 = self-report only) |
| — | Style class trigger thresholds (how many nodes = class awarded?) | Deferred out of v1 |
| — | Grade threshold percentages (what % of a grade's nodes = you've reached that grade?) | Deferred — same tuning-problem class as style class thresholds |
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
still expected post-v1. ✅ **Built 2026-06-29 — see "Node ↔ Content Links" below.**

---

## Node ↔ Content Links (Step A — BUILT 2026-06-29)

The precise content link the tag bridge above always said would follow. Replaces the lossy
`content_tag_slug` path as the primary mechanism (tag bridge kept as fallback). Powers two
directions: **forward** = a (future) node landing page lists "content that builds this skill";
**reverse** = a rhythm/progression/chord/song detail page shows "skills this builds" (`$model->skillNodes`).

**Two storage shapes, by content type — a deliberate split:**

1. **Rhythms, progressions, songs → polymorphic pivot `sbn_skill_node_content`.**
   Migration `2026_06_29_000001`. Columns: `skill_node_id` (FK cascade), `morphs('content')`
   (`content_type` = FQCN, parallel to `sbn_taggables`; NO morphMap enforced app-wide, so full class
   names are stored), `sort_order`, timestamps; `unique(skill_node_id, content_type, content_id)`.
   - `SkillNode`: `rhythmPatterns()` / `chordProgressions()` / `leadsheets()` (`morphedByMany`),
     plus `linkedContent()` grouper.
   - Reverse `skillNodes()` (`morphToMany`) on `RhythmPattern`, `ChordProgression`, `Leadsheet`.
   - **Songs are the "equipment" link** (vision pillar = repertoire). A song maps to the nodes it
     exercises; "ready vs stretch" can later be computed from those nodes' prereq completion — WITHOUT
     building the deferred `repertoire_nodes` table. That table (prestige tiers, acquisition type,
     affiliate links) is still post-v1; this pivot delivers the collectible feel now.
   - **Exercises deliberately EXCLUDED** — they surface only inside courses, not on public detail
     pages, so they don't belong on this public-facing link. Course-internal teaching is already
     covered by `sbn_course_skill_node`.

2. **Chord voicings → category column `voicing_categories` (JSON) on `sbn_skill_nodes`.**
   Migration `2026_06_29_000002`. NOT the pivot. Rationale: a node like `drop2-voicings` **IS** the
   category — listing 47 individual diagrams is wrong-grained and goes stale as the library grows.
   The node stores the `voicing_category` keys it teaches (e.g. `["drop2"]`); `SkillNode::chordDiagrams()`
   resolves them via `ChordDiagram::whereIn('voicing_category', …)` so **new voicings are covered
   automatically**. Categories come from the existing `ChordDiagram::VOICING_CATEGORIES` constant
   (~10: archetype, drop2, drop3, shell, rootless, closed, closed_triads, spread_triads, slash, custom).
   Reverse `ChordDiagram::skillNodes()` uses `whereJsonContains('voicing_categories', $this->voicing_category)`
   (needs SQLite JSON1 — standard; first thing to check if a reverse panel ever misbehaves on prod).
   *Why no category equivalent for rhythms/progressions/songs:* there's no "category = the skill"
   relationship there — a song is a specific song.

**Admin editor** (`SkillNodeController` + `skill-nodes/edit.blade.php`): rhythms/progressions/songs use
a reusable **search + chips** picker (`admin/_partials/content-picker.blade.php`) — Alpine-based to
match the house pattern (`courses/_form.blade.php` chips, `sbn-tag-chip` CSS, Alpine already loaded in
`layouts/admin.blade.php`); data rendered into a `<script type="application/json">` (NOT inline in
`x-data`) so apostrophes in titles can't break the attribute; results capped at 50. Voicings use a
category toggle palette (`sbn-tag-preset`). Picker chosen over the Vue `tab-editor/ChordPicker` (which
stays tab-editor-only) and over native `<select multiple>` (unusable at 265 rows).

**Seeded starter links (2026-06-29, illustrative):** `two-four-feel`→{gilberto, clave rhythms, Ipanema},
`syncopation`→{samba, partido-alto}, `ii-v-i-major`→{a ii-V progression, Ipanema}, plus voicing
categories `shell-voicings`→`["shell"]` (33 diagrams), `drop2-voicings`→`["drop2"]` (47).

**Deploy:** both migrations + the data ride along via `scripts/deploy_db.sh` (whole-DB scp). Worth
folding into the schema-as-migrations housekeeping follow-up so prod isn't dependent on the scp'd file.

### Step B — student-facing "Skills this builds" panel (BUILT 2026-06-29)

The first *student-facing* surface for the content links (was `SBN-Skill-StepB-Spec.md` → `docs/archive/`, now folded
here). Shared **`resources/js/Components/Skill/SkillsBuiltPanel.vue`** on all four library detail
pages (`Library/{Songs,Rhythms,Progressions,Chords}/Show.vue`); each `show()` controller passes a
`skills` array via the reverse relations. Per-node completion ✓ for the logged-in student (the
`flip()`-into-lookup pattern copied from `CourseController::courseSkills()`). Empty → panel renders
nothing (`v-if`). **Gotchas Sonnet got right:** chord uses `$chord->skillNodes()` (METHOD —
category-resolved Collection) while song/rhythm/progression use `$model->skillNodes` (relation
property); the panel has **no scoped `<style>`** — all `sbn-skills-built-*` classes live in
`sbn-design-system.css` (theme-switch safe, per `SBN-Design-Reference.md`). Nodes render as
non-links for now (node landing page = Phase 2, not built). Audited 2026-06-29: counts/relations
verified, `.sbn-table-wrap` class fix + skill-nodes-index "Coverage" link added during audit.

### Admin content-health dashboard (BUILT 2026-06-29)

Was `SBN-Admin-Dashboard-Spec.md` (→ `docs/archive/`), now folded here. `/admin` rebuilt: stale migration-progress card
removed, orphan `dashboard.blade.php` deleted. Three zones — Totals (9 tiles), Recently Edited (last
12 across 7 content types, `updated_at`-sorted; progressions gained `updated_at` via migration
`2026_06_29_000003` backfilled from `created_at`), Content Health (10 gap counts). All gap queries
live in **`App\Services\ContentHealthService`** (`summary()` for counts, `details()` for lists),
reused by the new **`/admin/skill-nodes/coverage`** drill-down (`SkillNodeController@coverage` +
`coverage.blade.php`, collapsible `<details>` per gap, `:target` highlight from dashboard anchor
links). Coverage reachable from the skill-nodes index ("📊 Coverage" action) + the dashboard.

---

## Existing SBN Integration Points

- **Tag system** — used as a node's optional content-discovery bridge via `content_tag_slug`
  (see "Tag System Integration"). Nodes are NOT themselves tags.
- **Course/lesson structure** — existing courses get mapped to skill nodes over time
- **Chord/tab database** — skill nodes link directly to rhythms / progressions / songs (pivot) and
  chord-voicing categories (node column) — see "Node ↔ Content Links". Exercises are NOT linked
  (course-only content).
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
3. **Student-facing progress** — ✅ a first cut shipped 2026-06-23 (`/account/skills`, commit `309e555`):
   per-branch grid of self-report toggle cards backed by `sbn_user_skill_progress`, with `SkillIcon.vue`
   (3-tier icon fallback) and account-nav links. ✅ 2026-06-25 (commit `1baeae4`): "Skills you'll build"
   section on the course detail page (`/learn/{course}`, `CourseController::show()` → `Courses/Show.vue`)
   with the same inline toggle for signed-in students (POSTs to `account.skills.toggle`, syncs with
   `/account/skills`); guests see it read-only and clicking routes to `/register`. ✅ 2026-06-25 (commit
   `0da79c3`): `CourseSkillTracker.vue` — a collapsible self-report panel *inside the lesson player*
   (`Courses/Player.vue`, gated to enrolled students), backed by a shared `CourseController::courseSkills()`
   helper. **Self-report toggles on lesson/course pages = DONE.** Next: "recommended next nodes". *That* is
   when cycle detection / topological traversal earns its place (see v1 gaps). ✅ `sbn_user_skill_progress`
   added to the `TABLES` preserve list in `scripts/deploy_db.sh` 2026-06-25 (prerequisite for prod).
4. **Style classes** — the deferred tables + auto-award logic. Treat thresholds as a tuning problem.
5. **Repertoire nodes** — the deferred tables + acquisition types + affiliate links.
6. **Graph visualization (student-facing skill tree)** — ✅ **SHIPPED 2026-07-02** at
   `/account/skills/tree` — see "Student Skill Tree (Shipped 2026-07-02)" below for the as-built
   design, including how the multi-style-tile question actually resolved.

---

## Student Skill Tree (Shipped 2026-07-02)

The student-facing render at `/account/skills/tree`, resolving the two items that had gated it
(design brief's open multi-style-tile question, and the missing mobile pass).

**Multi-style resolution — split into tabs, not multi-color tiles.** Mid-build, a full ~64-node
single tree read as too dense/intense to a real student trying it. Rather than solve "how does one
tile show 2+ style colors," the tree was split into **5 tabs**: **Foundations** (nodes with no style
tag — the neutral grade-1 base) plus one tab each for **Bossa Nova / Jazz / Classical / Pop**. Each
style tab shows Foundations nodes (the shared base every style climbs from) plus that style's tagged
nodes. A node's tile color is still resolved by `SkillNode::styleColor()` — dominant weight wins, tie
priority `bossa-nova > jazz > classical > pop` — but that only matters for a node's own tab coloring
now, not for representing multiple styles on one tile. Real counts: Foundations 27, Bossa Nova 49
(27+22), Jazz 53 (27+26), Classical 43 (27+16), Pop 37 (27+10).

**Tile density fix.** The seeded `pos_x`/`pos_y` grid was hand-laid assuming all ~64 nodes shared one
canvas (grade 3 alone had 18 nodes across 1000 design units — some 1 unit apart, effectively stacked).
Filtering to a tab's subset doesn't fix this alone, since the original crowded x-coordinates are still
crowded. `SkillTree.vue` re-packs each visible tier's x-positions client-side per active tab (even
spacing with a minimum gap, falling back to a full-width spread when a tier is dense), preserving
left-to-right order from the seeded data as a stable tiebreak.

**Visual design** (iterated against real project design tokens, not the design brief's placeholder
mockup colors): white canvas background; tiles are frameless — no border/box/shadow, the icon itself
(colored via the style token, `currentColor`) is the only visible element, sized up (40px) so it reads
as a standalone mark; completion state is a colored `drop-shadow` glow + small check/lock badge rather
than a box treatment. Style tabs reuse the existing library filter-pill idiom
(`.sbn-lib-sidebar-option`/`.sbn-filter-active` — quiet outline pill at rest, `--cat-clr`-driven fill
only when active) instead of inventing new per-style buttons, plus an active-tab banner matching
`.sbn-lib-category-header--{style}`'s two-tone gradient treatment. The node-detail popover shows a
large hero icon (96px box) of the clicked node above its title, a `.sbn-btn-secondary` (plain, not
the orange gradient CTA) for the complete/incomplete toggle, and prerequisites rendered as an
icon+title+checkmark list (each prereq's own icon/style color) instead of plain bullet text. All new
classes live in `sbn-design-system.css` (not Vue scoped styles), per the existing `[data-theme]`
constraint.

**Files:** `SkillController::tree()` + `/account/skills/tree` route; `SkillTree.vue` (page),
`SkillTile.vue`, `SkillTreeEdges.vue`, `SkillTreeMobile.vue` (one-branch-at-a-time collapse below
768px, cross-branch prereqs shown as text notes); `SkillNode::styleColor()` accessor;
`resources/js/Constants/skillBranches.ts` (branch order/labels, extracted from `Skills.vue` for
reuse). Mobile: one branch at a time within the active style tab, matching `Skills.vue`'s existing
card visual language rather than a new mobile-only design.

**Not done:** custom per-node SVG art (`icon_path`, still Heroicon fallbacks for all nodes);
"recommended next nodes" / topological traversal; automated tests (manual QA only, matching how the
rest of this feature area has shipped).

---

*Document created during brainstorming session — June 2026. v1 implemented & documented as-built 2026-06-23.*
*2026-06-23 (later same day): all 6 branches seeded (35 nodes, 38 edges), 15/16 courses mapped to nodes
(see "Course → Node Mapping"). Considered and rejected splitting courses into per-skill micro-courses;
chose a skill-node landing page with deep-link anchors instead (see Phase 2 + TBD table).*
*2026-06-23 (later): Course 73 "The CAGED System" imported (6 lessons); closes caged-system, scale-patterns,
arpeggio-shapes. barre-chords and position-shifting are the next Technique gap.*
*2026-06-23 (later): Courses 74–76 imported (16 lessons total). 74 closes nashville-number-system +
leadsheet-reading; 75 closes arpeggio-shapes; 76 closes motivic-development + contributes to
improvisation-over-changes. 19/20 courses now mapped.*
*2026-06-23 (later): Skill node expansion seeded — 15 new nodes (Rhythm +6: meter-basics, waltz-feel,
swing-feel, polyrhythm, clave-systems, brazilian-rhythm-styles; Harmony +7: diatonic-harmony,
cadences, pop-progressions, turnarounds, secondary-dominants, borrowed-chords, voice-leading;
Reading & Theory +2: scale-degrees, tab-reading-basics). 3 existing nodes updated with new prereqs
(pulse-subdivision←meter-basics, leadsheet-reading←scale-degrees, nashville-number-system←scale-degrees).
7 courses gained additional node mappings (70, 5, 10, 7, 8, 12, 69). Graph: 53 nodes, 57 edges,
97 pivot rows, all 20 courses mapped. `fretboard-note-names` deliberately deferred — no lesson yet.*
*Continue: content-evidence pass on Melody/Technique/Ear Training/Reading & Theory nodes; close the
curriculum gaps the mapping surfaced (Ear Training has no course coverage at all); build the skill-node
landing page when ready to go student-facing.*
*2026-06-29 (later): Course 9 ("Right Hand Technique for Nylon Guitar") foundational rewrite drafted in
full per brainstorm crossref recommendation #1 — 9 new lessons + 4 new technique skill nodes
(`guitar-posture-setup`, `pima-finger-assignment`, `rest-stroke-free-stroke`, `hand-damping-control`),
seeder updated. Blocked from applying to the DB this session by genuine file truncation on
`database/sbn.db` (host-side, confirmed via `db_checkout.py status`, not a mount-flakiness retry case).
Draft + idempotent apply script left ready for the next session once the file is restored.*
*2026-07-02: Student-facing skill tree shipped at `/account/skills/tree` — see "Student Skill Tree
(Shipped 2026-07-02)" above. Resolves Post-v1 Roadmap #6 and the design brief's open multi-style-tile
and mobile-pass items.*

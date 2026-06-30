# Handover: §2 — Ear Training Branch

## What you're doing

The skill tree has an `ear-training` branch with 4 nodes but it's almost completely unimplemented — no dedicated course, no content rows, and no content type in the app that supports listening drills. This task is part research/decision, part build.

## App context

Laravel + SQLite guitar education app at `C:\Users\info\sbn-app`. The skill tree (`sbn_skill_nodes`) is a curriculum graph. The front-end player surfaces skill nodes per course, and `sbn_skill_node_content` links each node to actual content (lessons, exercises, rhythm patterns, leadsheets). The ear-training branch currently has nodes defined but nothing backing them.

## DB workflow — critical

Never write directly to the mounted DB. Always:

```bash
# 1. checkout
WORK=$(python3 scripts/db_checkout.py checkout)

# 2. all reads/writes go against $WORK (native local copy)

# 3. commit only when done
python3 scripts/db_checkout.py commit
```

Use Python `sqlite3` against the work path. PHP/artisan not available in sandbox.

Sandbox path mapping: `C:\Users\info\sbn-app\` → `/sessions/.../mnt/sbn-app/`

## The 4 ear-training nodes

| id | slug | title | grade | current course |
|---|---|---|---|---|
| 28 | interval-recognition | Interval Recognition | 2 | intervals-building-blocks-of-harmony (id 72) |
| 29 | chord-quality-recognition | Chord Quality Recognition | 3 | *(none)* |
| 30 | rhythm-dictation | Rhythm Dictation | 3 | *(none)* |
| 31 | melodic-dictation | Melodic Dictation | 4 | *(none)* |

Prerequisites already set (check via `sbn_skill_node_prerequisites`):
- `interval-recognition` is grade 2, so it likely requires `intervals` (harmony branch, grade 1)
- Query: `select requires_skill_node_id from sbn_skill_node_prerequisites where skill_node_id in (28,29,30,31)`

## The core problem

`sbn_skill_node_content` can reference: `App\Models\Lesson`, `App\Models\RhythmPattern`, `App\Models\Leadsheet`, `App\Models\ChordProgression`, `App\Models\Exercise`. None of these content types support interactive listening/dictation drills. Interval recognition, chord quality recognition, rhythm dictation, and melodic dictation require the student to *hear* something and identify it — a fundamentally different interaction than reading a lesson or playing a rhythm pattern.

## Decision needed before building

**Option A — Fold into existing courses, use lessons as content**
Link each node to the closest existing lesson that *introduces* the concept aurally (e.g. interval-recognition → a lesson in "Intervals: Building Blocks of Harmony" that covers listening). This is a pragmatic shortcut — the node is "taught" by a lesson even if there's no dedicated listening drill. Low effort, imperfect.

**Option B — Build a standalone Ear Training course**
Create a new course (`ear-training-fundamentals` or similar) with lessons for each node, then link via `sbn_skill_node_content`. The lessons themselves would need to explain the concepts and point students to an external tool (e.g. Teoria, EarMaster) or embed a future interactive component. Medium effort.

**Option C — Build a new content type for drills**
Add a new table (e.g. `sbn_ear_training_exercises`) and a new `content_type` value. Requires PHP model + migration + Vue component — this is a full feature build, not a DB-only task. High effort, proper long-term solution.

**Option D — Defer ear-training entirely**
Leave nodes in the graph unlinked, hide the branch from the player UI until a proper drill system exists. Add a `status='stub'` flag or similar to suppress them. No content risk.

## Key tables to query

```sql
-- current prerequisites for ear-training nodes
select snp.skill_node_id, sn1.slug as node, sn2.slug as requires
from sbn_skill_node_prerequisites snp
join sbn_skill_nodes sn1 on sn1.id = snp.skill_node_id
join sbn_skill_nodes sn2 on sn2.id = snp.requires_skill_node_id
where snp.skill_node_id in (28,29,30,31);

-- lessons in the Intervals course (id 72) — closest existing course
select id, slug, title, sort_order from sbn_lessons
where course_id = 72 order by sort_order;

-- all courses
select id, slug, title, status from sbn_courses order by id;
```

## If going with Option A or B — example inserts

```python
# Link node to a lesson
cur.execute("""
    insert into sbn_skill_node_content (skill_node_id, content_type, content_id, sort_order)
    values (?, 'App\\Models\\Lesson', ?, 0)
""", (28, <lesson_id>))

# Link node to a course (sbn_course_skill_node)
cur.execute("""
    insert into sbn_course_skill_node (course_id, skill_node_id) values (?, ?)
""", (<course_id>, 29))
```

## What's already been done (don't redo)

- Nodes 29, 30, 31 have no course links — that's expected and was left intentional pending this decision
- Node 28 (`interval-recognition`) is already linked to `intervals-building-blocks-of-harmony` (course 72)
- No content rows exist for any of the 4 ear-training nodes
- All other branches (harmony, rhythm, melody, technique, reading-theory) are being handled in a separate parallel session (§1 handover)

## Recommendation

Start by querying the prerequisites and the lessons in course 72 to see what's actually there. Then present the four options above to the user for a decision before doing any writing. The product decision (which option) determines whether this is a 30-minute DB task or a multi-day feature build.

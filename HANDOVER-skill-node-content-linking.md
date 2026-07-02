# Handover: §1 — Skill Node Content Linking

> **✅ COMPLETE 2026-07-02** (verified against `sbn.db`). This handover is now history. The pass
> described below was carried out: **62 of 64 nodes now have content** (510 `sbn_skill_node_content`
> rows) across all six branches. The only two still unlinked are `blues` and `blues-scale` — the
> "no course exists, skip for now" nodes this doc itself parked. The "Current state = 4 nodes" section
> below is the pre-work snapshot, kept for context only.

## What you're doing

Wiring up `sbn_skill_node_content` rows so the skill-tree player knows which actual lesson/exercise/leadsheet/chord-diagram teaches each skill node. Right now only 4 of 64 nodes have any content linked — everything else is floating.

## App context

Laravel + SQLite guitar education app at `C:\Users\info\sbn-app`. The skill tree is a curriculum graph (`sbn_skill_nodes`) where each node represents a skill (e.g. "Shell Voicings", "Pulse & Subdivision"). Nodes link to courses via `sbn_course_skill_node`, and to actual content via `sbn_skill_node_content`. The content-linking table is what the front-end uses to surface "here's the lesson that teaches this skill" — without it, nodes exist in the graph but lead nowhere.

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

## Key tables

### `sbn_skill_nodes`
- `id`, `slug`, `title`, `branch`, `sub_branch`, `grade`, `completion_type`

### `sbn_skill_node_content`
- `skill_node_id` — FK to sbn_skill_nodes
- `content_type` — Laravel model class string: `App\Models\Lesson`, `App\Models\RhythmPattern`, `App\Models\Leadsheet`, `App\Models\ChordProgression`, `App\Models\Exercise`
- `content_id` — PK of the referenced row
- `sort_order` — for multiple content items on one node

### Other relevant tables
- `sbn_lessons` — id, course_id, slug, title, content (HTML)
- `sbn_courses` — id, slug, title
- `sbn_course_skill_node` — course_id, skill_node_id (which courses claim which nodes)
- `sbn_rhythm_patterns` — id, slug, name
- `sbn_leadsheets` — id, slug, title
- `sbn_exercises` — id, slug, title
- `sbn_chord_progressions` — id, slug, name

## Current state

4 nodes already have content linked (done by a previous migration):

| node | content |
|---|---|
| `two-four-feel` (id 12) | RhythmPattern: gilberto-rhythm (id 1), bossa-nova-clave (id 7); Leadsheet: The Girl from Ipanema (id 551) |
| `syncopation` (id 13) | RhythmPattern: samba (id 2), partido-alto (id 3) |
| `drop2-voicings` (id 4) | Leadsheet: Desafinado (id 555) |
| `ii-v-i-major` (id 7) | ChordProgression: II-V to relative Major (id 43); Leadsheet: The Girl from Ipanema (id 551) |

## The 60 nodes still needing content

Ordered by branch and grade (suggested pass order: harmony → rhythm → melody → technique → reading-theory → ear-training):

### harmony branch
| id | slug | title | grade | linked to course(s) |
|---|---|---|---|---|
| 36 | the-basic-8 | The Basic 8 | 1 | bossa-nova-basics |
| 1 | intervals | Intervals | 1 | music-theory-basics, intervals-building-blocks-of-harmony |
| 2 | triads | Triads | 2 | music-theory-basics |
| 3 | chord-inversions | Chord Inversions | 2 | music-theory-basics |
| 39 | diatonic-harmony | Diatonic Harmony | 2 | music-theory-basics, diatonic-chords-and-the-nashville-number-system |
| 40 | cadences | Classical Cadences | 2 | music-theory-basics |
| 41 | pop-progressions | Pop & Folk Progressions | 2 | music-theory-basics |
| 5 | drop2-voicings | Drop 2 Voicings | 3 | bossa-nova-chords-ii, easy-bossa-nova-songs, latin-side-pat-metheny, latin-side-wes-montgomery |
| 6 | drop3-voicings | Drop 3 Voicings | 3 | solo-guitar-joe-pass |
| 42 | turnarounds | Turnarounds | 3 | chord-progressions-and-voice-leading |
| 8 | ii-v-i-minor | ii-V-I in Minor | 3 | chord-progressions-and-voice-leading |
| 45 | voice-leading | Smooth Voice Leading | 3 | chord-progressions-and-voice-leading |
| 9 | tritone-substitution | Tritone Substitution | 4 | chord-progressions-and-voice-leading, diminished-chords-bossa-nova |
| 43 | secondary-dominants | Secondary Dominants | 4 | chord-progressions-and-voice-leading |
| 10 | chord-melody | Chord Melody | 5 | solo-guitar-joe-pass |
| 44 | borrowed-chords | Borrowed Chords / Modal Interchange | 5 | chord-progressions-and-voice-leading |
| 57 | blues | Blues | 1 | *(no course — skip for now)* |

### rhythm branch
| id | slug | title | grade | linked to course(s) |
|---|---|---|---|---|
| 46 | meter-basics | Meter & Time Signatures | 1 | basic-rhythms, bossa-nova-basics |
| 11 | pulse-subdivision | Pulse & Subdivision | 1 | basic-rhythms, right-hand-technique |
| 59 | bossa-syncopated-push | Bossa Syncopated Push | 2 | bossa-nova-rhythm |
| 60 | alternating-bass-patterns | Alternating Bass Patterns | 2 | bossa-nova-rhythm |
| 13 | syncopation | Syncopation | 2 | right-hand-technique, syncopation-off-beat-rhythms *(already has content — verify)* |
| 47 | waltz-feel | 3/4 / Waltz Feel | 2 | basic-rhythms |
| 48 | swing-feel | Swing Feel | 3 | basic-rhythms |
| 50 | clave-systems | Clave Systems | 3 | the-clave, bossa-nova-rhythm |
| 51 | brazilian-rhythm-styles | Brazilian & Afro-Latin Rhythm Styles | 3 | the-clave, brazilian-rhythms |
| 61 | partido-alto-groove | Partido Alto Groove | 3 | bossa-nova-rhythm |
| 14 | comping-patterns | Comping Patterns | 3 | easy-bossa-nova-songs, bossa-nova-rhythm |
| 49 | polyrhythm | Polyrhythm | 5 | the-clave |

### melody branch
| id | slug | title | grade | linked to course(s) |
|---|---|---|---|---|
| 37 | foundational-scales | Foundational Scales | 1 | music-theory-basics, right-hand-technique |
| 54 | major-minor-scales | Major & Minor Scales | 1 | music-theory-basics |
| 56 | blues-scale | Blues Scale | 1 | *(no course — skip for now)* |
| 58 | chromatic-scale | Chromatic Scale | 2 | music-theory-basics |
| 16 | pentatonic-scale | Pentatonic Scale | 2 | pentatonic-scale-five-positions |
| 15 | scale-patterns | Scale Patterns | 2 | the-caged-system |
| 17 | arpeggio-shapes | Arpeggio Shapes | 3 | arpeggio-shapes-the-five-chord-qualities, the-caged-system, right-hand-technique |
| 18 | motivic-development | Motivic Development | 5 | approach-notes-and-enclosures |
| 19 | improvisation-over-changes | Improvisation Over Changes | 5 | approach-notes-and-enclosures |

### technique branch
| id | slug | title | grade | linked to course(s) |
|---|---|---|---|---|
| 65 | guitar-posture-setup | Posture & Setup | 1 | right-hand-technique |
| 66 | pima-finger-assignment | The PIMA Finger System | 1 | right-hand-technique |
| 67 | rest-stroke-free-stroke | Rest Stroke & Free Stroke | 1 | right-hand-technique |
| 55 | the-spider | The Spider | 1 | right-hand-technique |
| 20 | fingerpicking-basics | Fingerpicking Basics | 1 | right-hand-technique |
| 24 | barre-chords | Barre Chords | 2 | the-caged-system |
| 21 | right-hand-independence | Right Hand Independence | 2 | right-hand-technique |
| 22 | thumb-independence | Thumb Independence | 2 | right-hand-technique |
| 26 | legato-slurs | Legato / Slurs | 2 | right-hand-technique |
| 27 | tone-production | Tone Production | 2 | right-hand-technique |
| 68 | hand-damping-control | Hand Damping & Muting | 2 | right-hand-technique |
| 23 | caged-system | CAGED System | 3 | the-caged-system |
| 25 | position-shifting | Position Shifting | 3 | the-caged-system |

### reading-theory branch
| id | slug | title | grade | linked to course(s) |
|---|---|---|---|---|
| 32 | standard-notation-basics | Standard Notation Basics | 1 | music-theory-basics |
| 52 | tab-reading-basics | Tab Reading Basics | 1 | music-theory-basics |
| 33 | rhythm-notation | Rhythm Notation | 2 | music-theory-basics |
| 53 | scale-degrees | Scale Degrees & Roman Numerals | 2 | intervals-building-blocks-of-harmony |
| 34 | leadsheet-reading | Leadsheet Reading | 3 | music-theory-basics |
| 35 | nashville-number-system | Nashville Number System | 3 | diatonic-chords-and-the-nashville-number-system |

### ear-training branch
| id | slug | title | grade | linked to course(s) |
|---|---|---|---|---|
| 28 | interval-recognition | Interval Recognition | 2 | intervals-building-blocks-of-harmony |
| 29 | chord-quality-recognition | Chord Quality Recognition | 3 | *(no course)* |
| 30 | rhythm-dictation | Rhythm Dictation | 3 | *(no course)* |
| 31 | melodic-dictation | Melodic Dictation | 4 | *(no course)* |

*Note: nodes 29–31 have no course and no content type exists for ear-training drills yet. Do the other branches first and leave these for the separate ear-training handover (§2).*

## Suggested approach for each node

1. Find the lesson(s) in the linked course(s) that most directly teach the skill
2. Insert a row into `sbn_skill_node_content`:
   - `content_type` = `App\Models\Lesson` (most common), or RhythmPattern / Leadsheet / ChordProgression / Exercise where appropriate
   - `content_id` = the lesson/asset id
   - `sort_order` = 0 (or increment if adding multiple)
3. A node can have multiple content rows — e.g. a lesson AND a rhythm pattern, or two lessons from different courses

## Example insert

```python
cur.execute("""
    insert into sbn_skill_node_content (skill_node_id, content_type, content_id, sort_order)
    values (?, ?, ?, ?)
""", (11, 'App\\Models\\Lesson', <lesson_id>, 0))
```

## How to find the right lesson for a node

```python
# Get all lessons in a course
cur.execute("""
    select l.id, l.slug, l.title, l.sort_order
    from sbn_lessons l
    join sbn_courses c on c.id = l.course_id
    where c.slug = 'right-hand-technique'
    order by l.sort_order
""")
```

## What's already been done (don't redo)

- §4: courses 68/69 flipped to draft
- §5: all broken sbn-sheet/sbn-song slug refs fixed
- §6: all WP migration artifacts (alphatex, rhythm shortcodes, wp-block-* classes) cleaned up
- §3: barre-chords and position-shifting linked to CAGED System; the-spider linked to Right Hand Technique; muting node merged into hand-damping-control (deleted)

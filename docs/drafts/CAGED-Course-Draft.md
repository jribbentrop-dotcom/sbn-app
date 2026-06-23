# CAGED Course — Draft for Implementation

Status: **content drafted, NOT yet written to the database.** This file is the complete brief for whoever (or whichever Claude Code session) implements the DB rows. No `sbn_courses`/`sbn_lessons`/`sbn_course_skill_node` writes have happened yet.

## Before you touch the DB

A live-DB corruption incident happened today (2026-06-23) while preparing this course: `database/sbn.db` fails `PRAGMA integrity_check` — header declares 11,324 pages, actual file is 7,003 pages (truncated by ~17.7MB), reproducible byte-for-byte across repeated copies. Two valid backups exist next to it (`sbn.db.bak-backfill-20260623-173501`, `sbn.db.bak-versions-20260623-173216`), both passing integrity_check with courses/lessons intact through course 72. **No restore has been performed** — the user wanted this re-verified rather than auto-restored. Confirm DB integrity (and resolve however the user decided to handle it) before writing any of this content.

Follow the CLAUDE.md DB workflow exactly: copy `database/sbn.db` to a non-mounted path (`$HOME/work/...`, not `/tmp`, not anything under a mounted folder), do all reads/writes there, verify, then copy back.

**Don't hardcode IDs from this doc blindly** — re-check `MAX(id)` on whichever DB copy you're actually writing to, since the corruption/backup situation above may have shifted state. As of the last known-good state (course 72 / lesson 113), the expected next IDs are:
- Course id: **73**
- Lesson ids: **114–119**

If those don't match what you find, use the real next-available ids instead and adjust cross-references.

---

## Course metadata

| Field | Value |
|---|---|
| id | 73 (verify) |
| slug | `the-caged-system` |
| title | The CAGED System |
| excerpt | One set of five chord shapes that maps the entire fretboard — learn to find major, minor, and dominant sounds anywhere on the neck. |
| description | The CAGED system uses five open-position chord shapes (C, A, G, E, D) as movable templates that tile the whole fretboard. This course breaks down four of the five shapes in depth — each one explored as a single hand position that can produce a major 7, minor 7, and dominant 7 sound around the same root, plus the scales that go with each. |
| genres | `["bossa-nova","jazz","pop"]` |
| levels | `["basic"]` |
| style | null (or omit, matching pattern of course 71) |
| level | `basic` |
| topics | `["caged system","chord shapes","scale patterns","arpeggios","fretboard positions"]` |
| is_free | 1 |
| status | `publish` |
| category | `jazz` |
| sort_order | next available (after 72) |
| learning_outcomes | (newline-separated, see below) |

**learning_outcomes:**
```
Understand the five CAGED shapes and how they tile the fretboard
Locate the major, minor, and dominant-7 sound within a single CAGED position
Connect each chord shape to its matching scale fingering
Use phrygian dominant and mixolydian scales as color choices over a dominant chord
Navigate between adjacent CAGED positions on the neck
```

---

## Skill node mapping

Map this course to (insert rows into `sbn_course_skill_node`):
- `caged-system` (Technique) — primary node this course closes
- `scale-patterns` (Melody) — each lesson explicitly teaches a scale fingering tied to the shape
- `arpeggio-shapes` (Melody) — each lesson explicitly teaches triad + 7th-arpeggio shapes

Do **not** map directly to `barre-chords` or `position-shifting` — this course doesn't teach barre technique itself, and only lesson 6 touches position-shifting briefly as a preview/teaser. Those remain open nodes for a future course, now that `caged-system` (their prerequisite) is closing.

---

## Source files (for fact-checking against, if anything below needs re-verification)

All in `Partituren/Theorie/`:

| File | Shape taught | Root used | Measures |
|---|---|---|---|
| `SKALEN - CAGED.musicxml` | All 5 shapes, once each (overview) | C-shape→D, A-shape→C, G-shape→A, E-shape→G, D-shape→F | m1-4, 5-8, 9-13, 14-18, 19-22 |
| `SKARPAKK - Die A-Form.musicxml` | A-shape | C | Maj: m1-10, Min: m11-20, Dom: m21-32 |
| `SKARPAKK - Die C-Form.musicxml` | C-shape | D | Maj: m1-11, Min: m12-22, Dom: m23-34 |
| `SKARPAKK - Die E-Form.musicxml` | E-shape | G | Maj: m1-11, Min: m12-22, Dom: m23-35 |
| `SKARPAKK - Die G-Form.musicxml` | G-shape | A | Maj: m1-11, Min: m12-22, Dom: m23-33 |

**Known gap:** no dedicated `SKARPAKK` deep-dive file exists for the D-shape. The overview file (`SKALEN - CAGED.musicxml`) includes one brief D-shape example (root F), which is enough for lesson 1's overview, but not enough for a full Maj7/min7/Dom7 deep-dive lesson like the other four shapes get. Flag this as a deferred/second-pass item (same pattern as the Intervals course's deferred "Beispiele" pop/rock examples) — do not invent a 5th deep-dive lesson without real source material.

Verified note content per shape (major scale / minor scale / mixolydian / phrygian dominant / arpeggios), confirmed directly against the MusicXML pitch data:

- **A-shape, root C:** Major: C-D-E-F-G-A-B-C, triad C-E-G, Cmaj7 C-E-G-B. Minor (natural minor): C-D-Eb-F-G-Ab-Bb-C, triad C-Eb-G, Cmin7 C-Eb-G-Bb. Dominant: mixolydian C-D-E-F-G-A-Bb-C, phrygian dominant C-Db-E-F-G-Ab-Bb-C, C7 C-E-G-Bb.
- **C-shape, root D:** Major: D-E-F#-G-A-B-C#-D, triad D-F#-A, Dmaj7 D-F#-A-C#. Minor: D-E-F-G-A-Bb-C-D, triad D-F-A, Dmin7 D-F-A-C. Dominant: mixolydian D-E-F#-G-A-B-C-D, phrygian dominant D-Eb-F#-G-A-Bb-C-D, D7 D-F#-A-C.
- **E-shape, root G:** Major: G-A-B-C-D-E-F#-G, triad G-B-D, Gmaj7 G-B-D-F#. Minor: G-A-Bb-C-D-Eb-F-G, triad G-Bb-D, Gmin7 G-Bb-D-F. Dominant: mixolydian G-A-B-C-D-E-F-G, phrygian dominant G-Ab-B-C-D-Eb-F-G, G7 G-B-D-F.
- **G-shape, root A:** Major: A-B-C#-D-E-F#-G#-A, triad A-C#-E, Amaj7 A-C#-E-G#. Minor: A-B-C-D-E-F-G-A, triad A-C-E, Amin7 A-C-E-G. Dominant: mixolydian A-B-C#-D-E-F#-G-A, phrygian dominant A-Bb-C#-D-E-F-G-A, A7 A-C#-E-G.

Each SKARPAKK file uses **one fixed root for all three sections** — that's the pedagogical hook worth stating explicitly in lesson copy: the same hand position produces a major-7, minor-7, and dominant-7 sound around the same root note, just by changing which notes you target within the shape.

---

## Lessons

### Lesson 1 — "What Is the CAGED System?"
- id 114 (verify) · course_id 73 · slug `what-is-the-caged-system`
- section_title: `Foundations`
- is_preview: **1**
- sort_order: 1
- concept_slug: `caged-system`

```html
<h2 id="section-overview">Five Shapes, One Fretboard</h2>
<p>The CAGED system gets its name from five open chords you probably already know: C, A, G, E, and D. Each of those open-position chords has a distinct shape — and if you slide that shape up the neck as a barre chord, it still works. Do this with all five shapes and you've mapped the entire fretboard, because the five shapes overlap and connect in a fixed, repeating order.</p>
<p>The same five shapes also describe five places to play a scale. Wherever a CAGED chord shape sits, there's a matching scale fingering built around it. That's the real payoff of learning CAGED: it links chords, scales, and arpeggios into one set of physical positions instead of five separate things to memorize.</p>

<h2 id="section-five-shapes">The Five Shapes</h2>
<p>Here is each shape played once, using a convenient root note for each — notice that the shape itself doesn't change no matter which root you put it on:</p>
<p>[NOTATION: C-shape, root D — chord tones D-F#-A-D-F# across strings 5,4,3,2,1]</p>
<p>[NOTATION: A-shape, root C — chord tones C-G-C-E-G across strings 5,4,3,2,1]</p>
<p>[NOTATION: G-shape, root A — chord tones E-A-C#-A across strings 4,3,2,1]</p>
<p>[NOTATION: E-shape, root G — chord tones G-D-G-B-D-G across strings 6,5,4,3,2,1]</p>
<p>[NOTATION: D-shape, root F — chord tones F-C-F-A across strings 4,3,2,1]</p>

<sbn-info heading="How to use these shapes" items="Each shape can move to any fret — the root note just changes|Shapes overlap: the top of one shape lines up with the bottom of the next|You don't need to learn all five at once — start with one and add others over time"></sbn-info>

<h2 id="section-whats-next">What's Next</h2>
<p>The next four lessons take a deep dive into the A-shape, C-shape, E-shape, and G-shape positions. In each one, you'll find a major 7, minor 7, and dominant 7 sound — plus the scale that goes with each — all within a single hand position. The D-shape gets a full deep-dive lesson in a future update.</p>
```

---

### Lesson 2 — "The A-Shape Position"
- id 115 (verify) · slug `the-a-shape-position`
- section_title: `The Four Positions`
- is_preview: 0 · sort_order: 2
- concept_slug: `caged-system`

```html
<h2 id="section-a-shape-intro">Finding the A-Shape</h2>
<p>The A-shape gets its name from the open A chord. As a movable shape, its root notes sit on the 5th string and the 3rd string. This lesson keeps the root on C, so the shape sits with its lowest root around the 3rd fret.</p>

<h2 id="section-a-shape-major">Major 7 Sound</h2>
<p>Starting with the C major scale across this position, then the triad, then the full Cmaj7 arpeggio:</p>
<p>[NOTATION: C major scale (C-D-E-F-G-A-B-C) in A-shape position]</p>
<p>[NOTATION: C major triad arpeggio (C-E-G) in A-shape position]</p>
<p>[NOTATION: Cmaj7 arpeggio (C-E-G-B) in A-shape position]</p>

<h2 id="section-a-shape-minor">Minor 7 Sound</h2>
<p>Same position, same root — now built from C natural minor:</p>
<p>[NOTATION: C natural minor scale (C-D-Eb-F-G-Ab-Bb-C) in A-shape position]</p>
<p>[NOTATION: C minor triad arpeggio (C-Eb-G) in A-shape position]</p>
<p>[NOTATION: Cmin7 arpeggio (C-Eb-G-Bb) in A-shape position]</p>

<h2 id="section-a-shape-dominant">Dominant 7 Sound</h2>
<p>For a dominant 7 sound on C, two scale choices work over the same arpeggio. Mixolydian is the straightforward choice; phrygian dominant (the 5th mode of harmonic minor) adds a sharper, more tense color often used to lean into a resolution.</p>
<p>[NOTATION: C mixolydian scale (C-D-E-F-G-A-Bb-C) in A-shape position]</p>
<p>[NOTATION: C phrygian dominant scale (C-Db-E-F-G-Ab-Bb-C) in A-shape position]</p>
<p>[NOTATION: C7 arpeggio (C-E-G-Bb) in A-shape position]</p>

<sbn-info heading="Practice approach" items="Loop each scale slowly before adding the arpeggio|Compare the minor and major thirds (E vs Eb) by ear, not just by shape|Try resolving the dominant 7 sound to the major 7 sound in the same position"></sbn-info>
```

---

### Lesson 3 — "The C-Shape Position"
- id 116 (verify) · slug `the-c-shape-position`
- section_title: `The Four Positions`
- is_preview: 0 · sort_order: 3
- concept_slug: `caged-system`

```html
<h2 id="section-c-shape-intro">Finding the C-Shape</h2>
<p>The C-shape comes from the open C chord. As a movable shape its root sits on the 5th string. This lesson keeps the root on D, putting the shape a little further up the neck than the A-shape position from the last lesson.</p>

<h2 id="section-c-shape-major">Major 7 Sound</h2>
<p>[NOTATION: D major scale (D-E-F#-G-A-B-C#-D) in C-shape position]</p>
<p>[NOTATION: D major triad arpeggio (D-F#-A) in C-shape position]</p>
<p>[NOTATION: Dmaj7 arpeggio (D-F#-A-C#) in C-shape position]</p>

<h2 id="section-c-shape-minor">Minor 7 Sound</h2>
<p>[NOTATION: D natural minor scale (D-E-F-G-A-Bb-C-D) in C-shape position]</p>
<p>[NOTATION: D minor triad arpeggio (D-F-A) in C-shape position]</p>
<p>[NOTATION: Dmin7 arpeggio (D-F-A-C) in C-shape position]</p>

<h2 id="section-c-shape-dominant">Dominant 7 Sound</h2>
<p>[NOTATION: D mixolydian scale (D-E-F#-G-A-B-C-D) in C-shape position]</p>
<p>[NOTATION: D phrygian dominant scale (D-Eb-F#-G-A-Bb-C-D) in C-shape position]</p>
<p>[NOTATION: D7 arpeggio (D-F#-A-C) in C-shape position]</p>

<sbn-info heading="Practice approach" items="Notice the C-shape sits one shape higher than the A-shape for a nearby root|Play the same Dmaj7/Dmin7/D7 sounds you just learned in the A-shape position on C — same logic, different shape|Use a metronome and keep the tempo slow until the shifts between sections are clean"></sbn-info>
```

---

### Lesson 4 — "The E-Shape Position"
- id 117 (verify) · slug `the-e-shape-position`
- section_title: `The Four Positions`
- is_preview: 0 · sort_order: 4
- concept_slug: `caged-system`

```html
<h2 id="section-e-shape-intro">Finding the E-Shape</h2>
<p>The E-shape comes from the open E chord and is the shape most players learn first as a barre chord, since its root sits on the low 6th string. This lesson keeps the root on G.</p>

<h2 id="section-e-shape-major">Major 7 Sound</h2>
<p>[NOTATION: G major scale (G-A-B-C-D-E-F#-G) in E-shape position]</p>
<p>[NOTATION: G major triad arpeggio (G-B-D) in E-shape position]</p>
<p>[NOTATION: Gmaj7 arpeggio (G-B-D-F#) in E-shape position]</p>

<h2 id="section-e-shape-minor">Minor 7 Sound</h2>
<p>[NOTATION: G natural minor scale (G-A-Bb-C-D-Eb-F-G) in E-shape position]</p>
<p>[NOTATION: G minor triad arpeggio (G-Bb-D) in E-shape position]</p>
<p>[NOTATION: Gmin7 arpeggio (G-Bb-D-F) in E-shape position]</p>

<h2 id="section-e-shape-dominant">Dominant 7 Sound</h2>
<p>[NOTATION: G mixolydian scale (G-A-B-C-D-E-F-G) in E-shape position]</p>
<p>[NOTATION: G phrygian dominant scale (G-Ab-B-C-D-Eb-F-G) in E-shape position]</p>
<p>[NOTATION: G7 arpeggio (G-B-D-F) in E-shape position]</p>

<sbn-info heading="Practice approach" items="The E-shape root on the low string makes this position easy to find anywhere on the neck|Try barring the full E-shape chord before isolating the scale|Compare this G major7/minor7/7 set against the same sounds in the A-shape and C-shape positions"></sbn-info>
```

---

### Lesson 5 — "The G-Shape Position"
- id 118 (verify) · slug `the-g-shape-position`
- section_title: `The Four Positions`
- is_preview: 0 · sort_order: 5
- concept_slug: `caged-system`

```html
<h2 id="section-g-shape-intro">Finding the G-Shape</h2>
<p>The G-shape comes from the open G chord. It's the widest stretch of the five CAGED shapes in open position, but as a movable shape it's no harder than the others. This lesson keeps the root on A.</p>

<h2 id="section-g-shape-major">Major 7 Sound</h2>
<p>[NOTATION: A major scale (A-B-C#-D-E-F#-G#-A) in G-shape position]</p>
<p>[NOTATION: A major triad arpeggio (A-C#-E) in G-shape position]</p>
<p>[NOTATION: Amaj7 arpeggio (A-C#-E-G#) in G-shape position]</p>

<h2 id="section-g-shape-minor">Minor 7 Sound</h2>
<p>[NOTATION: A natural minor scale (A-B-C-D-E-F-G-A) in G-shape position]</p>
<p>[NOTATION: A minor triad arpeggio (A-C-E) in G-shape position]</p>
<p>[NOTATION: Amin7 arpeggio (A-C-E-G) in G-shape position]</p>

<h2 id="section-g-shape-dominant">Dominant 7 Sound</h2>
<p>[NOTATION: A mixolydian scale (A-B-C#-D-E-F#-G-A) in G-shape position]</p>
<p>[NOTATION: A phrygian dominant scale (A-Bb-C#-D-E-F-G-A) in G-shape position]</p>
<p>[NOTATION: A7 arpeggio (A-C#-E-G) in G-shape position]</p>

<sbn-info heading="Practice approach" items="The G-shape's stretch gets easier with repetition — don't force it early on|This is the last of the four positions covered here; the D-shape deep dive is still to come|Try playing a ii-V-I in this position once you're comfortable with all three sounds"></sbn-info>
```

---

### Lesson 6 — "Connecting the Shapes Up the Neck"
- id 119 (verify) · slug `connecting-the-shapes-up-the-neck`
- section_title: `Putting It Together`
- is_preview: 0 · sort_order: 6
- concept_slug: `caged-system`

```html
<h2 id="section-connecting-intro">The Shapes Don't Stand Alone</h2>
<p>The five CAGED shapes always appear in the same order as you move up the neck — C, A, G, E, D, then back to C an octave higher — and each shape's root overlaps with the edge of the next one. That overlap is what makes it possible to slide smoothly from one position to another instead of jumping blind.</p>

<h2 id="section-connecting-example">A Worked Example</h2>
<p>Take the four shapes from this course and line them up on the same root note. Played in order, they walk a single major-7 sound up the entire neck:</p>
<p>[NOTATION: Cmaj7 arpeggio played across A-shape, G-shape, E-shape, C-shape, and back to A-shape an octave up, same root C throughout]</p>

<h2 id="section-connecting-next">Where This Goes Next</h2>
<p>Two things build directly on what you've covered here, planned as future courses: turning each shape into a movable barre chord you can drop into any progression, and using the overlap between shapes to shift position mid-phrase without losing your place. The D-shape will also get its own full deep-dive lesson, matching the treatment given to the other four shapes in this course, once a complete source example for it is ready.</p>

<sbn-info heading="Where to go from here" items="Pick one root note and find it in all four shapes covered in this course|Practice moving from one shape to the next without stopping|Revisit lesson 1's overview once the four deep dives feel solid"></sbn-info>
```

---

## Implementation checklist

1. Resolve the DB integrity situation (see top of this doc) before writing anything.
2. Copy `database/sbn.db` to a safe working path per CLAUDE.md.
3. Confirm real next `id` values for `sbn_courses` and `sbn_lessons` — don't trust the ids in this doc without checking.
4. Insert the course row (metadata table above).
5. Insert the 6 lesson rows (content blocks above), in order, with correct `course_id`.
6. Insert `sbn_course_skill_node` rows for `caged-system`, `scale-patterns`, `arpeggio-shapes`.
7. Copy the working DB back to the mounted path.
8. Re-open the copied-back file and run `PRAGMA integrity_check` plus a row-count sanity check before considering this done.
9. Update `docs/SBN-Skill-System-Plan.md`: add course 73 to the course→skill-node mapping table, and add a dated note under "Curriculum gaps this surfaced" describing the source files used and the deferred D-shape deep-dive, matching the pattern used for course 72.

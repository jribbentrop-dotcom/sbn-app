# Arpeggio Shapes: The Five Chord Qualities — Draft for Implementation

Status: **content drafted, NOT yet written to the database.** Pure brief — no DB rows inserted.

Follow the CLAUDE.md DB workflow (copy `database/sbn.db` to a non-mounted path, write there, verify, copy back). **Re-check `MAX(id)` before using the ids below** — they assume the CAGED course (id 73, lessons 114-119) and the Diatonic Chords / Nashville Numbers course (id 74, lessons 120-123) both land first. Recompute if that ordering changes.

- Course id: **75**
- Lesson ids: **124–129**

---

## Course metadata

| Field | Value |
|---|---|
| slug | `arpeggio-shapes-the-five-chord-qualities` |
| title | Arpeggio Shapes: The Five Chord Qualities |
| excerpt | Almost every 4-note chord you'll meet is one of five qualities — and each one fits the same two-octave arpeggio shape with only a note or two moved. |
| description | Major 7, minor 7, dominant 7, minor 7♭5, and diminished 7 — these five chord qualities cover the overwhelming majority of 4-note chords in jazz and bossa nova harmony. This course walks through four CAGED-style fretboard positions, and in each one shows how all five qualities sit inside the same physical arpeggio shape, changing only a note or two between them. |
| genres | `["jazz","pop"]` |
| levels | `["intermediate"]` |
| level | `intermediate` |
| topics | `["arpeggios","chord qualities","caged system","four-note chords"]` |
| is_free | 1 |
| status | `publish` |
| category | `jazz` |
| sort_order | next available |
| learning_outcomes | (newline-separated, see below) |

**learning_outcomes:**
```
Identify the five core 4-note chord qualities: maj7, m7, dom7, m7b5, dim7
Play a 2-octave arpeggio for each quality in four CAGED-style positions
Recognize how little changes physically between qualities in the same position
Connect arpeggio shapes back to the CAGED chord/scale positions
```

---

## Skill node mapping

- `arpeggio-shapes` (Melody) — primary node this course closes
- Cross-reference only (no pivot row needed): `caged-system` — this course assumes the same four fretboard positions taught in "The CAGED System" course and should link to it in lesson copy

---

## Source files and verified data

`Partituren/Theorie/`, four files, each 15 measures, each structured identically: a 2-octave ascending-then-descending arpeggio for 5 chord qualities in a row (Maj7 → m7 → Dom7 → m7b5 → dim7), 3 measures per quality, on a single fixed root.

| File | Shape | Root | Verified chord tones |
|---|---|---|---|
| `ARPEGGIOS - Vierklänge (A-Form).musicxml` | A-shape | C | Cmaj7 C-E-G-B · Cm7 C-Eb-G-Bb · C7 C-E-G-Bb · Cm7b5 C-Eb-Gb-Bb · Cdim7 C-Eb-Gb-Bbb(≈A) |
| `ARPEGGIOS - Vierklänge (C-Form).musicxml` | C-shape | F | Fmaj7 F-A-C-E · Fm7 F-Ab-C-Eb · F7 F-A-C-Eb · Fm7b5 F-Ab-Cb-Eb · Fdim7 F-Ab-Cb-Ebb(≈D) |
| `ARPEGGIOS - Vierklänge (E-Form).musicxml` | E-shape | G | Gmaj7 G-B-D-F# · Gm7 G-Bb-D-F · G7 G-B-D-F · Gm7b5 G-Bb-Db-F · Gdim7 G-Bb-Db-Fb(≈E) |
| `ARPEGGIOS - Vierklänge (G-Form).musicxml` | G-shape | A | Amaj7 A-C#-E-G# · Am7 A-C-E-G · A7 A-C#-E-G · Am7b5 A-C-Eb-G · Adim7 A-C-Eb-Gb |

All chord tones above were checked directly against the MusicXML `<pitch>` data, not just the `<harmony>` chord-symbol tags. Each file's roots are independent of the roots used in the SKARPAKK files behind the CAGED course (e.g. this C-Form file uses F, while the CAGED course's C-Form lesson uses D) — that's expected; the two courses draw from different source files and each is internally consistent. Mention this explicitly in lesson copy so it doesn't read as a contradiction between the two courses.

No gaps or ambiguous passages found in these four files — all 60 measures (15 × 4) check out cleanly.

---

## Lessons

### Lesson 1 — "The Five Chord Qualities"
- id 124 (verify) · course_id 75 · slug `the-five-chord-qualities`
- section_title: `Foundations` · is_preview: **1** · sort_order: 1
- concept_slug: `arpeggio-shapes`

```html
<h2 id="section-five-qualities-intro">Five Shapes Cover Almost Everything</h2>
<p>Stack four notes a third apart and you'll land on one of five chord qualities almost every time: major 7, minor 7, dominant 7, minor 7♭5 (half-diminished), and diminished 7. Learn the arpeggio shape for one of these in a given fretboard position, and the other four are just one or two notes away.</p>

<h2 id="section-five-qualities-demo">Same Position, Five Sounds</h2>
<p>Here's all five qualities built on C, in the same hand position:</p>
<p>[NOTATION: Cmaj7 arpeggio (C-E-G-B), 2 octaves]</p>
<p>[NOTATION: Cm7 arpeggio (C-Eb-G-Bb), 2 octaves]</p>
<p>[NOTATION: C7 arpeggio (C-E-G-Bb), 2 octaves]</p>
<p>[NOTATION: Cm7b5 arpeggio (C-Eb-Gb-Bb), 2 octaves]</p>
<p>[NOTATION: Cdim7 arpeggio (C-Eb-Gb-Bbb), 2 octaves]</p>
<p>Notice how each quality differs from the one before it by just a single note: maj7 → m7 flattens the 3rd, m7 → dom7 raises the 7th back up, dom7 → m7b5 flattens the 3rd and 5th, m7b5 → dim7 flattens the 7th once more.</p>

<sbn-info heading="How to use this course" items="The next four lessons cover this same five-quality set in four different fretboard positions|These positions match the ones from The CAGED System course — review that course first if the shapes feel unfamiliar|Bbb in the diminished 7th chord sounds identical to A, just spelled to fit the chord's stacked-thirds logic"></sbn-info>
```

---

### Lesson 2 — "A-Shape Arpeggios"
- id 125 (verify) · slug `a-shape-arpeggios`
- section_title: `The Four Positions` · is_preview: 0 · sort_order: 2
- concept_slug: `arpeggio-shapes`

```html
<h2 id="section-a-shape-arp-intro">Five Qualities, Root on C</h2>
<p>This lesson stays in the A-shape position (root on C) and runs through all five qualities as full 2-octave arpeggios:</p>
<p>[NOTATION: Cmaj7 arpeggio, A-shape position, 2 octaves ascending and descending]</p>
<p>[NOTATION: Cm7 arpeggio, A-shape position, 2 octaves ascending and descending]</p>
<p>[NOTATION: C7 arpeggio, A-shape position, 2 octaves ascending and descending]</p>
<p>[NOTATION: Cm7b5 arpeggio, A-shape position, 2 octaves ascending and descending]</p>
<p>[NOTATION: Cdim7 arpeggio, A-shape position, 2 octaves ascending and descending]</p>

<sbn-info heading="Practice approach" items="Play each quality slowly before speeding up|Stop between qualities and notice exactly which finger moved|Compare this to the Cmaj7/Cmin7/C7 sounds from the A-shape lesson in The CAGED System course"></sbn-info>
```

---

### Lesson 3 — "C-Shape Arpeggios"
- id 126 (verify) · slug `c-shape-arpeggios`
- section_title: `The Four Positions` · is_preview: 0 · sort_order: 3
- concept_slug: `arpeggio-shapes`

```html
<h2 id="section-c-shape-arp-intro">Five Qualities, Root on F</h2>
<p>[NOTATION: Fmaj7 arpeggio, C-shape position, 2 octaves ascending and descending]</p>
<p>[NOTATION: Fm7 arpeggio, C-shape position, 2 octaves ascending and descending]</p>
<p>[NOTATION: F7 arpeggio, C-shape position, 2 octaves ascending and descending]</p>
<p>[NOTATION: Fm7b5 arpeggio, C-shape position, 2 octaves ascending and descending]</p>
<p>[NOTATION: Fdim7 arpeggio, C-shape position, 2 octaves ascending and descending]</p>

<sbn-info heading="Practice approach" items="The C-shape sits higher up the neck than the A-shape — give yourself time to find it cleanly|Loop just the maj7-to-m7 change until it's automatic before adding the rest|This position uses F as its root here, even though the CAGED System course's C-shape lesson used D — different source material, same shape"></sbn-info>
```

---

### Lesson 4 — "E-Shape Arpeggios"
- id 127 (verify) · slug `e-shape-arpeggios`
- section_title: `The Four Positions` · is_preview: 0 · sort_order: 4
- concept_slug: `arpeggio-shapes`

```html
<h2 id="section-e-shape-arp-intro">Five Qualities, Root on G</h2>
<p>[NOTATION: Gmaj7 arpeggio, E-shape position, 2 octaves ascending and descending]</p>
<p>[NOTATION: Gm7 arpeggio, E-shape position, 2 octaves ascending and descending]</p>
<p>[NOTATION: G7 arpeggio, E-shape position, 2 octaves ascending and descending]</p>
<p>[NOTATION: Gm7b5 arpeggio, E-shape position, 2 octaves ascending and descending]</p>
<p>[NOTATION: Gdim7 arpeggio, E-shape position, 2 octaves ascending and descending]</p>

<sbn-info heading="Practice approach" items="The E-shape's root on the low string makes this one of the easiest positions to locate by ear|Try playing just the outer notes (root and octave) first to anchor the shape before filling in the rest|This G root matches the E-shape lesson from The CAGED System course — a good position to compare directly"></sbn-info>
```

---

### Lesson 5 — "G-Shape Arpeggios"
- id 128 (verify) · slug `g-shape-arpeggios`
- section_title: `The Four Positions` · is_preview: 0 · sort_order: 5
- concept_slug: `arpeggio-shapes`

```html
<h2 id="section-g-shape-arp-intro">Five Qualities, Root on A</h2>
<p>[NOTATION: Amaj7 arpeggio, G-shape position, 2 octaves ascending and descending]</p>
<p>[NOTATION: Am7 arpeggio, G-shape position, 2 octaves ascending and descending]</p>
<p>[NOTATION: A7 arpeggio, G-shape position, 2 octaves ascending and descending]</p>
<p>[NOTATION: Am7b5 arpeggio, G-shape position, 2 octaves ascending and descending]</p>
<p>[NOTATION: Adim7 arpeggio, G-shape position, 2 octaves ascending and descending]</p>

<sbn-info heading="Practice approach" items="This is the last of the four positions covered in this course|This A root matches the G-shape lesson from The CAGED System course|Once comfortable, try all five qualities back to back without stopping between them"></sbn-info>
```

---

### Lesson 6 — "Putting the Shapes to Work"
- id 129 (verify) · slug `putting-the-arpeggio-shapes-to-work`
- section_title: `Putting It Together` · is_preview: 0 · sort_order: 6
- concept_slug: `arpeggio-shapes`

```html
<h2 id="section-arp-synthesis-intro">Same Root, Four Positions</h2>
<p>To hear how completely these four shapes overlap, here's a major 7 arpeggio on a single root — C — played across all four positions covered in this course, illustrating how the same chord tones reappear in different places up the neck:</p>
<p>[NOTATION: Cmaj7 arpeggio illustrated across A-shape, G-shape, E-shape, and C-shape positions, same root C throughout — illustrative synthesis combining shapes from this course, not a single source excerpt]</p>

<h2 id="section-arp-application">Using Arpeggios in Real Progressions</h2>
<p>The real payoff of this course is being able to switch arpeggio quality the instant the chord changes. Take a ii-V-I progression and play the matching-quality arpeggio under each chord — minor 7 under the ii, dominant 7 under the V, major 7 under the I — all without leaving the same fretboard position where possible.</p>

<sbn-info heading="Where to go from here" items="Revisit The CAGED System course if any of these four positions still feel unfamiliar as chord or scale shapes|Try outlining a ii-V-I using only arpeggio quality changes, no scale notes|Pick one root note and find all five qualities in all four positions from memory"></sbn-info>
```

---

## Implementation checklist

1. Resolve any DB integrity concerns per CLAUDE.md before writing.
2. Copy `database/sbn.db` to a safe working path.
3. Confirm real next `id` values for `sbn_courses` / `sbn_lessons`.
4. Insert course + 6 lesson rows above.
5. Insert `sbn_course_skill_node` row for `arpeggio-shapes`.
6. Copy DB back, verify with `PRAGMA integrity_check` + row counts.
7. Update `docs/SBN-Skill-System-Plan.md` course→skill-node table and gaps section.
8. Consider adding an explicit cross-link (in both directions) between this course and "The CAGED System" course, since they share fretboard positions but use different roots per shape — worth calling out clearly so students aren't confused by the mismatch.

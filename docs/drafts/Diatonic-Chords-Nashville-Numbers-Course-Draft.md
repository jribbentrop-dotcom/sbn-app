# Diatonic Chords & the Nashville Number System — Draft for Implementation

Status: **content drafted, NOT yet written to the database.** Pure brief — no `sbn_courses`/`sbn_lessons`/`sbn_course_skill_node` rows have been inserted.

Follow the CLAUDE.md DB workflow (copy `database/sbn.db` to a non-mounted path, write there, verify, copy back). **Re-check `MAX(id)` before using the ids below** — they assume this course lands right after the CAGED course draft (id 73, lessons 114-119), which itself hasn't been written yet either. If CAGED gets built first, these numbers hold; if not, recompute.

- Course id: **74**
- Lesson ids: **120–123**

---

## Course metadata

| Field | Value |
|---|---|
| slug | `diatonic-chords-and-the-nashville-number-system` |
| title | Diatonic Chords & the Nashville Number System |
| excerpt | Stack a 7th chord on every step of the major scale and you get a self-contained harmonic toolkit — and a numbering system that works in any key. |
| description | Every major scale generates the same seven chord qualities in the same order, no matter what key you're in. This course builds that "diatonic ladder" in C, shows the ii-V-I cadence hiding inside it, and introduces the Nashville number system — naming chords by scale-degree number instead of letter name, so what you learn in one key transfers instantly to the next. |
| genres | `["jazz","bossa-nova"]` |
| levels | `["intermediate"]` |
| level | `intermediate` |
| topics | `["diatonic harmony","nashville numbers","ii-v-i","chord functions","leadsheet reading"]` |
| is_free | 1 |
| status | `publish` |
| category | `jazz` |
| sort_order | next available |
| learning_outcomes | (newline-separated, see below) |

**learning_outcomes:**
```
Build the diatonic 7th chord ladder on any major scale
Recognize the seven chord qualities that occur naturally in a major key
Read and use Nashville numbers (1, 2m, 3m, 4, 5, 6m, 7m7b5) in place of letter names
Identify the ii-V-I cadence by ear and on paper
Recognize a secondary ii-V leading to the vi chord
```

---

## Skill node mapping

- `nashville-number-system` (Reading & Theory) — primary node this course closes
- `leadsheet-reading` (Reading & Theory) — secondary; understanding diatonic chord function is foundational to fluent leadsheet reading

---

## Source file and verified data

`Partituren/Theorie/THEORIE - Stufenvierklänge.musicxml` — 34 measures total. Verified directly against `<harmony>` tags and the actual sounding pitches (not just the tags — see the flagged inconsistency below).

| Measures | Content | Verified |
|---|---|---|
| m1-2 | Full diatonic 7th-chord ladder in C major: CMaj7(I) – Dm7(ii) – Em7(iii) – FMaj7(IV) – G7(V) – Am7(vi) – Bm7b5(vii°) – CMaj7(I) | Yes — harmony tags and pitches agree |
| m3-6 | Same seven chords grouped in adjacent pairs: I-ii, iii-IV, V-vi, vii°-I | Yes — pitches confirmed: m3 = C-E-G-B / D-F-A-C, m4 = E-G-B-D / F-A-C-E, m5 = G-B-D-F / A-C-E-G, m6 = B-D-F-A / C-E-G-B |
| m7-10 | ii-V-I-I cadence: Dm7 – G7 – CMaj7 – CMaj7 | Yes — pitches confirmed: D-F-A-C / F-B-D-(G implied bass) / G-B-E(repeated) |
| m19-22 | Secondary ii-V to the relative minor, in G major: Am7-D7 (ii-V of I) \| GMaj7 (I) \| F#m7b5-B7 (ii-V of vi) \| Em7 (vi) | Yes — every chord tone individually checked against the `<pitch>` data: Am7=A-C-E-G, D7=D-F#-A-C, GMaj7=G-B-D-F#, F#m7b5=F#-A-C-E, B7=B-D#-F#-A, Em7=E-G-B-D |

**Flagged and excluded — do not use without further verification:**
- **m11-18**: the engraved pitches are an exact duplicate of m3-6 (same notes, note-for-note), but the attached `<harmony>` chord-symbol tags are different (e.g. m11 tags the C-E-G-B voicing as "C minor-seventh" when the actual notes spell C major 7). This is internally inconsistent and almost certainly a copy-paste/chord-symbol-relabeling artifact in the source score, not real content. Skip it.
- **m23-34**: a denser passage using diminished 7th chords and chromatic motion (e.g. C#dim7, F#dim7, Gm7b5/Gdim7 pairs) that appears to function as chromatic passing/connecting chords between diatonic chords. The harmonic logic here is plausible but more tangled than the rest of the file, and it overlaps territory already covered by course 69 (Diminished Chords — The Secret Weapon of Bossa Nova). Rather than force it into this course or risk mis-explaining it, leave it as a deferred item — possibly future material for course 69 instead of here.

---

## Lessons

### Lesson 1 — "The Diatonic 7th Chord Ladder"
- id 120 (verify) · course_id 74 · slug `the-diatonic-7th-chord-ladder`
- section_title: `Foundations` · is_preview: **1** · sort_order: 1
- concept_slug: `nashville-number-system`

```html
<h2 id="section-ladder-intro">One Scale, Seven Chords</h2>
<p>Take a major scale and build a four-note chord on every single step, using only notes from that scale. In C major, that gives you seven chords in a row: Cmaj7, Dm7, Em7, Fmaj7, G7, Am7, and Bm7b5. Play them in order and you've built the entire diatonic 7th-chord ladder for the key of C.</p>
<p>[NOTATION: diatonic 7th chord ladder in C major — Cmaj7, Dm7, Em7, Fmaj7, G7, Am7, Bm7b5, Cmaj7]</p>

<h2 id="section-ladder-pattern">The Pattern Never Changes</h2>
<p>Notice the order of chord qualities: major7, minor7, minor7, major7, dominant7, minor7, minor7b5. That exact sequence happens in every major key, every time — only the letter names change. That's the whole idea behind numbering chords by scale degree instead of by letter name.</p>

<h2 id="section-nashville-numbers">Introducing Nashville Numbers</h2>
<p>Instead of saying "Cmaj7, Dm7, Em7...", number each chord by its scale degree: 1, 2m, 3m, 4, 5, 6m, 7m7b5. Now the same numbers describe the identical pattern in any key — in G major it's Gmaj7(1), Am7(2m), Bm7(3m), Cmaj7(4), D7(5), Em7(6m), F#m7b5(7m7b5). Same shape, different letters.</p>

<sbn-info heading="Why this matters" items="Nashville numbers let you transpose a progression instantly without rewriting it|Chord qualities (m7, maj7, dom7, m7b5) are determined entirely by which scale step you're on|This is the same logic CAGED uses for chord shapes — one pattern, movable to any root"></sbn-info>
```

---

### Lesson 2 — "Chords in Pairs"
- id 121 (verify) · slug `chords-in-pairs`
- section_title: `Foundations` · is_preview: 0 · sort_order: 2
- concept_slug: `nashville-number-system`

```html
<h2 id="section-pairs-intro">Grouping the Ladder</h2>
<p>The seven diatonic chords are easier to internalize in pairs than all at once. Try grouping them as 1-2m, 3m-4, 5-6m, 7m7b5-1:</p>
<p>[NOTATION: C major diatonic chords grouped in pairs — Cmaj7/Dm7, Em7/Fmaj7, G7/Am7, Bm7b5/Cmaj7]</p>
<p>Each pair shares three out of four notes — moving between them is mostly a one- or two-note adjustment, not a totally new shape. That's worth feeling out slowly before trying to play the full ladder at tempo.</p>

<h2 id="section-pairs-numbers">Numbering the Pairs</h2>
<p>In Nashville numbers, those same pairs are 1-2m, 3m-4, 5-6m, 7m7b5-1 — and that labeling holds in every major key. Try saying the numbers out loud as you play through the pairs in C, then try the same pairs in G or F using only the numbers as your guide before checking the letter names.</p>

<sbn-info heading="Practice approach" items="Play each pair slowly, listening for which notes move and which stay put|Say the Nashville number out loud as you play each chord|Once C feels solid, try building the same numbered ladder starting on G or F"></sbn-info>
```

---

### Lesson 3 — "The ii-V-I Cadence"
- id 122 (verify) · slug `the-ii-v-i-cadence`
- section_title: `Cadences` · is_preview: 0 · sort_order: 3
- concept_slug: `nashville-number-system`

```html
<h2 id="section-ii-v-i-intro">The Most Important Three Chords in the Ladder</h2>
<p>Out of all seven diatonic chords, three of them — 2m, 5, and 1 — form the single most common cadence in jazz and pop harmony: the ii-V-I. In C major that's Dm7, G7, Cmaj7:</p>
<p>[NOTATION: ii-V-I cadence in C major — Dm7, G7, Cmaj7, Cmaj7]</p>

<h2 id="section-ii-v-i-why">Why It Works</h2>
<p>The ii chord sets up tension that the V chord (a dominant 7th, the most unstable-sounding chord in the ladder) intensifies, and the I chord finally resolves it. Once you can spot 2m-5-1 by its numbers, you'll find it constantly — inside songs, inside solos, and inside chord charts that otherwise look unfamiliar.</p>

<sbn-info heading="Practice approach" items="Loop the ii-V-I slowly and listen for the resolution at the I chord|Find a ii-V-I in a leadsheet you already know and label it with Nashville numbers|Try the same progression in G (Am7-D7-Gmaj7) and F (Gm7-C7-Fmaj7)"></sbn-info>
```

---

### Lesson 4 — "Borrowing the Relative Minor: a Secondary ii-V to vi"
- id 123 (verify) · slug `secondary-ii-v-to-the-relative-minor`
- section_title: `Cadences` · is_preview: 0 · sort_order: 4
- concept_slug: `nashville-number-system`

```html
<h2 id="section-secondary-intro">Targeting a Chord Other Than 1</h2>
<p>The ii-V-I cadence doesn't only resolve to the I chord — the same trick works to resolve to any diatonic chord, including the vi chord (the relative minor). This passage is in G major: it opens with a normal ii-V-I (Am7-D7-Gmaj7), then immediately sets up its own ii-V aimed at the vi chord (Em7) instead of resolving back to G:</p>
<p>[NOTATION: Am7-D7 | Gmaj7 | F#m7b5-B7 | Em7 — ii-V-I in G major followed by a secondary ii-V to vi]</p>

<h2 id="section-secondary-why">Borrowing From the Relative Minor</h2>
<p>F#m7b5 and B7 aren't part of G major's diatonic ladder in their usual role — they're borrowed because they're the ii and V of E minor, the relative minor of G. This is exactly the same ii-V-I logic from the last lesson, just aimed at a different target chord. The numbering system still works here: think of it as "2m-5 of 6m."</p>

<sbn-info heading="Practice approach" items="Play the full four-chord passage slowly, noticing where it resolves|Compare this to a plain ii-V-I — same shape, different target|Try building a secondary ii-V to the vi chord in C major (Cmaj7's vi is Am — its ii-V is Bm7b5-E7)"></sbn-info>
```

---

## Deferred / not used

- m11-18 of the source file (duplicate pitches, mismatched chord-symbol labels) — likely a score-authoring artifact. Worth a quick look in MuseScore directly if anyone wants to recover what was originally intended there.
- m23-34 (diminished passing chords connecting diatonic chords chromatically) — coherent-looking content but denser and more tangled than the rest of the file; better suited as a future addition to course 69 (Diminished Chords) than as part of this course.

## Implementation checklist

1. Resolve any DB integrity concerns per CLAUDE.md before writing.
2. Copy `database/sbn.db` to a safe working path.
3. Confirm real next `id` values for `sbn_courses` / `sbn_lessons`.
4. Insert course + 4 lesson rows above.
5. Insert `sbn_course_skill_node` rows for `nashville-number-system` and `leadsheet-reading`.
6. Copy DB back, verify with `PRAGMA integrity_check` + row counts.
7. Update `docs/SBN-Skill-System-Plan.md` course→skill-node table and gaps section.

# Approach Notes & Enclosures — Draft for Implementation

Status: **content drafted, NOT yet written to the database.** Pure brief — no DB rows inserted.

Follow the CLAUDE.md DB workflow (copy `database/sbn.db` to a non-mounted path, write there, verify, copy back). **Re-check `MAX(id)` before using the ids below** — they assume the CAGED course (73), Diatonic Chords/Nashville Numbers course (74), and Arpeggio Shapes course (75) all land first. Recompute if that ordering changes.

- Course id: **76**
- Lesson ids: **130–135**

A correction worth flagging up front: the source file's raw `<measure>` element count is 442, but that's because the MusicXML export has **two parts** (likely notation + tab) each independently numbered 1–221. The piece is actually **221 measures long**, not 442 — verified by checking part counts directly. All measure ranges below refer to the real (221-measure) numbering.

---

## Course metadata

| Field | Value |
|---|---|
| slug | `approach-notes-and-enclosures` |
| title | Approach Notes & Enclosures |
| excerpt | One small melodic trick — approaching a chord tone from a neighboring note instead of landing on it directly — is behind a huge amount of what makes a melodic line sound "finished." |
| description | This course builds the approach-note technique from the ground up: starting with a single neighbor note leading into a chord tone, then combining approaches from both sides into full "enclosures," then applying the technique across longer chord progressions. Examples are drawn from both classical and jazz repertoire, showing the same device at work across very different styles. |
| genres | `["jazz","classical"]` |
| levels | `["intermediate"]` |
| level | `intermediate` |
| topics | `["approach notes","enclosures","melodic embellishment","improvisation","bebop vocabulary"]` |
| is_free | 1 |
| status | `publish` |
| category | `jazz` |
| sort_order | next available |
| learning_outcomes | (newline-separated, see below) |

**learning_outcomes:**
```
Approach a chord tone (root, 3rd, or 5th) from a single neighboring note
Recognize approach-note technique in familiar classical and jazz melodies
Build a diatonic-above/chromatic-below enclosure around any chord tone
Combine diatonic and chromatic approaches from both sides for a fuller enclosure
Apply approach-note technique across a full chord progression, not just a single chord
```

---

## Skill node mapping

- `motivic-development` (Melody) — primary node this course closes
- `improvisation-over-changes` (Melody) — secondary; note its other listed prerequisites (`arpeggio-shapes`, `ii-v-i-major`) aren't fully covered by this course alone, so don't treat this course as satisfying that node on its own — it contributes toward it

---

## Source file and verified structure

`Partituren/Theorie/JAZZ - Umspielungen.musicxml` ("Anspielungen an Akkordtöne" — approaches to chord tones), 221 measures, confirmed via `<words>` section markers:

| Measures | Section | Content |
|---|---|---|
| m1-6 | Single approach notes | Approaching the root (m1), 3rd (m3), and 5th (m5) from one neighbor note each |
| m7-14 | Combinations | Combining approaches to different chord tones in sequence |
| m15-26 | Real-tune examples | Für Elise and "I Found a New Baby" approaching the 5th; "Mood Indigo" approaching the 3rd; "The Pink Panther" approaching the root |
| m27-31 | Further combinations in longer forms | Setting up the technique across a full progression, not just one chord |
| m32-63 | Longer-form examples | Au Privave, Billie's Bounce, a Kurt Rosenwinkel solo pickup, Chopin's Valse in A minor, "Stars Fell on Alabama" |
| m64-72 | The enclosure: diatonic above, chromatic below | Applied to root, 3rd, and 5th |
| m73-88 | Enclosure examples | Rondo Alla Turca, "You and the Night and the Music", Solar |
| m89-100 | Multi-step enclosures | Combining diatonic and chromatic approaches from both sides; four combination types (major 3rd diatonic/chromatic, minor 3rd chromatic/chromatic, 5th diatonic/chromatic, root chromatic/chromatic), plus "Yesterdays" and "You're My Everything" as diatonic examples |
| m101-105 | Examples | (unlabeled beyond "Beispiele:" — not individually itemized) |
| m106-221 | Practice drills | "1. von unten" (from below, m114-137), "Chromatisch" (m138-167), "Diatonisch" + "1. von unten" (m168-191), "Chromatisch" (m192-221) — a large, systematic drill section |

The real-tune references (Für Elise, I Found a New Baby, Mood Indigo, The Pink Panther, Au Privave, Billie's Bounce, Stars Fell on Alabama, Rondo Alla Turca, You and the Night and the Music, Solar, Yesterdays, You're My Everything) are **not** in `sbn_leadsheets` per the slugs documented in CLAUDE.md. Use `[NOTATION: ...]` placeholders for these — do not invent leadsheet slugs.

**Not individually verified / out of scope for this draft:** the practice-drill section (m106-221, ~115 measures) was confirmed structurally (the four labeled sub-sections above) but not transcribed measure-by-measure — it's treated below as a practice routine to summarize, not literal content to reproduce. If more granular lesson content is wanted from this section later, it needs its own pass.

---

## Lessons

### Lesson 1 — "What Is an Approach Note?"
- id 130 (verify) · course_id 76 · slug `what-is-an-approach-note`
- section_title: `Foundations` · is_preview: **1** · sort_order: 1
- concept_slug: `motivic-development`

```html
<h2 id="section-approach-intro">Don't Land Directly — Approach</h2>
<p>Instead of jumping straight to a chord tone, play a single note right before it that leads into it — usually a step above or below. That's an approach note, and it's one of the simplest, most useful melodic devices in any style of music.</p>

<h2 id="section-approach-targets">Three Targets</h2>
<p>You can approach any chord tone, but three come up constantly: the root, the 3rd, and the 5th.</p>
<p>[NOTATION: single approach note leading into the root of a chord]</p>
<p>[NOTATION: single approach note leading into the 3rd of a chord]</p>
<p>[NOTATION: single approach note leading into the 5th of a chord]</p>

<h2 id="section-approach-combinations">Combining Targets</h2>
<p>Once each one feels natural on its own, try linking approaches to different chord tones back to back within the same phrase:</p>
<p>[NOTATION: combined approach-note figures targeting root, 3rd, and 5th in sequence]</p>

<sbn-info heading="Practice approach" items="Play the target chord tone alone first, then add the approach note right before it|Try both a half-step and whole-step approach into the same target and compare|This is the foundation for everything else in this course — don't rush past it"></sbn-info>
```

---

### Lesson 2 — "Approach Notes in Familiar Melodies"
- id 131 (verify) · slug `approach-notes-in-familiar-melodies`
- section_title: `Foundations` · is_preview: 0 · sort_order: 2
- concept_slug: `motivic-development`

```html
<h2 id="section-familiar-intro">You've Already Heard This</h2>
<p>Approach notes aren't a jazz-only trick — they show up everywhere, including melodies you already know well.</p>

<h2 id="section-familiar-fifth">Approaching the 5th</h2>
<p>Beethoven's "Für Elise" and the standard "I Found a New Baby" both lean on an approach note into the 5th of the chord at key moments:</p>
<p>[NOTATION: opening phrase of Für Elise, highlighting the approach note into the 5th]</p>
<p>[NOTATION: phrase from "I Found a New Baby", highlighting the approach note into the 5th]</p>

<h2 id="section-familiar-third">Approaching the 3rd</h2>
<p>"Mood Indigo" uses the same idea targeting the 3rd:</p>
<p>[NOTATION: phrase from "Mood Indigo", highlighting the approach note into the 3rd]</p>

<h2 id="section-familiar-root">Approaching the Root</h2>
<p>And the theme from "The Pink Panther" targets the root the same way:</p>
<p>[NOTATION: phrase from "The Pink Panther", highlighting the approach note into the root]</p>

<sbn-info heading="Practice approach" items="Listen for the approach note first, then find it on the page|Once you can spot it in a melody you know, start listening for it in melodies you don't|Try replacing the approach note with a direct jump to the target and compare how much flatter it sounds"></sbn-info>
```

---

### Lesson 3 — "Embellishments in Longer Forms"
- id 132 (verify) · slug `embellishments-in-longer-forms`
- section_title: `Application` · is_preview: 0 · sort_order: 3
- concept_slug: `motivic-development`

```html
<h2 id="section-longer-forms-intro">Beyond a Single Chord</h2>
<p>Approach notes get more interesting once a progression is moving — each new chord gives you a fresh target to approach. This shows up across a wide range of material: jazz blues heads, a contemporary jazz solo excerpt, and even classical and pop repertoire.</p>
<p>[NOTATION: excerpt from "Au Privave", highlighting approach-note figures across the changes]</p>
<p>[NOTATION: excerpt from "Billie's Bounce", highlighting approach-note figures across the changes]</p>
<p>[NOTATION: solo pickup segment in the style of Kurt Rosenwinkel, highlighting approach-note figures]</p>
<p>[NOTATION: excerpt from Chopin's Valse in A minor, highlighting an approach-note figure]</p>
<p>[NOTATION: excerpt from "Stars Fell on Alabama", highlighting approach-note figures]</p>

<sbn-info heading="Practice approach" items="Pick one of these examples and isolate just the approach notes, ignoring everything else|Notice that the technique doesn't change between genres — only the harmonic context does|Blues heads like these are a natural next step once this course's techniques feel solid"></sbn-info>
```

---

### Lesson 4 — "The Enclosure: Diatonic Above, Chromatic Below"
- id 133 (verify) · slug `the-enclosure-diatonic-above-chromatic-below`
- section_title: `Application` · is_preview: 0 · sort_order: 4
- concept_slug: `motivic-development`

```html
<h2 id="section-enclosure-intro">Approaching From Both Sides</h2>
<p>An enclosure surrounds a target note with two approach notes instead of one — typically a diatonic (in-key) note from above and a chromatic (half-step) note from below. This is one of the most recognizable building blocks of bebop melodic language.</p>
<p>[NOTATION: diatonic-above/chromatic-below enclosure around the root]</p>
<p>[NOTATION: diatonic-above/chromatic-below enclosure around the 3rd]</p>
<p>[NOTATION: diatonic-above/chromatic-below enclosure around the 5th]</p>

<h2 id="section-enclosure-examples">Where It Shows Up</h2>
<p>[NOTATION: excerpt from Rondo Alla Turca, highlighting an enclosure figure]</p>
<p>[NOTATION: excerpt from "You and the Night and the Music", highlighting an enclosure figure]</p>
<p>[NOTATION: excerpt from "Solar", highlighting an enclosure figure]</p>

<sbn-info heading="Practice approach" items="Play the enclosure slowly as three even notes before trying it at tempo|The chromatic note from below is the one that gives this its bebop flavor — don't skip it|Try this enclosure pattern on a target note you chose yourself, not just the examples here"></sbn-info>
```

---

### Lesson 5 — "Multi-Step Enclosures"
- id 134 (verify) · slug `multi-step-enclosures`
- section_title: `Application` · is_preview: 0 · sort_order: 5
- concept_slug: `motivic-development`

```html
<h2 id="section-multistep-intro">A Fuller Surround</h2>
<p>Once the basic enclosure feels comfortable, try combining diatonic and chromatic approaches from both sides for a more elaborate figure around the same target.</p>
<p>[NOTATION: "Yesterdays" excerpt, diatonic multi-step enclosure]</p>
<p>[NOTATION: "You're My Everything" excerpt, diatonic multi-step enclosure]</p>

<h2 id="section-multistep-types">Four Combinations</h2>
<p>[NOTATION: major 3rd target — diatonic approach above, chromatic approach below]</p>
<p>[NOTATION: minor 3rd target — chromatic approach above, chromatic approach below]</p>
<p>[NOTATION: 5th target — diatonic approach above, chromatic approach below]</p>
<p>[NOTATION: root target — chromatic approach above, chromatic approach below]</p>

<sbn-info heading="Practice approach" items="Work through these one combination at a time — there's no need to learn all four at once|Notice how the minor 3rd and root both use chromatic notes from both sides, while the major 3rd and 5th mix diatonic and chromatic|These four patterns are worth memorizing as a set, since they cover the most common targets you'll meet"></sbn-info>
```

---

### Lesson 6 — "Practice Drills"
- id 135 (verify) · slug `approach-note-practice-drills`
- section_title: `Putting It Together` · is_preview: 0 · sort_order: 6
- concept_slug: `motivic-development`

```html
<h2 id="section-drills-intro">Building the Habit</h2>
<p>The techniques in this course reward repetition more than analysis. This lesson is a structured practice routine rather than a set of new ideas — work through it slowly, in order.</p>

<h2 id="section-drills-from-below">From Below</h2>
<p>[NOTATION: approach-note drill pattern "from below," applied across a moving set of target notes]</p>

<h2 id="section-drills-chromatic">Chromatic</h2>
<p>[NOTATION: chromatic approach drill pattern, applied across a moving set of target notes]</p>

<h2 id="section-drills-diatonic">Diatonic</h2>
<p>[NOTATION: diatonic approach drill pattern, "from below," applied across a moving set of target notes]</p>

<h2 id="section-drills-chromatic-2">Chromatic, Revisited</h2>
<p>[NOTATION: second chromatic approach drill pattern, applied across a moving set of target notes]</p>

<sbn-info heading="How to practice this" items="Loop each drill slowly with a metronome before increasing tempo|Move on to the next drill only once the current one feels automatic|Revisit lesson 4 and 5 if any individual drill pattern feels unfamiliar"></sbn-info>
```

---

## Deferred / not used

- The practice-drill section (m106-221) was confirmed structurally but not transcribed measure-by-measure. If finer-grained drilling content is wanted later (e.g. breaking "Chromatisch" into its own multi-lesson treatment), it needs a dedicated extraction pass.
- None of the real-tune references in this course (classical pieces, jazz standards, a blues head or two) exist yet in `sbn_leadsheets`. If any of these are added to the catalog later (most plausibly the jazz standards), the `[NOTATION: ...]` placeholders referencing them could be upgraded to real `<sbn-song>` embeds.
- The BLUES-genre source files in the same folder (`BLUES - Licks.musicxml`, etc.) weren't touched here — flagged previously as a separate catalog-scope decision, not part of this course.

## Implementation checklist

1. Resolve any DB integrity concerns per CLAUDE.md before writing.
2. Copy `database/sbn.db` to a safe working path.
3. Confirm real next `id` values for `sbn_courses` / `sbn_lessons`.
4. Insert course + 6 lesson rows above.
5. Insert `sbn_course_skill_node` rows for `motivic-development` and `improvisation-over-changes`.
6. Copy DB back, verify with `PRAGMA integrity_check` + row counts.
7. Update `docs/SBN-Skill-System-Plan.md` course→skill-node table and gaps section.

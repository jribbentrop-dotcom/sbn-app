# SBN Skill Node Expansion — Content Audit & Proposal

> Companion to `SBN-Skill-System-Plan.md`. This is a proposal, not a seeded change — nothing in
> `sbn_skill_nodes` / `sbn_skill_node_prerequisites` / `sbn_course_skill_node` has been modified.
> Audited against a verified-good snapshot of the live DB (44 tables, integrity-checked) on 2026-06-23.

## What was audited

Current graph: **38 nodes**, 40 prerequisite edges, 72 course-node pivot rows, across Harmony (11),
Technique (9), Melody (6), Ear Training (4), Reading & Theory (4), Rhythm (4) — slightly ahead of the
35/38 the plan doc records, so some curation has happened since that doc was last updated.

Cross-checked against actual content, not just the taxonomy draft:

- 31 rows in `sbn_rhythm_patterns`
- 45 rows in `sbn_chord_progressions` (categories: jazz, pop, classical, bossa-nova)
- 65 `sbn_leadsheets`, 16 `sbn_exercises`, 260 `sbn_chord_diagrams`
- All 118 lessons across 18 published courses — titles, section titles, and full HTML body
- Every `<sbn-widget>`, `<sbn-fretboard>`, `<sbn-progression>` embed and its slug (the "theory widgets")
- The tag cloud (`sbn_tags` — 12 tags, all genre/vibe, confirming the plan doc's caveat that tags rarely
  match skill granularity)

`sbn_jazz_standards` (1,382 rows) was checked but excluded from this pass — it's a reference/lookup
database, not lesson content, and isn't pedagogically sequenced anywhere. Worth mining later for
repertoire nodes (post-v1 per the roadmap), not for the skill graph itself.

## Headline findings

1. **Course 70 (Chord Progressions & Voice Leading) is the single biggest gap.** 8 lessons, but only 3
   of its taught concepts (triads, chord-inversions, ii-v-i-major) have nodes. Lessons 2–8 — classical
   cadences, jazz turnarounds, pop progressions, borrowed chords, secondary dominants, voice leading —
   map to **zero** nodes today, despite being backed by 30+ rows in `sbn_chord_progressions`.
2. **Course 5 (Choro) is still completely unmapped** (confirmed: 0 rows in `sbn_course_skill_node`),
   and Course 10 is literally named *"The Clave: Latin Rhythm 101"* but has no clave node — it only
   maps to the generic pulse/feel/syncopation trio. The rhythm branch has 4 nodes covering 31 patterns;
   that's the most under-built branch relative to content volume.
3. **The taxonomy's own first draft listed "3/4 / Waltz feel" and "Polyrhythm" under Rhythm — neither
   was ever seeded**, even though both have direct pattern evidence (`waltz`; `cross-rhythm-i` = 3-over-4).
4. **The "theory widgets" are real, separate atomic concepts currently absorbed into broader nodes.**
   `time-signature` and `scale-steps` in particular are taught as standalone widgets in Music Theory
   Basics but have no node of their own — they're foundational enough to sit *below* existing root nodes
   like `pulse-subdivision`.
5. **No content anywhere teaches absolute guitar fundamentals** — tuning, fretboard note names, open
   chords, how to hold a pick. I checked every lesson/section title for these keywords; the only "basic"
   on-ramp that exists is "Bossa Nova Basics," which already assumes you can play open 7th-chord shapes.
   This is a genuine curriculum gap, not a node-modeling gap — flagged separately below since it would
   need new content, not just a new node pointing at existing content.

## Proposed new nodes

All of these are backed by specific rows/lessons (cited), not guesses. None require new content except
the one flagged in its own section at the end.

### Rhythm (4 → 10)

| Slug | Title | Sub-branch | Prereq | Evidence |
|---|---|---|---|---|
| `meter-basics` | Meter & Time Signatures | Foundations | *(none — floor)* | `time-signature` widget, Music Theory Basics |
| `waltz-feel` | 3/4 / Waltz Feel | Feels | `pulse-subdivision` | `waltz` pattern; in original taxonomy draft, never seeded |
| `polyrhythm` | Polyrhythm | Feels | `syncopation` | `cross-rhythm-i` ("3 over 4") pattern; also in original draft, never seeded |
| `swing-feel` | Swing Feel | Feels | `pulse-subdivision` | `swing`, `charleston`, `second-line` patterns; Wes Montgomery / Pat Metheny courses are swing-jazz but currently have no swing node |
| `clave-systems` | Clave Systems | Latin Rhythm *(new sub-branch)* | `two-four-feel` | `bossa-nova-clave`, `son-clave-2-3/3-2`, `rumba-clave-2-3/3-2`, `afro-jazz-clave`; Course 10 is named after this concept and doesn't have it |
| `brazilian-rhythm-styles` | Brazilian & Afro-Latin Rhythm Styles | Latin Rhythm | `clave-systems`, `two-four-feel` | `baiao`, `partido-alto` (+reversed), `choro`, `samba-brasil`; closes the Course 5 (Choro) mapping gap |

`meter-basics` is deliberately the new floor: I'd also add it as a prerequisite of `pulse-subdivision`
itself, since right now `pulse-subdivision` is a root node with nothing under it.

### Harmony (11 → 18)

All seven of these come directly from Course 70's lesson list, which currently has no node for 7 of
its 8 lessons:

| Slug | Title | Sub-branch | Prereq | Evidence |
|---|---|---|---|---|
| `diatonic-harmony` | Diatonic Harmony (Building the Scale's Chords) | Foundations | `triads` | Lesson 1, "Building the Diatonic Chords" |
| `cadences` | Cadences (Classical) | Progressions | `diatonic-harmony` | Lesson 2; `perfect-authentic-cadence`, `deceptive-cadence`, `minor-plagal-cadence`, `tonic-dominant`, `dorian-vamp-i-iv` |
| `pop-progressions` | Pop & Folk Progressions | Progressions | `triads` *(intentionally NOT behind ii-V-I)* | Lesson 4; 8 rows tagged `pop` (`i-v-vi-iv`, `i-vi-iv-v-50s-progression`, etc.) |
| `turnarounds` | Turnarounds | Progressions | `ii-v-i-major` | Lesson 3; `the-turnaround`, `the-bill-evans-turnaround`, `the-extended-turnaround` |
| `secondary-dominants` | Secondary Dominants | Reharmonization | `ii-v-i-major` | Lesson 7; `ii-v-to-relative-major`, `ii-v-to-relative-minor` |
| `borrowed-chords` | Borrowed Chords / Modal Interchange | Reharmonization | `diatonic-harmony`, `cadences` | Lessons 5–6; `modal-interchange`, `modal-interchange-i-to-im` |
| `voice-leading` | Smooth Voice Leading | Voicings | `drop2-voicings` | Lesson 8, "Smooth Voice Leading Through Progressions" |

`pop-progressions` is the one I'd flag as the best "very basic" entry point of this whole batch — it
only needs `triads`, not the jazz-voicing chain, so a brand-new student can reach it almost immediately.

### Reading & Theory (4 → 6)

| Slug | Title | Sub-branch | Prereq | Evidence |
|---|---|---|---|---|
| `scale-degrees` | Scale Degrees & Roman Numerals | Foundations | *(none — floor)* | `scale-steps` widget, Music Theory Basics "Melody" lesson |
| `tab-reading-basics` | Tab Reading Basics | Notation | *(none — floor)* | `tab-diagram` widget, Music Theory Basics |

`scale-degrees` is worth wiring in as an *additional* prerequisite on existing nodes that already
silently assume it: `diatonic-harmony`, `cadences` (both above), plus the existing `leadsheet-reading`
and `nashville-number-system`. Right now those two only require `standard-notation-basics`/`triads` and
`leadsheet-reading` respectively — scale-degree naming is arguably more fundamental to both than what's
currently listed.

### Technique — flagged separately, not evidenced by existing content

`fretboard-note-names` ("Notes on the Fretboard") would be a genuinely basic node — arguably a
prerequisite of `caged-system`, `position-shifting`, and `shell-voicings` (which all implicitly assume
you can find notes on the neck). I checked: **no lesson currently teaches this.** "The Fretboard Theory"
lesson in Course 3 explicitly says "you are already familiar with the open-position seventh chord
shapes" and jumps straight to moving shapes around — it assumes the knowledge rather than teaching it.
Adding this node now would create a node with zero content behind it. I'd treat it as a content gap to
solve first (a short lesson or widget), then add the node, rather than seeding an empty one.

## Edge additions to existing nodes

Beyond the new nodes above:

- `pulse-subdivision` ← `meter-basics` (makes meter-basics the true floor under rhythm)
- `nashville-number-system` ← `scale-degrees` (in addition to its current `leadsheet-reading` prereq)
- `leadsheet-reading` ← `scale-degrees` (in addition to current `standard-notation-basics`, `triads`)

## Course → node mapping fixes once nodes exist

| Course | Add |
|---|---|
| 70 Chord Progressions & Voice Leading | `diatonic-harmony`, `cadences`, `pop-progressions`, `turnarounds`, `secondary-dominants`, `borrowed-chords`, `voice-leading` |
| 5 Choro: The Ancestor of Bossa Nova | `brazilian-rhythm-styles`, `clave-systems`, `two-four-feel`, `syncopation`, `pulse-subdivision` (closes the only fully-unmapped course) |
| 10 The Clave: Latin Rhythm 101 | `clave-systems` |
| 7 Latin Side of Pat Metheny | `swing-feel` |
| 8 Latin Side of Wes Montgomery | `swing-feel` |
| 12 Music Theory Basics | `scale-degrees`, `tab-reading-basics`, `meter-basics` |
| 69 Diminished Chords | `secondary-dominants`, `turnarounds` |

## What I didn't touch

- Style classes, repertoire nodes — out of scope per the v1 lock, not revisited here.
- `sbn_jazz_standards` (1,382 tunes) — a lookup database, not curriculum; a future repertoire-node pass
  could mine it for blues/rhythm-changes/AABA-form examples, but that's a different kind of audit.
- No cycle/dangling-reference check was run on the *proposed* edges yet — do that before seeding (same
  check the original 38-node graph already passed).

## Open questions for you

1. Want me to write these straight into `SkillNodeSeeder.php` (code, for you to review/run `migrate --seed`
   yourself), or insert them directly into a DB working copy using the established copy-out/copy-back
   pattern so they're live without you touching artisan?
2. `clave-systems` / `brazilian-rhythm-styles` as a new "Latin Rhythm" sub-branch under Rhythm, or fold
   them into the existing "Feels" sub-branch?
3. Want the `fretboard-note-names` content gap turned into an actual to-do (lesson/widget brief), or park it?

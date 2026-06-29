> ⚠️ **ARCHIVED 2026-06-29.** Analysis acted on: rhythm-ladder nodes + course mappings shipped;
> the surviving open item (recommendation #1, Course 9 technique rewrite) is tracked in
> `docs/SBN-Skill-System-Plan.md`. Kept for the 108-node comparison + technique-gap analysis.

# Skill Nodes Brainstorm — Cross-Reference vs. Built System

> Compares `Skill Nodes.docx` (108-node brainstorm, uploaded 2026-06-29) against the live skill-node
> system (`sbn_skill_nodes`, 58 nodes) and the course catalog. See `SBN-Skill-System-Plan.md` for the
> as-built system this is being checked against.

## TL;DR

The brainstorm doc is a **classical/fingerstyle-technique-first curriculum ladder** (5 graded levels,
posture → mechanics → repertoire-grade performance). The built system is a **concept-graph weighted
toward bossa/jazz harmony and rhythm**, matching the course catalog. They overlap heavily in Harmony
and Rhythm but diverge sharply in Technique: the brainstorm has 37 posture/right-hand/left-hand
mechanics nodes against the built system's 10. That's the one branch worth real investment. Most of
the rest is a granularity mismatch (one built node per concept vs. several brainstorm nodes per
level/string-set), not a content gap.

## The two systems, side by side

**Brainstorm doc** — 108 nodes, 5 levels, 9 categories (flat CSV pasted into Word; one stray
non-English artifact line removed):

| Category | L1 | L2 | L3 | L4 | L5 | Total |
|---|---|---|---|---|---|---|
| Right Hand Mechanics | 5 | 1 | 2 | 4 | 2 | 14 |
| Left Hand Mechanics | 2 | 4 | 1 | 1 | 2 | 10 |
| Postures & Setup | 3 | 0 | 0 | 0 | 0 | 3 |
| Harmony & Chords | 3 | 8 | 4 | 5 | 1 | 21 |
| Drop Chord System | 0 | 2 | 7 | 0 | 1 | 10 |
| Rhythm & Groove | 3 | 5 | 2 | 1 | 1 | 12 |
| Scales & Improvisation | 2 | 2 | 4 | 3 | 3 | 14 |
| Music Theory & Literacy | 2 | 3 | 2 | 2 | 1 | 10 |
| Advanced Performance | 0 | 0 | 6 | 4 | 4 | 14 |

**Built system** — 58 nodes, 6 branches, ungraded levels collapse to G1–G5:

| Branch | Nodes | Notes |
|---|---|---|
| harmony | 19 | shells, drop2/drop3, ii-V-I, turnarounds, reharm, chord melody |
| rhythm | 10 | meter, subdivision, 2/4 feel, syncopation, swing, clave, Brazilian styles |
| technique | 10 | muting, spider, fingerpicking, RH/thumb independence, CAGED, barre, position shift, legato, tone |
| melody | 9 | scales, pentatonic, chromatic, arpeggios, improv, motivic development |
| reading-theory | 6 | notation basics, tab, rhythm notation, scale degrees, leadsheet, Nashville numbers |
| ear-training | 4 | interval/chord-quality recognition, rhythm/melodic dictation |

Both systems are real and live: the built one has 62 prerequisite edges, mappings into 20 of 23
courses, 8 content links (rhythms/progressions/songs) plus 2 chord-voicing categories, and 74
style-weight rows. The brainstorm doc is unstructured relative to that — no slugs, no prerequisites, no
course links — so "adding" any of it means doing that modeling work, not just pasting titles in.

## Branch-by-branch cross-reference

### Technique (the real gap)

Built `technique` has 10 nodes. The brainstorm's three technique-adjacent categories (Right Hand
Mechanics, Left Hand Mechanics, Postures & Setup) total **37 nodes** — PIMA finger assignment, rest
stroke / free stroke, finger angle, thumb pivot, nail shaping for tone, hammer-ons, pull-offs, slides,
left/right-hand damping, vibrato, natural/artificial harmonics, campanella, classical tremolo,
rasgueado (basic + extended), Travis picking, percussive body taps, cross-string trills, posture
(classical/casual), tuning.

None of these have a corresponding node today, and — more importantly — **the content doesn't clearly
exist as standalone lessons either.** Course 9, "Right Hand Technique for Nylon Guitar," is the
obvious home, but its 3 lessons are organized around repertoire (a Villa-Lobos étude, Tárrega/Sor,
Gilberto's batida), not atomic mechanics like rest-stroke or PIMA assignment. So this isn't a tagging
gap — it's a genuine content gap if you want these as teachable, completable nodes rather than just
checklist items.

This is also the brainstorm's strongest, most original contribution: classical-guitar technique
fundamentals are basically absent from the current taxonomy, which leans on repertoire-based teaching
for technique instead of decomposed skills.

### Harmony & Chords + Drop Chord System

Built `harmony` (19 nodes) already covers shells, drop2, drop3, triads, inversions, ii-V-I (major +
minor), turnarounds, tritone sub, secondary dominants, borrowed chords, chord melody, voice leading,
cadences, pop progressions — essentially everything the brainstorm's "Harmony & Chords" (21 nodes)
proposes, just at a higher level of abstraction.

The brainstorm's "Drop Chord System" (10 nodes) is the interesting case: it breaks Drop 2 into
root/inv1/inv2/inv3 on one string set plus a separate "all inversions" node on another string set, and
Drop 3 into 6th-string and 5th-string inversions separately. The built system has exactly **one** node
each for `drop2-voicings` and `drop3-voicings`. The underlying chord-diagram data already supports the
finer grain — diagrams like `dom7-drop2-roota-inv1/inv2/inv3` exist in `sbn_chord_diagrams` — so this
is a genuine design choice already made (concept-level nodes, not exercise-level), not a missing
feature. Worth flagging as a decision to revisit only if you want grade-gated sub-checkpoints within a
single voicing type.

One real beginner-tier gap: open chords (109-Open-Major, 110-Open-Minor, 111-Open-Dominant7) and open
scales (119-Open C Major, 120-Open A Minor) have no equivalent node at all. The lowest current harmony
node is `the-basic-8` / `intervals` (G1); lowest melody is `major-minor-scales` / `blues-scale` (G1).
If `music-theory-basics` (course 12) is meant to be the true zero-experience onboarding path, an
explicit "open chords" / "open scale shapes" G1 node pair is a sensible, cheap add.

### Rhythm & Groove

Built `rhythm` (10) vs. brainstorm (12) — close, and this is where the brainstorm doc's bossa/samba
ladder is actually the most useful thing in the whole document for *this* app. It breaks the
bossa/samba feel into a difficulty progression: bossa syncopated push (G2) → alternating bassline (G2)
→ thumb/finger separation (G2) → partido alto groove (G3) → slow samba (G3) → up-tempo samba (G5). The
built system has one `two-four-feel` (G2) node plus `brazilian-rhythm-styles` (G3) and `clave-systems`
(G3) — three checkpoints where the brainstorm has six. Given the course catalog is overwhelmingly
bossa/Brazilian, this progression ladder is a stronger candidate for adoption than most of the
technique branch, and it's on-brand rather than scope creep.

Action item unrelated to the brainstorm doc but surfaced while checking this: courses 77
(`syncopation-off-beat-rhythms`), 78 (`brazilian-rhythms`), 79 (`basic-rhythms`) are draft and currently
have **zero** skill-node mappings. They should map onto `syncopation`, `brazilian-rhythm-styles`, and
`meter-basics`/`pulse-subdivision` respectively before they're published.

### Scales & Improvisation

Built `melody` (9) vs. brainstorm (14) — reasonably aligned. Brainstorm adds graduated checkpoints the
built system doesn't have explicitly: minor pentatonic by position (brainstorm has "Position 1" as its
own G2 node; built has one generic `pentatonic-scale` node), and jazz melodic minor / whole tone /
diminished scale applications (brainstorm G3–G4) which have no equivalent at all today — the built
system jumps from `arpeggio-shapes` (G3) straight to `motivic-development` / `improvisation-over-changes`
(G5) with nothing covering specific advanced scale vocabulary in between. If jazz-improv content is a
priority, this is a real gap; if not, it's fine to leave as a hole the curriculum doesn't currently need.

### Music Theory & Literacy / Reading-Theory

Built `reading-theory` (6) vs. brainstorm (10). The brainstorm proposes a 4-step sight-reading ladder
(basics G1 → 5th position G3 → 9th position G4 → advanced G5); the built system has one flat
`standard-notation-basics` (G1) node with no progression. This branch is one of the two thinnest in the
built system (tied with ear-training) and the brainstorm's leveled approach is a clean, low-cost way to
flesh it out if sight-reading is something you want to track as students advance.

### Ear Training

The brainstorm barely touches this — two items folded into "Music Theory & Literacy" (aural
major-vs-minor G2, aural extension ID G3) rather than a dedicated branch. The built system's dedicated
`ear-training` branch (4 nodes, interval/chord-quality recognition + rhythm/melodic dictation) is
already more developed than what the brainstorm proposes. No action needed here — this is one branch
where the built system is ahead, not behind.

### Advanced Performance

14 brainstorm nodes with no real built-system equivalent: 2-and-3-voice counterpoint, natural/artificial
harmonics, campanella, CAGED minor (built only has CAGED, ungendered), fretboard octave navigation,
Baroque interpretation rules, micro-timing/rubato, concert-level endurance, polyphonic tapping,
Coltrane changes. These are almost all classical-performance or advanced-jazz-soloing skills that sit
outside the current branch taxonomy entirely (closest fits would be technique or melody, but they don't
cleanly belong in either). Worth a deliberate scope call rather than ad-hoc insertion — see below.

## Top recommendations, in priority order

1. **Build out a real technique sub-curriculum** (PIMA, rest/free stroke, posture, nail tone, slurs
   detail, damping) if classical/fingerstyle fundamentals matter to your audience. This is the
   brainstorm's biggest and most legitimate contribution, and it requires new lesson content, not just
   new nodes — `right-hand-technique` doesn't currently teach this material in decomposed form.
2. **Adopt the bossa/samba rhythm progression ladder** (3 new rhythm nodes between `two-four-feel` and
   `brazilian-rhythm-styles`/`clave-systems`). Small lift, high relevance given the catalog's bossa/
   Brazilian center of gravity.
3. **Add an explicit beginner tier**: open chords + open scale shapes as G1 harmony/melody nodes, so
   `music-theory-basics` has a true zero-experience entry point in the graph.
4. **Map the three draft rhythm courses** (77/78/79) to existing nodes before publishing — unrelated to
   the brainstorm doc, but found while checking rhythm-branch coverage.
5. **Leave Drop Chord System and Ear Training alone.** The first is a deliberate granularity choice
   already made (and the underlying chord-diagram data already supports finer grain if you ever want
   it); the second is already more developed in the built system than in the brainstorm.

## Open questions for you

- **Scope of "Advanced Performance"** (counterpoint, harmonics, campanella, Baroque rules, etc.): do
  you want the skill graph to formally cover broad classical-guitar repertoire skills, or stay
  tightly scoped to the bossa/jazz/Brazilian identity the course catalog already has? The style system
  already has a `classical` style tag ready to receive this (currently mostly untagged/neutral nodes),
  so it's not pure scope creep — but 37+ new technique nodes plus 14 advanced-performance nodes would
  roughly double the graph and shift its center of gravity.
- **Granularity**: do you want per-inversion / per-position checkpoints (brainstorm's style) or
  one-node-per-concept (built system's current style)? This is the recurring tension across Drop
  Chord System, pentatonic positions, and sight-reading levels.

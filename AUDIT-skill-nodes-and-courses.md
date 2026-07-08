# Skill Node & Course Audit — 2026-06-30

Read-only audit of `sbn_skill_nodes`, `sbn_course_skill_node`, `sbn_skill_node_content`, `sbn_skill_node_prerequisites`, courses, and lesson HTML. Organized as a step-by-step punch list — check items off as we fix them.

> **RESOLVED 2026-07-02** (verified against `sbn.db`): §1 and §2 are DONE — the "current state"
> numbers below describe the pre-work snapshot and are kept only for history. Live figures now:
> **64 nodes, 62 with content, 510 content rows** (`sbn_skill_node_content`). Per-branch coverage:
> ear-training 4/4, harmony 19/18, melody 9/8, reading-theory 6/6, rhythm 13/13, technique 13/13.
> Only **2 nodes remain unlinked** — `blues` (harmony g1) and `blues-scale` (melody g1) — both are the
> "no course exists, skip for now" nodes the §1 handover explicitly parked. Icons: `icon_key` 64/64,
> `icon_path` 64/64. Courses 68/69 confirmed `draft`. Course 9 rewrite confirmed applied (12 lessons,
> nodes 65–68 present). The remaining open work is NEW (not in this audit): a public per-node
> explanation page, node↔grade surfacing, and grade-page skill-node integration — tracked in
> `docs/SBN-Skill-System-Reference.md`.

---

## 1. Skill nodes with no content attached ✅ DONE

`sbn_skill_node_content` only has 8 rows, covering 4 of 65 nodes (`drop2-voicings`, `ii-v-i-major`, `two-four-feel`, `syncopation`). Every other node is "floating" — it has a course but nothing tells the player which lesson/exercise/leadsheet/chord-diagram teaches it.

*(Historic snapshot — see RESOLVED banner above. Now 62/64 nodes linked, 510 rows.)*

Branch summary (total nodes / linked to a course / has content row):

- ear-training: 4 / 1 / 0
- harmony: 19 / 18 / 2
- melody: 9 / 7 / 0
- reading-theory: 6 / 6 / 0
- rhythm: 13 / 13 / 2
- technique: 14 / 10 / 0

- [x] Decide on a content-linking pass order (suggest: harmony foundations → rhythm → melody → technique → reading-theory → ear-training)
- [x] For each node, add `sbn_skill_node_content` row(s) pointing at the lesson/exercise/leadsheet/chord-diagram that actually teaches it — 510 rows, all branches covered
- [x] Start with foundational nodes that anchor whole branches: `intervals`, `triads`, `chord-inversions`, `shell-voicings`, `foundational-scales`, `meter-basics`, `fingerpicking-basics`
- [ ] `blues` + `blues-scale` still unlinked — deliberately parked (no blues course/content exists yet)

---

## 2. Ear-training branch is effectively a stub ✅ CONTENT-LINKED (product decision still open)

- 4 nodes total: `interval-recognition`, `chord-quality-recognition`, `rhythm-dictation`, `melodic-dictation`
- Only `interval-recognition` is attached to a course (*Intervals: Building Blocks of Harmony*)
- None have content rows
- **No dedicated ear-training course exists at all**

- [x] All 4 ear-training nodes now have content rows (4/4 — verified 2026-07-02)
- [ ] Product decision still open: fold into existing courses vs. build a standalone Ear Training
      course with a listening-drill content type. The nodes are no longer "floating," but a dedicated
      ear-training *product* is still unbuilt.

---

## 3. Fretboard/technique nodes orphaned from the obvious course

- `barre-chords` and `position-shifting` (technique → Fretboard) aren't linked to **any** course
- *The CAGED System* course (id 73) only carries `scale-patterns`, `arpeggio-shapes`, `caged-system` — barre chords and position shifting are core CAGED material and belong here
- `muting` and `the-spider` (technique, no sub-branch) also aren't linked to any course

- [x] Added `barre-chords` and `position-shifting` to *The CAGED System* (course 73)
- [x] Added `the-spider` to *Right Hand Technique for Nylon Guitar* (course 9) — it's a left-hand drill but course 9 is the de facto general technique home
- [x] Merged `muting` into `hand-damping-control` — deleted the `muting` node (id 38) and its nonsensical `foundational-scales` prerequisite; `hand-damping-control` in course 9 covers the concept with better prereqs

---

## 4. Two "published" courses have zero visible lessons

| course | status | lessons | published |
|---|---|---|---|
| Solo Guitar Style of Joe Pass (id 68) | publish | 14 | **0** |
| Diminished Chords — The Secret Weapon of Bossa Nova (id 69) | publish | 13 | **0** |

Both show as live on the site but every lesson inside is still draft — students see an empty course.

- [x] Flipped both courses to `draft` (2026-06-30) — will revisit and publish once lesson content is finished
- [ ] Update CLAUDE.md's course table to reflect draft status (now matches the DB again)

---

## 5. Broken slug references in lesson content

Lessons referencing slugs that don't exist in the current DB (likely renamed/removed since the lesson was written):

| lesson | course | tag | broken slug(s) |
|---|---|---|---|
| Theory of Scales | Music Theory Basics | `sbn-sheet` | `c-major-scale`, `a-minor-scale`, `g-major-scale`, `e-minor-scale`, `f-major-scale`, `d-minor-scale` |
| Recommended Study Pieces Part 1 | Music Theory Basics | `sbn-sheet` | `ode-to-joy` |
| Chords D | Bossa Nova Basics | `sbn-sheet` | `berimbau` *(exists as a leadsheet, not an exercise — wrong tag)* |
| Chords G | Bossa Nova Basics | `sbn-sheet` | `brazil-exercise-2` |
| Chords B | Bossa Nova Basics | `sbn-sheet` | `basic-chords-bm79` |
| Chords Fmaj7 | Bossa Nova Basics | `sbn-sheet` | `gymnopedie` *(exists as leadsheet `gymnopedie-1` — wrong tag + wrong slug)* |
| Samba da Benção | Easy Bossa Nova Songs | `sbn-sheet` | `samba-da-bencao` *(exists as leadsheet — wrong tag)* |
| What is the Pentatonic Scale | Pentatonic Scale: Five Positions | `sbn-sheet` | `a-minor-scale`, `c-major-scale` |
| Syncopation in Melody and Soloing | Syncopation & Off-Beat Rhythms | `sbn-sheet` | `c-major-scale` |
| Right Hand Arpeggio Patterns | Right Hand Technique | `sbn-sheet` | `c-major-scale` |
| Hammer-ons and Pull-offs | Right Hand Technique | `sbn-sheet` | `a-minor-scale` |
| Basics of Notation | Music Theory Basics | `sbn-song` | `the-girl-from-ipanema` *(actual slug is `the-girl-from-ipanema-1`)* |
| Tico Tico (Zequinha Abreu) | Choro: The Ancestor of Bossa Nova | `sbn-song` | `a-night-in-tunisia` *(not in leadsheet library at all)* |

- [x] Fixed wrong-tag cases: `berimbau` → `<sbn-song slug="berimbau">`, `gymnopedie` → `<sbn-song slug="gymnopedie-1">`, `samba-da-bencao` sbn-sheet → `[NOTATION: ...]`
- [x] Fixed ipanema slug typo (`the-girl-from-ipanema` → `the-girl-from-ipanema-1`)
- [x] Replaced `a-night-in-tunisia` with `<sbn-song slug="tico-tico">` (correct Choro-era tune for that lesson)
- [x] `ode-to-joy` — exists as a leadsheet; swapped `<sbn-sheet>` → `<sbn-song slug="ode-to-joy">`
- [x] All major/minor scale exercise refs (8 lessons) → `[NOTATION: ...]` placeholders with context-specific descriptions
- [x] `brazil-exercise-2`, `basic-chords-bm79` → `[NOTATION: ...]` placeholders

*Note: also found `[chord name="..." frets="..."]` WP shortcode in lessons 7, 8, 9, 47 (not in CLAUDE.md's replacement table). No matching `sbn-chord` slug exists for basic open/barre shapes — replaced with `[NOTATION: ...]`. New chord diagram rows would be needed to upgrade these to `<sbn-chord>` properly.*

---

## 6. Leftover WordPress migration artifacts

Per CLAUDE.md's "always replace" table — still present in 12 lessons across 4 courses:

- [x] `[alphatex]` shortcode — Melody Playing on Nylon Guitar: *C Major/A Minor Complete*, *G Major/E Minor Complete*, *F Major/D Minor Complete* → `[NOTATION: ...]` placeholders
- [x] `[rhythm name="..."]` shortcode — The Clave: *The Bembe 6/8* → `[NOTATION: ...]`; *From 6/8 to 4/4* → `<sbn-rhythm slug="rumba-clave-3-2">`; *The Bossa Nova Adaptation* → `<sbn-rhythm slug="bossa-nova-clave">`
- [x] `class="wp-block-heading"` — stripped from all 4 lessons (Bossa Nova Rhythm, Choro Masterpieces, Latin Side of Pat Metheny, The Clave)
- [x] `class="wp-block-list"` — stripped from Melody Playing on Nylon Guitar and The Clave

---

## 7. CLAUDE.md is stale — refresh after the above

- [x] Course table: updated — all 26 courses (ids 1–12, 68–81), correct draft/publish status, notes on free/paid/category
- [x] Course table: courses 68/69 now correctly marked draft
- [x] `<sbn-sheet>` and `<sbn-song>` example slugs corrected in Custom HTML Components section
- [x] Exercise slug table: replaced with full current list (19 exercises, including new jazz-blues entries)
- [x] Leadsheet table: full re-export — all 72 rows with id, slug, title, license_status, is_pro
- [x] Skill nodes section added: all 64 nodes with branch/sub_branch/grade, key tables documented

---

## Suggested working order

1. §4 — empty published courses (student-facing, easy fix)
2. §5 + §6 — broken refs and WP artifacts (content correctness)
3. §3 — fretboard node connections (quick wins)
4. §1 — content-linking pass for skill nodes (largest effort, do branch by branch)
5. §2 — ear-training (needs a product decision first)
6. §7 — CLAUDE.md refresh (last, once the DB stops moving)

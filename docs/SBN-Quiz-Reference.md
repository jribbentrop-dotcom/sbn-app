# SBN Quiz System — Reference & Authoring Guide

> **Authoring from an iPad / DB-only (co-work)?** Use the companion guide
> `SBN-Quiz-Authoring-CoWork.md` instead — it's self-contained and assumes SQL
> access only (no repo, no scripts, no PHP). This doc has the deeper design
> reference for when the repo IS available.
>
> **This doc is the authoring surface.** The quiz *engine* (models, controller,
> grading, Vue runner, `<sbn-quiz>` tag) is built and tested — shipped 2026-07-10.
> Creating quizzes needs **no code**: you write one JSON object and run one
> script. This guide is written so that an agent with DB access but **no repo
> access** can author quizzes end-to-end. If you find yourself wanting a new Vue
> component to make a quiz, stop — the declarative `prompt` schema (below) almost
> certainly already covers it.

---

## 1. What a quiz is

A quiz is a single row in `sbn_quizzes` whose `questions` column is a JSON array.
When a student passes (score ≥ `pass_threshold`), every skill node the quiz is
linked to is granted — this is the replacement for the old "click to acquire"
skill checkbox.

A quiz appears in a lesson via one tag:

```html
<sbn-quiz slug="shell-voicings-check"></sbn-quiz>
```

That's the same mechanism as `<sbn-chord>`, `<sbn-rhythm>`, etc.
(`resources/js/lib/mountSbnNodes.ts`).

**Worked example already in the DB:** `shell-voicings-check` (4 questions,
one of each shape). Inspect it any time with:

```bash
python scripts/seed_quiz.py --show shell-voicings-check
```

---

## 2. How to author a quiz (the whole recipe)

1. Write a JSON file matching the schema in §4.
2. Validate + write it:
   ```bash
   python scripts/seed_quiz.py path/to/my-quiz.json
   ```
   The script **refuses to write** if anything is wrong — an unknown question
   type, a `correct` id that isn't an option, a chord/rhythm slug that doesn't
   exist, a missing answer key, a duplicate question id, or a skill node that
   doesn't exist. A bad slug caught here is a bad slug that never becomes a
   broken card in a student's browser.
3. Embed `<sbn-quiz slug="my-quiz"></sbn-quiz>` in the lesson HTML (usually at
   the end, as the section's test).
4. Re-running the script with the same `slug` **updates** the quiz — it never
   duplicates. Edit and re-run freely.

Helpers:
```bash
python scripts/seed_quiz.py --list                # every quiz + question count
python scripts/seed_quiz.py --show <slug>         # dump one quiz as JSON
python scripts/seed_quiz.py                        # (re)seed the built-in example
```

> **Never invent slugs.** Chord and rhythm slugs must exist in the DB. The
> master lists are in `CLAUDE.md` (chord diagrams, rhythm patterns). The seeder
> validates every one before writing, but check first to save a round-trip.

---

## 3. The declarative `prompt` — the key to everything

Every question carries a `prompt` object. One component (`QuizPrompt.vue`)
renders all of them, which is why quizzes need no new code. **Five kinds:**

| kind | what the student gets | fields |
|---|---|---|
| `text` | plain question text | `text` |
| `chord` | a chord you can **hear** (and optionally see) | `slug`, `root?`, `showDiagram?` |
| `diagram` | a chord shape shown **silently** | `slug`, `root?` |
| `rhythm` | a rhythm pattern you can hear / see as a grid | `slug`, `bpm?`, `loop?`, `showStrip?` |
| `notes` | a MIDI note sequence (intervals, fragments) | `midi[]`, `mode?`, `gapSec?` |

```jsonc
{ "kind": "text",    "text": "Which interval is a perfect fifth?" }
{ "kind": "chord",   "slug": "m7-shell-roote", "root": "C", "showDiagram": false }
{ "kind": "diagram", "slug": "maj7-shell-roote", "root": "C" }
{ "kind": "rhythm",  "slug": "gilberto-rhythm", "bpm": 80, "showStrip": true }
{ "kind": "notes",   "midi": [60, 67], "mode": "melodic", "gapSec": 0.6 }  // C then G
```

- `root` (chord/diagram) transposes the *displayed* root, using the same
  `?root=` the chord API already supports.
- `showDiagram: false` on a `chord` prompt makes it an **ear test** — the student
  hears the voicing but doesn't see it. This is how ear training is built:
  there is no separate "ear-training" question type.
- `mode` on `notes` is `"melodic"` (one after another) or `"harmonic"` (together)
  — the difference between an interval-recognition and a chord-quality-by-ear
  question. MIDI 60 = middle C.

---

## 4. Question types & full schema

Three types. A type exists only where the **answer input** is special; audio and
visuals are all handled by the prompt.

### 4.1 `multiple-choice` — the workhorse

Prompt + a set of options; pick one (or several with `"multi": true`).
**With an audio prompt this covers all ear training.**

```jsonc
{
  "q": "q1",                        // unique id within THIS quiz (required)
  "type": "multiple-choice",
  "prompt": { "kind": "chord", "slug": "m7-shell-roote", "root": "C", "showDiagram": false },
  "options": [
    { "id": "a", "label": "Maj7" },
    { "id": "b", "label": "m7", "hint": "1 b3 5 b7" },   // hint is optional sub-label
    { "id": "c", "label": "Dom7" }
  ],
  "correct": "b",                   // option id, or ["a","c"] for multi
  "multi": false,                   // optional; when true, correct must be a list
  "explanation": "Minor 3rd against a minor 7th."   // optional, never shown to client pre-grade
}
```

- **Options are submitted by `id`, never by position.** Ids let option order
  change (or shuffle, later) without breaking the answer key.
- `correct` is a single id (single-select) or a list (`multi: true`). Multi-select
  grades as a **set**: exact members, any order, no partial credit.

### 4.2 `chord-identify` — see/hear a chord, name it (or pick its shape)

Two modes. `"name"` is identical to multiple-choice (it exists only so the second
mode can share a type). `"diagram"` shows a rack of chord shapes and the student
picks one.

```jsonc
// answerMode "name": prompt shows/plays a chord, student names it
{
  "q": "q3",
  "type": "chord-identify",
  "prompt": { "kind": "diagram", "slug": "maj7-shell-roote", "root": "C" },
  "answerMode": "name",
  "options": [
    { "id": "a", "label": "Cm7" },
    { "id": "b", "label": "C7" },
    { "id": "c", "label": "Cmaj7" }
  ],
  "correct": "c"
}

// answerMode "diagram": prompt names a chord, student picks the shape
{
  "q": "q4",
  "type": "chord-identify",
  "prompt": { "kind": "text", "text": "Which shape is a Cmaj7 shell?" },
  "answerMode": "diagram",
  "choices": [
    { "slug": "maj7-shell-roote", "root": "C" },
    { "slug": "m7-shell-roote",  "root": "C" },
    { "slug": "dom7-shell-roote", "root": "C" }
  ],
  "correct": "maj7-shell-roote"     // the CHORD SLUG of the right shape
}
```

### 4.3 `rhythm-tap` — tap the rhythm

The construction type. A one-bar count-in clicks, then the student taps
(spacebar or the pad) and is graded against the pattern's onsets. **No `correct`
field** — the answer is the rhythm pattern itself.

```jsonc
{
  "q": "q5",
  "type": "rhythm-tap",
  "prompt": { "kind": "rhythm", "slug": "gilberto-rhythm", "bpm": 80, "showStrip": true },
  "countInBeats": 4,                // optional; one 4/4 bar by default
  "grading": {                      // optional; these are the defaults
    "toleranceBeats": 0.18,         // how far off a tap may land and still count
    "extraTapPenalty": 0.5,         // cost of each spurious tap (fraction of an onset)
    "passScore": 0.7                // fraction of onsets needed for THIS question
  }
}
```

- Grading is **absolute against the click** — the count-in is what the student
  locks to. It is not anchored to their first tap.
- The expected onsets are the de-duplicated **union** of the pattern's thumb and
  finger voices. For `gilberto-rhythm` that's beats `0, 0.5, 1.0, 1.25`.
- **`grading` lives in the JSON on purpose.** Rhythm timing is the one thing
  that will need tuning after real students use it — bump `toleranceBeats` here,
  no deploy required. Because raw taps are stored on every attempt, you can even
  re-grade historical attempts against new knobs.

---

## 5. Quiz-level fields & granting skills

```jsonc
{
  "slug": "shell-voicings-check",           // unique; also the <sbn-quiz slug="">
  "title": "Shell Voicings — Check Your Understanding",
  "description": "Optional intro line.",
  "pass_threshold": 0.75,                    // fraction of questions to pass; default 0.70
  "skill_nodes": ["shell-voicings"],         // node slugs granted on a pass
  "questions": [ /* … */ ]
}
```

- `pass_threshold` is over the **whole quiz** (fraction of questions correct).
  Each `rhythm-tap` also has its own per-question `passScore` deciding whether
  that one question counts as correct.
- On a pass, every node in `skill_nodes` is marked complete for the user with
  `source='quiz'`. Already-complete nodes are left untouched (a retake never
  rewrites history; a grandfathered self-report stays self-report).

### Making a node quiz-gated (optional, deliberate)

By default a linked node is *also* still self-reportable. To make a node earnable
**only** by passing its quiz:

```bash
python scripts/seed_quiz.py --gate shell-voicings
```

This sets `completion_type='quiz'`. After it, the skill can't be ticked by hand
(`/account/skills` toggle returns 403). **Existing completions are grandfathered**
— students who already had the skill keep it (their row is never removed, because
the toggle guard blocks the only path that would remove it). Only *new* students
must pass.

> Gating is a product decision, so it's a separate explicit command — seeding a
> quiz never gates a node on its own. Decide per node whether you want the
> "you must pass to advance" experience (Skoove-style) or "here's a test if you
> want it" (optional check).

---

## 6. How it works under the hood (for maintainers)

| Concern | Where |
|---|---|
| Tables | `sbn_quizzes`, `sbn_quiz_skill_node`, `sbn_quiz_attempts`; provenance cols `source` + `quiz_attempt_id` on `sbn_user_skill_progress` |
| Models | `app/Models/Quiz.php` (`publicQuestions()` strips the key), `app/Models/QuizAttempt.php` |
| API | `app/Http/Controllers/QuizController.php` — `apiShow` (strips key), `apiSubmit` (re-grades, grants). Routes `api.sbn.quizzes.show` / `.submit` (auth-gated) |
| Grading | `app/Services/QuizGradingService.php` + `app/Services/RhythmOnsets.php` (the PHP twin of the JS rhythm adapter) |
| Skill guard | `app/Http/Controllers/Account/SkillController.php::toggle()` — rejects quiz-gated nodes |
| Runner | `resources/js/edu/quiz/QuizRunner.vue` (shell), `QuizPrompt.vue` (prompts), `useQuizAudio.ts`, `questions/*.vue`, `registry.ts` |
| Tag | `<sbn-quiz>` branch in `resources/js/lib/mountSbnNodes.ts` |
| Authoring | `scripts/seed_quiz.py` (validate + write), `scripts/smoke_quiz.php` (end-to-end check) |

**Security invariants** (locked by `tests/Feature/Quiz/`):
- The answer key (`correct`, `explanation`, rhythm `grading`) never leaves the
  server — `apiShow` strips it; the `Quiz` model also `$hidden`s `questions`.
- The client never grades. It submits raw values (option ids, tap times); the
  server re-derives correctness. A forged `score` in the POST body is ignored.
- A quiz-gated node can't be self-reported, and a quiz-earned completion can't be
  un-toggled (the toggle is the only detach path, and it's blocked).

Run the suites: `php artisan test tests/Feature/Quiz` (40 tests). End-to-end
through the HTTP stack: `php scripts/smoke_quiz.php` (23 checks, DB rolled back).

---

## 7. Not built yet (deliberately deferred)

- **Standalone `/library/quizzes` page.** The `QuizRunner` shell is
  surface-agnostic (takes a `quiz` prop + submit URL), so a standalone Inertia
  page is a thin wrapper when wanted.
- **Option shuffling.** Stable option ids already make it a no-client-change
  addition — a `shuffle` flag would live in `Quiz::publicQuestions()`.
- **More construction types** (build-a-chord on a click-to-place fretboard,
  write-the-melody). The registry has room; the components don't exist. Grading a
  built chord or a written TAB is a musical-equivalence problem, not a string
  compare — a real project, not a quiz JSON edit.
- **Question-level review UI** (show the student *why* an answer was wrong using
  the stored `explanation`). The data is there; the results screen currently
  shows only per-question ✓/✗.

---

## 8. Theming — the standard for ALL quizzes

Quizzes are **light by default** and read as part of the lesson page — not the
always-dark "island" cards the edu widgets use. All look-and-feel lives in one
file: `resources/css/quiz-theme.css`, imported globally via `resources/css/app.css`.

**The rules (enforced by convention, not tooling):**
1. Every quiz component styles itself **only** through `var(--quiz-*)` tokens.
   Never hardcode a colour, and never reach for `--clr-*` (those are defined
   inconsistently across the public frontend, which is what made the first cut
   render wrong).
2. **Light is the default**; a dark override mirrors every token via both
   `@media (prefers-color-scheme: dark)` and `[data-theme="dark"]` (the app's own
   toggle wins in either OS context).
3. A new question type inherits the whole look for free by using the same tokens.
   A new token needs a light value in `:root` **and** a dark value in the
   override blocks.

The token set: `--quiz-bg`, `--quiz-surface(-hover)`, `--quiz-text(-muted)`,
`--quiz-border`, `--quiz-accent` / `--quiz-accent-ink` (text on accent) /
`--quiz-accent-tint` (selected fill), `--quiz-pass` / `--quiz-fail`,
`--quiz-font-{body,heading,mono}`, `--quiz-radius`, `--quiz-ease`, `--quiz-shadow`.

**To re-theme every quiz at once** (e.g. match a course's colour, or darken the
whole thing), edit `quiz-theme.css` — nothing else. That's the entire surface.

---

## 9. Content voice

Question text, option labels, and `explanation`s are student-facing copy —
consult `docs/SBN-Content-Style-Guide.md` before writing them, same as any other
description field. Keep prompts short and specific; an explanation should teach
the *why* in one sentence, not restate the answer.

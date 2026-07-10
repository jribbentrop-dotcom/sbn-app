# Quiz Authoring via Co-Work (iPad / DB-only)

> **Read this first, in full.** You are authoring quizzes for the Soul Bossa Nova
> guitar app with **DATABASE ACCESS ONLY** — no repository, no scripts, no PHP,
> no `artisan`. Everything below is done with plain SQL against `sbn.db`. This
> guide is self-contained: it has every query you need. The quiz *engine* is
> already built and deployed; you are only writing content (DB rows).
>
> A companion doc, `SBN-Quiz-Reference.md`, has deeper design detail — but you do
> **not** need it to author. This guide is enough.

---

## 0. The mental model (read once)

- A **quiz** is one row in `sbn_quizzes`. Its `questions` column is a JSON array.
- Passing a quiz (score ≥ `pass_threshold`) **grants skill nodes** linked via
  `sbn_quiz_skill_node`. This is how a student "earns" a skill.
- A quiz appears to students only when its **tag is embedded in a lesson**:
  `<sbn-quiz slug="your-slug"></sbn-quiz>` inside `sbn_lessons.content`.
- You never write Vue, PHP, or run a build. The app already knows how to render
  any quiz you put in the DB.

**The golden rule: never invent a slug.** Every chord/rhythm/skill-node slug you
reference must already exist. Section 5 gives you the queries to look them up.
A wrong slug produces a quiz that renders a broken card — and you won't see it
until someone loads the page. So *always verify slugs with SQL first* (§5) and
*run the self-checks* (§6) before you consider a quiz done.

---

## 1. The fastest possible quiz (copy, adapt, run)

This is a complete, valid quiz. Change the text/options, keep the shape.

```sql
INSERT INTO sbn_quizzes (slug, title, description, questions, pass_threshold, created_at, updated_at)
VALUES (
  'my-first-quiz',
  'My First Quiz',
  'One short question.',
  '[
    {
      "q": "q1",
      "type": "multiple-choice",
      "prompt": { "kind": "text", "text": "Which interval is a perfect fifth?" },
      "options": [
        { "id": "a", "label": "3 semitones" },
        { "id": "b", "label": "7 semitones" },
        { "id": "c", "label": "12 semitones" }
      ],
      "correct": "b",
      "explanation": "A perfect fifth is 7 semitones."
    }
  ]',
  0.70,
  datetime('now'), datetime('now')
);
```

That's a live quiz. To make students see it, embed it (§4).

**To EDIT a quiz later**, don't insert again — update:
```sql
UPDATE sbn_quizzes SET questions = '[...new json...]', updated_at = datetime('now')
WHERE slug = 'my-first-quiz';
```

---

## 2. The `prompt` — how to add audio & diagrams (no code)

Every question has a `prompt`. This is where sound and visuals come from — all
declarative. **Five kinds:**

| kind | student experiences | JSON |
|---|---|---|
| `text` | plain question text | `{ "kind": "text", "text": "…" }` |
| `chord` | **hears** a chord (optionally sees it) | `{ "kind": "chord", "slug": "m7-shell-roote", "root": "C", "showDiagram": false }` |
| `diagram` | **sees** a chord shape, silent | `{ "kind": "diagram", "slug": "maj7-shell-roote", "root": "C" }` |
| `rhythm` | hears/sees a rhythm pattern | `{ "kind": "rhythm", "slug": "gilberto-rhythm", "bpm": 80 }` |
| `notes` | hears MIDI notes (intervals) | `{ "kind": "notes", "midi": [60, 67], "mode": "melodic" }` |

Key tricks:
- **Ear training = a `chord` prompt with `"showDiagram": false`.** The student
  hears the voicing and must name it. There is no separate "ear" question type —
  it's just multiple-choice with an audio prompt.
- `"root": "C"` transposes the *displayed/played* root. Optional.
- `notes` MIDI: middle C = 60, and +1 per semitone (C=60, C#=61, D=62 … G=67).
  `"mode": "melodic"` plays them one after another (interval test);
  `"harmonic"` plays them together (chord-quality-by-ear test).

---

## 3. The three question types

You have exactly three. A type exists only because its *answer input* differs.

### 3a. `multiple-choice` — the workhorse (use for ~everything)
Prompt + options; the student picks one. **With an audio prompt, this is also
your ear-training tool.**

```json
{
  "q": "q2",
  "type": "multiple-choice",
  "prompt": { "kind": "chord", "slug": "dom7-shell-roote", "root": "C", "showDiagram": false },
  "options": [
    { "id": "a", "label": "Major 7th" },
    { "id": "b", "label": "Dominant 7th" },
    { "id": "c", "label": "Minor 7th" }
  ],
  "correct": "b",
  "explanation": "The b7 over a major 3rd is the dominant-7th sound."
}
```
- Multi-answer: add `"multi": true` and make `correct` a list: `"correct": ["a","c"]`.
  Grading needs the exact set.
- **`correct` is always an option `id`** ("b"), never the label text, never a number.

### 3b. `chord-identify` — name a shape, or pick a shape
Two sub-modes via `answerMode`:

```json
{
  "q": "q3",
  "type": "chord-identify",
  "prompt": { "kind": "diagram", "slug": "maj7-shell-roote", "root": "C" },
  "answerMode": "name",
  "options": [
    { "id": "a", "label": "Cm7" },
    { "id": "b", "label": "Cmaj7" }
  ],
  "correct": "b"
}
```
`answerMode: "diagram"` instead shows a rack of shapes; the student picks one,
and `correct` is the **chord slug** of the right shape:
```json
{
  "q": "q4", "type": "chord-identify",
  "prompt": { "kind": "text", "text": "Which shape is a Cmaj7 shell?" },
  "answerMode": "diagram",
  "choices": [
    { "slug": "maj7-shell-roote", "root": "C" },
    { "slug": "m7-shell-roote",  "root": "C" }
  ],
  "correct": "maj7-shell-roote"
}
```

### 3c. `rhythm-tap` — tap the rhythm
The student taps along after a count-in; graded automatically against the
pattern. **No `correct` field** — the pattern itself is the answer.

```json
{
  "q": "q5",
  "type": "rhythm-tap",
  "prompt": { "kind": "rhythm", "slug": "gilberto-rhythm", "bpm": 80, "showStrip": true },
  "countInBeats": 4
}
```
If it grades too strictly/loosely after you try it, add a `grading` object:
`"grading": { "toleranceBeats": 0.22, "passScore": 0.7 }` — bigger `toleranceBeats`
= more forgiving. You can tune this any time by UPDATE-ing the quiz.

---

## 4. Making a quiz appear to students

A quiz is invisible until its tag is in a lesson. Two SQL steps.

**Step 1 — find a lesson to put it in** (usually the last/"exercises" lesson of a section):
```sql
SELECT l.id, l.slug, l.title
FROM sbn_lessons l JOIN sbn_courses c ON c.id = l.course_id
WHERE c.slug = 'bossa-nova-chords-ii'   -- the course you're adding to
ORDER BY l.sort_order;
```

**Step 2 — append the tag** to that lesson's HTML content:
```sql
UPDATE sbn_lessons
SET content = content || '
<h2 id="section-check">Check your understanding</h2>
<p>Pass this quiz to earn the skill.</p>
<sbn-quiz slug="my-first-quiz"></sbn-quiz>',
    updated_at = datetime('now')
WHERE id = 69;   -- the lesson id from Step 1
```
> Append with `||`. **Do not** rewrite the whole `content` — you'd risk losing
> existing lesson material. Only ever add to the end.
> Before you change a lesson, copy its current content somewhere first
> (`SELECT content FROM sbn_lessons WHERE id = 69;`) so you can restore it.

---

## 5. Granting a skill + optional gating

**Link the quiz to skill node(s)** so passing earns them:
```sql
-- after inserting the quiz, get its id:
SELECT id FROM sbn_quizzes WHERE slug = 'my-first-quiz';

-- link it to a skill node (look the node up first, §5-lookup):
INSERT INTO sbn_quiz_skill_node (quiz_id, skill_node_id)
VALUES (
  (SELECT id FROM sbn_quizzes    WHERE slug = 'my-first-quiz'),
  (SELECT id FROM sbn_skill_nodes WHERE slug = 'intervals')
);
```

**Optional — gate the node** so it can ONLY be earned by the quiz (not
self-reported / clicked). This is the "Skoove-style, must pass to advance"
behaviour. Do this deliberately, per node:
```sql
UPDATE sbn_skill_nodes SET completion_type = 'quiz' WHERE slug = 'intervals';
```
Students who already completed that node keep it (grandfathered). To un-gate:
`UPDATE sbn_skill_nodes SET completion_type = 'self_report' WHERE slug = '…';`

### 5-lookup — finding valid slugs (NEVER guess these)

```sql
-- Chord diagram slugs (for chord/diagram prompts and chord-identify choices):
SELECT slug, name, root_note, quality FROM sbn_chord_diagrams
WHERE slug LIKE '%shell%' ORDER BY slug;          -- or LIKE '%drop2%', etc.

-- Rhythm pattern slugs (for rhythm prompts / rhythm-tap):
SELECT slug, name FROM sbn_rhythm_patterns ORDER BY name;

-- Skill node slugs (to grant/gate):
SELECT slug, title, branch, grade, completion_type FROM sbn_skill_nodes ORDER BY branch, grade;

-- Courses & lessons (to place the quiz):
SELECT slug, title FROM sbn_courses ORDER BY title;
```

---

## 6. Self-checks — RUN THESE before you call a quiz done

You have no script validating your JSON, so validate by hand with SQL. A quiz
that passes all four checks will render and grade correctly.

**Check 1 — every chord/diagram slug you used exists:**
```sql
-- For each slug in your prompts, this must return a row:
SELECT slug FROM sbn_chord_diagrams WHERE slug = 'm7-shell-roote';
```
**Check 2 — every rhythm slug exists:**
```sql
SELECT slug FROM sbn_rhythm_patterns WHERE slug = 'gilberto-rhythm';
```
**Check 3 — the quiz JSON actually parses (SQLite can test this):**
```sql
-- Returns your quiz if the JSON is valid; ERRORS if malformed:
SELECT slug, json_array_length(questions) AS num_questions
FROM sbn_quizzes WHERE slug = 'my-first-quiz';
```
If Check 3 errors with "malformed JSON", your `questions` string has a syntax
problem (usually a missing comma or quote). Fix and re-UPDATE.

**Check 4 — every `correct` names a real option id.** Read your own JSON: for
each `multiple-choice`/`chord-identify` question, confirm the `correct` value
appears as an `"id"` in that question's `options` (or, in diagram mode, as a
`slug` in `choices`). There's no SQL for this — eyeball it. It's the most common
mistake.

**Also sanity-check the whole thing reads back:**
```sql
SELECT slug, title, pass_threshold, json_array_length(questions) AS qs
FROM sbn_quizzes WHERE slug = 'my-first-quiz';
```

---

## 7. JSON gotchas (SQLite / SQL specifically)

- The whole `questions` value is **one SQL string** in single quotes. Any literal
  single quote **inside** your text must be doubled: `it''s`, not `it's`.
- Keep the JSON valid: double-quotes around every key and string value, commas
  between items, no trailing comma after the last item in an array/object.
- `pass_threshold` is a fraction: `0.70` = 70%. Default is `0.70` if you omit it.
- Always set `created_at` and `updated_at` to `datetime('now')` on INSERT, and
  bump `updated_at` on every UPDATE.
- Use `busy_timeout` if the tool lets you (`PRAGMA busy_timeout=5000;`) so a
  write waits instead of failing if the live app is mid-write.

---

## 8. A complete worked example already in the DB

`shell-voicings-check` is a real 4-question quiz covering all three types and
four prompt kinds. Study it as a template:
```sql
SELECT questions FROM sbn_quizzes WHERE slug = 'shell-voicings-check';
```

---

## 9. What you CANNOT do from here (leave for the repo owner)

- Add a **new question type** or a new **prompt kind** — those need Vue/PHP code.
  Work within the three types and five prompt kinds above; they cover a lot.
- Change **grading logic**, **styling/theme**, or the **runner UI**.
- Deploy. Your DB edits are live in whatever DB you're connected to. If that's a
  local/staging copy, the owner ships it later; if it's production, it's live now.
  If unsure which DB you're on, ask before gating nodes or editing lessons.

If you want any of the above, write down exactly what you want as a note for the
owner and keep authoring content in the meantime.
```
```

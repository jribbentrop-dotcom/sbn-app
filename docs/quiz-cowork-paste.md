You are authoring quizzes for the Soul Bossa Nova guitar app. You have DATABASE
ACCESS ONLY (SQL against sbn.db) — no repo, no scripts, no PHP. The quiz engine
is already built; you only write DB rows. Follow this exactly.

## MODEL
- A quiz = one row in `sbn_quizzes`; its `questions` column is a JSON array.
- Passing a quiz (score ≥ pass_threshold) grants skill nodes linked via
  `sbn_quiz_skill_node`.
- A quiz shows to students only when its tag `<sbn-quiz slug="X"></sbn-quiz>` is
  inside a lesson's HTML (`sbn_lessons.content`).
- GOLDEN RULE: never invent a slug. Every chord/rhythm/skill slug must already
  exist — look them up with SQL first (see LOOKUPS). A wrong slug = a broken card
  the user only sees on page load.

## AUTHOR A QUIZ (adapt this, then run it)
```sql
INSERT INTO sbn_quizzes (slug,title,description,questions,pass_threshold,created_at,updated_at)
VALUES ('my-quiz','My Quiz','desc',
'[
  {"q":"q1","type":"multiple-choice",
   "prompt":{"kind":"text","text":"Which interval is a perfect fifth?"},
   "options":[{"id":"a","label":"3 semitones"},{"id":"b","label":"7 semitones"}],
   "correct":"b","explanation":"7 semitones."}
]',
0.70, datetime('now'), datetime('now'));
```
To edit later: `UPDATE sbn_quizzes SET questions='[...]', updated_at=datetime('now') WHERE slug='my-quiz';`

## PROMPT KINDS (this is where audio/visuals come from — all declarative)
- text:    {"kind":"text","text":"..."}
- chord:   {"kind":"chord","slug":"m7-shell-roote","root":"C","showDiagram":false}  ← hears it. showDiagram:false = EAR TEST
- diagram: {"kind":"diagram","slug":"maj7-shell-roote","root":"C"}  ← sees a shape, silent
- rhythm:  {"kind":"rhythm","slug":"gilberto-rhythm","bpm":80}
- notes:   {"kind":"notes","midi":[60,67],"mode":"melodic"}  ← C=60, +1/semitone; melodic=one-by-one, harmonic=together

## THREE QUESTION TYPES
1. multiple-choice — prompt + options, pick one. WITH AN AUDIO PROMPT THIS IS EAR
   TRAINING. `correct` is an option id ("b"), never the label. Multi-answer:
   add "multi":true and "correct":["a","c"] (exact set).
2. chord-identify — "answerMode":"name" (options=labels, correct=id) OR
   "answerMode":"diagram" ("choices":[{"slug":"...","root":"C"}], correct=the chord slug).
3. rhythm-tap — {"q":"q5","type":"rhythm-tap","prompt":{"kind":"rhythm","slug":"gilberto-rhythm","bpm":80},"countInBeats":4}
   No `correct` (pattern is the answer). Too strict/loose? add
   "grading":{"toleranceBeats":0.22,"passScore":0.7} (bigger = more forgiving).

## MAKE IT VISIBLE (find a lesson, append the tag — NEVER rewrite content)
```sql
SELECT l.id,l.slug,l.title FROM sbn_lessons l JOIN sbn_courses c ON c.id=l.course_id
WHERE c.slug='bossa-nova-chords-ii' ORDER BY l.sort_order;
-- copy the current content first so you can restore: SELECT content FROM sbn_lessons WHERE id=69;
UPDATE sbn_lessons SET content = content ||
'<h2 id="section-check">Check your understanding</h2><sbn-quiz slug="my-quiz"></sbn-quiz>',
updated_at=datetime('now') WHERE id=69;
```

## GRANT A SKILL (+ optional gate)
```sql
INSERT INTO sbn_quiz_skill_node (quiz_id,skill_node_id) VALUES
((SELECT id FROM sbn_quizzes WHERE slug='my-quiz'),
 (SELECT id FROM sbn_skill_nodes WHERE slug='intervals'));
-- optional: make the node ONLY earnable by the quiz (existing completions kept):
UPDATE sbn_skill_nodes SET completion_type='quiz' WHERE slug='intervals';
```

## LOOKUPS (run these to get real slugs — never guess)
```sql
SELECT slug,name,root_note,quality FROM sbn_chord_diagrams WHERE slug LIKE '%shell%';
SELECT slug,name FROM sbn_rhythm_patterns ORDER BY name;
SELECT slug,title,branch,completion_type FROM sbn_skill_nodes ORDER BY branch;
SELECT slug,title FROM sbn_courses ORDER BY title;
```

## SELF-CHECK BEFORE DONE (no validator — check by hand)
1. Each chord/rhythm slug returns a row (LOOKUPS above).
2. JSON parses: `SELECT json_array_length(questions) FROM sbn_quizzes WHERE slug='my-quiz';`
   — if it ERRORS "malformed JSON", fix a missing comma/quote.
3. Every `correct` value is a real option `id` (or a `choices` slug in diagram mode). Eyeball it.

## GOTCHAS
- questions is ONE sql string in single quotes → double any inner apostrophe: it''s.
- Valid JSON: double-quote all keys/strings, commas between items, NO trailing comma.
- pass_threshold 0.70 = 70%. Always datetime('now') on insert; bump updated_at on edit.
- If asked, PRAGMA busy_timeout=5000; before writing.

## YOU CANNOT (write these down for the owner instead)
- New question types / prompt kinds, grading logic, styling, or deploying.
- If unsure whether you're on the LIVE or a copy DB, ASK before gating nodes or
  editing lessons — on production those changes are instantly live.

## TEMPLATE TO STUDY (a real 4-question quiz already in the DB)
```sql
SELECT questions FROM sbn_quizzes WHERE slug='shell-voicings-check';
```

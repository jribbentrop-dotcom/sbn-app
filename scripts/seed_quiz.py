#!/usr/bin/env python3
"""
Seed (or re-seed) a quiz from a JSON definition.

This is the reference authoring path for the quiz system, and the script an
agent with DB access but no repo access should use. It writes a single row to
`sbn_quizzes` plus the `sbn_quiz_skill_node` grants, idempotently: running it
twice updates the existing quiz rather than duplicating it.

Usage
-----
    python scripts/seed_quiz.py                       # seed the built-in example
    python scripts/seed_quiz.py path/to/quiz.json     # seed from a file
    python scripts/seed_quiz.py --show <slug>         # print a stored quiz
    python scripts/seed_quiz.py --list                # list all quizzes

The JSON shape is documented in docs/SBN-Quiz-Reference.md. Every `slug` a
question references (chords, rhythms) is validated against the DB before the
write, because a typo'd slug produces a quiz that renders an error card instead
of a question — and that failure only shows up in the browser.

Scoped INSERT/UPDATE with busy_timeout, deliberately NOT a whole-file commit:
the app may be serving live traffic, and a whole-file overwrite would clobber
concurrent writes (see the db-concurrency fix, 2026-07-08).
"""

from __future__ import annotations

import json
import sqlite3
import sys
from datetime import datetime, timezone
from pathlib import Path

DB_PATH = Path(__file__).resolve().parent.parent / "database" / "sbn.db"

# Question types the runner can render. Mirrors resources/js/edu/quiz/registry.ts.
KNOWN_TYPES = {"multiple-choice", "chord-identify", "rhythm-tap"}

# Prompt kinds QuizPrompt.vue understands.
KNOWN_PROMPT_KINDS = {"chord", "rhythm", "notes", "diagram", "text"}


# ---------------------------------------------------------------------------
# The worked example. Exercises all three question types and all five prompt
# kinds — if this quiz renders and grades, the whole system works.
# ---------------------------------------------------------------------------

EXAMPLE_QUIZ = {
    "slug": "shell-voicings-check",
    "title": "Shell Voicings — Check Your Understanding",
    "description": "Four questions on the three shell voicings and the Gilberto groove.",
    "pass_threshold": 0.75,
    "skill_nodes": ["shell-voicings"],
    "questions": [
        {
            # kind:"text" — the plainest possible question. No audio, no diagram.
            "q": "q1",
            "type": "multiple-choice",
            "prompt": {
                "kind": "text",
                "text": "A shell voicing keeps which chord tones?",
            },
            "options": [
                {"id": "a", "label": "Root, 3rd, 5th"},
                {"id": "b", "label": "Root, 3rd, 7th", "hint": "1 3 7"},
                {"id": "c", "label": "Root, 5th, 7th"},
                {"id": "d", "label": "3rd, 5th, 7th"},
            ],
            "correct": "b",
            "explanation": "The 5th is dropped — it carries no harmonic information. "
                           "The 3rd and 7th are the guide tones that define the quality.",
        },
        {
            # kind:"chord" with showDiagram:false — this IS an ear-training
            # question. No dedicated question type needed.
            "q": "q2",
            "type": "multiple-choice",
            "prompt": {
                "kind": "chord",
                "slug": "m7-shell-roote",
                "root": "C",
                "showDiagram": False,
            },
            "options": [
                {"id": "a", "label": "Maj7"},
                {"id": "b", "label": "m7"},
                {"id": "c", "label": "Dom7"},
                {"id": "d", "label": "m7b5"},
            ],
            "correct": "b",
            "explanation": "The minor 3rd against a minor 7th — the sound of a m7 shell.",
        },
        {
            # kind:"diagram" — see the shape, name the chord. Silent.
            "q": "q3",
            "type": "chord-identify",
            "prompt": {
                "kind": "diagram",
                "slug": "maj7-shell-roote",
                "root": "C",
            },
            "answerMode": "name",
            "options": [
                {"id": "a", "label": "Cm7"},
                {"id": "b", "label": "C7"},
                {"id": "c", "label": "Cmaj7"},
            ],
            "correct": "c",
            "explanation": "Root on the 6th string, major 3rd and major 7th above it.",
        },
        {
            # kind:"rhythm" + rhythm-tap — the construction type. Grading knobs
            # live here in the JSON, so they can be re-tuned without a deploy.
            "q": "q4",
            "type": "rhythm-tap",
            "prompt": {
                "kind": "rhythm",
                "slug": "gilberto-rhythm",
                "bpm": 80,
                "showStrip": True,
            },
            "countInBeats": 4,
            "grading": {
                "toleranceBeats": 0.22,
                "extraTapPenalty": 0.5,
                "passScore": 0.7,
            },
        },
    ],
}


def connect() -> sqlite3.Connection:
    if not DB_PATH.exists():
        sys.exit(f"error: database not found at {DB_PATH}")
    conn = sqlite3.connect(DB_PATH, timeout=10)
    # Wait rather than fail if the app is mid-write. See the db-concurrency fix.
    conn.execute("PRAGMA busy_timeout = 5000")
    conn.execute("PRAGMA foreign_keys = ON")
    conn.row_factory = sqlite3.Row
    return conn


def validate(conn: sqlite3.Connection, quiz: dict) -> list[str]:
    """Return a list of problems. An empty list means the quiz is safe to write."""
    problems: list[str] = []

    for field in ("slug", "title", "questions"):
        if not quiz.get(field):
            problems.append(f"quiz is missing required field `{field}`")

    seen_ids: set[str] = set()

    for i, question in enumerate(quiz.get("questions", [])):
        where = f"question {i + 1}"

        qid = question.get("q")
        if not qid:
            problems.append(f"{where}: missing `q` id")
        elif qid in seen_ids:
            problems.append(f"{where}: duplicate `q` id {qid!r}")
        else:
            seen_ids.add(qid)

        qtype = question.get("type")
        if qtype not in KNOWN_TYPES:
            problems.append(f"{where}: unknown type {qtype!r} (known: {sorted(KNOWN_TYPES)})")

        # rhythm-tap grades against the pattern, so it needs no `correct`.
        # Everything else is ungradeable without one.
        if qtype in {"multiple-choice", "chord-identify"} and "correct" not in question:
            problems.append(f"{where}: no `correct` answer key - it can never be answered correctly")

        prompt = question.get("prompt") or {}
        kind = prompt.get("kind")

        if qtype == "rhythm-tap" and kind != "rhythm":
            problems.append(f"{where}: rhythm-tap needs a `rhythm` prompt (got {kind!r})")

        if kind and kind not in KNOWN_PROMPT_KINDS:
            problems.append(f"{where}: unknown prompt kind {kind!r} (known: {sorted(KNOWN_PROMPT_KINDS)})")

        # Never invent slugs — a bad one renders an error card in the browser.
        slug = prompt.get("slug")
        if slug and kind in {"chord", "diagram"}:
            if not conn.execute("select 1 from sbn_chord_diagrams where slug = ?", (slug,)).fetchone():
                problems.append(f"{where}: no chord diagram with slug {slug!r}")
        if slug and kind == "rhythm":
            if not conn.execute("select 1 from sbn_rhythm_patterns where slug = ?", (slug,)).fetchone():
                problems.append(f"{where}: no rhythm pattern with slug {slug!r}")

        # chord-identify in diagram mode answers with chord slugs.
        for choice in question.get("choices", []):
            cslug = choice.get("slug")
            if cslug and not conn.execute("select 1 from sbn_chord_diagrams where slug = ?", (cslug,)).fetchone():
                problems.append(f"{where}: answer choice references unknown chord {cslug!r}")

        # The `correct` id must name an option that exists.
        options = question.get("options") or []
        if options and "correct" in question:
            ids = {o.get("id") for o in options}
            correct = question["correct"]
            for c in (correct if isinstance(correct, list) else [correct]):
                if c not in ids:
                    problems.append(f"{where}: `correct` {c!r} is not one of the option ids {sorted(ids)}")

    for node_slug in quiz.get("skill_nodes", []):
        if not conn.execute("select 1 from sbn_skill_nodes where slug = ?", (node_slug,)).fetchone():
            problems.append(f"skill node {node_slug!r} does not exist")

    return problems


def seed(conn: sqlite3.Connection, quiz: dict) -> int:
    now = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
    questions_json = json.dumps(quiz["questions"], ensure_ascii=False)
    threshold = quiz.get("pass_threshold", 0.70)

    existing = conn.execute("select id from sbn_quizzes where slug = ?", (quiz["slug"],)).fetchone()

    if existing:
        quiz_id = existing["id"]
        conn.execute(
            "update sbn_quizzes set title=?, description=?, questions=?, pass_threshold=?, updated_at=? where id=?",
            (quiz["title"], quiz.get("description"), questions_json, threshold, now, quiz_id),
        )
        action = "updated"
    else:
        cur = conn.execute(
            "insert into sbn_quizzes (slug, title, description, questions, pass_threshold, created_at, updated_at)"
            " values (?,?,?,?,?,?,?)",
            (quiz["slug"], quiz["title"], quiz.get("description"), questions_json, threshold, now, now),
        )
        quiz_id = cur.lastrowid
        action = "created"

    # Re-sync the skill-node grants.
    conn.execute("delete from sbn_quiz_skill_node where quiz_id = ?", (quiz_id,))
    for node_slug in quiz.get("skill_nodes", []):
        node_id = conn.execute("select id from sbn_skill_nodes where slug = ?", (node_slug,)).fetchone()["id"]
        conn.execute(
            "insert into sbn_quiz_skill_node (quiz_id, skill_node_id) values (?,?)",
            (quiz_id, node_id),
        )

    conn.commit()
    print(f"{action} quiz {quiz['slug']!r} (id {quiz_id}) with {len(quiz['questions'])} questions")
    if quiz.get("skill_nodes"):
        print(f"  grants on pass: {', '.join(quiz['skill_nodes'])}")
    print(f"  embed with: <sbn-quiz slug=\"{quiz['slug']}\"></sbn-quiz>")
    return quiz_id


def gate_nodes(conn: sqlite3.Connection, node_slugs: list[str]) -> None:
    """
    Flip a node to completion_type='quiz' so it can no longer be self-reported.

    Deliberately NOT called by default. Gating a node that students already
    self-reported is a product decision, not a seeding side effect — existing
    completions are grandfathered (they keep source='self_report' and are never
    detached, because the toggle guard blocks the only detach path), but new
    students will have to pass the quiz.
    """
    for slug in node_slugs:
        conn.execute("update sbn_skill_nodes set completion_type='quiz' where slug=?", (slug,))
        print(f"  gated node {slug!r} — it now requires a quiz pass")
    conn.commit()


def main() -> None:
    args = sys.argv[1:]
    conn = connect()

    if args and args[0] == "--list":
        rows = conn.execute("select id, slug, title, pass_threshold from sbn_quizzes order by id").fetchall()
        if not rows:
            print("(no quizzes)")
        for r in rows:
            n = len(json.loads(conn.execute("select questions from sbn_quizzes where id=?", (r["id"],)).fetchone()[0]))
            # ASCII only: Windows consoles default to cp1252 and choke on
            # anything else (a stray >= glyph crashed this line once).
            print(f"{r['id']:>3}  {r['slug']:<32} {n:>2}q  pass>={r['pass_threshold']}  {r['title']}")
        return

    if args and args[0] == "--show":
        if len(args) < 2:
            sys.exit("usage: seed_quiz.py --show <slug>")
        row = conn.execute("select * from sbn_quizzes where slug=?", (args[1],)).fetchone()
        if not row:
            sys.exit(f"no quiz with slug {args[1]!r}")
        print(json.dumps({**dict(row), "questions": json.loads(row["questions"])}, indent=2, ensure_ascii=False))
        return

    if args and args[0] == "--gate":
        gate_nodes(conn, args[1:])
        return

    quiz = json.loads(Path(args[0]).read_text(encoding="utf-8")) if args else EXAMPLE_QUIZ

    problems = validate(conn, quiz)
    if problems:
        print("refusing to write - fix these first:", file=sys.stderr)
        for p in problems:
            print(f"  - {p}", file=sys.stderr)
        sys.exit(1)

    seed(conn, quiz)


if __name__ == "__main__":
    main()

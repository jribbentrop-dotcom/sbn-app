#!/usr/bin/env python3
"""
One-off: append the shell-voicings quiz to the Exercises lesson (id 69) and
gate the shell-voicings node.

Scoped UPDATE with busy_timeout (not a whole-file rewrite) so a live browser
save can't be clobbered. Backs up the old lesson content to a sidecar file
first, because this mutates published content.
"""

import sqlite3
import sys
from datetime import datetime, timezone
from pathlib import Path

DB = Path(__file__).resolve().parent.parent / "database" / "sbn.db"
LESSON_ID = 69
QUIZ_SLUG = "shell-voicings-check"
NODE_SLUG = "shell-voicings"

QUIZ_BLOCK = (
    '\n<h2 id="section-check-your-understanding">Check your understanding</h2>\n'
    "<p>Pass this short quiz to earn the <strong>Shell Voicings</strong> skill. "
    "It covers the guide tones, hearing a minor 7th shell, naming a shape, and "
    "the Gilberto groove.</p>\n"
    f'<sbn-quiz slug="{QUIZ_SLUG}"></sbn-quiz>\n'
)


def main() -> None:
    conn = sqlite3.connect(DB, timeout=10)
    conn.execute("PRAGMA busy_timeout = 5000")
    conn.execute("PRAGMA foreign_keys = ON")
    conn.row_factory = sqlite3.Row

    lesson = conn.execute(
        "select id, slug, title, content from sbn_lessons where id = ?", (LESSON_ID,)
    ).fetchone()
    if not lesson:
        sys.exit(f"lesson {LESSON_ID} not found")

    if QUIZ_SLUG in lesson["content"]:
        print(f"lesson {LESSON_ID} already embeds {QUIZ_SLUG!r} — nothing to do")
        return

    # Back up the pre-edit content next to the DB.
    stamp = datetime.now(timezone.utc).strftime("%Y%m%d-%H%M%S")
    backup = DB.parent / f"lesson-{LESSON_ID}-content.bak-{stamp}.html"
    backup.write_text(lesson["content"], encoding="utf-8")
    print(f"backed up old content -> {backup.name} ({len(lesson['content'])} chars)")

    new_content = lesson["content"] + QUIZ_BLOCK
    now = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")

    conn.execute(
        "update sbn_lessons set content = ?, updated_at = ? where id = ?",
        (new_content, now, LESSON_ID),
    )

    # Gate the node: quiz-only from now on. Existing completions grandfathered.
    conn.execute(
        "update sbn_skill_nodes set completion_type = 'quiz', updated_at = ? where slug = ?",
        (now, NODE_SLUG),
    )

    conn.commit()

    ct = conn.execute(
        "select completion_type from sbn_skill_nodes where slug = ?", (NODE_SLUG,)
    ).fetchone()["completion_type"]

    print(f"embedded <sbn-quiz slug=\"{QUIZ_SLUG}\"> in lesson {LESSON_ID} "
          f"({lesson['slug']} — {lesson['title']})")
    print(f"gated node {NODE_SLUG!r}: completion_type = {ct}")


if __name__ == "__main__":
    main()

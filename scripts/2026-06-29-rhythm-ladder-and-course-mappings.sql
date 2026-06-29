-- 2026-06-29: Bossa/samba rhythm ladder + course→skill-node mappings + description fixes
-- Source: cross-referencing "Skill Nodes.docx" brainstorm against the skill-node system.
-- See docs/SBN-Skill-Nodes-Brainstorm-Crossref.md for rationale/evidence, and the
-- "Bossa/samba rhythm ladder added 2026-06-29" changelog entry in docs/SBN-Skill-System-Plan.md.
--
-- This script is idempotent (INSERT OR IGNORE / slug-based lookups) and safe to re-run.
-- The 3 new nodes + 7 prerequisite edges are also captured in
-- database/seeders/SkillNodeSeeder.php (NODES const) — running that seeder accomplishes the
-- same node/edge creation. This script additionally covers the course pivot rows and
-- description updates, which are NOT in the seeder.
--
-- Run against the checked-out work copy (per CLAUDE.md db workflow), e.g.:
--   WORK=$(python3 scripts/db_checkout.py checkout) && sqlite3 "$WORK" < scripts/2026-06-29-rhythm-ladder-and-course-mappings.sql && python3 scripts/db_checkout.py commit

-- ── 1. New rhythm nodes (if SkillNodeSeeder hasn't already been run) ──────────────────────
INSERT OR IGNORE INTO sbn_skill_nodes
  (slug, title, branch, sub_branch, grade, icon_key, completion_type, content_tag_slug, sort_order, created_at, updated_at)
VALUES
  ('bossa-syncopated-push', 'Bossa Syncopated Push', 'rhythm', 'Feels', 2, 'bolt', 'self_report', NULL, 0, datetime('now'), datetime('now')),
  ('alternating-bass-patterns', 'Alternating Bass Patterns', 'rhythm', 'Feels', 2, 'arrows-up-down', 'self_report', NULL, 0, datetime('now'), datetime('now')),
  ('partido-alto-groove', 'Partido Alto Groove', 'rhythm', 'Latin Rhythm', 3, 'globe-alt', 'self_report', NULL, 0, datetime('now'), datetime('now'));

-- ── 2. Prerequisite edges for the new nodes ────────────────────────────────────────────────
INSERT OR IGNORE INTO sbn_skill_node_prerequisites (skill_node_id, requires_skill_node_id)
SELECT n.id, p.id FROM sbn_skill_nodes n, sbn_skill_nodes p
WHERE (n.slug, p.slug) IN (
  ('bossa-syncopated-push', 'two-four-feel'),
  ('bossa-syncopated-push', 'syncopation'),
  ('alternating-bass-patterns', 'two-four-feel'),
  ('partido-alto-groove', 'bossa-syncopated-push'),
  ('partido-alto-groove', 'alternating-bass-patterns'),
  ('partido-alto-groove', 'clave-systems'),
  ('brazilian-rhythm-styles', 'partido-alto-groove')
);

-- ── 3. Course → skill-node pivot rows ──────────────────────────────────────────────────────
-- Course 1 (bossa-nova-basics)
INSERT OR IGNORE INTO sbn_course_skill_node (course_id, skill_node_id)
SELECT 1, id FROM sbn_skill_nodes WHERE slug IN ('bossa-syncopated-push', 'alternating-bass-patterns');

-- Course 4 (bossa-nova-rhythm)
INSERT OR IGNORE INTO sbn_course_skill_node (course_id, skill_node_id)
SELECT 4, id FROM sbn_skill_nodes WHERE slug IN ('bossa-syncopated-push', 'partido-alto-groove');

-- Course 77 (syncopation-off-beat-rhythms, draft)
INSERT OR IGNORE INTO sbn_course_skill_node (course_id, skill_node_id)
SELECT 77, id FROM sbn_skill_nodes WHERE slug IN ('pulse-subdivision', 'syncopation', 'swing-feel', 'polyrhythm', 'comping-patterns');

-- Course 78 (brazilian-rhythms, draft)
INSERT OR IGNORE INTO sbn_course_skill_node (course_id, skill_node_id)
SELECT 78, id FROM sbn_skill_nodes WHERE slug IN ('two-four-feel', 'syncopation', 'clave-systems', 'brazilian-rhythm-styles', 'bossa-syncopated-push', 'alternating-bass-patterns', 'partido-alto-groove');

-- Course 79 (basic-rhythms, draft)
INSERT OR IGNORE INTO sbn_course_skill_node (course_id, skill_node_id)
SELECT 79, id FROM sbn_skill_nodes WHERE slug IN ('meter-basics', 'pulse-subdivision', 'syncopation', 'waltz-feel');

-- ── 4. Description clarifications (naming alignment — no new beginner nodes needed) ───────
UPDATE sbn_skill_nodes
SET description = 'The basic eight open-position chords (major, minor, and dominant 7th) to get you started playing songs.',
    updated_at = datetime('now')
WHERE slug = 'the-basic-8';

UPDATE sbn_skill_nodes
SET description = 'The very first scale exercises — open-position major and minor scale shapes for absolute beginners.',
    updated_at = datetime('now')
WHERE slug = 'foundational-scales';

-- ── 5. Expected end state (for verification after running) ────────────────────────────────
-- sbn_skill_nodes:               61 rows
-- sbn_skill_node_prerequisites:  69 rows
-- sbn_course_skill_node:        118 rows, covering all 24 courses
-- PRAGMA quick_check:            ok, no cycles in prerequisite graph

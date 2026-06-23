# Course draft handover — `docs/drafts/`

Drop zone for course drafts authored by a co-worker (e.g. Claude co-work, in-memory)
that need importing into the DB by Claude Code.

## Handover contract

**Author (co-worker) writes** one file per course: `<course-slug>-draft.md`, containing:

1. **Course row fields** — `slug`, `title`, `excerpt`, `description`, `genres` (array),
   `levels`, `style`, `level`, `topics` (array), `is_free`, `category`,
   `learning_outcomes`, `status` (`draft`/`publish`).
2. **Lessons, in order** — each with: `title`, `section_title`, `content` (the lesson
   HTML), `is_preview` (1 on the first lesson of the intro section), `concept_slug`
   (optional). `sort_order` is implied by file order.

Use the canonical custom tags in `content` (see project CLAUDE.md): `<sbn-chord>`,
`<sbn-rhythm>`, `<sbn-sheet>`, `<sbn-song>`, `<sbn-info>`, `<sbn-widget>`. **Only use
slugs that exist in the DB** — do not invent chord/leadsheet/rhythm/exercise slugs.

## Importer (Claude Code) does

1. Read the draft file.
2. **Validate every `sbn-*` slug against the live DB. BLOCK on any miss** — stop and
   report missing slugs rather than inserting broken widgets or inventing replacements.
   (Policy chosen 2026-06-23.)
3. Strip any legacy WP artifacts (`[alphatex]`, `wp-block-*`, `[PDF ...]`, etc.).
4. Insert via the safe DB workflow (copy-to-temp, or a seeder) so the `Lesson` model's
   H2-id auto-fix + casting apply. Set `is_preview`, `sort_order`.
5. Verify row counts + spot-render, report back.

Drafts here are **handover artifacts**, not the source of truth once imported — the DB
is. Safe to delete a draft file after its course is imported and verified.

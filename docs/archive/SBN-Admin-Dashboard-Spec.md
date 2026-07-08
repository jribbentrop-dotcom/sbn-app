> ⚠️ **ARCHIVED / SHIPPED 2026-06-29.** Built; as-built folded into
> `docs/SBN-Skill-System-Reference.md` → "Admin content-health dashboard". Kept for history only.

# Admin Dashboard Rebuild — Spec (for Sonnet)

> **For:** Sonnet (implementer). **Status:** ready to build. Self-contained — read the named files
> before editing. Companion task to `SBN-Skill-StepB-Spec.md` (independent; either order).

## Decisions locked (2026-06-29 — these override any "your call" phrasing below)

1. **Use a `ContentHealthService`.** The gap data is needed by BOTH the dashboard card (counts) and
   the coverage drill-down (lists), so put it in `App\Services\ContentHealthService` with two methods:
   `summary(): array` (the counts for Zone 3) and `details(): array` (the offending-row lists for
   Zone 4). Reason is reuse, not line count — without it the ~10 queries get duplicated across two
   controller methods and drift. **Keep the Zone-2 recently-edited merge in the controller** — it's
   dashboard-only, not shared.
2. **Add `updated_at` to `sbn_chord_progressions`** so progressions appear in the recently-edited
   feed. Migration: add nullable `updated_at` timestamp, **backfill existing rows from `created_at`**
   (which IS present) in the same migration's `up()` so they don't all sort as null. After this,
   include progressions in Zone 2.
3. **`?gap=` scrolling → use a URL fragment + CSS `:target`, NOT a query param.** Link to
   `…/coverage#gap-nodes-no-content`; give each section `id="gap-<key>"`; add one global CSS rule
   `section:target { /* highlight bg */ }`. Zero JS, browser auto-scrolls to the fragment. Drop the
   `?gap=` param entirely from Zone 3 links and the `coverage()` signature.
4. **Voicing drafts → show the count, NO link.** There is no GET index route for the drafts queue
   (only POST `voicings.dismiss` / `voicings.promote` / `voicings.clearAll` / `voicings.reprocess`
   exist — drafts are reviewed inline elsewhere). Surface the pending count as a plain number; do not
   invent a queue page (that's a separate task if ever wanted).

## Goal

Turn the admin landing page (`/admin`) from a stale stub into a useful content-ops cockpit. It
currently shows count tiles + a **stale "Migration Progress" card** (phases 0–7, "Rhythms = active")
— a WordPress-migration-era artifact, long complete. **Remove it.** Replace with three working zones:
**Totals**, **Recently edited**, **Content Health** (the skill-system interconnect gaps). The
Content-Health *summary* lives on the dashboard; its *drill-down lists* live as a new tab in the
skill-nodes admin.

## Files (verified)

- Controller: `app/Http/Controllers/Admin/DashboardController.php` (`index()`).
- **Live view:** `resources/views/admin/dashboard/index.blade.php` ← edit THIS one.
- **Stale orphan:** `resources/views/admin/dashboard.blade.php` (NOT wired to any route — the
  controller renders `dashboard.index`). **Delete `dashboard.blade.php`** as part of this cleanup
  (verify nothing references it first: `grep -rn "admin.dashboard'" ` returns the route name, not
  this file; the route points at the controller which renders `dashboard.index`).
- Route: `routes/web.php` line ~122, `admin.dashboard`.
- CSS: dashboard uses `sbn-stats-grid` / `sbn-stat-card` / `sbn-migration-card` (in
  `public/css/admin2.css`). Reuse the stat-card styling; add new card classes there (global, NOT
  scoped — admin is Blade anyway).

## Zone 1 — Totals (extend existing)

Existing tiles: leadsheets, chords, progressions, rhythms, voicing-links, pending-drafts. **Add:**
courses, lessons, skill nodes. Keep the existing `$modules` tile pattern + colors. Counts go in
`DashboardController` via the existing `$count()` helper (it's null-safe on missing tables — keep that).

## Zone 2 — Recently edited (new)

A unified "last ~12 edited, across content types" feed, newest first, each row a link to its editor.

Source `updated_at` (verified present): `sbn_leadsheets`, `sbn_rhythm_patterns`, `sbn_chord_diagrams`,
`sbn_courses`, `sbn_lessons`, `sbn_skill_nodes`. **`sbn_chord_progressions` has NO `updated_at`** —
so progressions are absent from this feed. (Optional: add an `updated_at` column to progressions in a
tiny migration if you want them included — your call; not required. If you skip it, note it in a code
comment so it's not mistaken for a bug.)

Build in the controller — one normalized list. Each item: `type` (label), `title` (use `title` or
`name` per table — see below), `slug`, `updated_at`, `edit_url` (route to that type's admin editor).
Merge, sort by `updated_at` desc, take 12. Title column per table: leadsheets/courses/lessons/skill-
nodes use `title`; rhythms/chords use `name`. Admin edit routes already exist per type (e.g.
`admin.rhythms.edit`, `admin.leadsheets.edit`, `admin.courses.edit`, `admin.skill-nodes.edit`,
`admin.chords.edit` — grep `routes/web.php` in the `admin.` group to confirm exact names/params).

Render as a simple table/list card. Relative time ("3 days ago") via Carbon `->diffForHumans()`.

## Zone 3 — Content Health (new — the interconnect gaps)

A card of headline gap numbers. **Each number links to the drill-down** (Zone 4). These are the
queries — all verified to run against the live DB (2026-06-29 counts in parens, will drift):

**Node-side gaps:**
- **Nodes with no linked content** (50) — node has nothing in `sbn_skill_node_content` AND no
  `voicing_categories` AND no `content_tag_slug`. *The headline "Step A curation" number.*
  ```sql
  SELECT COUNT(*) FROM sbn_skill_nodes n
   WHERE n.id NOT IN (SELECT skill_node_id FROM sbn_skill_node_content)
     AND (n.voicing_categories IS NULL OR n.voicing_categories='')
     AND (n.content_tag_slug IS NULL OR n.content_tag_slug='');
  ```
- **Nodes with no course** (13) — `n.id NOT IN (SELECT skill_node_id FROM sbn_course_skill_node)`.
- **Nodes with no style** (21) — `n.id NOT IN (SELECT skill_node_id FROM sbn_skill_node_style)`.
  (Foundational nodes are legitimately style-neutral, so this is informational, not pure "error" —
  label it gently.)
- **Nodes with no icon_key** (8) — `icon_key IS NULL OR icon_key=''`.

**Content-side gaps** (content with no skill node yet — the linking worklist):
- **Songs unlinked** (71/73), **Rhythms unlinked** (28/32), **Progressions unlinked** (43/44):
  `... WHERE id NOT IN (SELECT content_id FROM sbn_skill_node_content WHERE content_type = ?)`
  with the FQCN — `App\Models\Leadsheet`, `App\Models\RhythmPattern`, `App\Models\ChordProgression`
  (full class names; no morphMap enforced — matches Step A / sbn_taggables convention).
- **Voicing categories with no node** (8: archetype, closed, closed_triads, custom, drop3, quartal,
  slash, spread_triads) — distinct `sbn_chord_diagrams.voicing_category` not present in any node's
  `voicing_categories` JSON. (Compute in PHP: pluck all categories, subtract the union of node
  `voicing_categories` arrays.)
- **Courses with no node** (3) — `id NOT IN (SELECT course_id FROM sbn_course_skill_node)`.

**Pipeline:**
- **Pending voicing drafts** — already counted (`sbn_voicing_drafts WHERE status='pending'`); surface
  it here too with a link to that admin queue if one exists.

**Do NOT include** "dead-end nodes (unlocks=0)" (31) — terminal skills legitimately unlock nothing;
it's noise. **Do NOT include** "no grade" (it's 0 — fully populated).

Put these queries in `DashboardController` (or a small `App\Services\ContentHealthService` if the
controller gets fat — cleaner, and the drill-down reuses it). Each card row: label + count +
link to `admin.skill-nodes.coverage?gap=<key>`.

## Zone 4 — Coverage drill-down (new page, tab in skill-nodes admin)

The lists behind Zone 3's numbers. **New route + view:**
- Route: `Route::get('/skill-nodes/coverage', [SkillNodeController::class, 'coverage'])->name('admin.skill-nodes.coverage');`
  (add near the other `skill-nodes` routes in `routes/web.php`; bind in the `admin` group).
- Controller: `SkillNodeController@coverage` — returns all gap lists (reuse the
  `ContentHealthService` if you made one). Accept `?gap=<key>` to scroll/highlight one section, but
  render all sections on the page.
- View: `resources/views/admin/skill-nodes/coverage.blade.php`. Add a tab/nav alongside the existing
  skill-nodes index + layout editor (look at how `index.blade.php` / `layout.blade.php` cross-link —
  match that). Each gap = a section with the list of offending rows, each row linking to the relevant
  editor:
  - node gaps → `admin.skill-nodes.edit` for that node.
  - unlinked song/rhythm/progression → that content's admin editor (so you can... actually, linking
    *content* to a *node* is done from the NODE editor in Step A, not the content editor. So for
    content-side gaps, link each row to a node-search or just list them as a worklist with the
    content's own edit link for context. Simplest: list title + link to the node editor index with a
    note "link this from a node." Use judgment; don't overbuild.)
  - unclaimed voicing categories → link to `admin.skill-nodes.create` (make a node for it) or just
    name them.

Keep it a plain Blade table page in the house admin style (`sbn-editor-card`, etc.). No Vue.

## Out of scope

- No student-facing anything (that's Step B).
- No new content *editors* — only the dashboard, the coverage page, and the count/query plumbing.
- Don't redesign the admin layout/sidebar beyond adding the coverage tab link.

## Done = verified

1. `/admin` shows Totals (now incl. courses/lessons/skill-nodes), a Recently-edited feed with working
   edit links, and a Content-Health card. **The stale "Migration Progress" card is gone**, and
   `dashboard.blade.php` orphan is deleted.
2. Each Content-Health number links to `/admin/skill-nodes/coverage` and lands on/highlights the right
   section.
3. Coverage page lists the actual offending rows (spot-check: "nodes with no content" should list ~50
   incl. most Ear-Training/Technique nodes; "songs unlinked" ~71; voicing categories shows the 8).
4. Counts match a manual query (the spec's SQL is the oracle).
5. Numbers are computed live (no caching needed at this scale — 57 nodes, <300 of any content type).

## Reference trail

- `SBN-Skill-System-Reference.md` → "Node ↔ Content Links (Step A)" — what the links/columns are.
- `DashboardController.php` + `dashboard/index.blade.php` — what you're extending.
- `admin/skill-nodes/{index,layout}.blade.php` — the tab/nav pattern to match for the coverage page.

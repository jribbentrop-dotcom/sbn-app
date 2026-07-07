# SBN Leadsheet Versions — Design History & Open Items

Status: **shipped** (schema + backfill migrated 2026-06-23; editor/viewer call sites, tab-layer
mechanism, and merge flows shipped through 2026-06-29). This doc is now a design-history record —
the as-built reference lives elsewhere:

- **Data model** (work vs. arrangement split, `sbn_leadsheet_versions` schema, version-scoped
  detection caches, URL/slug resolution): [SBN-Leadsheet-Reference.md §2.3](SBN-Leadsheet-Reference.md#23-versions-arrangements--workarrangement-split-shipped-2026-06-23–29).
- **Editor mechanics** (version switcher, clone/delete, the melody/chord TAB-layer switching
  mechanism, tab-data merge, song merge): [SBN-Admin-Chord-Tab-Editor-Reference.md](SBN-Admin-Chord-Tab-Editor-Reference.md)
  — "LEADSHEET EDITOR PAGE" → Header bar / Tab layers / Merge flows.

The original design rationale (two-axis model: arrangements vs. notation layers, the FK blast
radius across three detection-cache tables, the `deploy_db.sh` preserve-list decision, and why
`chord_tab_xml` is a column and not a hidden sibling version row) is preserved below for context,
but treat the two docs above as current for anything about *today's* behavior.

---

## Open items (as of 2026-07-07)

1. **Version create/clone/delete UI** — clone/delete now exist (Actions menu, see admin editor
   ref); **create from scratch** (not clone) still requires SQL.
2. ~~Viewer chord-TAB toggle~~ — **shipped 2026-07-07.** `SongLibraryController::viewer()` /
   `apiViewerData()` now parse `chord_tab_xml` server-side (`resolveChordLayerMelody()`) into a
   `chordLayerMelody` array on the leadsheet payload. `LeadsheetViewer.vue` adds a `tabLayer` ref
   (`'melody'|'chord'`) with a Melody/Chords sub-toggle in the Options menu, shown only in Tab
   mode when `hasChordTab`; switching swaps `melodyRef`'s contents and rebuilds the shared tab
   model (no save-serialization needed — read-only viewer). Verified against real data: 8
   versions across 7 songs already have `chord_tab_xml` (Amazing Grace, Blue Bossa, Desafinado,
   Insensatez, Shadow of Your Smile, Corcovado, Love for Sale).
   **Bugfix same day:** `TabXmlParser::parse()` returns tick-grouped `TabEvent`s (nested
   `notes[]` per string), not the flat per-note array `useTabModel.buildModel()` consumes as
   input — the initial implementation (and the pre-existing `apiSheet?layer=chord`, shipped
   2026-06-29, never actually exercised) both fed the grouped shape straight through, so the
   chord layer silently rendered garbage/empty and fell back to showing melody notes. Fixed by
   adding `flattenTabEventsToNotes()` (un-groups each event back to one entry per note/rest);
   both call sites now route through it.
3. **`<sbn-song layer="chord">` slug access** — shipped 2026-06-29; `apiSheet?layer=chord` parses
   `chord_tab_xml` server-side into the same tick-based melody shape; `PdfController` also accepts
   `layer` in song/chord-card config.

---

## Original design rationale (historical — see pointers above for current behavior)

### The problem this solved

`sbn_leadsheets` used to be one row per song, mixing catalog identity (title, composer,
licensing) with one specific arrangement's data (`json_data`, `tab_xml`). Three needs didn't fit:
difficulty variants ("Basic" vs "João Gilberto" vs "Wes Montgomery"), performer versions, and two
notated TAB layers (melody vs. chord/comping) per arrangement.

**Two distinct axes, deliberately not conflated:**
- **Axis A — arrangements**: different *data* for the same song (difficulty/performer). One list
  entry, a dropdown to switch, separate DB rows.
- **Axis B — notation layers**: two notated views *within one arrangement* (melody TAB vs.
  chord/comping TAB), switched by a viewer/editor toggle, not a dropdown — they share the
  arrangement's key/form/progressions.

### Why `chord_tab_xml` is a column, not a hidden version row

A second TAB layer is the *same* arrangement viewed two ways, sharing the version's
key/form/progressions/voicing caches. A hidden sibling `sbn_leadsheet_versions` row would create a
phantom arrangement leaking into `versions()`, the dropdown, `versions_count` badges, and spawn
duplicate `sbn_voicing_usage`/`sbn_progression_occurrences` rows double-counting the same song's
harmony. The `chord_tab_xml` column avoids all of that: one identity, one set of detection caches,
two notation payloads.

### Decisions locked at design time (2026-06-23)

1. Detection caches (`sbn_progression_occurrences`, `sbn_voicing_usage`, `sbn_voicing_drafts`) are
   version-scoped via `version_id`, but **aggregate popularity counts stay `leadsheet_id`-keyed**
   — switching them to `version_id` would inflate counts ~N× per arrangement.
2. Dedicated `App\Models\LeadsheetVersion` model (not an `activeVersion` proxy on `Leadsheet`).
3. Version URL key is a **slug**, not numeric id — `deploy_db.sh` replaces content tables
   wholesale, so autoincrement ids can drift local↔prod; a bookmarked `?v=3` would silently point
   at the wrong arrangement after a reseed.
4. `sbn_leadsheet_versions` is content (authored + pushed like `sbn_leadsheets`), **not** added to
   the `deploy_db.sh` preserve-list. Any *future* user-state table referencing a version (e.g.
   last-viewed arrangement) would join the preserve list instead — never the versions table.

### Risk register (closed)

The FK graph spanned three tables (not just progressions, as originally scoped), which was the
main source of breadth. Mitigated by `deploy_db.sh` replacing content tables from a locally
verified DB — no risky in-place prod content migration was needed. All risks resolved during
shipping; see git history around 2026-06-23–29 for detail if needed.

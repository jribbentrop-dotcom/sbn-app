# SBN Leadsheet Versions — Design Plan

Status: **design agreed — ready to implement** (no code written yet). Author date: 2026-06-23.
§5 decisions resolved 2026-06-23.

Goal: support **multiple arrangements of one song** (difficulty levels, performer
versions) under a **single song-library entry**, plus **multiple notated TAB layers**
(melody TAB vs chord/comping TAB) inside each arrangement — switchable in the viewer
and the admin tab editor.

---

## 1. The problem

Today `sbn_leadsheets` is one row per song, and that row holds *both* the catalog
identity (title, composer, licensing) *and* one specific arrangement's data
(`json_data` chord grid, `tab_xml` melody notation). Three things we now want don't fit:

1. **Difficulty variants** — "Basic chords" vs "João Gilberto" vs "Wes Montgomery"
   of the same tune.
2. **Performer versions** — the same song as arranged/recorded by different players.
3. **Two notated TAB layers** — a melody TAB *and* a chord/comping TAB (with rhythm).

### Two distinct axes (don't conflate them)

- **Axis A — arrangements** (points 1 & 2): different *data* for the same song.
  Want **one list entry** with a **dropdown** to switch; separate DB rows are fine.
- **Axis B — notation layers** (point 3): two notated views *within one arrangement*.
  Switchable by a **viewer toggle**, NOT a dropdown. They share the arrangement's
  key/form/progressions, so they are layers of one row — not sibling rows.

Difficulty and performer are **not** two multiplying axes — they are two **columns
describing one arrangement** (every arrangement has a difficulty; some have a named
performer). A flat list of arrangements, each carrying `performer` + `difficulty`,
covers all of points 1 & 2.

### 1.1 FK surface (the real blast radius)

`leadsheet_id` is a FK in **three** content tables, all of which become version-scoped
after the split (a voicing usage / progression occurrence belongs to one *arrangement*,
not the whole song):

| Table | Keyed on | Written by |
|---|---|---|
| `sbn_progression_occurrences` | `leadsheet_id` + `section_id` | `ProgressionDetector` |
| `sbn_voicing_usage` | `leadsheet_id` | `VoicingCrossref` |
| `sbn_voicing_drafts` | `leadsheet_id` | `VoicingCrossref` |

All three need a `version_id` (Decision 1 generalised). See §7 risk register.

---

## 2. Schema

### 2.1 `sbn_leadsheets` — becomes "the WORK" (catalog identity)

Keeps everything intrinsic to the composition; loses the per-arrangement data.

Stays:
```
id, slug, title, composer, song_key (canonical/default), time_signature,
genre, popularity, status,
description, harmony_notes, form_notes, voicing_notes,
license_status, is_pro,          ← properties of the WORK, never the arrangement
course_id, cover_image_path, created_at, updated_at
```

Adds:
```
default_version_id  → FK to sbn_leadsheet_versions (which arrangement loads by default)
```

Moves OUT (to versions): `json_data`, `tab_xml`, `shortcode_content`, `rhythm`,
`tempo`, `measure_count`, `difficulty`.

> **Licensing stays on the work.** `is_pro` / `license_status` must never live on a
> version — otherwise one arrangement could be PD and another not. The rule
> "`is_pro` only on `public_domain` rows" stays a single decision per song.

### 2.2 `sbn_leadsheet_versions` — NEW, "the ARRANGEMENT"

```
id
leadsheet_id      FK → sbn_leadsheets, cascade on delete
label             display name, e.g. "Basic" / "João Gilberto" / "Wes Montgomery"
performer         nullable string   ← must-do per product
difficulty        int (1–5)         ← drives dropdown sort order
song_key          nullable          ← versions may transpose; fall back to work key
rhythm            nullable          ← versions may differ
tempo             nullable
measure_count     int
json_data         chord grid + chordVoicings (today's parsed_data)
melody_tab_xml    notated melody MusicXML  (today's tab_xml)
chord_tab_xml     notated comping/rhythm TAB MusicXML   ← NEW data to author
shortcode_content nullable (legacy voicings block, follows json_data)
sort_order        int
status            'draft' | 'publish'   ← draft a Wes version while Basic is live
created_at, updated_at
```

#### Why `chord_tab_xml` is genuinely new data (not a render mode)

Today a "chord" in a leadsheet is a **grid cell** (name + voicing diagram) with **no
rhythmic placement** on a staff. The only rhythmically-notated layer is the melody
(`json_data.melody` / `tab_xml`). A **chord TAB** is the chords *voiced as tab with a
strum/rhythm pattern* — that notation does not exist yet and must be authored. So
melody-TAB and chord-TAB are two **distinct notation payloads**, stored as two columns
on the same version row. The chord **grid** view remains a third view, derived from
`json_data` as today.

### 2.3 Viewer views per loaded version

```
Grid        ← json_data chord cells (existing default)
Melody TAB  ← melody_tab_xml
Chord TAB   ← chord_tab_xml   (hidden until authored, like today's tabHasData gate)
```

This extends the viewer's existing `mode: 'no-chords' | 'chords' | 'tab'` toggle
(SBN-Leadsheet-Reference.md §"mode ref") — add the melody/chord-TAB distinction as
toggle states reading from the active version. No dropdown involved.

---

## 3. Behaviour

- **Song library list** (`/library/songs`) queries `sbn_leadsheets` — still **one row
  per song**. No change to the card count.
- **Show / Viewer pages** resolve an **active version**: URL `?v={version_id|label-slug}`
  or fall back to `default_version_id`. All `json_data`/tab reads come from that version.
- **Dropdown** (Show page + admin list): lists the song's `versions` ordered by
  `difficulty` then `sort_order`, labelled `label` (+ `performer` when set).
- **Admin tab editor**: a version switcher (swap the loaded version's `json_data` +
  tab XML into the existing editor model) AND the Grid/Melody-TAB/Chord-TAB view toggle.
- **Migration of existing data**: every current `sbn_leadsheets` row spawns exactly one
  version (its current `json_data`/`tab_xml` → a version row labelled "Basic",
  difficulty from old `difficulty`), and `default_version_id` points at it. Existing
  URLs keep working because the controller falls back to the default version.

---

## 4. Call sites to refactor

`json_data` / `tab_xml` / `parsed_data` currently read off the **leadsheet**. After the
split they read off the **active version**. The schema is small; this list is the real work.

### Model
- **New `app/Models/LeadsheetVersion.php`** (Decision 2 chose this over a proxy) — owns
  the arrangement-level accessors moved off `Leadsheet`: `getParsedDataAttribute`,
  `getSectionCount`, `getVoicingCount`, `getHasMelody`, `getHasTabXml`, `getHasRepeats`,
  `getChordNames`, `computeMeasureCount`, `removeVoicing*`. Holds `versions`-table
  columns + `generateUniqueVersionSlug`.
- `app/Models/Leadsheet.php` — keeps work-level methods (`toLinkArray`, licensing scopes,
  `styleSlug`, distinct-* statics). Add `versions()` hasMany + `defaultVersion()`
  belongsTo. `getStats`'s `withMelody` LIKE query moves to count over the **versions**
  table. `VoicingUsage`/`VoicingDraft`/`ChordProgression` hasMany relations (currently
  keyed `leadsheet_id`) gain version-scoped sibling relations.

### Controllers
- `app/Http/Controllers/Library/SongLibraryController.php` — `show`, `viewer`, `cinema`,
  `apiViewerData`, `apiSheet`, `apiSearch`. Resolve active version; pass `versions[]`
  list to Show for the dropdown. **Keep `abortIfNotPro` reading `is_pro` off the work.**
- `app/Http/Controllers/Admin/LeadsheetController.php` — ✅ version-aware editing DONE
  (stage 6). `edit(?v=slug)` overlays the selected version's data onto the rendered
  leadsheet; `update(?v=slug)` writes arrangement fields to that version (+ detects
  against it) AND dual-writes the leadsheet legacy columns (read fallback). List query
  eager-loads `versions`/`versions_count`. Badge "N ▾" + Alpine accordion in
  `index.blade.php` link to `edit?v=slug`; a header `<select>` switches arrangements in
  `edit.blade.php`. NOT yet built: create/clone/delete a version in the UI (new
  arrangements still need the merge/SQL path).
- `app/Http/Controllers/Admin/PdfController.php` — reads `json_data`/`tab_xml` for PDF
  render; point at active version.
- `app/Http/Controllers/CourseController.php`, `HomeController.php`,
  `Library/SyncedPlayerController.php` — embed leadsheet data; route through default version.

### Services
- `app/Services/LeadsheetViewerService.php` (`enrich`, `fetchProgressions` joins
  `o.leadsheet_id`) — core consumer of `parsed_data`; takes a version, filters
  occurrences on `version_id`.
- `app/Services/ProgressionDetector.php` — writes occurrences (`leadsheet_id` at :1106,
  clears at :930); add `version_id`. **Keep `song_count` `COUNT(DISTINCT leadsheet_id)`
  at :1518 (§7 Risk 2).**
- `app/Services/VoicingCrossref.php` — writes `sbn_voicing_usage`/`sbn_voicing_drafts`
  (:1218–1262), clears at :1261. Add `version_id`. **Keep `usage_count` `COUNT(DISTINCT
  leadsheet_id)` at :223 (§7 Risk 2).**
- `app/Services/LeadsheetParser.php`, `TabXmlParser.php`, `AnalysisToLeadsheet.php`,
  `LeadsheetScaffolder.php`, `VoicingMaterializer.php`, `ProgressionBuilder.php` —
  produce/consume `json_data`; write into a version.

### Other
- `app/Console/Commands/NormalizeLeadsheetChordNames.php`, `BassSnapDebug.php`,
  `LlmSpike.php` — batch tools over `json_data`; iterate versions.
- `app/Models/Exercise.php` shares `sbn_leadsheets` semantics in places — confirm the
  Exercises path is unaffected (different controller; likely keep single-version).

### Frontend
- `Pages/Library/Songs/Show.vue` — ✅ DONE (stage 5). Arrangement `<select>` in the
  hero, shown only when `versions.length > 1`; `switchVersion()` does
  `router.get(?v=slug)`. Consumes `versions[]`/`activeVersion`.
- `Pages/Library/Songs/Viewer.vue` + `LeadsheetViewer.vue` — ✅ version switcher DONE
  (stage 5b). `<select>` in the viewer header controls band, shown only with >1 version;
  `switchVersion()` does `router.get(/viewer?v=slug)` with a full reload so the tab model /
  audio engine rebuild from the new arrangement. Props threaded Viewer.vue → LeadsheetViewer.
  The existing `mode` toggle (`'no-chords' | 'chords' | 'tab'`, localStorage-persisted)
  already provides **Grid** (chords/no-chords) and **Melody TAB** (`'tab'`, renders the
  tab model from `json_data`/`melody`). The **Chord TAB** layer is DEFERRED: `chord_tab_xml`
  is null on every version (it's authored-later data), so a 4th mode would render an empty
  branch for all songs. Add it only when chord-tab data exists — the `hasChordTab` flag is
  already plumbed to the frontend props for that day.
- `toLinkArray()` unchanged (work-level identity).

---

## 5. Decisions (resolved 2026-06-23)

1. **Detection caches are version-scoped — all three tables (§1.1).** Progression
   occurrences AND voicing usage/drafts are derived from a version's `json_data` (Wes's
   reharm uses different progressions and voicing shapes than Basic), so each row belongs
   to one arrangement. For **each** of `sbn_progression_occurrences`, `sbn_voicing_usage`,
   `sbn_voicing_drafts`: **add `version_id`** (FK), filter Show/viewer queries on it, and
   **keep `leadsheet_id`** denormalized for rollup. For occurrences, extend the existing
   `['leadsheet_id','section_id']` index with `['version_id','section_id']`. Backfill all
   existing rows → their leadsheet's default version. `ProgressionDetector` and
   `VoicingCrossref` write `version_id` on detection.
   - **Aggregate counts stay `leadsheet_id`-keyed (§7 Risk 2).** `COUNT(DISTINCT
     leadsheet_id)` queries are "how many *songs*" popularity metrics
     (`ProgressionDetector` `song_count`, `VoicingCrossref` `usage_count`). They must
     NOT switch to `version_id` or counts inflate ~N× per arrangement. Only
     display/filtering uses `version_id`.
   - *Carry-forward, do not fix here:* `section_id` is already always null in occurrences
     (SBN-Leadsheet-Reference.md §"ProgressionRef.sectionId") — preserve that behaviour,
     don't add section-filtering against it in this work.
2. **Use a dedicated `App\Models\LeadsheetVersion` model** (option (a) in §4), not an
   `activeVersion` proxy on `Leadsheet`. The `json_data`/`tab_xml`-touching accessors are
   arrangement-level and move onto it; the table-wide statics (`getStats`'s `withMelody`
   query, `getDistinctKeys`) become queries over the **versions** table, which only makes
   sense with a first-class version model. `Leadsheet` keeps work-level concerns
   (`toLinkArray`, licensing scopes, style slug, distinct-* statics) and exposes
   `versions()` / `defaultVersion()`.
3. **Version URL key is a slug**, not numeric. Add `version_slug`, unique **per
   leadsheet** (`['leadsheet_id','version_slug']`), derived from `performer`/`label`
   (`basic`, `joao-gilberto`, `wes-montgomery`). URL:
   `/library/songs/{slug}/viewer?v=joao-gilberto`. Rationale: `deploy_db.sh` replaces
   content tables wholesale, so autoincrement `version.id` can differ local↔prod — a
   bookmarked or lesson-embedded `?v=3` would drift to the wrong arrangement. Slugs are
   stable across reseeds and readable in `<sbn-song>` embeds. Add a
   `generateUniqueVersionSlug` helper mirroring `Leadsheet::generateUniqueSlug`.
4. **`sbn_leadsheet_versions` is NOT added to the `deploy_db.sh` preserve-list.** It is
   content — authored locally and pushed up like `sbn_leadsheets` itself (the preserve
   list is user-state only: users/orders/course_user/sessions/…). **Rule going forward:**
   if any *user-state* table is later added that references a version (e.g. last-viewed
   arrangement, per-version practice progress), *that* table joins the preserve list —
   never the versions table. (Same caveat already tracked for `sbn_user_skill_progress`.)

---

## 6. Migration outline (when approved)

Schema migrations (each with the repo's idempotent `if Schema::hasTable/hasColumn` guard):

1. **Create `sbn_leadsheet_versions`** — columns per §2.2, including `version_slug`
   (Decision 3) with a unique `['leadsheet_id','version_slug']` index.
2. **Add `default_version_id`** to `sbn_leadsheets`.
3. **Add `version_id`** (Decision 1) to all three detection-cache tables:
   `sbn_progression_occurrences` (+ a `['version_id','section_id']` index alongside the
   existing `leadsheet_section`), `sbn_voicing_usage`, `sbn_voicing_drafts`.

Data migration (one migration, runs after 1–3):

4. **Pre-flight sanity check** (§7 Risk 3) — before inserting versions, confirm against
   the live DB how many rows have empty `json_data` and whether Exercises rows are mixed
   into `sbn_leadsheets`; decide skip-vs-handle for those. Then for each leadsheet, insert
   one **"Basic"** version (`version_slug='basic'`) copying `json_data` → `json_data`,
   `tab_xml` → `melody_tab_xml`, and `rhythm`/`tempo`/`measure_count`/`difficulty` across;
   set the leadsheet's `default_version_id`. Backfill `version_id` on **all three** cache
   tables → each row's leadsheet's default version.

Code (§4):

5. Add `App\Models\LeadsheetVersion` (Decision 2); move the `json_data`/`tab_xml`
   accessors onto it; `Leadsheet` exposes `versions()`/`defaultVersion()` and keeps
   work-level methods. Refactor controllers/services to resolve and read the **active
   version**. `ProgressionBuilder` + detection command write `version_id`.

Cleanup (later, separate migration):

6. Leave the old `json_data`/`tab_xml`/`rhythm`/`tempo`/`measure_count`/`difficulty`
   columns on `sbn_leadsheets` in place initially (dual-read) → drop them only once all
   call sites are confirmed off them, in a follow-up migration. Follow the deploy doc's
   "no destructive migration on the first pass" caution.

---

## 7. Risk register

Overall: **moderate, well-bounded.** The work/arrangement model is conceptually safe; the
risk is breadth of the FK graph and a few queries that quietly assume one-leadsheet =
one-arrangement. Biggest mitigation: **deploy replaces content tables from the local DB**
(`deploy_db.sh` §5), so the whole migration is done and verified locally before push — no
risky in-place prod content migration. `migrate --force` on prod only runs schema DDL.

| # | Risk | Severity | Mitigation |
|---|---|---|---|
| 1 | **Three FK tables, not one.** `sbn_voicing_usage` + `sbn_voicing_drafts` also key on `leadsheet_id` (plan originally named only occurrences). Each needs `version_id`. | Medium | §1.1, §3, §4, §6 now cover all three. 3× the migration/backfill, same pattern. |
| 2 | **`COUNT(DISTINCT leadsheet_id)` aggregates inflate if re-keyed to `version_id`.** `ProgressionDetector` `song_count` (:1518), `VoicingCrossref` `usage_count` (:223) are *song-level* popularity — switching them silently multiplies counts ~N× per arrangement and distorts Top-4 chord cards + progression ordering. **No error, just wrong numbers.** | Medium | These stay `leadsheet_id`-keyed; only display/filtering uses `version_id`. Spelled out in Decision 1 + §4. |
| 3 | **Backfill is only as clean as current data.** Rows with empty/null `json_data` (chord stubs), and Exercises rows sharing `sbn_leadsheets` (different controller), can create junk version rows. | Low-Med | §6.4 pre-flight: count empty-`json_data` rows + decide Exercises skip-vs-handle BEFORE writing the backfill. |
| 4 | **Dual-read window must not be skipped.** Dropping old columns before every call site is confirmed off them risks a missed reader breaking silently. | Low | §6.6 keeps old columns; drop in a separate later migration after verification. |
| 5 | **Legacy WP code** (`sbn-course-player(legacywp)/`) also references `leadsheet_id`. | None | Not running; ignore. |

### Out of scope / carry-forward (don't fix in this work)
- `section_id` always null in occurrences (pre-existing) — preserve behaviour.
- Exercises versioning — Exercises stay single-version; only confirm they're unaffected.

---

## 8. Chord-TAB layer (Axis B) — implementation plan

Status: **design agreed 2026-06-24, ready to implement.** This is the second notated
TAB layer (`chord_tab_xml`) — the comping/rhythm staff alongside the melody staff,
**within one version row**. It is NOT a second version (sibling row) and NOT a second
song-library entry — it is invisible as a standalone leadsheet, switched by a sub-toggle
inside the editor/viewer Tab view.

### 8.0 Why a column, not a hidden version row (decision)
A second TAB layer is the *same* arrangement viewed two ways (melody-tab vs comping-tab),
sharing the version's key/form/progressions/voicing caches. Storing it as a hidden sibling
`sbn_leadsheet_versions` row would create a phantom arrangement that leaks into
`versions()` relations, the dropdown, `versions_count` badges, and spawns duplicate
`sbn_voicing_usage` / `sbn_progression_occurrences` rows that double-count the same song's
harmony. The `chord_tab_xml` column (already in the schema, §2.2) avoids all of that: one
identity, one set of detection caches, two notation payloads.

### 8.1 Already shipped — no work
- `chord_tab_xml` column on `sbn_leadsheet_versions` (migration `…000002`).
- `LeadsheetVersion::getHasChordTabAttribute()` — the authored-yet gate (mirrors `tabHasData`).
- `LeadsheetVersion::getHasMelodyTabAttribute()`.
- Version-aware `edit(?v=slug)` / `update(?v=slug)` in `LeadsheetController` (stage 6).
- `update()` already dual-writes `melody_tab_xml` on the active version from the incoming
  `tab_xml` (`LeadsheetController.php` ~:888) — the version-write plumbing exists; today it
  only ever targets the melody column.

### 8.2 Core architectural fact

> **CORRECTION (2026-06-24, as-built).** The original §8.2 premise — "route a different
> `tabXml` string through `_dispatchTabInit()` and the model reloads" — was **wrong**, and
> that wrong premise was the data-loss bug. The Vue tab model does **not** build its staff
> from the `tabXml` string. `useAlpineBridge.handleTabInit` sets `melody.value =
> d.parsed.melody` and `useTabModel.buildModel()` reads `melody.value`; the `tabXml` string
> is **only** the save-serializer's output (`modelToMusicXml(model.value)`), never an input
> to the model. So swapping only the `tabXml` string left both layers rendering the **same
> melody notes**, and a chord-layer save serialized those melody-derived notes back over
> `chord_tab_xml`. Symptom the user hit: *"importing the melody overwrites the chords too."*

**As-built mechanism.** The two layers share **one structure** (`parsed.sections` / chord
grid / `chordVoicings` / key / TS / repeats / videoSync — the MuseScore-two-instruments model)
and differ only in the **staff notes**. The staff notes live in `parsed.melody`. So a layer
switch must put the **active layer's notes** into `parsed.melody` before reload:

- `_graftLayerNotes(layerXml)` (`edit.blade.php`) parses the layer's XML → `melody` notes,
  spreads them onto the shared `parsed` skeleton (`{ ...skeleton, melody: notes }`), and
  warns (does not block) on a bar-count mismatch — first import defines the structure of
  record. Reassigns `parsed` by reference so `$watch('parsed')` + the bridge fire as on a
  fresh import. Then `_dispatchTabInit()` reloads (same route `sbn-rhythm-applied` uses).
- `melodyTabXml` / `chordTabXml` remain the **persistence** strings (parse-in on import/load,
  serialize-out on save) — they are not the model's data source on their own.

**`json_data` is the melody-layer's data, full stop.** A chord-layer save must NOT pull
`sections`/`melody`/shortcode from the live Vue model (it holds chord-staff events then).
`save()` and `shortcodeOutput` gate the `window.__sbnTabModel` reads on `activeTabLayer !==
'chord'`; `_melodyLayerJsonMelody` preserves the melody notes across a chord-layer edit so
`json_data.melody` is never clobbered with chord notes.

### 8.3 Steps

**Step 1 — Editor Tab-layer sub-switch (Vue, `TabEditor.vue`)**
- Add `tabLayer = ref('melody')` (`'melody' | 'chord'`). Render a Melody/Chord pill **only
  when `viewMode === 'tab'`** so the top bar stays Chords | Tab | Analysis (per user: "opens
  in the tab sub switch", not a 4th top-level tab).
- `provide('tabLayer', …)` + a `setTabLayer()` setter. On switch, clear `inlineRenameTarget`
  (same as `setViewMode` does) to avoid a stale open rename input.
- The pill for "Chord" is always visible in the editor (authoring surface); the viewer hides
  it until `hasChordTab` (Step 6).

**Step 2 — Alpine shell holds both strings (`edit.blade.php`)**
- Split `tabXml` into `melodyTabXml` + `chordTabXml`. Keep `tabXml` as a computed alias =
  the active layer's string to minimise churn through the existing save/init code.
- Add `activeTabLayer` (one-way mirror from Vue, same pattern as `alpineViewMode`).
- Seed `chordTabXml` from `ls.chord_tab_xml` alongside the existing `ls.tab_xml` seed (~:2023).
- `_dispatchTabInit()` (~:2120) sends the **active layer's** XML as `tabXml` (via the alias).

**Step 3 — Layer switch = serialize-out-then-load-in (reuse rhythm-applied reload)**
New event `sbn-tab-layer-changed` (Vue→Alpine). On switch, in order:
1. Vue serializes current layer → round-trip via existing `sbn-tab-save-request` /
   `sbn-tab-save-response` → Alpine stores result into the **outgoing** layer's string.
2. Alpine sets the active layer to incoming, resets `_tabInitDone` / `_tabVueInitialized`
   (exactly as the `sbn-rhythm-applied` handler ~:1636 does), calls `_dispatchTabInit()`.
3. Vue rebuilds the model from the incoming layer's XML.
The 3s `sbn-tab-save-response` timeout guard (~:2638) applies — if serialize times out,
abort the switch and keep the current layer (do NOT load incoming over an unsaved outgoing).

**Step 4 — Save writes both columns**
- `save()` (~:2510): ensure the active layer is serialized (already happens); the inactive
  layer keeps its last-known string. POST **both** `melody_tab_xml` and `chord_tab_xml`.
- `LeadsheetController::update()` (~:888): write `melody_tab_xml` (as today) AND
  `chord_tab_xml` from the new payload onto `$activeVersion`. Add `chord_tab_xml` to
  validation (~:1539, mirror the `tab_xml` `nullable|string` rule). Keep the legacy
  `tab_xml` dual-write on the leadsheet for **melody only** — chord-TAB has no legacy
  column (new data), that's correct.
- **Detection caveat (do not "fix" later):** `VoicingCrossref::processLeadsheet()` and
  `ProgressionDetector` run off `json_data`, NOT tab XML. Authoring `chord_tab_xml` must
  NOT touch voicing/progression caches. Confirm no detection pass keys off `chord_tab_xml`
  and note it in the commit message.

**Step 5 — Import / generate into the chord layer**
- 5a (primary): the existing MusicXML file-drop import (~:1833) writes to the **active**
  layer's string. Minimal change: target the active layer, not unconditionally
  `melodyTabXml`. So: switch to Chord layer → drop MusicXML → parses into `chord_tab_xml`.
- 5b (fast follow): a "Generate from voicings" button on the Chord layer that calls the
  existing Apply Rhythm pipeline (`VoicingMaterializer` → materialized `tab_xml`) and routes
  its output into `chord_tab_xml` instead of melody. Reuses `applyRhythm()` wholesale; only
  the destination column changes. Build 5a first.

**Step 6 — Viewer toggle (`LeadsheetViewer.vue` + `SongLibraryController`)**
- `mode` toggle is `'no-chords' | 'chords' | 'tab'`. Add the Melody/Chord distinction
  within the `'tab'` state, gated on the already-plumbed `hasChordTab` prop (hide Chord when
  null). Reads `chord_tab_xml` off the active version.
- `SongLibraryController` `viewer` / `apiViewerData`: pass `chord_tab_xml` + `hasChordTab`
  for the active version (`?v=` resolution already exists). Can ship after Steps 1–5.

### 8.4 Risks
- **Highest: switch/save ordering (Steps 3–4).** Serialize-outgoing-BEFORE-load-incoming
  must be airtight or edits are lost — this is the exact class of bug commit `17b5acf`
  ("restore arrangement overwritten by stale-editor save") already hit. Abort-on-timeout
  is mandatory, not optional.
- **Scope split:** Steps 1–5 (editor + storage) are the core deliverable; Step 6 (viewer)
  can ship separately. No schema, no migration — frontend + one controller method + one
  validation rule.
- **Test matrix:** author melody → switch to chord → import XML → switch back → save →
  reload; both layers must survive independently. Verify `versions_count` / dropdown
  unchanged (no phantom row) and detection caches unchanged when only `chord_tab_xml` edits.

---

## 9. Editor chrome + tab-data merge (SHIPPED 2026-06-24)

- **Tab bar** (`TabEditor.vue`): layers promoted to top-level tabs —
  **Grid | Chords | Melody | Analysis | 🎬 Video**. `Grid` = chord-cell grid
  (`viewMode='chords'`); `Chords` = Tab-II (`chord_tab_xml`, `tabLayer='chord'`);
  `Melody` = Tab-I (`melody_tab_xml`, `tabLayer='melody'`). `selectTabLayerView()` enters tab
  view + runs the layer switch. Nested pills removed. External `sbn-tab-set-layer` event lets
  Alpine drive the switch.
- **Actions dropdown** (`edit.blade.php` `@section('actions')`): Import MusicXML → Melody /
  → Chords (switches layer first via `sbn-import-into`, then opens the picker), Merge sheets…,
  Save as Exercise. Loose import/exercise buttons removed.
- **Merge — Phase 1 (tab-data into one version's two layers):**
  `LeadsheetController::mergeVersions()` + `mergeSourceList()`; routes
  `admin.leadsheets.merge-{versions,sources}`. Pick a **mother** version (target, this song) +
  a **melody source** + a **chords source**; each source's single tab → the mother's
  melody/chord layer. Mother's `json_data`/grid kept verbatim; **no detection run** (tab XML
  doesn't drive caches). Source-list aliases are `tab_melody`/`tab_chord` — must stay
  accessor-free (the model's `getHasMelody*Attribute` would otherwise shadow a `has_*` alias
  with grid-melody logic; this was a real bug, fixed).

### 9.1 Axis-A leadsheet merge — SHIPPED (2026-06-29)
`LeadsheetController::mergeSong()` + `mergeSongSourceList()`; routes
`admin.leadsheets.merge-song` + `admin.leadsheets.merge-song-sources`. Song B becomes a sibling
arrangement under song A; B's `sbn_voicing_usage` / `sbn_voicing_drafts` /
`sbn_progression_occurrences` re-pointed to A's id + new version id; B's row deleted.

---

## 10. Open items (as of 2026-06-29)

1. **Version create/clone/delete UI** — new arrangements still require SQL; no admin UI yet.
2. **Viewer chord-TAB toggle (Step 6)** — `melodyTabXml`/`chordTabXml` now passed in props
   (shipped 2026-06-29) but `LeadsheetViewer.vue` doesn't yet expose a Melody/Chord sub-toggle.
   Add only when chord-tab data exists on at least one song (`hasChordTab` gate).
3. **`<sbn-song layer="chord">` slug access** — `apiSheet?layer=chord` shipped 2026-06-29;
   parses `chord_tab_xml` server-side → same tick-based melody shape. `PdfController` also
   accepts `layer` in song/chord-card config.

# SBN PDF Pipeline — Reference

**Single source of truth for the PDF product system.** Supersedes and merges: `SBN-PDF-Briefing.md`,
`SBN-PDF-Pipeline-Plan.md`, `SBN-PDF-DESIGN-SYSTEM.md`, `SBN-PDF-Editor-Build-Spec.md`,
`SBN-PDF-Top10-Template-Spec.md`, and `design/pdf-system/docs/RENDER-PIPELINE.md`. Those are historical;
this is the as-built reference. Last updated 2026-06-27.

---

## 1. What this is

Branded, print-ready A4 PDFs for the SBN shop (chord books, song books, teaching material), generated
**from the live DB** — the same chord diagrams, rhythm patterns, and TAB notation the webapp uses. No
hand-built HTML, no design double-maintenance.

**You author content in an admin editor; the layout is fixed Blade. Preview in the browser, download as PDF.**

### The flow

```
Admin editor (/admin/pdf)  ──edit text + pick slugs──►  sbn_pdf_documents.content (JSON)
                                                              │
                              PdfController::buildData($doc)  │  pulls diagrams / rhythm / TAB from DB,
                                                              │  renders each to SVG via scripts/pdf/*.cjs
                                                              ▼
                              composed.blade.php  →  partials (_cover/_theory/_chord-item/_song-example)
                                                              ▼
                              preview: returns HTML   ·   download: Browsershot (Chrome) → A4 PDF
```

---

## 2. Architecture (as-built)

### Database — `sbn_pdf_documents`
Migration `2026_06_27_000001_create_sbn_pdf_documents_table.php`.

| Column | Notes |
|---|---|
| `slug` | unique; route key (`/admin/pdf/{slug}/...`) |
| `template_key` | `composed` for new docs; legacy keys (`top10`, `chord-book`) for pre-page-registry docs |
| `title` | display title |
| `content` (JSON) | all authored field values, shape defined by the page registry |
| `pages` (JSON) | array of page-type keys, e.g. `["cover","theory","chords","songs"]` — drives both editor + render |
| `status` | `draft` \| `publish` |

### Model — `app/Models/PdfDocument.php`
- Casts `content` and `pages` to array.
- **`pageRegistry()`** — THE central registry. Each page type declares `label`, `partial` (Blade view),
  and `fields` (the editor fields it contributes). This is the single source of truth for both the editor
  form and what content each page consumes.
- **`editorSchema()`** — builds the editor field list from `$this->pages` by walking the registry
  (inserts a `section` divider per page). Falls back to `config/pdf/templates/{key}.php` for legacy docs.
- `getRouteKeyName()` → `slug`.

### Controllers
- **`PdfDocumentController`** (the editor): `index`, `store` (create new composed doc), `edit`, `update`
  (saves `content`), `destroy`, + autocomplete `searchChords` / `searchRhythms` / `searchSongs`.
- **`PdfController`** (the renderer): `preview($slug)` returns HTML; `download($slug)` → Browsershot PDF.
  - `resolveBlade($doc)`: `admin.pdf.composed` when `pages` set, else legacy blade from template config.
  - `buildData($doc)`: branches → `buildComposedData` (pages set) → which calls `buildTop10Data`
    (the rich builder) and injects `$pages`. Legacy `top10`/chord-book branches still exist for old docs.
  - `buildTop10Data` does the DB work: per chord item renders the **color** diagram SVG, slices the
    practice-TAB source (`practice_tab_slug`, 1-indexed `tab_bars`) — tries `sbn_leadsheets` first,
    falls back to `sbn_exercises` (both have a `tab_xml` column) — and fetches rhythm pattern
    strings; per song slices the leadsheet and renders TAB rows with inline chord diagrams.

### Routes (`routes/web.php`, admin group `auth`+`instructor`)
```
GET    /admin/pdf                     pdf.index
POST   /admin/pdf                     pdf.store        (create composed doc)
GET    /admin/pdf/{document}/edit     pdf.edit
PUT    /admin/pdf/{document}          pdf.update
DELETE /admin/pdf/{document}          pdf.destroy
GET    /admin/pdf/preview/{slug}      pdf.preview      ← declared BEFORE {document} wildcard
GET    /admin/pdf/download/{slug}     pdf.download
# api/admin group:
GET    /api/admin/pdf/search-chords|search-rhythms|search-songs|search-tab-sources
```
⚠️ **Route order:** the static `preview`/`download` routes are intentionally before `/pdf/{document}/edit`
so "preview"/"download" don't bind as a `{document}`. Keep that order.

### The editor — `resources/views/admin/pdf/edit.blade.php`
Schema-driven Alpine form. Walks `editorSchema()['fields']` and renders one widget per field `type`:

| `type` | widget |
|---|---|
| `section` | a divider/heading in the form (from page registry) |
| `text` (`multiline:true` ⇒ small textarea) | input |
| `textarea` | plain textarea (raw HTML ok) |
| `richtext` | TipTap (description-editor) — **top-level fields only**, NOT inside repeaters |
| `number` / `range` | number input / two number inputs `[from,to]` |
| `chord-slug` / `rhythm-slug` / `song-slug` (`multiple:true` ⇒ chip list) | debounced autocomplete dropdown |
| `tab-source-slug` | same, but searches `sbn_leadsheets` + `sbn_exercises` together (dropdown shows a `· leadsheet`/`· exercise` tag) — used only by `practice_tab_slug` |
| `repeater` | add/remove/reorder list of sub-field groups; sub-fields support text/textarea/number/range/slug (**no richtext, no nested repeater**) |

Top bar: status select · Save (AJAX PUT) · Preview ↗ (save-then-open) · Download.

### Render scripts — `scripts/pdf/` (only these are load-bearing)
| Script | Role |
|---|---|
| `render-diagram.cjs <slug> [--bw]` | chord diagram SVG from DB (color default; `--bw` for print mono) |
| `render-tab.cjs` | TAB row SVG from measure JSON (stdin/file) |
| `render-tab-v2.cjs` / `render-tab-diagrams.cjs` | TAB row with inline chord diagrams above the staff |
| `extract-tab-svg.cjs` | pull a TAB staff out of a MuseScore SVG export (legacy import path) |
| `render-diagram-inline.cjs` / `render-diagram-from-json.cjs` | diagram from inline voicing JSON (no DB) |

Everything else in `scripts/pdf/` (`check-*`, `inject-*`, `fix-tab*`, `restructure-*`, `debug-*`,
`list-*`, `find-*`, `dump-*`, `query-*`, `test-*`) is **one-off scratch from the manual build era — not
used by the pipeline.** Safe to delete or archive (see §6).

### Browsershot
`download()` uses `Browsershot::html($html)->setChromePath('C:\Program Files\Google\Chrome\Application\chrome.exe')->format('A4')->showBackground()->waitUntilNetworkIdle()->pdf()`.
`waitUntilNetworkIdle` matters: the inline `sbnFormatChord` JS formats chord names after load (see §4).

---

## 3. Page-layout structure (reference for building new layouts)

A "layout" = a set of **page types**. A document's `pages` array lists which page types it includes, in
order. Each page type is one partial + a field block in the registry. The four shipped page types:

### `cover` → `partials/_cover.blade.php`
Centered title page. Fields: `eyebrow`, `title` (multiline → `<br>`), `subtitle`, `hook`, `facts`
(one per line → `.cover__fact`). Logo via `asset('images/soulbossanova.jpg')`. No DB data.

### `theory` → `partials/_theory.blade.php`
Prose page. Fields: `theory_title`, `theory_html` (richtext). No DB data.

### `chords` → `partials/_chord-item.blade.php` (one page per repeater item)
The rich method-book page. Repeater `chords[]`, each item:

| Field | Slot | Source |
|---|---|---|
| `title` | `.item__title` | authored |
| `display_chord` | diagram + margin chord name (`data-chord`, JS-formatted) | authored (plain text) |
| `slug` | `.item__diagram-feature` SVG | **DB** `render-diagram.cjs` (color) |
| `lede` | `.item__lede` (italic) | authored |
| `body` | `.item__body` | authored HTML (`{!! !!}`) |
| `voicing_pill` | `.item__voicing-pill` | authored (`\n`→`<br>`) |
| `intervals` | `.item__interval-row` pills | authored `"label:kind, …"` — kind ∈ `root\|third\|fifth\|seventh\|ext` (pill color) |
| `listen` / `try_this` / `related` | margin notes | authored HTML |
| step-1 rhythm grid | `.rhythm-grid` cells | **DB** `rhythm_slug` → `rhythm_pattern`/`thumb_pattern` (`x`/`X`=hit, `.`=rest); labels from time sig |
| `rhythm_meta` | step-1 meta line | authored |
| `practice_label` / `practice_meta` | step-2 label/meta | authored (label HTML ok) |
| `practice_tab_slug` + `tab_bars` (1-indexed) + `tab_bars_per_row` | `.pattern-tab` SVG | **DB** slice leadsheet or exercise (leadsheet checked first) → `render-tab.cjs` |

### `songs` → `partials/_song-example.blade.php` (one page per repeater item)
Repertoire/excerpt page. Repeater `songs[]`, each item:

| Field | Slot | Source |
|---|---|---|
| `eyebrow` / `title` / `sub` | header | authored (sub HTML ok) |
| `legend` | `.example__legend` chips | authored, one line `NN ChordName` per chip |
| `slug` + `bars` (1-indexed) + `bars_per_row` | `.example__notation-wrap` rows | **DB** slice leadsheet → `render-tab-diagrams.cjs` (TAB + inline diagrams; diagrams resolved via `sbn_voicing_usage`) |
| `note` | `.example__note` callout | authored HTML |

### Shared shell — `partials/_layout.blade.php`
Holds the `<style>` (all CSS classes: `.pdf-page`, `.cover__*`, `.theory__*`, `.item__*`, `.example__*`,
`.badge`, `.rg-*`, `.pattern-*`, `.sbn-chord-*`), `@font-face` (Bravura), `@page A4`, and the inline
`sbnFormatChord` JS. `composed.blade.php` extends it and `@yield('pages')`. Design tokens (`:root` colors,
fonts) mirror `public/css/sbn-design-system.css`.

### How to add a NEW page type (the recipe — 3 coordinated edits)
1. **`PdfDocument::pageRegistry()`** — add an entry: `label`, `partial`, `fields[]` (use existing field
   types; for repeating pages wrap fields in a `repeater`).
2. **`resources/views/admin/pdf/partials/_yourtype.blade.php`** — the layout. Author static text +
   `{{ }}`/`{!! !!}` slots. For DB-rendered SVG, expect a `_xxx_svg` key injected by the builder.
3. **`composed.blade.php`** — add a `@case('yourtype')` that includes the partial (looping a repeater if
   the page repeats).
4. If the page needs DB rendering, extend **`buildTop10Data`** to populate the `_xxx` keys.
5. Add new CSS classes to **`_layout.blade.php`**.

**This is the place to use Claude co-work:** give it this section + an existing partial (e.g.
`_chord-item.blade.php`) + the target visual, and have it produce the new registry entry + partial + CSS.
Field types are a closed set (§2 editor table) — a new layout combines them; it should not need editor JS
changes. The one thing that needs editor work is a genuinely new field *widget* (e.g. real nested
repeaters for the interval pills, currently encoded as delimited text).

---

## 4. Conventions & gotchas

- **Chord names**: store as **plain text** (`Db6(9)/Ab`). Emit `<span class="sbn-chord-symbol"
  data-chord="…"></span>`; the inline `sbnFormatChord` JS in `_layout` formats it (superscript extensions,
  ♯/♭, slash bass) on load. Don't hand-encode the spans.
- **Bars are 1-indexed** in the editor (`tab_bars`, `bars`); the builder converts to 0-indexed array
  offsets. (Legacy chord-book `measures` path was 0-indexed — don't confuse the two.)
- **Diagram color vs B&W**: item-page feature diagrams render **color**; the small inline diagrams above
  song TAB use `--bw`.
- **Interval pill kinds** map to CSS colors: root=green, third=blue, fifth=grey, seventh=orange, ext=purple.
- **Slugs are real**: chord slugs ⇒ `sbn_chord_diagrams`, rhythm ⇒ `sbn_rhythm_patterns`, song ⇒
  `sbn_leadsheets`. The autocomplete enforces this; a bad slug renders a placeholder box.
- **TAB needs `tab_xml`**: a song renders TAB only if its leadsheet has `melody_tab_xml` (default version)
  or legacy `tab_xml`. Songs without it: no notation.

---

## 5. Deploy note
`sbn_pdf_documents` is a **create-table migration**. Per the deploy workflow (server has no auto-applied
create-table migrations), the table must be created on prod via the established DB deploy path
(`scripts/deploy_db.sh` / preserve-users flow) — not assumed to exist. Documents live in the DB, so they
travel with the DB deploy, not git.

---

## 6. `/design` folder — status & cleanup

**Nothing in `/design` is referenced by live app code** (verified: no controller/blade/script imports it).
It is entirely reference/scratch from the *manual* design-studio era that preceded this DB-driven system.
~2.4 MB total.

### Keep (still useful as reference)
- `design/pdf-system/templates/top10-bossa-nova.html` — the approved visual mockup the current layout was
  ported from. Useful as the canonical look reference when tweaking CSS. *(556 KB — could slim by dropping
  embedded SVGs, but harmless.)*
- `design/pdf-system/content/TOP10-CONTENT-DRAFT.md` — the authored prose source for the Top10 content.
  Keep until the DB content is considered final.
- `design/source-pdfs/*.pdf` — the original MuseScore exports (TOP10, Vierklänge, Shell Voicings). Keep as
  source material for future books.

### Safe to delete / archive (superseded, dead weight)
- `design/pdf-templates/` (entire dir, ~1.1 MB) — old `system-v1` / `layout-directions` /
  `song-redesign` experiments and their PNG/PDF previews. Predates the partials; not referenced.
- `design/pdf-system/assets/chord-diagrams/*.png` + `chord-svgs/*.svg` + `diagrams/*.svg` — pre-rendered
  diagram assets. The pipeline renders these live from the DB now; the static copies are stale.
- `design/pdf-system/audits/` (`TOP10-AUDIT.md`, `TOP10-CONTENT-TOC.md`) and
  `design/pdf-system/templates/ITEM01-BUILD-LOG.md` — process notes from the build; archival only.
- `design/pdf-system/docs/RENDER-PIPELINE.md`, `design/pdf-system/layouts/TOP10-LAYOUT-MAP.md`,
  `design/pdf-system/README.md` — merged into THIS doc; redundant now.

### Recommended end state
Keep a slim `design/pdf-system/reference/` with just the mockup HTML, the content draft, and source PDFs;
delete `design/pdf-templates/` and the stale rendered assets. (Not done automatically — see the prompt at
the end of the audit.)

### Old docs in `docs/` to retire
After this doc is accepted, delete the five merged docs: `SBN-PDF-Briefing.md`,
`SBN-PDF-Pipeline-Plan.md`, `SBN-PDF-DESIGN-SYSTEM.md`, `SBN-PDF-Editor-Build-Spec.md`,
`SBN-PDF-Top10-Template-Spec.md`. (`TOP10 Bossa Nova Chords.pdf` is a sample output — keep or move to design.)
```

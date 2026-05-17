# SBN Course System — Reference

Living reference for the course system as it works **today**. Update this doc when behavior changes; do not append phase narratives. For phase-by-phase migration history see [Frontend-Migration-Plan.md](Frontend-Migration-Plan.md).

For visual tokens, brand colors, fonts, and design-system load order see [SBN-Design-Reference.md](SBN-Design-Reference.md). This doc does not redocument those.

---

## 1. Overview

Two surfaces sharing one data model and one HTML contract:

- **Public player** — Inertia/Vue page lessons render to. Reader-facing.
- **Admin editor** — Blade page hosting a Vue island (TipTap + palette). Author-facing.

A lesson is HTML stored as a string. Inside that HTML, custom `<sbn-*>` tags act as placeholders for live components. The public runtime walks the rendered DOM, fetches data per tag, and mounts the matching Vue component on each tag element. The admin editor inserts the same `<sbn-*>` tags via TipTap atom nodes; what's stored is the same HTML the player reads.

One contract — the `<sbn-*>` tag set in §3 — binds editor, runtime, and JSON API. All three must agree on attrs.

---

## 2. Data model

Two tables: `sbn_courses` and `sbn_lessons`.

- [`Course`](../app/Models/Course.php) — `Course extends Model`. Casts `genres`/`levels`/`topics` as array, `is_free` as bool. `hasMany` lessons (ordered by `sort_order`). `belongsTo` `Product` for paid courses. Scopes: `published()`, `byGenre()`, `byLevel()`. Accessors: `primaryGenre`, `primaryLevel`, `lessonCount`, `isGated`.
- [`Lesson`](../app/Models/Lesson.php) — `Lesson extends Model`. `belongsTo` Course. `content` is `longText` containing the lesson HTML. Scope: `published()`. Accessor: `subsections` parses `<h2 id="section-…">` headings out of `content` for sidebar nav.

No DB migration is required to add new `<sbn-*>` types — they live inside `content` HTML.

---

## 3. The `<sbn-*>` tag family

Stored in `content` as plain HTML. Always require explicit closing tags (`<sbn-foo></sbn-foo>`) — the HTML parser does not honor self-closing on unknown elements.

| Tag | Mounted? | Attrs | Public renderer |
|---|---|---|---|
| `<sbn-chord>` | yes | `slug` (required), `root` (optional, e.g. `F`) | [`ChordCard.vue`](../resources/js/Components/Library/ChordCard.vue) |
| `<sbn-rhythm>` | yes | `slug` (required) | [`RhythmStrip.vue`](../resources/js/Components/Library/RhythmStrip.vue) — TODO: migrate embed in `mountSbnNodes.ts` from `RhythmCard` (see `SBN-Rhythm-Reference.md §11`) |
| `<sbn-progression>` | yes | `slug` (required), `key` (optional, default `C`) | [`ChordProgressionViewer.vue`](../resources/js/Components/Library/ChordProgressionViewer.vue) |
| `<sbn-sheet>` | yes | `slug` (required), `key` (optional, default `C`) | [`SheetMiniPlayer.vue`](../resources/js/Components/Course/SheetMiniPlayer.vue) — see §9 |
| `<sbn-song>` | no — link only | `slug` (required), `label` (optional, default = slug) | none — see §5.2 |
| `<sbn-youtube>` | yes (no fetch) | `id` (required), `start` (optional, seconds) | inline `<iframe>` to `youtube-nocookie.com` |

**Attr semantics:**

- `<sbn-chord root="F">` — server-side fret-shifts the stored shape to the requested root. See §7.2.
- `<sbn-progression key="Bb">` — runs the progression builder in that key, returns voiced tiles.
- `<sbn-sheet key="G">` — fetches from `/api/sbn/exercises/{slug}?key=G`; key is advisory (transposition). See §9.
- `<sbn-song label="…">` — text shown in the link. If absent, slug is shown.

---

## 4. JSON API

Public, no auth. Defined in [`routes/web.php`](../routes/web.php) under `Route::prefix('api/sbn')`.

| Method | Path | Purpose | Returns |
|---|---|---|---|
| GET | `/api/sbn/chords/{slug}?root=F` | Mount payload for `<sbn-chord>` | `ChordSerializer::serialize()` shape, with `root_note` / `diagram_data` / `start_fret` / `interval_labels` / `notes` shifted when `root` is given |
| GET | `/api/sbn/rhythms/{slug}` | Mount payload for `<sbn-rhythm>` | Pattern object — `RhythmCard`'s `pattern` prop |
| GET | `/api/sbn/progressions/{slug}?key=Bb&pass2=1` | Mount payload for `<sbn-progression>` | `{ progression, key, chords: [{chordName, diagramData, beats, slug}] }` |
| GET | `/api/sbn/songs/{slug}/viewer-data` | Used by future leadsheet embed (not by `<sbn-song>` link) | Full leadsheet viewer payload via `LeadsheetViewerService` |
| GET | `/api/sbn/{type}` | Palette search (admin) | `{ results: […] }` |

Controllers: [`ChordLibraryController::apiShow`](../app/Http/Controllers/Library/ChordLibraryController.php), [`ProgressionLibraryController::apiShow`](../app/Http/Controllers/Library/ProgressionLibraryController.php), [`RhythmLibraryController::apiShow`](../app/Http/Controllers/Library/RhythmLibraryController.php), [`SongLibraryController::apiViewerData`](../app/Http/Controllers/Library/SongLibraryController.php).

---

## 5. Public player

### 5.1 Components

- [`Pages/Courses/Player.vue`](../resources/js/Pages/Courses/Player.vue) — top-level page (sidebar + content + bottom bar). Inertia entry.
- [`Components/Course/LessonContent.vue`](../resources/js/Components/Course/LessonContent.vue) — renders one lesson. Owns the `v-html` mount and the chunking logic.
- [`Components/Course/LessonSidebar.vue`](../resources/js/Components/Course/LessonSidebar.vue) — lesson list, grouped by `section_title`.
- [`Components/Course/BottomBar.vue`](../resources/js/Components/Course/BottomBar.vue) — prev/next, related songs, practice.
- [`lib/mountSbnNodes.ts`](../resources/js/lib/mountSbnNodes.ts) — mount runtime (§5.3).

### 5.2 LessonContent — render + chunking

`v-html` injects `lesson.content` into a `<article>`. After the DOM updates, two things happen in order:

1. **Chunking** (`refreshChunks`): if the lesson has `<h2 id="section-…">` headings, the body is split into subsection chunks wrapped in `<div class="sbn-subsection-chunk">`. Tabs at the top switch chunks via `display:none`. Lessons without those headings render flat.
2. **Mount** (`mountNodes`): walks the article DOM, runs `mountSbnNodes()` to attach Vue apps to every `<sbn-*>` tag.

`<sbn-song>` is rendered inline in this step as a styled link — no Vue component, no fetch. Its element gets `innerHTML = '<a class="sbn-song-link" href="/library/songs/{slug}/viewer">{label} ↗</a>'`. See §7.4 for why.

On lesson change, `unmountSbnNodes()` is called before re-running. On `onBeforeUnmount`, same.

### 5.3 mountSbnNodes — runtime contract

The mount runtime in [`mountSbnNodes.ts`](../resources/js/lib/mountSbnNodes.ts) has three concerns: **fetch**, **adapt**, **mount**.

- **Component registry** (`components`) — type → dynamic import. Add new mountable types here.
- **Endpoint map** (`apiUrl`) — type → URL builder. Query string is appended via `queryStringFor`.
- **Per-type adapter** (`propsFor`) — `(apiPayload) => propBag`. Each component has its own prop convention; the registry reconciles. See §7.1 for why per-type, not generic spread.
- **Per-type query builder** (`queryStringFor`) — reads attrs off the element (e.g. `root` on `<sbn-chord>`) and builds the query string. New attrs go here.
- **Fetch cache** — keyed by `${type}:${url}` so `?root=F` and `?root=G` cache separately. Cleared on full unmount.

Returns an unmount function the caller invokes on lesson change or component teardown.

---

## 6. Admin editor

Blade page hosting a Vue island. Lives in [`resources/views/admin/lessons/edit.blade.php`](../resources/views/admin/lessons/edit.blade.php). Form posts to standard Laravel routes — no separate save endpoint. The editor syncs HTML to a hidden `<textarea id="content-sync" name="content">` on every change; submit picks it up.

### 6.1 Routes

Defined in [`routes/web.php`](../routes/web.php) under `Route::middleware('auth')->prefix('admin')`:

- `admin.courses.{index,create,store,edit,update,destroy}` — [`Admin\CourseController`](../app/Http/Controllers/Admin/CourseController.php)
- `admin.courses.lessons.{create,store}` — nested
- `admin.lessons.{edit,update,destroy}` — shallow, [`Admin\LessonController`](../app/Http/Controllers/Admin/LessonController.php)
- `admin.lessons.reorder` — POST array of `{id, sort_order}`
- `admin.lessons.upload-image` — multipart, returns `{ url }`

### 6.2 LessonEditor.vue — TipTap + chips + bridge

[`resources/js/admin/LessonEditor.vue`](../resources/js/admin/LessonEditor.vue)

- **TipTap** with `StarterKit`, `Placeholder`, `Image`, `SlashCommands`, plus the SBN custom nodes.
- **Chip nodes** — one per type via `makeSbnNode(type)`. Each is `inline + atom` (or `block + atom` for youtube). Attrs per type are declared in the `ATTRS` map. `parseHTML` matches the tag; `renderHTML` round-trips with `mergeAttributes`. NodeView renders a `<span class="sbn-chip">` with type label, slug suffix, ✎ edit, ✕ delete.
- **`__sbnInsert(type, slug, extras)`** — bridge function on `window` that the palette calls. Inserts `{ type: 'sbn-{type}', attrs: { slug, ...extras } }` at the cursor. `image`/`media` go through TipTap's image node instead.
- **`__sbnPalette(type)`** — bridge function on `window` that switches the palette to a tab and focuses its search input. Called by slash commands and Ctrl+Shift keyboard shortcuts.
- **Hidden textarea sync** — every TipTap update writes `editor.getHTML()` into `#content-sync`. Form submit picks it up unchanged.

### 6.3 LessonPalette.vue — search + insert

[`resources/js/admin/LessonPalette.vue`](../resources/js/admin/LessonPalette.vue)

- Tabs: Chord | Rhythm | Progression | Song | Media.
- Search hits `/api/sbn/{type}` (see §4 search row). Debounced 250ms.
- Click row → for chord/progression/song, expands an inline config row (root selector / key selector / label input) before insert. For rhythm/media, inserts immediately.
- Insert calls `window.__sbnInsert(type, slug, extras)`.
- Drag-drop emits the type+slug as JSON; **drag insertion does not carry extras** (chord drops as `root=""`, progression as `key="C"`). Documented limitation.
- Media tab is per-lesson scoped; uploads via `/admin/lessons/{id}/upload-image`.

### 6.4 slashCommands.ts

[`resources/js/admin/slashCommands.ts`](../resources/js/admin/slashCommands.ts)

Typing `/` opens an inline popup with the SBN types, image, and youtube. Picking a type calls `window.__sbnPalette(type)` — which switches the palette and focuses search. Insertion happens through the normal palette path, not the slash menu. One code path. YouTube is the exception: it prompts for a URL and inserts directly.

---

## 7. Conventions

### 7.1 Per-type prop adapter (not generic spread)

`mountSbnNodes` does **not** spread the API payload as flat props. Each component has a different prop shape — `ChordCard` takes a single `chord` prop, `RhythmCard` takes `pattern`, `ChordProgressionViewer` takes a top-level `chords` array. A generic spread would break two of three. Instead, `propsFor[type](data)` returns the exact prop bag for that type. New types add an entry; no universal convention is enforced.

### 7.2 Server-side chord transposition

When `<sbn-chord root="F">` is mounted, transposition happens in `ChordLibraryController::apiShow` via the existing `shapeCalculator->calculateFrets($chord, $root)` helper (the same path the library page uses). The client receives a payload with `start_fret`, `diagram_data`, `interval_labels`, `notes`, and `root_note` already shifted. `ChordCard` is unchanged and unaware of transposition. Consequence: each unique `root` is a separate fetch and a separate cache entry; this is fine because the per-shape variant set is small.

### 7.3 Server-side progression tile-building

`<sbn-progression key="Bb">` triggers the same `harmonicContext->buildFromNumerals('Bb', $progression->numerals)` + `progressionBuilder->buildVoicings()` pipeline the library page uses. `apiShow` returns `{ chords: [...] }` in the exact shape `ChordProgressionViewer` accepts. No service factoring beyond what already exists in the library controller.

### 7.4 `<sbn-song>` is a link, not an embed

The leadsheet viewer is a full Inertia page with its own layout, related-songs section, and audio engine. Embedding it inline in a lesson would be wrong on principle, not just technically. So `<sbn-song>` renders as a styled link to `/library/songs/{slug}/viewer`. No component, no fetch, no payload. If a future need arises for a richer in-lesson preview, build a dedicated `LeadsheetEmbed.vue` then — don't reuse the page component.

### 7.5 HTML normalization

Custom elements in HTML do not honor self-closing syntax (`<sbn-chord />` is parsed as an open tag swallowing the rest of the document). Stored content must use explicit closing tags. TipTap's `renderHTML` already produces compliant output via `mergeAttributes`. If pasted or hand-edited content uses self-closing form, it will break — consider a server-side normalization in `LessonRequest` if this becomes a recurring author error.

### 7.6 Standard Laravel POST, hidden textarea sync

Editor state is synced into a hidden `<textarea name="content">` on every TipTap update. Form submit picks up the value. No autosave, no separate save endpoint, no JSON-vs-HTML mismatch. Server-side validation errors round-trip via `old('content')` on re-render — TipTap reads its initial HTML from the same source on next mount.

---

## 8. Open work

Tracked here to prevent future agents from re-deriving the gaps.

- **Drag-drop with extras** — palette drag currently inserts with default attrs only. Reaching feature parity with click-insert needs the drag payload to carry extras.
- **Chip editing for non-slug attrs** — the chip ✎ button uses `window.prompt` for slug only. Editing root/key/label requires a small modal. Workaround today: delete chip, re-insert from palette.
- **Self-closing tag normalization** — see §7.5. Server-side `LessonRequest` normalization not yet implemented.
- **Placeholder cleanup** — imported lessons contain literal `[CHORD DIAGRAMS MISSING]` and `[VIDEO EMBED: …]` strings. Left as visible text intentionally; admins replace them via the palette. Optional `php artisan sbn:scan-lesson-placeholders` command to enumerate progress is not yet built.
- **Alias chord library link** — when a hero chord is an alias match (`alias_match: true`), the library link is suppressed because the parent diagram's detail page shows the parent name rather than the alias name. Fix: add `?alias=` param support to `/library/chords/{slug}` that overrides the displayed name/root to match the alias. See §9.5.

---

## 9. Sheet Mini Player (`<sbn-sheet>`)

Shipped 2026-05-09. Horizontal, scrollable tab+chord exercise strip embedded in lesson content.

### 9.1 Data model

Table `sbn_exercises`. Key columns: `slug`, `title`, `key_center`, `time_sig`, `bpm_default`, `type` (`tab_exercise` | `chord_etude`), `content_json`.

`content_json` is `LeadsheetJson` format — same schema as `sbn_leadsheets.json_data`. `useTabModel` and `TabMeasure` consume it directly with no conversion. No new TypeScript types.

**Creating exercises:** use the leadsheet editor (full visual editor, voicing builder, all creation modals) then click **"→ Save as Exercise"** on the edit page. This copies the leadsheet into `sbn_exercises` via `Admin\ExerciseController::createFromLeadsheet()`. Admin CRUD at `/admin/exercises`.

### 9.2 API

`GET /api/sbn/exercises/{slug}?key=G` → [`Library\ExerciseController::apiShow`](../app/Http/Controllers/Library/ExerciseController.php)

Returns `content_json` fields at top level plus a `meta` object:
```json
{ "meta": { "slug", "title", "key_center", "time_sig", "bpm_default", "type" }, "sections": [...], "melody": ..., "chordVoicings": {...} }
```

Palette search: `GET /api/sbn/exercises?q=…`

### 9.3 Component

[`SheetMiniPlayer.vue`](../resources/js/Components/Course/SheetMiniPlayer.vue) — feeds `content_json` through `useTabModel`, renders with `TabMeasure` (`readOnly=true`, `allowChordClick=true`). All measures in one horizontal scrolling row. Transport: play/stop toggle + BPM range slider. Uses the AudioEngine singleton; tags its `engine.play('sheet')` call so `PracticePanel` can detect the conflict.

Provides the full inject contract required by `TabMeasure` (model, beatsPerMeasureRef, playingMeasureIndex, transportBeat, transportPlaying, readOnly, seekToMeasure, gridSelection, globalIndexOf, inlineRenameTarget).

### 9.4 Chord click → PracticePanel

Chord clicks in `SheetMiniPlayer` emit `@chord-click` from `TabMeasure`, which calls `props.onChordSelect(chordName, root, voicingData)`. `voicingData` is looked up from `exercise.chordVoicings` keyed by `"chordName@gi.ci"` — the exact stored voicing, not a DB lookup.

`onChordSelect` is threaded from `Player.vue` → `LessonContent.vue` → `mountSbnNodes.ts` via `el.__onChordSelect` DOM side-channel (isolated Vue app instances cannot receive function props through normal prop passing).

`sbn-chord` inline tags also fire `onChordSelect` through the same channel.

### 9.5 PracticePanel states

The chords card is driven by `selectedChord` ref in `Player.vue`. The card is
**hidden entirely** when there is no `selectedChord` and the lesson has no
chords.

| State | Condition | Display |
|---|---|---|
| Chord grid | `selectedChord === null` | All `chordSlugs` from lesson as a grid of `ChordDiagram` cells (chord name above diagram, `--font-chord` style). Slugs parsed server-side in `CourseController::player()` |
| Hero chord | `selectedChord` set | Always goes through `/api/sbn/chords?q={name}` name search — DB voicing, full `diagram_data` with correct fingers |

**Voicing resolution in `loadHeroChord`:**

1. Try `GET /api/sbn/chords/{slug}?root=` — succeeds if `sel.slug` is already a library slug (e.g. from `sbn-chord` click).
2. Otherwise search by name: `GET /api/sbn/chords?q={chordName}`.
3. From results, pick the entry whose `diagram_data` frets match `voicingData.frets` exactly (stored in `exercise.chordVoicings`). Fall back to first result if no exact match.
4. Search results contain full `diagram_data` — no second fetch needed.

**Library link:** hero chord wraps `ChordCard` in `<a href="/library/chords/{slug}?root={root}">`. Link is suppressed when the match is `alias_match: true` — alias results map to the parent diagram's slug, whose detail page shows the parent's name (e.g. "Bbm6") rather than the alias name the student sees (e.g. "Eb7(9)/Bb"), which would be confusing.

**Fingers:** `chordVoicings` stores `{frets, position, fingers}`. Historically `fingers` was hardcoded `'000000'` — fixed in `useVoicingPickerStore._diagramDataToFingers()` (2026-05-10). Existing saved content with `'000000'` is harmless because `loadHeroChord` always fetches from the DB (which has correct `diagram_data`). The `fingers` field is still written correctly for audio playback via `chordVoicingsToEvents`.

**Alias chord library feature (not yet built):** the chord library has no detail page for alias names. A future `?alias=` param on `/library/chords/{slug}` could override the displayed chord name and root to match the alias, allowing the hero link to work correctly for alias voicings too. Tracked in §8 open work.

### 9.5b PracticePanel rhythm card

The rhythm card shows the rhythm patterns referenced by `<sbn-rhythm slug="…">`
tags in the lesson content — **lesson-specific**, not course-genre-generic.

- `CourseController::player()` parses `<sbn-rhythm>` slugs from lesson content
  (same approach as `chordSlugs`), fetches the patterns via `whereIn('slug', …)`,
  and preserves the slug order from the content. Passed as the `rhythms` prop.
- The card is hidden when the lesson has no `<sbn-rhythm>` tags.
- `RhythmStrip` renders with `mini` and a `color` tint from
  `getCategoryColor(pattern.category)` (e.g. `brazilian` → bossa style color).
- The pattern selector pills appear only when there is more than one rhythm.
- Pattern data comes from `RhythmPattern::toPlayerData()` (shape:
  `RhythmPatternWithMeta`).

### 9.6 Admin editor integration

- LessonPalette: "Exercise" tab — search + key selector + insert as `<sbn-sheet slug="…" key="C">`
- LessonEditor: `sbn-sheet` TipTap chip node (attrs: `slug`, `key`)
- Slash command: `/sheet` → `window.__sbnPalette('sheet')`
- Leadsheets index: "Exercises →" cross-link; Exercises index: "← Songs" cross-link
- Exercise edit page uses the full `TabEditor.vue` visual editor via `exerciseEditor()` Alpine function — loads data from `GET /admin/exercises/{id}/data`, dispatches `sbn-tab-init`, syncs back on save via hidden `content_json` field

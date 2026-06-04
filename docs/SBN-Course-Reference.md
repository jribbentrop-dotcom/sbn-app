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

- [`Course`](../app/Models/Course.php) — `Course extends Model`. Casts `genres`/`levels` as array, `is_free` as bool. `hasMany` lessons (ordered by `sort_order`). `belongsTo` `Product` for paid courses. Scopes: `published()`, `byGenre()`, `byLevel()`. Accessors: `primaryGenre`, `primaryLevel`, `lessonCount`, `isGated`. Note: `topics` column exists in DB but is no longer used (replaced by tags).
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
| `<sbn-fretboard>` | yes (vanilla JS, no Vue) | `slug` (required) | `sbnHydrateFretboard()` in `public/js/chords.js` — see [SBN-Fretboard-Reference.md](SBN-Fretboard-Reference.md) |

**Attr semantics:**

- `<sbn-chord root="F">` — server-side fret-shifts the stored shape to the requested root. See §7.2.
- `<sbn-progression key="Bb">` — runs the progression builder in that key, returns voiced tiles.
- `<sbn-sheet key="G">` — fetches from `/api/sbn/exercises/{slug}?key=G`; key is advisory (transposition). See §9.
- `<sbn-song label="…">` — text shown in the link. If absent, slug is shown.
- `<sbn-fretboard slug="…">` — no extra attrs; all display options (mode, theme, guide tones, RH fingers) are baked into the stored record.

---

## 3b. Library cross-linking

**Established 2026-05-27.** Every library show page (song, progression, rhythm, chord) displays a "Related Courses" shelf. Matching is done by `CourseRepository` — not FK joins.

### `CourseRepository` (`app/Repositories/CourseRepository.php`)

Two-tier matching strategy:

1. **Tier 1 — tag match**: courses sharing ≥1 tag with the entity (via `sbn_taggables` polymorphic join). Requires both the entity and a course to be tagged. Returns up to `$limit` results ordered by `sort_order`.
2. **Tier 2 — category fallback**: if tier 1 produces nothing, fall back to courses whose `category` column matches the entity's genre/category string.

```php
// Standard call — entity has tags() relation (Leadsheet, RhythmPattern, ChordProgression)
$courses = $this->courseRepo->relatedTo($entity, $entityCategory, limit: 6);

// Fallback call — entity has no tags() (ChordDiagram: use songs' dominant style as proxy)
$courses = $this->courseRepo->relatedByCategory($categoryString, limit: 6);
```

Both methods return `Collection` of `Course::toShelfArray()` arrays.

### `Course::toShelfArray()`

Compact serializer for shelf tiles (parallel to `Leadsheet::toLinkArray()`):
```php
['id', 'slug', 'title', 'primaryGenre', 'primaryLevel', 'lessonCount', 'featuredImagePath']
```

### Category normalisation

Course categories use full slugs. Style slugs on library entities may differ:

| Entity style slug | Course category passed to repo |
|---|---|
| `bossa` | `bossa-nova` |
| `samba` | `bossa-nova` |
| `jazz` | `jazz` |
| anything else | as-is |

Normalisation is applied in `SongLibraryController` and `ChordLibraryController`. Rhythm and Progression controllers pass `$pattern->category` / `$progression->category` directly (already full slugs).

### Polymorphic tags requirement

Entities passed to `relatedTo()` must have a `tags()` MorphToMany relation. Currently implemented on: `Leadsheet`, `RhythmPattern`, `ChordProgression`. `ChordDiagram` has no tags — use `relatedByCategory()`.

### Frontend rendering

Each show page receives `courses: CourseShelfCardData[]` as an Inertia prop. Rendered as:
```vue
<MediaShelf title="Related Courses" v-if="courses && courses.length">
    <CourseShelfCard v-for="course in courses" :key="course.id" :course="course" />
</MediaShelf>
```

See [SBN-Design-Reference.md § Library Link Components](SBN-Design-Reference.md) for `CourseShelfCard` and `MediaShelf` docs.

---

## 4. JSON API

Public, no auth. Defined in [`routes/web.php`](../routes/web.php) under `Route::prefix('api/sbn')`.

| Method | Path | Purpose | Returns |
|---|---|---|---|
| GET | `/api/sbn/chords/{slug}?root=F` | Mount payload for `<sbn-chord>` | `ChordSerializer::serialize()` shape, with `root_note` / `diagram_data` / `start_fret` / `interval_labels` / `notes` shifted when `root` is given |
| GET | `/api/sbn/rhythms/{slug}` | Mount payload for `<sbn-rhythm>` | Pattern object — `RhythmCard`'s `pattern` prop |
| GET | `/api/sbn/progressions/{slug}?key=Bb&pass2=1` | Mount payload for `<sbn-progression>` | `{ progression, key, chords: [{chordName, diagramData, beats, slug}] }` |
| GET | `/api/sbn/songs/{slug}/viewer-data` | Used by future leadsheet embed (not by `<sbn-song>` link) | Full leadsheet viewer payload via `LeadsheetViewerService` |
| GET | `/api/sbn/fretboards/{slug}` | Mount payload for `<sbn-fretboard>` | Full fretboard record — see [SBN-Fretboard-Reference.md §6](SBN-Fretboard-Reference.md#6-course-tag--sbn-fretboard) |
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
- **`__sbnEditor`** — bridge object on `window` for the AI panel (§6.5): `getSelection()`, `getContext()` (≤800-char plain-text excerpt), `insertAtCursor(html)`, `replaceSelection(html)`. The panel touches the document *only* through these — AI output never lands without an explicit click.
- **Hidden textarea sync** — every TipTap update writes `editor.getHTML()` into `#content-sync`. Form submit picks it up unchanged.
- **Selection state** — `updateFmt()` (runs on every `onSelectionUpdate`) publishes whether text is selected into the shared reactive `hasSelection` ref ([`editorSelection.ts`](../resources/js/admin/editorSelection.ts)), which the AI panel reads live.
- `aiAutocomplete` (Ctrl+Space) inserts an AI continuation at the cursor — the only AI feature still on the editor itself; proofread/generate moved to the AI panel.

### 6.3 LessonPalette.vue — search + insert

[`resources/js/admin/LessonPalette.vue`](../resources/js/admin/LessonPalette.vue)

- Tabs: Chord | Rhythm | Progression | Exercise | Song | Media.
- Search hits `/api/sbn/{type}` (see §4 search row). Debounced 250ms.
- **Chord rows** show the exact chord slug (mono font) under the name, so admins can copy it. The root used to seed the inserted chip is detected from the search query, falling back to the chord's `root` field.
- **Insert behavior by type:** Chord, Exercise, Media insert immediately on click. Song opens an inline config row for a display label. Rhythm/Progression insert immediately *unless* the item has video snippets — then a config row opens with a "Video example" picker. The standalone key-selector was removed (it had no effect on the rendered component).
- Insert calls `window.__sbnInsert(type, slug, extras)`.
- Drag-drop emits the type+slug as JSON; **drag insertion does not carry extras** (chord drops as `root=""`). Documented limitation.
- Media tab is per-lesson scoped; uploads via `/admin/lessons/{id}/upload-image`.

### 6.4 slashCommands.ts

[`resources/js/admin/slashCommands.ts`](../resources/js/admin/slashCommands.ts)

Typing `/` opens an inline popup with the SBN types, image, and youtube. Picking a type calls `window.__sbnPalette(type)` — which switches the palette and focuses search. Insertion happens through the normal palette path, not the slash menu. One code path. YouTube is the exception: it prompts for a URL and inserts directly.

### 6.5 LessonAiPanel.vue — inline AI assistant

[`resources/js/admin/LessonAiPanel.vue`](../resources/js/admin/LessonAiPanel.vue) — separate Vue island, mounted on `#lesson-ai-panel` by [`lesson-editor.ts`](../resources/js/admin/lesson-editor.ts). The mount point sits inside the editor card, immediately below the TipTap editor div — the panel is always visible (no toggle).

- **Why a panel, not toolbar buttons** — the old `✨ Proof` / `✍️ Gen` toolbar buttons could overwrite the *whole document* in one click. They were removed. AI output only reaches the document on an explicit click.
- **Layout** — inline block below the editor, `max-height: 320px` scrollable message area. No fixed positioning, no body class manipulation.
- **Lesson metadata** — the mount div carries `data-lesson-title`, `data-course-title`, `data-course-genre`, `data-section-title` attributes (set in `lessons/edit.blade.php`). The panel reads these in `onMounted` and forwards them as `lessonMeta` in every fetch body, so the AI knows exactly what lesson it's assisting with.
- **Quick-start buttons** — shown before the first message: Draft intro / Explain the concept / Continue writing / Practice tips. Each button generates a prompt that names the lesson title explicitly (e.g. `"Write an opening paragraph for 'The Basic Bossa Clave'…"`).
- **Chat** — multi-turn. Each send posts `{ action: 'chat', content, context, history, selection, lessonMeta }` to `/admin/ai/process`. `context` and `selection` come from the `window.__sbnEditor` bridge (§6.2).
- **Applying output** — an AI reply may carry insertable `html`. Two buttons: **Insert at cursor** (always) and **Replace selection** (enabled only when text is selected). Nothing changes the lesson without one of these clicks.
- **Quick actions** — when text is selected, a bar offers Proofread / Improve / Shorten.
- **Backend** — the `chat` action in [`Admin\AIController`](../app/Http/Controllers/Admin/AIController.php) injects lesson + course metadata at the top of the user prompt, uses a domain-specific system prompt (expert music educator, bossa nova/jazz/guitar), and returns `{ reply, html }`.

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

Shipped 2026-05-09; video sync + cinema player added 2026-06-01.

### 9.1 Data model

Table `sbn_exercises`. Key columns: `slug`, `title`, `key_center`, `time_sig`, `bpm_default`, `type` (`tab_exercise` | `chord_etude`), `content_json`.

`content_json` is `LeadsheetJson` format — same schema as `sbn_leadsheets.json_data`. Includes `videoSync` (same shape as leadsheet video sync — `videoId`, `videoType`, `mappings[]`) when authored. `useTabModel` and `TabMeasure` consume it directly with no conversion. No new TypeScript types.

**Creating exercises:** use the leadsheet editor (full visual editor, voicing builder, all creation modals) then click **"→ Save as Exercise"** on the edit page. This copies the leadsheet into `sbn_exercises` via `Admin\ExerciseController::createFromLeadsheet()`. Admin CRUD at `/admin/exercises`. Video sync is authored in the same tap-to-mark editor as leadsheets.

### 9.2 API

`GET /api/sbn/exercises/{slug}?key=G` → [`Library\ExerciseController::apiShow`](../app/Http/Controllers/Library/ExerciseController.php)

Returns `content_json` fields at top level plus a `meta` object:
```json
{ "meta": { "slug", "title", "key_center", "time_sig", "bpm_default", "type" }, "sections": [...], "melody": ..., "chordVoicings": {...}, "videoSync": {...} }
```

`videoSync` is present when the exercise has been video-synced in the editor. `SheetMiniPlayer` reads it directly from `exercise.videoSync`.

Palette search: `GET /api/sbn/exercises?q=…`

**Cinema player:** `GET /library/exercises/{slug}/cinema` → [`Library\ExerciseController::cinema`](../app/Http/Controllers/Library/ExerciseController.php) — renders `Leadsheet/Cinema` with `content_json` as `jsonData`. The classic-view back button is hidden (no public viewer page for exercises). `classicUrl` is passed as `''`.

### 9.3 Component

[`SheetMiniPlayer.vue`](../resources/js/Components/Course/SheetMiniPlayer.vue) — feeds `content_json` through `useTabModel`, renders with `TabMeasure` (`readOnly=true`, `allowChordClick=true`).

**Layout:** rows respect `section.lineBreaks` from `content_json` (the authored row layout). Falls back to 4 bars/row when `lineBreaks` is absent. Each row is a `.sbn-sheet-row` flex container; rows stack vertically in `.sbn-sheet-measures`.

**Play button:** styled `.sbn-play-btn` (design system), 28px, always filled with the orange-red gradient. When `videoSync` is present the button drives the shared video clock via `onVideoPlay`/`onVideoPause` callbacks (see §9.7). When no video sync, drives the AudioEngine synth as before.

**Bar click → seek:** clicking any measure wrapper seeks to that bar. In video mode: looks up the bar's `videoTime` from `videoSync.mappings` and calls `onVideoSeek(seconds)` → `ph.seek(seconds); ph.play()`. In synth mode: calls `engine.seek(beat)`.

**Video sync clock:** when `videoPlayhead` prop is non-null and `videoSync` is present, the component uses a mapping-interpolation pipeline (mirrors `useVideoSync.videoTimeToPlayPosition`): binary search + linear interpolation over `mappings` projected onto play positions → fractional beat → `transportBeat` / `playingMeasureIndex`. This matches the full-editor cursor accuracy.

Props:
- `exercise` — full API payload (includes `videoSync`)
- `onChordSelect` — chord click handler
- `videoPlayhead` — seconds from shared playhead registry (null when video not playing)
- `videoSync` — `{ videoId, videoType, mappings[] }` block from exercise
- `onVideoPlay`, `onVideoPause`, `onVideoSeek` — callbacks to shared playhead

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

- `CourseController::player()` parses `<sbn-rhythm>` tags via
  `parseRhythmTags()` — **one panel entry per tag occurrence**, in document
  order, NOT deduped by slug (the same pattern may appear twice with different
  video examples). The parser matches each tag whole, then extracts `slug` and
  `video-snippet` — attribute order is irrelevant. Patterns are fetched via
  `whereIn('slug', …)`. Passed as the `rhythms` prop.
- The card is hidden when the lesson has no `<sbn-rhythm>` tags.
- `RhythmStrip` renders with `mini` and a `color` tint from
  `getCategoryColor(pattern.category)` (e.g. `brazilian` → bossa style color).
- The pattern selector pills appear only when there is more than one rhythm.
- Pattern data comes from `RhythmPattern::toPlayerData()` (shape:
  `RhythmPatternWithMeta`). The resolved video snippet (§10) is nested onto it
  as `pattern.videoSnippet`.

### 9.6 Admin editor integration

- LessonPalette: "Exercise" tab — search + key selector + insert as `<sbn-sheet slug="…" key="C">`
- LessonEditor: `sbn-sheet` TipTap chip node (attrs: `slug`, `key`)
- Slash command: `/sheet` → `window.__sbnPalette('sheet')`
- Leadsheets index: "Exercises →" cross-link; Exercises index: "← Songs" cross-link
- Exercise edit page uses the full `TabEditor.vue` visual editor via `exerciseEditor()` Alpine function — loads data from `GET /admin/exercises/{id}/data`, dispatches `sbn-tab-init`, syncs back on save via hidden `content_json` field

### 9.7 Sheet video sync (2026-06-01)

Sheet exercises that have been video-synced in the editor (via the same tap-to-mark tool as leadsheets) surface their video in the PracticePanel sidebar automatically — no extra authoring step in the lesson editor.

**Server side (`CourseController::player()`):**
- Scans lesson content for `<sbn-sheet>` tags via regex, collects unique slugs.
- Fetches matching `Exercise` records, reads `content_json['videoSync']`.
- Passes a `sheets` map (keyed by slug: `{ slug, title, videoId, videoType }`) to the Inertia page for exercises that have a `videoSync.videoId`.

**PracticePanel:**
- `sheets` prop (Record<slug, SheetVideo>) contributes entries to `snippetEntries` alongside rhythm and progression snippets. Each entry carries `playheadKey: "sheet:{slug}"`.
- The shared VideoEmbed slot shows the exercise's video when a sheet entry is focused (same pill selector as rhythm/progression).
- A "Open in cinema player ↗" link appears below the video, pointing to `/library/exercises/{slug}/cinema`.

**`mountSbnNodes` — sheet branch:**
- When `exercise.videoSync.videoId` is present, registers a playhead under key `"sheet:{slug}"` in the `useVideoPlayhead` registry.
- Mounts `SheetMiniPlayer` via a reactive render function that reads `ph.playing` and `ph.playheadSec` — same pattern as the `<sbn-progression>` branch.
- Passes `onVideoPlay`, `onVideoPause`, `onVideoSeek` callbacks bound to `ph`.
- `onVideoPlay`: finds the earliest mapping's `videoTime`, seeks there if the playhead is outside the mapped range, then calls `ph.play()`.
- `onVideoSeek(seconds)`: calls `ph.seek(seconds)` then `ph.play()` — bar clicks seek and start playing.

**`VideoEmbed` — first-play fix:**
- Added `_seekOnReady` queue: if `seekTo()` is called while `_ytPlayer` is null (facade not yet loaded), the target seconds are stored and applied in `onYTReady` before `playVideo()`. Without this, the first bar click or first play-from-exercise-start would start from the video's beginning.

**Playhead key contract:** `"sheet:{slug}"` never collides with rhythm/progression snippet ids (which are UUIDs prefixed `vs_`). The PracticePanel reads `focusedEntry.playheadKey` directly for the cinema link; `mountSbnNodes` derives the same key from `el.getAttribute('slug')`.

---

## 10. Video Sync — real-world example snippets

Hooktheory-style YouTube examples synced to a course-player component.
**Shipped: rhythm, progression, and sheet paths.** Three sync variants — see
§10.3 (rhythm, PracticePanel-only), §10.4 (progression, inline body),
§10.5 (sheet, uses exercise's own `videoSync` block — no snippet library needed).

### 10.1 Two layers (rhythm + progression)

| Layer | Owns | Where authored |
|---|---|---|
| Component snippet library | A rhythm/progression's list of `VideoSnippet`s | Rhythm · Progression admin edit pages |
| Course slot selection | *Which* snippet (if any) shows for this lesson's instance | `LessonPalette` "Video example" picker |

The course slot stores only a **reference** — the snippet `id` — as a tag
attribute, never snippet data. Editing a snippet in the rhythm admin updates
every lesson that points at it.

**Sheet exercises use a different model** (§9.7 / §10.5): the video and its sync
marks live directly in `content_json.videoSync`, authored once in the exercise
editor. No snippet library; no tag attribute required.

### 10.2 The tag attribute

Both `<sbn-rhythm>` and `<sbn-progression>` tags carry an optional
`video-snippet` id:

```html
<sbn-rhythm slug="son-clave-3-2" video-snippet="vs_02839d89-…">
<sbn-progression slug="ii-v-i" key="F" video-snippet="vs_5e1c…">
```

- Authored in `LessonPalette.vue`: selecting a rhythm **or progression** shows a
  "Video example" dropdown (None + one per the component's snippets, fetched
  from `/api/sbn/rhythms` / `/api/sbn/progressions`, both of which return
  `snippets: [{id,label}]`). Both use the select→configure→confirm path.
- `LessonEditor.vue`: the `rhythm` and `progression` TipTap nodes both have a
  `videoSnippet` attr with explicit HTML mapping (camelCase attr →
  `video-snippet` HTML attribute). The chip-edit slug prompt preserves all
  other attrs (`key`, `videoSnippet`).
- `CourseController::player()` resolves the id against the component's
  `video_snippets` library; a dangling id (snippet deleted) resolves to `null`
  → slot hidden. For rhythm it nests as `pattern.videoSnippet`; for progression
  it rides on the `progressions` prop entry as `videoSnippet`.

### 10.3 Runtime sync (PracticePanel only)

The synced video lives in the **PracticePanel** companion, not the inline
lesson-body component:

- `PracticePanel` renders one `VideoEmbed` bound to the active rhythm's
  `videoSnippet`, driven by the `useVideoPlayhead` composable (seconds-based,
  domain-free clock).
- Transport ⇄ video are two-way bound: the rhythm strip's play button (in
  video mode) emits `playRequest` → toggles the shared video; driving the
  YouTube player directly mirrors back to the transport. Play seeks to the
  snippet's `startSec` first; `setLoop` wraps at `endSec`.
- `RhythmStrip`'s `videoPlayhead` prop converts video seconds → pattern cells;
  the audio engine is untouched in video mode (no competing clocks).

**By design, the inline `<sbn-rhythm>` in the lesson body is audio-only.** It is
mounted by `mountSbnNodes` as an isolated `createApp` with no access to the
PracticePanel's playhead; its play button runs the rhythm samples. Rhythm video
sync is PracticePanel-only — `mountSbnNodes` does not read `video-snippet` for
the `rhythm` type.

### 10.4 Runtime sync — progression (inline body component)

Progression sync differs from rhythm: the **inline `<sbn-progression>` in the
lesson body is the synced surface**, not the PracticePanel companion. The video
sits in the sidebar; the component stays in the main column.

The two live in **separate Vue roots** — the inline component is an isolated
`createApp` from `mountSbnNodes`, the `VideoEmbed` is in the `Courses/Player`
root. They share one clock via a **playhead registry**:

- `getVideoPlayhead(snippetId)` in `useVideoPlayhead.ts` — a module-level
  `Map` memoising one `useVideoPlayhead` instance per snippet id. The snippet
  id is the explicit cross-root contract.
- `PracticePanel` owns the `VideoEmbed` and registers its playhead under the
  focused snippet's id. The inline `<sbn-progression>` *reads* the same
  instance: `mountSbnNodes` mounts it via a render function bound to
  `getVideoPlayhead(id).playheadSec`, so the highlight stays reactive (root
  props passed to `createApp` are otherwise static).
- Anchors (`startSec`, `tempoBpm`) reach `mountSbnNodes` through a `snippetSync`
  map: `Player.vue` builds it from the `progressions` prop and threads it
  `Player → LessonContent → mountSbnNodes`.
- `ChordProgressionViewer.chordIndexAtTime()` converts video seconds → chord
  index. When the video isn't playing, `videoPlayhead` is `null` and the
  component falls back to its own audio playback / manual selection.

`PracticePanel` does **not** render its own `ChordProgressionViewer` — that
would duplicate the inline body component. It contributes only the `VideoEmbed`
slot, which is shared across **all** lesson snippets (rhythm + progression) via
a pill selector. The panel's `RhythmStrip` is still video-synced when a rhythm
snippet is focused (`rhythmVideoActive`); progression sync is entirely the
inline component's job.

### 10.5 Sheet exercise sync (2026-06-01)

Sheet exercises reuse the full leadsheet video sync model (`content_json.videoSync`) rather than the snippet-library model. See §9.7 for the complete as-built spec. Key differences from rhythm/progression:

- **No snippet library, no tag attribute.** The video is stored on the exercise itself. `CourseController` detects it automatically from `content_json['videoSync']['videoId']`.
- **Mapping-based interpolation.** `SheetMiniPlayer` implements `videoTimeToBeat()` inline — binary search over mappings projected onto play positions, identical algorithm to `useVideoSync.videoTimeToPlayPosition`. Frame-accurate cursor matching the full editor.
- **Playhead key:** `"sheet:{slug}"` (never collides with snippet UUIDs).
- **First-play seek:** `VideoEmbed._seekOnReady` queues a pending `seekTo` when called before the YouTube IFrame player is initialised (facade not yet dismissed). Applied in `onYTReady` before `playVideo()`.
- **Bar click → seek+play:** clicking any measure seeks to that bar's `videoTime` and starts playing immediately.
- **Cinema link:** "Open in cinema player ↗" shown below the video in PracticePanel; points to `/library/exercises/{slug}/cinema`.

### 10.6 Pinned voicings, play→video, overlay & layout (2026-05-22)

Extensions past the original plan, progression-only:

- **Pinned chord voicings.** A progression snippet may carry `key` (the key the
  recording is played in) + a `chords` array — one chord-library slug per
  numeral slot, blank = builder default. Authored per-slot in the snippet
  widget. `ProgressionLibraryController::buildChordsFor()` takes a
  `$pinnedSlugs` arg; for each pinned slot it **transposes** the diagram —
  a DB diagram is a *movable shape*, so it runs through
  `ChordSerializer::serialize($diagram, $targetRoot)` where `$targetRoot` is
  the slot's builder-resolved root note (so a pinned `maj7` shape becomes the
  actual chord in the snippet's key, not its stored reference position).
  `apiShow` accepts `?chords=` (comma-list); `mountSbnNodes` sends it from
  `snippetSync[id].chords`; `Player.vue` threads `key`/`chords` into that map.
- **Course-editor key.** `LessonPalette` stamps the chosen snippet's `key`
  onto the inserted `<sbn-progression key="…">` — `apiSearch` exposes
  `snippets[].key`. Without this the TipTap node defaults `key` to `C`.
- **Play button drives the video.** `mountSbnNodes` passes
  `onVideoPlay`/`onVideoPause` (bound to the registry playhead) to the synced
  `ChordProgressionViewer`. Its play button drives the shared video clock
  instead of synth audio when a snippet is attached.
- **Viewer parity.** `propsFor.progression` passes the full prop set so the
  inline course progression looks identical to the library detail page;
  `buildChordsFor` emits a per-chord `numeral`.
- **`VideoEmbed`** — opt-in `facade` prop (thumbnail until first play, skips
  the cued-state overlay) + seek-on-ENDED (suppresses the end-screen).
  `PracticePanel` uses it. The ~5 s YouTube intro overlay on play-start is
  timer-based and not removable by `playerVars` or the facade.
- **Practice-panel layout** — the right-rail companion is now flat stacked
  sections with hairline dividers (no nested cards); the `VideoEmbed` is
  full-bleed to the sidebar edge.

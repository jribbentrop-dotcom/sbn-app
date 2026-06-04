# SBN Song Library Reference

Public song (leadsheet) library: the index catalog, the song detail (Show) page, and the viewer/cinema routes. The viewer itself is documented in `SBN-Leadsheet-Reference.md` — this doc covers the library surfaces that surround it.

Audience: developers working on `/library/songs`. For the leadsheet viewer and cinema view see `SBN-Leadsheet-Reference.md`; for the chord progression viewer embedded on Show pages see `SBN-Progression-Library-Reference.md`.

---

## 1. Routes & Controllers

| Route | Controller action | Notes |
|---|---|---|
| `GET /library/songs` | `SongLibraryController::index` | Inertia `Library/Songs/Index` |
| `GET /library/songs/{slug}` | `SongLibraryController::show` | Inertia `Library/Songs/Show` |
| `GET /library/songs/{slug}/viewer` | `SongLibraryController::viewer` | Inertia `Library/Songs/Viewer` (full leadsheet viewer) |
| `GET /library/songs/{slug}/cinema` | `SongLibraryController::cinema` | Inertia `Leadsheet/Cinema` |
| `GET /api/sbn/songs/search` | `SongLibraryController::apiSearch` | Lesson editor palette search |
| `GET /api/sbn/songs/{slug}/viewer` | `SongLibraryController::apiViewerData` | JSON viewer data for course player |

Route model binding: `{slug}` binds via `Leadsheet` model's `slug` column.

**Draft gate:** `abortIfDraft()` is called on all public-facing routes — `status !== 'publish'` returns 404. Drafts are admin-only.

**Controller:** `app/Http/Controllers/Library/SongLibraryController.php`  
**Model:** `App\Models\Leadsheet` — table `sbn_leadsheets`

---

## 2. Data Model

### Key `sbn_leadsheets` columns used by the library

| Column | Notes |
|---|---|
| `slug` | URL key — unique, derived from `title` on creation |
| `title`, `composer` | Display fields |
| `song_key` | e.g. `C`, `Bb`, `F#m` |
| `tempo`, `time_signature` | Playback metadata |
| `rhythm` | Rhythm pattern slug (FK by convention to `sbn_rhythm_patterns.slug`) |
| `genre` | Genre string — used alongside `rhythm` for style slug resolution |
| `description`, `harmony_notes`, `form_notes`, `voicing_notes` | Rich text fields |
| `popularity`, `difficulty` | Integers |
| `measure_count` | Total measures in the leadsheet |
| `cover_image_path` | Filename only — served as `/images/songs/{cover_image_path}` |
| `status` | `publish` / `draft` — only `publish` is visible publicly |
| `parsed_data` | Full JSON leadsheet data (see `SBN-Leadsheet-Reference.md`) |

### Style slug resolution

`Leadsheet::resolveStyleSlug(genre, rhythm)` — derives a canonical style slug for color assignment. Rhythm slug takes priority; `genre` is a fallback.

`SongLibraryController::rhythmToStyleSlug()` maps rhythm slugs:

```php
'bossa' / 'bossa-nova' → 'bossa'
'samba'                → 'samba'
'jazz' / 'swing'       → 'jazz'
'latin' / 'afro-cuban' → 'latin'
'blues'                → 'blues'
'pop' / 'ballad'       → 'pop'
'classical'            → 'classical'
// default (no match)  → 'bossa'
```

Prefix matching is also applied (e.g. `bossa-nova-variation` → `bossa`).

---

## 3. Index Page

**File:** `resources/js/Pages/Library/Songs/Index.vue`  
**CSS:** `public/css/song-library.css`

Props from controller:

```
songs[]      — serialized song cards (see §2 serializeSong)
composers[]  — top 40 composers by song count
keys[]       — distinct keys used
rhythms[]    — distinct rhythm slugs used
totalCount   — int
```

All filtering is **client-side** — no server-side search endpoint. Filters: text search (title + composer + description), key, composer, rhythm/style, tempo range.

### `SongCard.vue`

**File:** `resources/js/Components/Library/SongCard.vue`  
Exports `SongCardData` interface. Renders: 4px colored style-bar top, title, composer, key/timesig/tempo meta badges, description snippet (2-line clamp), rhythm label + popularity pill.

Color comes from `useCategoryColors.getCategoryStyle(styleSlug)` — no hex in component.

---

## 4. Show Page (Teaser)

**File:** `resources/js/Pages/Library/Songs/Show.vue`

A teaser page — NOT the full viewer. Shows metadata + context and links to the viewer.

Props:

| Prop | Notes |
|---|---|
| `song` | Full song metadata including `rhythmData` (full `RhythmPatternData` for the mini strip) |
| `chordNames` | `string[]` — unique chord names in the leadsheet, via `Leadsheet::getChordNames()` |
| `chords` | Top 4 chord diagram cards (de-duped by voicing shape, sorted by popularity) |
| `progressions` | Detected progressions with voice-led tiles resolved in `song_key` |
| `courses` | Related courses via `CourseRepository::relatedTo` |

### Chord card resolution

`SongLibraryController::show` resolves the leadsheet's stored `chordVoicings` (`@key` format) against the library via `ChordVoicingSearch::searchByName`. Best match selected by `LeadsheetViewerService::pickBestVoicing` (fret proximity). Fallback: `synthesizeMinimalCard`. De-duped by voicing shape pattern. Top 4 by popularity.

### Progression tiles

Each detected progression is resolved via `HarmonicContext::buildFromNumerals(song_key, numerals)` → `ProgressionBuilder::buildVoicings`. Tiles rendered by `ChordProgressionViewer` with `name`, `category`, `numeralsDisplay`.

---

## 5. Viewer

The full interactive leadsheet viewer is at `/library/songs/{slug}/viewer`. Full documentation in `SBN-Leadsheet-Reference.md`.

`SongLibraryController::viewer` calls `LeadsheetViewerService::enrich` to build `chordCards` (enriched voicings for the viewer) and merges edu content (`eduChordQualities`, `eduRelatedConcepts`).

### Cinema view

`/library/songs/{slug}/cinema` → `Leadsheet/Cinema.vue`. Same `enrich` call but only `chordCards` is passed (cinema doesn't need the full edu panel). See `SBN-Leadsheet-Reference.md` for the cinema layout.

---

## 6. API Endpoints

### `GET /api/sbn/songs/search?q=`

Lesson editor palette search. Returns `{ slug, label (title), meta (composer) }`. Limit 20.

### `GET /api/sbn/songs/{slug}/viewer`

JSON version of the viewer data (same shape as the Inertia viewer props). Used by the course player to embed a leadsheet viewer inline.

---

## 7. `SongLink` pattern

Any page that links to a song from outside the song library (e.g. progression Show, rhythm Show, chord Show) uses the **canonical `SongLink` pattern**:

- `Leadsheet::toLinkArray()` — returns `{ id, slug, title, composer, styleSlug, coverImagePath, ... }`
- `Components/Library/SongLink.vue` — renders a consistent song row with cover art, title, composer, style color

Do not hand-roll song rows. See `SBN-Leadsheet-Reference.md` for the `SongLink` contract.

---

## 8. Cross-references

- Full viewer (chord grid, tab, edu panel): `SBN-Leadsheet-Reference.md`
- Cinema view: `SBN-Leadsheet-Reference.md`
- Progression tiles on Show page: `SBN-Progression-Library-Reference.md §5`
- `<sbn-song>` lesson tag: `SBN-Course-Reference.md §3` (renders as styled link, not embedded viewer)
- Cover images: served from `public/images/songs/`

# SBN Progression Library Reference

Public chord progression library: index, detail (Show) page, `ChordProgressionViewer` component, and the API endpoints used by the lesson editor and course player.

Audience: developers working on `/library/progressions` or `<sbn-progression>` lesson tags. For the voice-leading builder see `SBN-Builder-Reference.md`; for chord rendering see `SBN-Design-Reference.md`.

---

## 1. Routes & Controllers

| Route | Controller action | Notes |
|---|---|---|
| `GET /library/progressions` | `ProgressionLibraryController::index` | Inertia `Library/Progressions/Index` |
| `GET /library/progressions/{slug}` | `ProgressionLibraryController::show` | Inertia `Library/Progressions/Show` |
| `GET /api/sbn/progressions/{slug}` | `ProgressionLibraryController::apiShow` | JSON for `mountSbnNodes.ts` + course player |
| `GET /api/sbn/progressions/search` | `ProgressionLibraryController::apiSearch` | Lesson editor palette search |

**Controller:** `app/Http/Controllers/Library/ProgressionLibraryController.php`  
**Model:** `App\Models\ChordProgression` — table `sbn_chord_progressions`

---

## 2. Data Model

### `sbn_chord_progressions` columns

| Column | Notes |
|---|---|
| `slug` | URL key — unique, derived from `name` |
| `name` | Display name e.g. "ii–V–I" |
| `category` | `bossa-nova` / `jazz` / `classical` / `pop` |
| `numerals` | Comma-separated Roman numerals e.g. `"IIm7,V7,Imaj7"` |
| `tonality` | `major` / `minor` / `modal` |
| `tags` | Comma-separated tag string |
| `description` | Rich text / HTML — legacy field, kept for backwards compat |
| `intro` | Rich text / HTML — rendered **above** the `ChordProgressionViewer` (history, name, context) |
| `details` | Rich text / HTML — rendered **below** the viewer (voice leading, substitutions, variations). Seeded from `description` on migration. |
| `sort_order` | Manual ordering within category |
| `video_snippets` | JSON array — real-world YouTube examples (same shape as rhythm snippets) |

### Model accessors

- `numerals_display` — replaces commas with en-dashes for display
- `tags_array` — splits `tags` string into array
- `song_count` — set by the `withSongCounts` scope; not a real column

### Serialization (`serializeProgression`)

```php
[
    'id', 'slug', 'name', 'category',
    'styleSlug',        // mapped from category via mapCategoryToStyleSlug()
    'numerals',         // raw comma-separated string
    'numeralsDisplay',  // en-dash separated
    'tonality', 'tags', // tags is array
    'description',      // legacy — still present for backwards compat
    'intro',            // above-component prose
    'details',          // below-component prose (seeded from description)
    'chordCount',       // count of comma-separated numerals
    'songCount',        // 0 unless loaded via withSongCounts scope
    'videoSnippets',    // array from video_snippets JSON column
]
```

### Category → style slug mapping

```php
'bossa-nova' => 'bossa-nova'
'jazz'       => 'jazz'
'classical'  => 'classical'
'pop'        => 'pop'
// default: 'bossa-nova'
```

---

## 3. Index Page

**File:** `resources/js/Pages/Library/Progressions/Index.vue`

- Client-side filtering over the initial payload (category, tags, text search, sort)
- Sort options: `popularity` (song count, default), `name`, `category`
- Filter params passed in `activeFilters` prop so URL state is restored on back-navigation
- Uses `.sbn-pattern-row` card pattern (same as rhythm library) with category color accent

---

## 4. Show Page

**File:** `resources/js/Pages/Library/Progressions/Show.vue`

Props:

| Prop | Type | Notes |
|---|---|---|
| `progression` | serialized progression | See §2 |
| `tiles` | `ProgressionChord[]` | Voice-led, resolved in key C (or pinned key) |
| `songs` | `SongLink[]` | Leadsheets featuring this progression via `sbn_progression_occurrences` |
| `siblings` | serialized progressions | Other progressions in same category |
| `courses` | course stubs | Related courses via `CourseRepository::relatedTo` |

### Page layout (top → bottom)

1. `progression.intro` — general description (history, name, harmonic character); hidden when null
2. `ChordProgressionViewer` — interactive chord visualization
3. `progression.details` — technical description (voice leading, substitutions, variations); hidden when null
4. Songs shelf
5. Courses shelf

### Key resolution priority

The `show()` action resolves the playing key in this order:

1. **First video snippet's key** (auto-applied on plain load) — if `video_snippets[0].key` is set, the page always opens in the key the snippet was authored in.
2. **`?snippet=<id>`** — explicit snippet selection; uses that snippet's key + pinned chords.
3. **`?key=X`** — explicit key override (e.g. from `onSnippetSelected` navigation).
4. **`?chord=&highlight=`** — chord-detail back-link; derives key from the pinned chord's root + numeral slot.
5. **Default `C`**.

`progressionKey` is passed as an Inertia prop and displayed as the key badge in `ChordProgressionViewer`.

### Pinned chord (arriving from chord detail page)

When a user clicks "view in progression" from a chord Show page, the URL carries:
```
/library/progressions/{slug}?chord={chordSlug}&highlight={slot}&root={displayRoot}
```
The controller pins the user's exact voicing into the correct slot and derives the key from that root + numeral. The surrounding chords are then voice-led in the correct harmonic relationship.

**Key resolution:** `HarmonicContext::keyFromNumeralAndRoot(numeral, root)` — finds the key where that numeral resolves to that root.

---

## 5. Tile Resolution Pipeline

Progression chords are never stored — they are **materialised on the fly**:

```
HarmonicContext::buildFromNumerals(key, numerals)
  → ProgressionBuilder::buildVoicings(context, options)
    → tiles: [{ chordName, numeral, diagramData, functionalRole, slug }]
```

`buildChordsFor(progression, key, usePass2, pinnedSlugs)` is the shared helper used by both `show()` and `apiShow()`. It also handles **pinned slugs** from video snippets: when a snippet's `chords` field provides explicit diagram slugs, those are transposed to the target root via `ChordSerializer::serialize($diagram, $targetRoot)` rather than using the builder.

### Top10 key resolution

`Top10Controller::getTop10Data()` also uses the first snippet's key when building progression tiles — it prefers `video_snippets[0].key` over the config's `progressionSeedKey`. The config key is the fallback when no snippet key is set.

### Video snippet voicings — current status (2026-06-05)

The full pipeline is wired end-to-end (admin snippet editor with per-slot chord search, backend key + pinned slug resolution, `ChordProgressionViewer` with `snippets` prop and `@snippet-selected` navigation) **but is currently disabled at the view layer**: `:snippets` is not passed to `ChordProgressionViewer` on any page. The component renders builder voicings only.

**Why disabled:** Displaying pinned voicings alongside a video creates a mismatch risk — the builder transposes movable shapes correctly for standard cases, but the interaction between fixed-position shapes, enharmonic roots, and the voice-leading optimizer produces unreliable results for the specific shapes a transcriber would pick from the library. Re-enabling requires either a dedicated transposition validation pass or a separate "exact voicing" rendering mode that bypasses the builder entirely.

---

## 6. `ChordProgressionViewer` Component

**File:** `resources/js/Components/Library/ChordProgressionViewer.vue`

The canonical component for rendering a chord progression anywhere in the app.

### TypeScript interfaces (exported from the component)

```typescript
export interface ProgressionChord {
    chordName: string;
    diagramData: ChordDiagramData | null;
    beats?: number;
    slug?: string | null;
    numeral?: string;
    functionalRole?: string | null;
}

export interface VideoSnippet {
    id: string;
    label: string;
    videoId: string;
    videoType: string;
    startSec: number;
    endSec: number;
    tempoBpm: number;
    key?: string;
    chords?: string[];   // per-slot chord-library slugs (pinned voicings)
}

export interface ChordProgressionViewerProps {
    chords: ProgressionChord[];
    interactive?: boolean;      // default true — enables click-to-play
    compact?: boolean;          // default false
    color?: string | null;      // accent CSS value
    vintageCard?: boolean;      // thick right+bottom border
    name?: string;
    category?: string;
    numerals?: string;
    keyLabel?: string;
    snippets?: VideoSnippet[];  // wired but not currently passed from any page (see §5)
}
```

### Video embed (self-contained, currently disabled)

When `snippets` is non-empty the viewer renders a `VideoEmbed` above the fretboard stage. Clicking a snippet tab seeks the video; clicking a numeral chip seeks to that chord's beat offset (`startSec + beatOffset * 60/bpm`). `@snippet-selected` fires when the active snippet changes so the parent can navigate to reload tiles in the new key.

**Currently disabled** — no page passes `:snippets`. The wiring is preserved for future re-enablement. See §5 for why.

### Visual layout (top → bottom)

1. **Header** — name, category badge, key badge
2. **Stage** — play button | fretboard excerpt (75%) | chord diagram card (25%)
   - Fretboard: 7-fret sliding window, JS lerp animation (speed 0.035), centered on active chord
   - Dots colored by interval type (amber=7ths, blue=3rds, gray=R/5, purple=9ths, green=11ths)
   - Ghost dots at next chord positions (35% opacity) for voice-leading preview
3. **Badge row** — one button per chord; shows `numeral` if present, falls back to chord name

### Audio

- Per-tile click plays one strum via `chordDiagramToEvents` + shared `AudioEngine`
- Global play button sequences all chords at 180 BPM
- `engine.on('playStarted')` clears active-tile state when another consumer takes the engine (singleton-mutex pattern)
- Tiles never link out — click-to-play only (anti-rabbit-hole rule)

### Voice-leading overlay

`GuideToneArrowBridge.vue` renders SVG arrows between adjacent chord tiles:
- `guideToneResolution.js` mirrors `ProgressionBuilder::scoreVL()` exactly
- Resolving pairs: 7→3 (amber), 3→root/7 (blue), 9th ext (purple), #11 ext (green), 5th (gray)
- Common-tone suppression: no arrow drawn if source pitch-class already present in next chord
- Use pitch-class distance (`min(d%12, 12-d%12)`), NOT raw MIDI distance

**Critical invariant:** common-tone suppression is load-bearing — removing it causes false arrows on chords sharing any guide tone.

### Where it's used

| Location | Context |
|---|---|
| `Library/Progressions/Show.vue` | Main progression visualization |
| `Library/Chords/Show.vue` | "Progressions containing this chord" section |
| `Library/Songs/Show.vue` | Detected progressions in song |
| `Top10/*.vue` | Featured progression panels |
| Course lesson content via `mountSbnNodes.ts` | `<sbn-progression>` tag |

---

## 7. API Endpoints (lesson editor + course player)

### `GET /api/sbn/progressions/{slug}?key=C&pass2=true&chords=slug1,slug2,...`

Returns `ProgressionChord[]` for `ChordProgressionViewer`.

| Param | Default | Notes |
|---|---|---|
| `key` | `C` | Tonal centre for resolution |
| `pass2` | `true` | Enable option-tone Pass 2 in builder |
| `chords` | — | Comma-separated diagram slugs; overrides builder for those slots |

### `GET /api/sbn/progressions/search?q=`

Palette search — returns `{ slug, label, meta, snippets[] }`. Used by the lesson editor palette. `snippets` carries `{ id, label, key }` for the video picker.

---

## 8. Detection Engine (`ProgressionDetector`)

**File:** `app/Services/ProgressionDetector.php`

Detects which progressions occur in a leadsheet and writes to `sbn_progression_occurrences`. Run via the admin panel or `php artisan sbn:detect-progressions`.

### `sbn_progression_occurrences` schema

| Column | Notes |
|---|---|
| `progression_id` | FK → `sbn_chord_progressions.id` |
| `leadsheet_id` | FK → `sbn_leadsheets.id` |
| `section_id` | Section letter e.g. `"A"` |
| `start_measure` | **Section-relative** measure index (0-based) |
| `length_measures` | Span in measures |
| `start_chord` | Chord index within `start_measure` (0-based) where the progression starts |
| `end_chord` | Chord index within `end_measure` where the progression ends (inclusive) |
| `end_chord_start` | First chord index in `end_measure` that belongs to this progression (may be < `end_chord` when multiple progression chords share the end measure) |
| `detected_root` | Detected tonic note |
| `confidence` | Match confidence 0–1 |

### Key detection logic

- Chords are deduplicated into **slots** (consecutive identical numerals collapsed into one).
- `slotMap[i]` = first occurrence of slot `i` in the chord stream; `slotEndMap[i]` = last occurrence.
- Match boundaries: `storeStart = slotEndMap[firstPatternSlot]` (last repeat of the first chord), `storeEnd = slotMap[lastPatternSlot]` (first occurrence of the last chord). This ensures only the resolving instance is highlighted, not any run-up repetitions.
- `slotToMeasure()` / `slotToChordIndex()` convert slot index → (measure, ci) for precise chord-level storage.
- `end_chord_start` is computed by walking backwards from `storeEnd` while still in `endMeasure` — captures the first ci in that measure belonging to the progression.

### `flexTonic` rule

When a pattern slot is marked as a tonic (`flexTonic=true`), the detector allows `major` ↔ `minor` quality matching (e.g. Im vs I). **Dominant (`dom`) is explicitly excluded** — I7 must only match I7, not a plain I or Im, so "Tonic Dominant" (I7→IV) is not triggered by a plain I→IV.

### Dim7 pattern matching — pre-resolution stream

Progressions whose stored numeral string contains `dim7`, `o7`, or `°7` are matched against the **pre-resolution** numeral stream (raw tokens before `resolveDominantDim7s` runs). All other patterns match against the post-resolution stream (where dim7 chords functioning as dominants have been renamed, e.g. `#Io7 → VI7`).

This ensures a stored `I, #Idim7, IIm7, V7` pattern finds the actual `#Idim7` token in the song rather than the secondary-dominant substitution. Without this split, dim7 passing-chord progressions would never match because the token they look for no longer exists by the time pattern matching runs.

**Routing gate:** `patternContainsDim7()` tests the raw stored string for `dim7|o7|°7` — it must match all three notations because stored progressions use `dim7` while `parseNumeralSequence` normalises to `o7` internally.

### Enharmonic degree equivalence

`tokenScore()` converts accidental + Roman numeral to a semitone offset before comparing degrees, so enharmonic spellings match: `#II` and `bIII` both map to semitone 3 and are treated as the same degree. This is critical because:

- Songs spell chromatic degrees by the accidental of the actual note (e.g. `Ebdim` in key C → `bIIIdim`).
- Stored progressions often use the enharmonically equivalent sharp spelling (`#IIdim7`).
- Without semitone comparison, `bIII` ≠ `#II` as strings and the match fails.

**Implementation:** `degreeToSemitone(accidental, numeral)` in `ProgressionDetector`. Diatonic base offsets (I=0, II=2, III=4, IV=5, V=7, VI=9, VII=11) adjusted by `+1` per `#` and `-1` per `b`, mod 12.

### Quality family matching for dim7 vs dim

A plain diminished triad (`dim` / `o`) and a diminished 7th (`dim7` / `o7`) are in the **same harmonic family** (`dim`). A pattern token `#Idim7` will match a song token `#Idim` (or `bIIIdim`) with confidence 0.85 (same-family, different suffix) — above the 0.75 threshold, so the match is recorded with slightly reduced confidence.

### Parser alignment

`LeadsheetParser` preserves **empty bars** (e.g. pickup bars) so its measure indices match the `json_data` array that the frontend builds from the stored shortcode. Previously `array_filter` dropped whitespace-only segments, causing all occurrence indices to be off by one for songs with pickup bars. The fix: strip only the leading/trailing empty segment (artifact of leading/trailing `|` on a measure line), keep inner empty segments as `['chords' => []]`.

---

## 9. Cross-references

- Songs featuring a progression: `sbn_progression_occurrences.progression_id` → `sbn_leadsheets`
- Progressions in a song: `sbn_progression_occurrences.leadsheet_id` → `sbn_chord_progressions`
- Chord-level highlight in the viewer: see `SBN-Leadsheet-Reference.md §6.6`
- Progression tile building: see `SBN-Builder-Reference.md`
- `<sbn-progression>` lesson tag: see `SBN-Course-Reference.md §3`
- Video snippets on progressions: same shape as rhythm snippets — see `SBN-Rhythm-Reference.md §12`

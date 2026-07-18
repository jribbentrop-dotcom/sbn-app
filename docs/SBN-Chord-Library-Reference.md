# SBN Chord Library Reference

The public chord library: the index grid, the chord **detail (Show) page**, transposition search, the alias/inversion system, and the generated **diminished-7th symmetry** readings.

Audience: developers working on `/library/chords`. For chord-name *rendering* (CSS, `chord()` helper) see `SBN-Design-Reference.md`; for the analysis engine see `SBN-Identifier-Reference.md`.

---

## 1. Routes & controllers

| Route | Method | Notes |
|---|---|---|
| `GET /library/chords` | `ChordLibraryController::index` | Archetype/barré/drop/shell families + flat "other" grid |
| `GET /library/chords/{slug}` | `ChordLibraryController::show` | Detail page (Inertia `Library/Chords/Show`) |
| `GET /library/chords/search?q=` | `ChordLibraryController::search` | Transposition + alias search (JSON), via `ChordVoicingSearch` |
| `GET /library/chords/{slug}/api` | `ChordLibraryController::apiShow` | JSON for `mountSbnNodes.ts` |

Key services: `ChordSerializer` (model → frontend array; `serialize`/`serializeAs`/`spellBassNote`), `ChordShapeCalculator` (fret/note/bass math), `ChordVoicingSearch` (parse + transpose + alias match), `HarmonicContext\DiminishedSymmetry` (dim7 primitive).

---

## 2. The detail page (Show.vue)

Props: `chord`, `aliases`, `aliasInversions`, `inversions`, `siblings`, `songs`, `progressions`, `qualityTopic`, `courses`, `initialAliasIdx`, `arrivedVia`.

- `?root=` transposes the displayed shape without changing the slug.
- **Alias switcher**: button `-1` = the primary chord; buttons `0..n` = aliases. `activeAliasIdx` drives `displayChord` (overlays alias fields onto the shape) and `activeInversions` (swaps to that alias's inversion list). Inertia reuses the component, so `activeAliasIdx` is `watch`ed off `initialAliasIdx`.
- **Inversion panel** renders `allInversions` (the active reading's inversions + a synthetic "self" entry) — except for diminished pages, which supply complete lists server-side (see §4).

### Note spelling on the page
`formatNote()` is the canonical formatter: double accidentals first (`bb`→𝄫, `##`→𝄪), then single (`#`→♯, `b`→♭). All root/bass rendering routes through it.

### "View all" shelf hrefs (2026-07-16)

`songsViewAllHref`, `progressionsViewAllHref`, and `coursesViewAllHref` are
built in `ChordLibraryController::show` as `?slugs=...&from={chordLabel}`,
where `$chordLabel` is `chordDisplayName($effectiveDisplayRoot, $chord->quality,
$chord->extensions)` (the same helper that names the chord elsewhere on the
page, e.g. "Cmaj7") — reused rather than re-derived so the label always
matches what the page itself calls this chord. Note the progressions shelf is
scoped by **quality** (`$chord->quality`), not the exact voicing, so the
label is directionally correct ("progressions using a G7-type chord") rather
than guaranteeing every listed progression contains this precise extension
set. Full mechanism (why `?slugs=` is applied client-side on the target page,
not server-filtered here): [SBN-Design-Reference.md § Deep-linked "View all"
scoping](SBN-Design-Reference.md).

---

## 3. Alias search → detail deep-link

An **alias match** is a search result where one stored shape is *reinterpreted* as another chord (e.g. the `m6-drop3-roote` shape read as `Bm7b5/D`). Clicking one must open the parent shape's page at the parent's root, with the alias pre-selected.

**URL contract** (built by `chordShowUrl` in `useChordUrl.ts`):
```
/library/chords/{slug}?root={display_root}&aliasRoot={r}&aliasQuality={q}&aliasExt={e}&aliasBass={b}
```
- `root` = the **parent shape's** transposed root (so the primary diagram is correct) — NOT the alias root.
- `aliasRoot` = the alias's own transposed root (generally ≠ `root`).

Plumbing (4 touch points): `ChordVoicingSearch::findAliasMatches` emits `display_root`/`alias_root`/`alias_quality`/`alias_extensions`/`alias_bass` → `ChordLibraryController::search` passes them through → `chordShowUrl` builds the URL → `ChordLibraryController::show` resolves `$initialAliasIdx`.

**Gotcha — never match aliases on bass note.** The matcher keys on **root + quality + extensions only**. Search reports the *typed* bass (often empty); the Show page *derives* the alias bass from the shape's lowest note. They're computed independently and won't agree. Bass is a tiebreaker only, among candidates already matching root+quality+ext.

**Gotcha — `ChordCard` double-navigation.** `ChordCard` has its own `handleCardClick`. When wrapped in an Inertia `<Link>`, pass `:no-nav="true"` so the card defers to the Link (otherwise both fire and the card's `window.open` wins with a stale URL). `handleCardClick` itself uses `chordShowUrl` (the single URL builder — never hand-concat slug+root) and opens a new tab unless `:same-tab="true"`.

---

## 4. Diminished-7th symmetry (generated readings)

A dim7 (`o7`) divides the octave into four equal minor thirds, so it is inversionally symmetric: one shape names four dim7 chords (roots a minor third apart) **and** four rootless dominant-7(♭9) chords (each rooted a semitone below the dim tone that becomes its ♭9). Rather than hand-store 4 diagrams + ~16 alias rows per shape, the library **generates** the lattice from the single shape.

### The primitive — `App\Services\HarmonicContext\DiminishedSymmetry`
Pure, unit-tested (`tests/Unit/DiminishedSymmetryTest.php`). Also consumed by the identifier's two diminished resolvers (which keep their own sharp spelling — see Identifier ref).
- `symmetricRoots(pc)` → `[pc, +3, +6, +9]`
- `dominantReadings(pc)` → 4× `{domRootPc = (r−4) mod 12, b9Pc = r}` (dom root always absent → true rootless)
- `spellDim7(root)` → `{R, b3, b5, bb7}`; `spellDom7b9(domRoot)` → `{3, 5, b7, b9}`; `spellRoot(pc)` (flat); `spellSharp(pc)`
- `extensionLabelForReading(dimExtension, slotIndex, isDom)` → extension label as seen from a given inversion slot or dom alias; returns `null` when the extension lands on a chord tone of that reading
- `DIM_EXTENSION_SEMITONES` — known dim extensions to semitone offset: `'b13'`→8, `'11'`→5
- `EXTENSION_INTERVAL_LABELS` — semitone offset → label string (e.g. 9→`'13'`, 6→`'#11'`, 3→`'#9'`, 11→`'maj7'`)

### Spelling rule (locked)
Structural tones **3 / 5 / ♭7** are spelled functionally — correct letter, single accidental (a major 3rd above B is **D♯**, never E♭). Because dim7 stacks minor thirds, a strict spelling can force a double accidental on a structural tone (Eb°7's ♭5 → B♭♭); when it would, **fall back to the simple enharmonic** (→ A). The °7 tone and tensions (♭9) are spelled pragmatically (simple natural — C°7's 7th = A, not B♭♭).

### Extended dim7 shapes (°7b13, °711, …)
Some stored `o7` shapes carry an extension (e.g. `extensions='b13'`). The extension note is fixed in fretboard space, so its interval shifts relative to each of the eight generated readings. `extensionLabelForReading` computes this:

- Dim inversion slot k: interval from slot root = `(e − 3k) mod 12`
- Dom alias slot k: interval from dom root = `(e + 4 − 3k) mod 12`

**°7b13** (e=8): dim slots → b13/11/9/maj7; dom aliases → ROOT/13/#11/#9. The ROOT alias (slot 0) means the extension lands on the dom root — shape is no longer purely rootless (`rootless: false`) but still a valid rooted dominant voicing.

**°711** (e=5): dim slots → 11/9/maj7/b13; dom aliases → 13/#11/#9/ROOT.

**The iconic example**: the Getz/Gilberto *Girl from Ipanema* chord `Ab7(b9,13)/A` is voiced as diagram 121 (`A°7b13`) — the b13 of A°7 lands at the 13 of the `Ab7(b9)` reading (slot 1 of dominantReadings for dim root A).

### Generation — `ChordLibraryController::buildDiminishedReadings($chord, $displayRoot)`
Returns `['inversions', 'aliases', 'aliasInversions']`. `show()` **overrides** the DB-driven defaults when `quality ∈ DiminishedSymmetry::DIM_QUALITIES`.
- **Inversions**: the four dim7 inversions, **named by their own root** (C°7, E♭°7, G♭°7, A°7 — NOT slash chords like C°7/Eb), with the inversion *label* showing role relative to the display root. For extended shapes, the extension label rotates per slot (e.g. `Eb°7(11)` for a b13 shape at inv1). `bass_note` cleared.
- **Aliases**: the four dom7 readings. Extensions computed per slot via `extensionLabelForReading`; `rootless: false` when the extension lands on the dom root. `notes` re-spelled via `spellDom7b9`'s pc→name map.
- **aliasInversions**: the same four physical positions, relabelled by **bass function** vs the dominant root: ♭9 in bass → **"Rootless"**; 3/5/♭7 → 1st/2nd/3rd inversion.

### Frontend (Show.vue)
- `isDiminished` gates the dim7 copy; `activeIsRootless` and `activeSelfInversion` (returns `'rootless'` for rootless aliases) drive labels.
- Generated lists are complete, so `allInversions` does **not** append a synthetic self for dim pages. All generated entries share one diagram `id`, so `:key` = `id-inversion` and `inversionIsCurrent()` matches by **slot**. Inversion links use `chordShowUrl(inv)` so `?root=` is preserved.
- **Edu copy is context-aware** via `arrivedVia` (`'dominant'` when a dom7(♭9) reading was deep-linked, else `'diminished'`).
- Switcher shows "This voicing" / "Also reads as" grouping labels on dim pages.

### Search-side generation
Three methods in `ChordVoicingSearch` cover the full lattice:

**`findDiminishedDominantMatches($root, $quality, $extension)`** — fires for `dom7` searches carrying `b9`. Queries all `o7` shapes (bare and extended). For each shape, finds the alias slot index where `domRootPc` matches the searched root (via `dominantReadings($dimRootPc)`), then calls `extensionLabelForReading` to compute the correct `alias_extensions` hint (e.g. `'b9,13'` for a `°7b13` shape). Deep-link round-trip is exact.

**`findDiminishedAliasReadings($root, $quality, $extension)`** — fires for `o7` searches with a known extension (e.g. `G°7(b13)`). Emits all four dom7 alias readings as additional `alias_match` cards so the user can navigate directly to each dominant interpretation.

**`findDiminishedPickerResults($shapes, $root, $filter)`** — generates the full voicing-picker lattice for `o7` queries in `searchVoicingsAdvanced`. Returns 4 dim inversions (each named by its own root, e.g. C°7/E♭°7/G♭°7/A°7) and 4 rootless dom7(b9) alias readings per shape. `$filter` is `'dim'|'dom'|'all'`, driven by the Inv stepper selection. Called from `LeadsheetController::searchVoicingsAdvanced` when `quality=o7`; also supplements dom7(b9) queries.

**`VoicingCrossref::matchVoicing` dim symmetry passes** — two passes at the end of the matching pipeline:
1. **o7 pass** — when `baseQuality=o7` and no match found, retries all `o7` shapes against the 3 minor-3rd partner roots (+3/+6/+9 semitones). Fixes wrap-around failures where the calculator places the note at fret 11 instead of the low-position equivalent (e.g. `C#o7` matching `o7-drop2-rootd` stored as E♭ via transposing to E).
2. **dom7+b9 pass** — for leadsheet voicings parsed as `dom7 + b9`, queries all `o7` shapes and tries all 4 family roots (not just the single b9 root). Resolves matches like `F#7(b9) xx2323` → `o7-drop2-rootd` and `Ab7(b9,13)/A` → diagram 121 that the standard path misses.

### Retired data
Migration `2026_05_31_000001_remove_generated_dim7_aliases` deletes the now-redundant hand-authored alias rows on `o7` parents: the dim7 **inversion** aliases (`alt_quality = o7`) and the **bare** dom7(♭9) aliases (`alt_quality = dom7`, `alt_extensions = 'b9'`). Extended aliases the generators don't claim (e.g. `b9,13`, `#9`) are **left in place**. Non-destructive: the readings are recomputed at runtime.

---

## 5. Enharmonic spelling pipeline

All note names (chord tones displayed in the detail page circles, inversion bass labels, search result names) flow through one decision point: `ChordShapeCalculator::useFlatsForQuality(string $rootNote, string $quality): bool`.

> **This is the *chord-related* layer of the app-wide enharmonic core.** The library pages
> spell each chord on its own (there's no song key), so the chord rule is the whole story here.
> Where a **key** is in play (leadsheet editor, viewer, progressions), the *key-related* layer
> dominates instead — flats by default, sharps only for genuine sharp keys — via
> `HarmonicContext::useFlatsFor($root, $quality, $key)`, which falls back to `useFlatsForQuality`
> when no key is given. See **SBN-Admin-Chord-Tab-Editor-Reference.md → "The enharmonic spelling core"**.

**Rules (in priority order):**
1. Flat-accidental root (`Bb`, `Eb`, `Ab`, `Db`, `Gb`, `F`) → always flats
2. Sharp-accidental root (`C#`, `F#`, `G#`, `A#`, `D#`) → always sharps
3. Natural root + quality in `QUALITY_FLAT_INTERVALS` → check if any **minor/diminished** interval of that quality (b3=3, b5=6, b7=10) lands on a flat-side pc (1=Db, 3=Eb, 8=Ab, 10=Bb). If yes → flats. Major/perfect intervals (4, 7, 11) are excluded — a major 3rd above B is D♯ not E♭.
4. Otherwise → sharps.

**dim7 exception:** `o7` bypasses the flat/sharp map entirely. `calculateNoteNames` calls `DiminishedSymmetry::spellDim7(root)` to get the four correctly-spelled tones, builds a pc→name map, and looks each sounding string up in it. This gives root-relative strict spelling (A♯°7 → A♯, C♯, E, G) rather than forced flats.

**Open strings:** `calculateNoteNames` now merges open strings (fret 0) with fretted positions before spelling, sorted low→high string. Previously open strings were silently dropped.

**Bass note spelling** (`deriveBassNote`, `spellBassNote`) uses the same `useFlatsForQuality` — no separate flat-quality list. `deriveBassNote` is the single source of truth; `spellBassNote` no longer re-spells its return value.

**Search results** (`ChordVoicingSearch::transposeShapes`) delegates inversion bass names to `deriveBassNote` — the old local `$semitoneToNote` flat-only table is gone.

---

## 6. Diagram rendering — guide-tone colours & dot behaviour

### SVG renderer (`public/js/chords.js` → `sbnRenderDiagramSVG`)

Fixed viewBox `88×98`, `width="100%"` so diagrams scale fluidly.  
The renderer is invoked by `ChordDiagram.vue` with `showGuideTones: true` (default — on everywhere in the app) and `showFingers: true`.

**Grid line stroke**: fret/string lines use `stroke-width="1"` with `vector-effect="non-scaling-stroke"` so they render at a constant on-screen pixel width no matter how small the diagram is scaled down (mobile fix, 2026-07-16 — `stroke-width="0.4"` without `non-scaling-stroke` rendered under a physical pixel and disappeared on narrow mobile layouts). `AnimatedChordDiagram.vue` mirrors this same coordinate system and must stay in sync — same fix applied there.

**Guide-tone colour map** (`GT_COLORS`):

| Interval | Fill | Role |
|---|---|---|
| root | `#16a34a` (green) | tonic |
| 3 / b3 | `#3b82f6` (blue) | third |
| 7 / b7 | `#d97706` (amber) | seventh |
| 5 / b5 | `#9ca3af` (gray) | fifth |
| 2 / 4 / 6 / 9 / 11 / 13 / … | `#a855f7` (purple) | extensions |

`sbnGtColorForInterval(label)` maps the interval token. The full extension set is `['9', 'b9', '#9', '11', '#11', 'b13', '13', '2', '4', '6']`.

**Dot labels**: always **finger numbers** (when `showFingers=true`). Interval text removed — coloring carries the harmonic identity, numbers aid fingering.

**Open strings**: rendered as white circle + coloured border (interval colour as `stroke`). Position: `cy = top - 6` (above nut line). Class `sbn-svg-dot data-string="{n}"` so the ping animation targets them the same way as fretted dots.

**Barre**: rendered as a rounded rect in the root colour.

### Alias voicings and interval labels

`ChordVoicingSearch` transposes parent shapes to alias roots. The parent shape's `interval_labels` are relative to the *parent* root — they're wrong for the alias. **Fix**: after transposition, `ChordVoicingSearch` calls `ChordShapeCalculator::computeIntervalLabelsPublic($positions, $open, $muted, $aliasRoot, $aliasQuality)` using the alias root and quality. This overrides the parent's stored labels.

`ChordShapeCalculator::computeIntervalLabels` uses `QUALITY_INTERVALS` (a const matching `ChordDiagram.vue::getQualityIntervals`) — quality-aware so `m7` shapes correctly label the flat-third, `dom7` labels the flat-seventh, etc.

### ChordCard playback

`ChordCard.vue` plays a chord through `NylonSampler` (nylon guitar samples). A **shared singleton** (`resources/js/audio/engine/voices/sharedNylon.ts` → `getSharedNylon()`) is used app-wide so only one instance exists. Calling `nylon.releaseAll()` before scheduling a new play stops any currently-sounding card. Open strings are included in playback events (the barre field fix in `chordDiagramToEvents.js` uses `from`/`to` keys matching the DB schema, not `fromString`/`toString`).

**Dot ping animation** (`@keyframes sbnDotPing` in `chord-library.css`): scale-only — no fill override. Each dot keeps its own guide-tone colour through the animation. `.board-diagram { overflow: visible }` prevents clipping for dots near the top of the diagram (open strings above the nut).

---

## 7. Chord library index — sort modes & data conventions

### Sort sidebar

The index page (`/library/chords`) has a **Sort by** section in its filter sidebar with two options:

| Option | Behaviour |
|---|---|
| **Popularity** (default) | Voicings listed by `popularity` descending, then by `quality` — same as the server sort order |
| **Top 10** | Switches the grouped grid to a **2-column hitlist**; the 10 slugs from `config/top10/bossa-nova-chords.php` are pinned at the top in config order, then all remaining voicings follow by popularity |

Switching to Top 10 while any text/quality filter is active has no effect — the filter grid takes over as usual. The archetype/barré/drop/shell panel at the top of the page is always visible regardless of sort mode (it's hidden only by active filters, not sort).

### Top 10 hitlist

Each hitlist row: **rank number** → **chord card** (140 px wide) → **description** text, side by side. No badges or extra title. Clicking anywhere on the row navigates to the chord detail page.

The controller reads `config/top10/bossa-nova-chords.php` once per page load (`array_keys`) and passes `top10Slugs: string[]` as an Inertia prop. The frontend `top10Chords` computed promotes matching slugs in order, then appends the rest.

### Popularity tiers (used by the Popularity filter)

| Tier | `popularity` range | Label |
|---|---|---|
| Rare | 1–2 | — |
| Common | 3–5 | — |
| Core | 6–10 | — |
| Iconic | 11+ | — |

The 10 Top10 featured chords have `popularity = 15`. The highest naturally-occurring value (from song-usage counting) is ~15 for the most-used voicings.

### Difficulty conventions

| Value | Meaning |
|---|---|
| 1–2 | Open/beginner shapes |
| 3 | Drop 2 and Drop 3 voicings (all set via migration) |
| 4–5 | Advanced / extended |

`difficulty = 0` means unset. The Difficulty filter in the sidebar maps 1–5 to star labels.

### Extensions filter — composable facets, not combo strings (2026-07-16)

`sbn_chord_diagrams.extensions` stores each voicing's full extension set as one
comma-joined string (e.g. `'b9,13'`, `'#9'`, `'b13'`) — there is no separate
per-extension column. The sidebar filter does **not** offer one pill per
unique combo string (that explodes into near-duplicate options as the library
grows); instead `Index.vue` splits every chord's `extensions` on comma,
collects the distinct individual tokens, and renders **one pill per token**:

- Sort order is by scale degree then flat/natural/sharp (`extensionSortKey` —
  `b9, 9, #9, 11, #11, b13, 13, …`), not alphabetical — alphabetical would
  scatter `#11` and `b9` away from their numeric neighbors.
- `fExt` is an array (multi-select). A chord matches if it carries **ANY** of
  the selected tokens (OR) — e.g. selecting `9` and `13` shows every chord
  that has a 9 *or* a 13, not only chords with both. This was a judgment call
  (no existing convention to match); revisit if a future need calls for exact
  combination matching (AND) instead.
- `toggleExt(token)` / `extensionTokens(raw)` in `Index.vue` are the two
  helpers doing the split/toggle — reuse them rather than re-deriving the
  comma-parsing if extensions show up as a filter elsewhere.

---

## 8. Key files
- `app/Http/Controllers/Library/ChordLibraryController.php` — `index`, `show`, `search`, `buildInversionsForIdentity`, `buildDiminishedReadings`, `aliasInversionSlot`
- `app/Services/ChordSerializer.php` — `serialize`, `serializeAs`, `spellBassNote`
- `app/Services/ChordShapeCalculator.php` — `calculateFrets`, `calculateNoteNames`, `computeIntervalLabels` / `computeIntervalLabelsPublic`, `deriveBassNote`, `useFlatsForQuality`
- `app/Services/ChordVoicingSearch.php` — `parseChordName`, `searchByName`, `findAliasMatches`, `transposeShapes`; alias section overrides `interval_labels` with quality-aware recomputation
- `app/Services/HarmonicContext/DiminishedSymmetry.php` — dim7 primitive
- `public/js/chords.js` — `sbnRenderDiagramSVG`, `GT_COLORS`, `sbnGtColorForInterval`
- `public/css/chord-library.css` — `@keyframes sbnDotPing`, `.sbn-svg-dot` animation rules
- `resources/js/Pages/Library/Chords/Show.vue`, `Index.vue`
- `resources/js/Components/Library/ChordCard.vue` — playback via `getSharedNylon()`
- `resources/js/Components/Library/ChordDiagram.vue` — `showGuideTones` (default `true`), `showFingers` (always `true`)
- `resources/js/audio/engine/voices/sharedNylon.ts` — singleton NylonSampler
- `resources/js/audio/adapters/chordDiagramToEvents.js` — barre `from`/`to` field names
- `resources/js/composables/useChordUrl.ts`

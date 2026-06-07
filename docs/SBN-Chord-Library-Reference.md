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

### Spelling rule (locked)
Structural tones **3 / 5 / ♭7** are spelled functionally — correct letter, single accidental (a major 3rd above B is **D♯**, never E♭). Because dim7 stacks minor thirds, a strict spelling can force a double accidental on a structural tone (Eb°7's ♭5 → B♭♭); when it would, **fall back to the simple enharmonic** (→ A). The °7 tone and tensions (♭9) are spelled pragmatically (simple natural — C°7's 7th = A, not B♭♭).

### Generation — `ChordLibraryController::buildDiminishedReadings($chord, $displayRoot)`
Returns `['inversions', 'aliases', 'aliasInversions']`. `show()` **overrides** the DB-driven defaults when `quality ∈ DiminishedSymmetry::DIM_QUALITIES`.
- **Inversions**: the four dim7 inversions, **named by their own root** (C°7, E♭°7, G♭°7, A°7 — NOT slash chords like C°7/Eb), with the inversion *label* showing the role relative to the display root. `root_note`/`name` set to the symmetric root, `bass_note` cleared. Serialized with `$displayRoot` so diagram positions are correct; note names come from `calculateNoteNames` which routes `o7` through `DiminishedSymmetry::spellDim7` for root-relative spelling.
- **Aliases**: the four dom7(♭9) readings (switcher entries), each flagged `rootless: true`. `notes` are taken from the first serialized inversion entry (preserving string/low→high order), then each note is **re-spelled** via `spellDom7b9`'s pc→name map so enharmonics match the dominant root (e.g. Ab→G# for E7(b9)).
- **aliasInversions**: the same four physical positions, relabelled by **bass function** vs the dominant root: ♭9 in bass (interval 1) → **"Rootless"**; 3/5/♭7 (4/7/10) → 1st/2nd/3rd inversion.

### Frontend (Show.vue)
- `isDiminished` gates the dim7 copy; `activeIsRootless` and `activeSelfInversion` (returns `'rootless'` for rootless aliases) drive labels.
- Generated lists are complete, so `allInversions` does **not** append a synthetic self for dim pages. All generated entries share one diagram `id`, so `:key` = `id-inversion` and `inversionIsCurrent()` matches by **slot**. Inversion links use `chordShowUrl(inv)` (not a bare slug href) so the `?root=` param is preserved when navigating to a symmetric inversion root.
- **Edu copy is context-aware** via `arrivedVia` (`'dominant'` when a dom7(♭9) reading was deep-linked, else `'diminished'`): a dim7 search shows "this shape also voices four dom7(♭9)…"; a dom7(♭9) search shows the reveal "the dominant you searched is a pure diminished 7th…". The "four identical inversions" explanation lives in the **inversion panel**; the alias block covers only the dim7↔dom7(♭9) relationship.
- Switcher shows "This voicing" / "Also reads as" grouping labels on dim pages.

### Search-side generation
`ChordVoicingSearch::findDiminishedDominantMatches($root, $quality, $extension)` fires when the search is a `dom7` carrying a `b9` (e.g. `Ab7(b9)`, `G7b9`). It transposes **every** stored `o7` shape so the dominant's ♭9 (a semitone above the root) is a chord tone, and returns each as a rootless `{root}7(b9)` reading with the same deep-link context as `findAliasMatches` (`display_root` = the dim7 root transposed to; `alias_*` pre-selects the dom7(♭9) reading). So all 10 dim7 shapes surface consistently, not just the 2 that once had hand-authored rows. The `alias_extensions` hint is always `'b9'` (the bare dim shape can't voice extra tensions like `b9,13`), so the deep-link still matches the generated page alias.

### Retired data
Migration `2026_05_31_000001_remove_generated_dim7_aliases` deletes the now-redundant hand-authored alias rows on `o7` parents: the dim7 **inversion** aliases (`alt_quality = o7`) and the **bare** dom7(♭9) aliases (`alt_quality = dom7`, `alt_extensions = 'b9'`). Extended aliases the generators don't claim (e.g. `b9,13`, `#9`) are **left in place**. Non-destructive: the readings are recomputed at runtime, so nothing the user sees changes except that all dim shapes now surface (no more duplicate rows on diagrams 131/141).

---

## 5. Enharmonic spelling pipeline

All note names (chord tones displayed in the detail page circles, inversion bass labels, search result names) flow through one decision point: `ChordShapeCalculator::useFlatsForQuality(string $rootNote, string $quality): bool`.

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

## 7. Key files
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

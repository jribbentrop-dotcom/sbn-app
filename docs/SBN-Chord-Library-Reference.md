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
- **Inversions**: the four dim7 inversions, **named by their own root** (C°7, E♭°7, G♭°7, A°7 — NOT slash chords like C°7/Eb), with the inversion *label* showing the role relative to the display root. `root_note`/`name` set to the symmetric root, `bass_note` cleared.
- **Aliases**: the four dom7(♭9) readings (switcher entries), each flagged `rootless: true`, with `notes` = the reading-aware `{3,5,b7,b9}`.
- **aliasInversions**: the same four physical positions, relabelled by **bass function** vs the dominant root: ♭9 in bass (interval 1) → **"Rootless"**; 3/5/♭7 (4/7/10) → 1st/2nd/3rd inversion.

### Frontend (Show.vue)
- `isDiminished` gates the dim7 copy; `activeIsRootless` and `activeSelfInversion` (returns `'rootless'` for rootless aliases) drive labels.
- Generated lists are complete, so `allInversions` does **not** append a synthetic self for dim pages. All generated entries share one diagram `id`, so `:key` = `id-inversion` and `inversionIsCurrent()` matches by **slot** (and the entries are non-navigable — same physical shape).
- **Edu copy is context-aware** via `arrivedVia` (`'dominant'` when a dom7(♭9) reading was deep-linked, else `'diminished'`): a dim7 search shows "this shape also voices four dom7(♭9)…"; a dom7(♭9) search shows the reveal "the dominant you searched is a pure diminished 7th…". The "four identical inversions" explanation lives in the **inversion panel**; the alias block covers only the dim7↔dom7(♭9) relationship.
- Switcher shows "This voicing" / "Also reads as" grouping labels on dim pages.

### Search-side generation
`ChordVoicingSearch::findDiminishedDominantMatches($root, $quality, $extension)` fires when the search is a `dom7` carrying a `b9` (e.g. `Ab7(b9)`, `G7b9`). It transposes **every** stored `o7` shape so the dominant's ♭9 (a semitone above the root) is a chord tone, and returns each as a rootless `{root}7(b9)` reading with the same deep-link context as `findAliasMatches` (`display_root` = the dim7 root transposed to; `alias_*` pre-selects the dom7(♭9) reading). So all 10 dim7 shapes surface consistently, not just the 2 that once had hand-authored rows. The `alias_extensions` hint is always `'b9'` (the bare dim shape can't voice extra tensions like `b9,13`), so the deep-link still matches the generated page alias.

### Retired data
Migration `2026_05_31_000001_remove_generated_dim7_aliases` deletes the now-redundant hand-authored alias rows on `o7` parents: the dim7 **inversion** aliases (`alt_quality = o7`) and the **bare** dom7(♭9) aliases (`alt_quality = dom7`, `alt_extensions = 'b9'`). Extended aliases the generators don't claim (e.g. `b9,13`, `#9`) are **left in place**. Non-destructive: the readings are recomputed at runtime, so nothing the user sees changes except that all dim shapes now surface (no more duplicate rows on diagrams 131/141).

---

## 5. Key files
- `app/Http/Controllers/Library/ChordLibraryController.php` — `index`, `show`, `search`, `buildInversionsForIdentity`, `buildDiminishedReadings`, `aliasInversionSlot`
- `app/Services/ChordSerializer.php` — `serialize`, `serializeAs`, `spellBassNote`
- `app/Services/ChordShapeCalculator.php` — `calculateFrets`, `calculateNoteNames`, `deriveBassNote`, `FLAT_QUALITY_NOTES`
- `app/Services/ChordVoicingSearch.php` — `parseChordName`, `searchByName`, `findAliasMatches`, `transposeShapes`
- `app/Services/HarmonicContext/DiminishedSymmetry.php` — dim7 primitive
- `resources/js/Pages/Library/Chords/Show.vue`, `Index.vue`
- `resources/js/Components/Library/ChordCard.vue`
- `resources/js/composables/useChordUrl.ts`

# SBN Teaching Hub — Admin Reference

> **Purpose:** Complete functional documentation for the SBN admin section. Covers all implemented modules, their architecture, data models, and the design system. Use this as the reference when building the public frontend.
> **Last updated:** 2026-05-16 (video sync play-position model; extension_mode UI; progression slug auto-generation; ChordVoicingSearch alias dedup)
> **See also:** [SBN-Admin-Chord-Tab-Editor-Reference.md](SBN-Admin-Chord-Tab-Editor-Reference.md) — full Tab/Chord editor deep-dive (architecture, component tree, keyboard shortcuts, video sync, design system, and all creation flows).

---

## ADMIN MODULES AT A GLANCE

| Module | Status | Entry Point |
|--------|--------|-------------|
| Admin shell (auth, layout, sidebar, dashboard) | Done | `admin/dashboard/index.blade.php` |
| Rhythm patterns (CRUD) | Done | `admin/rhythms/` |
| Chord progressions (CRUD + occurrences) | Done | `admin/progressions/` |
| Chord diagrams + aliases + voicing crossref | Done | `admin/chords/` |
| Chord name styling component | Done | `public/js/sbn-chord-name.js` |
| Leadsheet model + parser + CRUD | Done | `admin/leadsheets/` |
| Leadsheet visual editor (chord grid + tab) | Done | `admin/leadsheets/edit.blade.php` |
| Shape calculator + voicing search + crossref | Done | `app/Services/` |
| Progression detection engine | Done | `app/Services/ProgressionDetectionController.php` |
| Analysis panel (in leadsheet editor) | Done | `edit.blade.php` (Alpine) |
| Enhanced voicing picker (context panel) | Done | `VoicingPicker.vue` |
| Reverse voicing lookup (identify_from_frets) | Done | `ChordShapeCalculator.php` |
| HarmonicContext + ProgressionBuilder services | Done | `app/Services/` |
| Progression builder admin UI | Done | `admin/progressions/builder.blade.php` |
| Fretboard diagrams (CRUD + interactive editor) | Done | `admin/fretboards/` — see [SBN-Fretboard-Reference.md](SBN-Fretboard-Reference.md) |
| Tab viewer + design system restructure | Done | `TabEditor.vue` |
| Interactive tab editor (Vue 3 / Vite) | Done | `resources/js/tab-editor/` |
| Tab ↔ chord sync + `<harmony>` in writer | Done | `useChordSync.js` / `musicXmlWriter.js` |
| Vue-native chord grid + voicing picker | Done | `ChordGridView.vue` / `VoicingPicker.vue` |
| Audio playback engine (Tone.js) | Done | `resources/js/audio/` |
| Video sync (Soundslice-style, YouTube) | Done | `VideoPlayer.vue` / `useVideoSync.js` |

---

## ARCHITECTURE OVERVIEW

### Source-of-truth split

```
VUE (TabEditor.vue — sole source of truth for editor content)
  ├── model.value             ← sections, measures, chordNames, chordVoicings, tab events
  ├── viewMode                ← 'chords' | 'tab' | 'analysis'
  ├── ChordGridView.vue       ← chord grid renderer
  ├── VoicingPicker.vue       ← desktop panel + mobile modal (Teleport into #sbn-vp-slot)
  ├── ChordPicker.vue         ← chord name inline picker
  ├── [tab editor components] ← notation SVG, cursor, sidebar
  └── Unified undo stack      ← covers all ops in both views

ALPINE (edit.blade.php — thin page shell)
  ├── Song metadata           ← title, composer, key, tempo, time signature
  ├── Analysis panel          ← reads from window.__sbnTabModel facade
  ├── HTTP save               ← reads from window.__sbnTabModel facade
  ├── alpineViewMode          ← one-way mirror of Vue's viewMode
  └── File import             ← drops/parses MusicXML → dispatches sbn-tab-init
```

**Key principle:** Vue owns all complex interactions. Alpine is a thin shell for metadata and HTTP. Do NOT mix Vue and Alpine reactivity — all complex state stays in Vue.

### What stays in Alpine vs Vue

| Concern | Owner |
|---------|-------|
| Chord grid render | **Vue** (ChordGridView.vue) |
| Chord name picker | **Vue** (ChordPicker.vue) |
| Voicing picker (panel + modal) | **Vue** (VoicingPicker.vue) |
| Voicing overview (resting state) | **Vue** (VoicingOverview.vue) |
| Voicing search API calls | **Vue** (utils/voicingApi.js) |
| Grid selection / clipboard / context menu | **Vue** (useGridSelection, useChordClipboard) |
| Row resize (−/+/§ per row) | **Vue** (rowShrink/rowGrow/splitSection in TabEditor) |
| Section collapse state | **Vue** (collapsedSections{} in TabEditor) |
| Section add/delete/rename | **Vue** (useTabModel: addSection, deleteSection, renameSection) |
| `viewMode` | **Vue** (Alpine mirrors one-way via sbn-tab-view-changed) |
| Undo / redo | **Vue** (useUndo.js — one stack) |
| Analysis view | Alpine (reads from window.__sbnTabModel facade) |
| Song meta (title/composer/key/tempo/time) | Alpine |
| Description, shortcode output | Alpine |
| HTTP save | Alpine |
| File import (drop/parse → sbn-tab-init) | Alpine |
| `identifyTabVoicings` (runs once on import) | Alpine |

---

## CHORD DIAGRAM STORAGE & TRANSPOSITION

The chord-diagram subsystem has a few non-obvious properties that
shape every consumer (builder, chord-detail page, leadsheet
voicing engine, transposition search). Read this once before
touching any code that reads from `sbn_chord_diagrams` or
`sbn_chord_diagram_aliases`.

### Storage convention

`sbn_chord_diagrams` rows store **a fingering (positions + bass
string + inversion + quality)** plus an identity label
(`root_note`). The fingering is what's musically real; the
identity is just a label.

- **`diagram_data`** — JSON: `{positions, barres, muted, open}`.
  String numbers are 1=low E, 6=high E (legacy WordPress
  numbering, do not renumber).
- **`root_string`** — which string carries the bass:
  `roote / roota / rootd / rootg / rootb / roothighe`.
- **`quality`** — chord quality (`maj7`, `m7`, `dom7`, `m7b5`, …).
- **`inversion`** — `root | inv1 | inv2 | inv3` (or empty for `root`).
- **`extensions`** — option tones present in the fingering as a
  comma string (`9`, `b9,#11`, `9,13`, …). Empty string = no
  option tones beyond the basic chord quality.
- **`root_note`** — the **label** the row goes by. Stored at C for
  most rows by editor default. The row's actual fingering may not
  match this label — it's just the canonical root the rest of the
  app should *display* for the row.
- **`start_fret`** — display offset for diagram rendering. Often
  unreliable on legacy rows (stored at `1` regardless of where
  the diagram actually draws).

The legacy WordPress importer stored shapes at arbitrary low
positions then auto-defaulted `root_note='C'` and
`start_fret=1`. That created widespread label-vs-fingering
drift: the row says C but the fingering is in some other key.
The transposition layer treats this as a non-issue (see below);
the chord-detail page used to render raw stored data and
inherited the drift.

### Transposition is root-agnostic

`App\Services\ChordShapeCalculator::calculateFrets($shape, $rootNote)`
ignores the row's `root_note` field for math. It works by:

1. Find the lowest sounding position (`findLowestPosition`).
2. Compute where that bass interval *should* land on its string
   for the target root (`calculateTargetFret`, mod 12).
3. Shift all positions by `targetBassFret - storedFret`.
4. Re-derive `start_fret` from the minimum fret of the
   transposed positions (`calculateStartFret`).

What this means: as long as `root_string`, `quality`, and
`inversion` are correct on the row, the calculator produces
correct fingerings for any target root, regardless of what
`root_note` claims. The math is interval-based, not label-based.

**Implication: storage convention has no algorithmic
significance.** Whether shapes are stored at iconic positions
(Wes's Dmaj7 in DOWAR), at fret 1, or at fret 8 — the math
doesn't care. Re-storing at iconic positions is a curatorial
choice for the chord-detail hero diagram, not a correctness
fix.

### Self-heal pattern (always run through the calculator)

Three consumers used to fall back to raw stored
`diagram_data + start_fret` when no `?root=` override was
supplied. All three were fixed in 2026-05-04 to always call
`calculateFrets` with the row's own `root_note` as target. The
calculator round-trip self-heals the label/data drift.

- **`ChordSerializer::serialize`** (chord-detail hero diagram).
- **`ChordLibraryController::show`** pinned-voicing block (the
  chord on the chord-detail page that gets pinned into surrounding
  progression tiles).
- **`ProgressionBuilder::fetchVoicingsForChord`** — already used
  the calculator on every candidate, so the audit-quality output
  was correct all along; only the chord-detail consumers were
  affected.

77 of 169 rows in the corpus had their displayed `start_fret`
corrected by the always-transpose change. Net result: the public-
facing pages now render at the right fret regardless of how
sloppily the row was stored.

### Alias table

`sbn_chord_diagram_aliases` stores re-spellings of parent
diagrams. Same fingering, different harmonic identity. The
canonical example: `Cmaj7 xx5557 = Em7/G` — same notes, different
chord name. Less obvious cases: the four `o7` rotations
(diminished symmetry), `Em7b5/G = Gm6` rotations, drop2 chords
that become extension voicings of a different root.

Schema:

```
diagram_id       → parent row in sbn_chord_diagrams
alt_root_note    → alias root
alt_quality      → alias quality
alt_extensions   → option tones (empty for plain re-spellings)
alt_bass_note    → optional slash bass
interval_labels  → recomputed from alias context
notes            → recomputed from alias context
```

Three consumers materialize aliases as fake shape objects with
the alias's identity but the parent's `diagram_data`:

- **`VoicingCrossref::findAliasShapes`** — leadsheet voicing engine.
- **`ChordVoicingSearch`** — transposition search (UI search box).
- **`ProgressionBuilder::fetchAliasShapes`** — added 2026-05-04;
  same pattern. Honors Pass 1 / Pass 2 extension gating, the
  category pool, `rootOnly`, and `is_fixed_position`. Inversion
  resolved via `analyzeSlashChord` (chord-tone bass → `inv1/inv2/inv3`,
  foreign bass → `calculateFretsWithBass` with the bass note
  transposed alongside the root).

When adding a new consumer that selects voicings, **check both
tables**. Querying only `sbn_chord_diagrams` silently misses
re-spellings — your candidate pool will be smaller than it
should be and Viterbi won't see the free-lunch alias options.

### Editor caveats

- The chord-shape editor does **not** validate that
  `root_note` matches the lowest-fingered position.
- Saving a shape doesn't recompute `start_fret` from the
  positions (it accepts whatever the form submits).
- Aliases must be hand-curated; no auto-suggestion of
  re-spellings exists.

The validator/auto-derive workflow is queued for a later cleanup
phase. Until then, when adding new shapes, manually verify that
`root_note` matches the lowest position's pitch and that
`start_fret` reflects the actual minimum fret. The
`ChordSerializer` self-heal mitigates display issues but doesn't
fix the underlying data.

---

## LEADSHEET EDITOR + TAB EDITOR

Full documentation for the chord/tab editor (architecture, Vue component tree, working model, chord grid ops, voicing picker, keyboard shortcuts, audio engine, video sync, design system, file map, bugs, and improvements) is in [SBN-Admin-Chord-Tab-Editor-Reference.md](SBN-Admin-Chord-Tab-Editor-Reference.md).

Creation flows (blank sheet, from Jazz Standards DB / saved progression, rhythm-aware materialization, exercises, LLM lookup, audio transcription) are also in that doc.

---

## ENVIRONMENT

- **Laravel 11** on Windows via Laravel Herd at `C:\Users\info\sbn-app`
- **SQLite** at `C:\Users\info\sbn-app\database\sbn.db`
- Auth: `lucas@soulbossanova.com` / `changeme123`
- Alpine.js via CDN + `@alpinejs/collapse` plugin
- **Vue 3 + Vite** for tab editor + chord grid
- Fonts: DM Sans + JetBrains Mono + Crimson Text (Google Fonts) + Bravura SMuFL

### Vite / build workflow
- `npm run dev` for HMR during development
- `npm run build` at end of each session for production bundle
- Page reload without dev server = blank tab view

---

## SONNET vs OPUS GUIDE

**Sonnet** (translation, porting, well-defined tasks):
- All Phase 8/9 feature implementation
- Bug fixes with clear root cause (B2, B3, I3)
- UI/CSS work, Blade views, admin CRUD
- Vue component additions following established patterns

**Opus** (design, ambiguous architecture, novel algorithms):
- I1 (scoreVL weight rebalancing)
- I4 (beaming polish around tuplets — cross-cutting change across beam model, renderer, writer, parser)
- Phase 8 architecture decisions (public chord grid, tab viewer, auth)
- Any task where the right approach is genuinely unclear

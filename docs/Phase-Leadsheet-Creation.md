# Phase L — Leadsheet Creation (Admin)

**Status:** 🔄 ACTIVE — L1/L2/L2.5/L3 shipped; consolidating toward Jazz Standards DB
**Owner:** Admin / Leadsheet Editor
**Related:** [Phase-9-Leadsheet-Viewer.md](Phase-9-Leadsheet-Viewer.md), Progression Builder

---

## 0. Why this phase

Today the admin can **import** leadsheets (via shortcode/JSON paste) and **edit** existing ones, but cannot **create from scratch**. The only path to a new sheet is hand-authoring shortcode and submitting through the import flow. This phase closes that gap.

The work is split into three layers. Each ships independently.

```
L1   Manual blank-sheet creation     ──►  ✅ SHIPPED
L2   Progression-based draft         ──►  ✅ SHIPPED (simplifying to DB-stored sources only)
L2.5 Rhythm-aware materialization    ──►  ✅ SHIPPED
L3   Song Lookup (LLM)               ──►  ✅ SHIPPED (maintenance mode — not primary path forward)
L3a  Audio Transcription             ──►  ⚠️ EXPERIMENTAL (embedded in L3 modal)
L4   Source-driven extractors        ──►  ❄️ ON STANDBY
```

**Design principle (revised 2026-05-04):** for jazz standards, the canonical changes are *already known* — they belong in a local database, not behind an LLM API call. A **Jazz Standards DB** (~1400 songs) eliminates API cost, latency, and hallucination risk for the majority of the admin's workflow. The LLM lookup path (L3) remains operational for pop/rock songs not in the DB, but is in maintenance mode — not the focus of new investment. Audio transcription (L3a) is experimental and interesting but difficult to perfect; the Jazz Standards DB serves as structural anchoring for transcription quality improvements.

---

## 1. Current state (as-built)

Relevant files:
- [app/Http/Controllers/Admin/LeadsheetController.php](../app/Http/Controllers/Admin/LeadsheetController.php) — already has `create()` returning `admin.leadsheets.edit` with `leadsheet = null`, and `store()` that accepts `shortcode_content` / `json_data` / `tab_xml`. The route exists; it just isn't surfaced as a "blank canvas" UX.
- [app/Services/ProgressionBuilder.php](../app/Services/ProgressionBuilder.php) — voicing builder used by `applyProgression()`. Already turns a chord sequence into MusicXML + `chordVoicings` + `melody`.
- [app/Http/Controllers/Admin/ProgressionBuilderController.php](../app/Http/Controllers/Admin/ProgressionBuilderController.php) — UI for picking/building progressions; currently writes back to an *existing* leadsheet.
- [resources/views/admin/leadsheets/index.blade.php](../resources/views/admin/leadsheets/index.blade.php) — list page; needs a "+ New" entry point with a mode chooser.

Storage shape (already supported by `Leadsheet` model — see [app/Models/Leadsheet.php](../app/Models/Leadsheet.php)):
- `shortcode_content` — canonical `[sbn_leadsheet]…[/sbn_leadsheet]` body
- `json_data` — `{ sections, chordVoicings, melody, repeatMarkers, … }`
- `tab_xml` — MusicXML for tab/melody
- meta: `title`, `composer`, `song_key`, `tempo`, `time_signature`, `rhythm`, `measure_count`

**Implication:** no schema changes for L1, L2, or L3. All layers funnel into the same `store()` endpoint and the same JSON shape the editor already understands. L4 adds a `sbn_leadsheet_drafts` table (out of scope for now).

---

## 2. L1 — Manual blank sheet (MVP)

**Goal:** admin clicks "+ New", chooses "Blank", picks key/tempo/time-signature/section count/bars-per-section, lands in the existing editor with an empty grid.

### 2.1 Entry UX
- Add a **"+ New leadsheet"** dropdown button on the index page with four options:
  - Blank sheet (L1)
  - From progression (L2)
  - From song lookup (L3, may ship disabled until L3 lands)
  - From source… (L4, disabled placeholder)
- "Blank sheet" opens a modal collecting:
  - Title, composer (optional)
  - Key (default `C`), tempo (default `120`), time signature (default `4/4`), rhythm pattern (optional)
  - **Initial structure:** one of:
    - "N empty bars in one section" (single integer, default 16)
    - "Sectioned" — repeatable rows of `{ name, bar_count }` (default `Verse:8, Chorus:8`)
  - Optional pickup bar checkbox

### 2.2 Backend
- New controller method `LeadsheetController@createBlank` (keep `store()` import-shaped and uncluttered).
- New service `app/Services/LeadsheetScaffolder.php` with:
  - `scaffoldBlank(array $opts): array` → returns `{ shortcode_content, json_data }` with empty measures (`chords: []`) but proper section/measure scaffolding so the existing editor opens cleanly.
  - Output must be parser-symmetric: whatever `LeadsheetParser` emits for the equivalent hand-authored empty sheet is what the scaffolder must emit.
- `measure_count` populated from the scaffold.
- Redirect to `admin.leadsheets.edit`, exactly as `store()` does today.

### 2.3 Acceptance
- Creating a 16-bar blank in C/4-4 produces an editable grid with 16 empty measures and zero voicings.
- Saving without edits round-trips.
- Density toggle, section rename, add/delete measure, chord picker all work — no special-casing.
- No new columns, no migration.

### 2.4 Estimated size
~1 day. The editor already supports the empty state; this is mostly modal UI + scaffolder + index entry point.

A self-contained spec for a coding AI is in **§8 below**.

---

## 3. L2 — Builder-assisted draft

**Status:** ✅ SHIPPED | L2.5 ✅ SHIPPED | Simplifying to DB-stored sources only

**Goal:** start a new sheet pre-populated from a saved progression or a Jazz Standards DB entry.

> **Direction change (2026-05-04):** L2 originally shipped with 4 free-text source tabs (Free Input, ChordPro, Pipe Bars, Clone). These are being removed. The admin's real workflow is picking from a curated library — not parsing arbitrary chord text. L2 will expose only: (1) Saved `ChordProgression` entries, and (2) Jazz Standards DB entries (when built). The `ChordSequenceParser` service remains in the codebase as shipped code but is no longer exposed in the modal UI.

### 3.1 Sources (simplified)
1. **Saved `ChordProgression`** — dropdown from the Progression Builder's library; numerals + tonality already in DB. Resolves numerals to chord names via `NumeralResolver` in the selected key.
2. **Jazz Standards DB** *(future — see §5.1)* — when the Jazz Standards database is seeded, its entries appear as a second source category. Pick a standard → instant structure + chords with correct bar count, sections, and form labels. Zero API cost, zero hallucination.

> **Removed from scope:** Free Input, ChordPro paste, Pipe Bars, Clone Source. The shipped `ChordSequenceParser` and clone logic remain in the codebase but are no longer surfaced in the modal. Clone functionality may return as a standalone "Duplicate" action on the index page (see §11.1).

### 3.2 Flow
- "+ New" → "From progression" triggers a 2-step wizard modal (`resources/views/admin/leadsheets/_progression-modal.blade.php`):
  1. **Source step** — pick source type. Separate input tracking using Alpine `x-show`. Live preview counts.
  2. **Layout step** — configure bars per chord, key, tempo, time-sig, **rhythm pattern (see §3.6)**; optional "build voicings now" toggle.
- State is preserved during backwards navigation. Submit issues a standard form POST redirecting into the editor.


### 3.3 Code reuse
- New: `LeadsheetScaffolder::scaffoldFromSequence` (~30 lines).
- Reuse: `HarmonicContext` for numerals → chords in a key.
- **Refactor:** extract the body of `LeadsheetController::applyProgression` into a shared service `VoicingMaterializer` so it can target either an existing or a new leadsheet, and so it can accept an optional rhythm pattern (§3.6). This is a structural change L2 requires — and L2.5 + L3 both depend on it.

### 3.4 Acceptance
- ii–V–I in B♭, 2 bars/chord → 6 measures, correct chord names, optional voicings.
- Clone is byte-identical except `id`, `title`, `slug`, timestamps.
- "Cmaj7 Am7 Dm7 G7" with 1 bar/chord → 4 measures.
- ii–V–I in C with rhythm pattern `joao-gilberto-bossa` selected → measures contain stroke-by-stroke notes following the pattern, audibly matching the standalone rhythm-pattern player.

### 3.5 Estimated size
~2–3 days for L2 base. Add **~3 days for L2.5** (§3.6) on top.

---

### 3.6 L2.5 — Rhythm-aware voicing materialization

**Goal:** when a rhythm pattern is selected at draft time, the generated tab notes follow the pattern instead of being whole-note-per-chord. Picking "João Gilberto bossa" + "ii–V–I in C" produces a draft where each chord-bar is filled with the bossa thumb/fingers stroke pattern over the chosen voicing.

This is an extension of L2's "build voicings now" path, not a separate feature. The wizard gets one new field; the backend gets one new service.

#### 3.6.1 Why this is in scope (and not creep)

Three things make this small:
- **Data is already there.** [app/Models/RhythmPattern.php](../app/Models/RhythmPattern.php) stores `rhythm_pattern` (fingers) and `thumb_pattern` strings, `grid_type` (`eighth` | `sixteenth` | `triplet`), `beats`, `time_signature`. No schema change.
- **Frontend does this transformation already.** [resources/js/audio/adapters/rhythmPatternToEvents.js](../resources/js/audio/adapters/rhythmPatternToEvents.js) converts `(thumb, fingers, gridType, beats)` → beat-positioned events for playback. `RhythmMaterializer` is a server-side port of the same logic, emitting MusicXML notes + `melody[]` entries instead of audio events.
- **Whole-note path stays.** When no rhythm is selected, `VoicingMaterializer` runs the same loop it has today.

#### 3.6.2 Architecture

```
ProgressionBuilder ──► chord sequence
                           │
                           ▼
   ┌── VoicingMaterializer (extracted applyProgression body) ──┐
   │                                                            │
   │   for each chord-bar in sequence:                          │
   │     if rhythm:  RhythmMaterializer.expand(voicing, …)      │
   │     else:       single whole-note (current behavior)       │
   │                                                            │
   │   emits: melody[], MusicXML <note>s, harmony per measure   │
   └────────────────────────────────────────────────────────────┘
                           │
                           ▼
                   Leadsheet (json_data + tab_xml)
```

`RhythmMaterializer` is the only place new musical logic lives. It is a pure service — no DB calls, no HTTP, deterministic output for given inputs.

#### 3.6.3 `RhythmMaterializer` API

```php
namespace App\Services;

use App\Models\RhythmPattern;

class RhythmMaterializer
{
    /**
     * Expand a single bar of one chord into stroke events.
     * Always produces exactly one bar's worth of strokes regardless of
     * how many bars the chord spans — the caller loops per bar.
     *
     * @param array $voicing       ['frets' => 'x35453', 'position' => 5]
     *                              Convention: index 0 = low E (string 6), index 5 = high E (string 1)
     *                              Same as the rest of the codebase (see applyProgression).
     * @param RhythmPattern $pattern
     * @param int $divisions       MusicXML divisions per quarter note (typically 480)
     * @param int $beatsPerMeasure From sheet's time signature numerator (e.g. 4 for 4/4)
     *
     * @return array<int, array{
     *   tickOffset: int,    // ticks from start of bar
     *   durTicks:   int,    // length of stroke (tick to next stroke or bar end)
     *   durName:    string, // 'q' | 'e' | 's' | 'h' | 'w' — matches useTabModel duration codes
     *   strings:    int[],  // tab string numbers to sound (1=high E … 6=low E)
     *   accent:     bool,
     *   velocity:   float,  // 0.0–1.0 (currently informational; not encoded into MusicXML in v1)
     * }>
     */
    public function expand(
        array $voicing,
        RhythmPattern $pattern,
        int $divisions,
        int $beatsPerMeasure
    ): array;
}
```

#### 3.6.4 Stroke-generation rules (v1)

Mirrors `rhythmPatternToEvents.js` so playback and notation stay aligned.

- **Step length in beats by `gridType`:** `eighth = 0.5`, `sixteenth = 0.25`, `triplet = 1/3`. Step in ticks = `stepBeats * divisions`.
- **Iterate `pattern->rhythm_pattern` (fingers) char-by-char:**
  - `.` → no stroke at this step.
  - `x` → stroke, soft, `accent: false`, `velocity: 0.85`.
  - `X` → stroke, accented, `accent: true`, `velocity: 1.0`.
  - **Strings sounded:** by default the **treble strings** of the voicing — voicing indexes 3, 4, 5 (= tab strings 3, 2, 1). Skip muted strings (`x` in fret string) and pass through frets unchanged.
- **Iterate `pattern->thumb_pattern` (bass) char-by-char** with the same rules but **bass strings** of the voicing — voicing indexes 0, 1, 2 (= tab strings 6, 5, 4), skipping muted.
- **`is_strum` patterns** (detect via `category` containing `strum` or a future explicit flag): a single stroke sounds **all 6 voicing strings** (skipping muted), and `thumb_pattern` is ignored. v1 includes this branch so basic pop strumming works.
- **Stroke duration:** `stepBeats * divisions` ticks (i.e. fills until the next grid step). Last stroke fills to bar end. Simple, audibly correct, no ties needed in v1.
- **Output ordering:** ascending `tickOffset`. When thumb and fingers strokes coincide, emit thumb first then fingers (caller will mark fingers note as `<chord/>` so MusicXML stays valid).

#### 3.6.5 `VoicingMaterializer` integration

The extracted service signature:

```php
public function materialize(
    array $sequence,                  // [['chord_name' => 'Dm7', 'frets' => 'x57565', 'position' => 5, 'bars' => 2], …]
    string $timeSignature,            // '4/4'
    ?RhythmPattern $rhythm = null,    // ← new optional parameter
): array;                             // ['xml' => string, 'melody' => array, 'chordVoicings' => array, 'measures' => int]
```

When `$rhythm` is null, the existing whole-note loop runs unchanged. When set, the inner loop becomes:

```
for each chord in sequence:
    for each bar in chord.bars:
        strokes = RhythmMaterializer.expand(chord.voicing, $rhythm, $divisions, $beatsPerMeasure)
        emit one <harmony> at start of bar
        for each stroke in strokes:
            emit one <note> per string in stroke.strings
                (first note plain, subsequent notes wrapped in <chord/>)
            push corresponding entry to melody[]
```

`<harmony>` stays at the bar level (one per measure, MusicXML-correct). `chordVoicings` is populated as today — keyed by chord name, one entry per unique chord.

#### 3.6.6 Wizard UI change

Layout step gains one field:

| Field | Type | Required | Default | Notes |
|---|---|---|---|---|
| `rhythm_pattern` | select | no | none | populated from `RhythmPattern::ordered()->get()`, grouped by `category`. "None (whole notes)" is the first option. |

Below the select, render a tiny preview line: `"8 strokes per bar × 4 bars × 3 chords = 96 strokes"` so the user knows what they're about to generate. Disable the select if "build voicings now" is off — the rhythm only applies when voicings are being built.

The selected slug is also written to `Leadsheet.rhythm` so the editor's existing rhythm-aware playback path picks it up on reload (no special re-materialization needed because the strokes are already encoded in `tab_xml`).

#### 3.6.7 What v1 does NOT do

Documented up front so the implementer doesn't widen scope:
- **Voicing-aware bass-note detection.** Thumb always hits voicing indexes 0–2 in v1, regardless of whether the actual bass note of the chord is on string 4 or 5. Acceptable for the existing pattern library.
- **Ties across rests.** Strokes are independent; sustain-by-tying is v2.
- **Swing/triplet feel beyond `gridType: triplet`.** No swing-eighths transformation in v1.
- **Palm mute, ghost notes, dynamics in MusicXML.** `velocity` and `accent` are computed but not written to MusicXML in v1 (could become `<dynamics>` later).
- **Patterns longer than one bar.** v1 assumes pattern fits one bar of the sheet's time signature. If `pattern->time_signature !== sheet->time_signature`, the wizard either filters that pattern out of the dropdown or shows a "not compatible" warning — pick one at PR time.

#### 3.6.8 Tests

`tests/Unit/RhythmMaterializerTest.php`:
- `it_emits_no_strokes_for_an_all_dots_pattern`
- `it_emits_eighth_grid_strokes_at_correct_ticks` — given `'X.x.X.x.'` eighth grid, expect strokes at offsets `0, divisions, 2*divisions, 3*divisions`.
- `it_separates_thumb_strings_4_to_6_from_finger_strings_1_to_3` — given a thumb-only pattern, all output strokes hit only bass strings.
- `it_skips_muted_voicing_strings` — voicing `xx0232` (D major), thumb stroke on bass strings emits only string 4 (others muted).
- `it_handles_strum_patterns_as_full_voicing_hits` — pattern flagged as strum sounds all non-muted strings on every stroke.
- `it_marks_capital_X_as_accent_with_higher_velocity`

`tests/Unit/VoicingMaterializerRhythmTest.php`:
- `it_falls_back_to_whole_notes_when_no_rhythm_supplied` — output equals current `applyProgression` byte-for-byte.
- `it_produces_one_harmony_per_bar_regardless_of_stroke_count`
- `it_produces_n_strokes_per_bar_matching_the_pattern`
- `it_writes_rhythm_slug_to_leadsheet_when_persisting`

#### 3.6.9 Why this empowers L3

L3's LLM lookup can return a `rhythm` hint (`"bossa nova, eighth-note alternating thumb"`). That hint maps to a `RhythmPattern` slug (or "closest match" via category + `gridType`) and feeds straight into `VoicingMaterializer` — no L3-specific rhythm code. The mapping logic is one lookup table in the L3 service.

This is the right shape: L2.5 builds the rhythm engine once, L3 just selects from it.

#### 3.6.10 L2.5 implementation spec (ready for a coding AI)

> **Briefing for the implementer:** this section is self-contained. You don't need to read all of §3.6 to ship — but §3.6.4 (stroke-generation rules) is the musical core and §3.6.7 ("what v1 does NOT do") is the scope fence. Do the L2 cleanup tasks in §3.7 in a separate prior commit so the L2.5 diff is clean.

##### A. Goal
Add a "Rhythm pattern" select to the L2 progression-modal's Layout step. When the user picks a pattern **and** "build voicings now" is on, the resulting leadsheet's `tab_xml` and `melody[]` are filled with stroke-by-stroke notes following the pattern, instead of one whole note per chord. When no pattern is picked, behavior is byte-identical to L2 today.

##### B. In scope
- New service `app/Services/RhythmMaterializer.php` (the §3.6.3 API).
- Modify `app/Services/VoicingMaterializer.php` to accept and use the existing `?RhythmPattern $rhythm = null` parameter (currently accepted but ignored).
- Add the `rhythm_pattern` select to `resources/views/admin/leadsheets/_progression-modal.blade.php`.
- Wire the selected slug through `LeadsheetController::createFromSequence` to `VoicingMaterializer::materialize`.
- Persist the slug to `Leadsheet.rhythm` so reload-rendering works.
- Tests for `RhythmMaterializer` and the rhythm-aware path of `VoicingMaterializer`.

##### C. Out of scope (do NOT do)
- Swing feel, ties across rests, palm mute, ghost notes, dynamics-as-MusicXML, voicing-aware bass-note detection, multi-bar patterns. See §3.6.7 — these are v2 concerns.
- Editor changes. The strokes ride the existing `melody[]` + MusicXML path; the editor cannot tell the difference.
- Changes to L1 paths (`createBlank`, `scaffoldBlank`).
- Refactoring `applyProgression` (the existing in-place admin endpoint). It already calls `VoicingMaterializer` indirectly via the L2 extraction; if it doesn't yet, scope that as part of the L2 cleanup (§3.7), not L2.5.
- Any L3 work or `RhythmHintMapper` (that's L3's problem).

##### D. Files to create
- `app/Services/RhythmMaterializer.php` — the API in §3.6.3.
- `tests/Unit/RhythmMaterializerTest.php` — tests in §3.6.8 (first list).
- `tests/Unit/VoicingMaterializerRhythmTest.php` — tests in §3.6.8 (second list).

##### E. Files to modify
- `app/Services/VoicingMaterializer.php` — replace the inner per-chord whole-note loop with a per-bar loop that delegates to `RhythmMaterializer` when `$rhythm` is non-null.
- `app/Http/Controllers/Admin/LeadsheetController.php` — `createFromSequence`: accept `rhythm` from request (already validated), resolve `RhythmPattern` by slug, pass to `VoicingMaterializer::materialize`.
- `resources/views/admin/leadsheets/_progression-modal.blade.php` — add the rhythm select on the Layout step (UX in §3.6.6).

##### F. Concrete VoicingMaterializer change

Today's loop (whole-note path) is one `<note>` per fret per chord. The L2.5 change replaces that with:

```php
// Pseudocode — adapt to the existing variable names in VoicingMaterializer
foreach ($selections as $sel) {
    $bars = max(1, (int) ($sel['bars'] ?? 1));
    $voicing = ['frets' => $sel['frets'], 'position' => $sel['position'] ?? 1];
    $chordVoicings[$sel['chord_name']] = $voicing;

    for ($bar = 0; $bar < $bars; $bar++) {
        $barStartTick = $globalTick;
        $harmonyXml = /* same as today, one per bar */;
        $notesXml   = '';

        if ($rhythm) {
            $strokes = $this->rhythmMaterializer->expand($voicing, $rhythm, $divisions, $beats);
            foreach ($strokes as $stroke) {
                // emit one <note> per string in $stroke['strings'] at $barStartTick + $stroke['tickOffset']
                // first note plain; subsequent get <chord/>
                // push parallel melody[] entries with same tick/string/fret/duration
            }
            if (empty($strokes)) {
                // fallback: rest for the bar (defensive — shouldn't happen with valid patterns)
                $notesXml = $this->buildRestForBar($durTicks, $durType);
            }
        } else {
            // existing whole-note logic, untouched
        }

        $measuresXml .= '<measure number="' . $measureNum . '">' . $attrs . $harmonyXml . $notesXml . '</measure>';
        $globalTick += $tpm;
        $measureNum++;
    }
}
```

Inject `RhythmMaterializer` via the constructor. `VoicingMaterializer` was a no-arg-constructor service; now becomes:

```php
public function __construct(private RhythmMaterializer $rhythmMaterializer) {}
```

Laravel's container will resolve it; existing call sites don't change because they `app()->make(VoicingMaterializer::class)` or are constructor-injected.

##### G. Modal change

In `_progression-modal.blade.php`, on the Layout step:

```html
<div x-show="layout.buildVoicings" class="form-row">
  <label for="rhythm_pattern">Rhythm pattern</label>
  <select name="rhythm_pattern" id="rhythm_pattern" x-model="layout.rhythmPattern">
    <option value="">None (whole notes per chord)</option>
    @foreach($rhythms->groupBy('category') as $category => $patterns)
      <optgroup label="{{ ucfirst($category) }}">
        @foreach($patterns as $p)
          <option value="{{ $p->slug }}">{{ $p->name }}</option>
        @endforeach
      </optgroup>
    @endforeach
  </select>
  <p class="form-hint" x-show="layout.rhythmPattern" x-text="rhythmPreviewText()"></p>
</div>
```

The `rhythmPreviewText()` Alpine method computes `"<strokesPerBar> strokes per bar × <bars> bars × <chords> chords = <total> strokes"` from the parsed sequence. Strokes-per-bar = count of non-`.` chars in the pattern's `rhythm_pattern` + `thumb_pattern`. Best-effort; doesn't need to be exact.

The `$rhythms` variable is already passed to the index view (see [LeadsheetController::index](app/Http/Controllers/Admin/LeadsheetController.php) — added during L2). No new data plumbing.

##### H. Controller change

In `createFromSequence`:

```php
$rhythmModel = null;
if (!empty($validated['rhythm']) && !empty($validated['build_voicings'])) {
    $rhythmModel = RhythmPattern::where('slug', $validated['rhythm'])->first();
    // If not found, silently fall back to whole notes — don't 500.
}

$materialized = $materializer->materialize($selectionsClean, $validated['time_signature'], $rhythmModel);
```

The `rhythm` field in the validation block already exists (`'rhythm' => 'nullable|string|max:50'`). Just thread it through. The existing `Leadsheet.rhythm` column already gets the slug (from `$validated['rhythm'] ?? ''`), so reload-rendering works for free.

##### I. Validation rules to verify before merging
- **Time-signature compatibility:** when the modal first opens, default the rhythm select to "None" *and* filter the dropdown to patterns where `pattern.time_signature === sheet.time_signature` (or include all and add an `optgroup` for "Other time signatures"). Pick one approach at PR time and document the choice in the PR description. Don't ship without this — picking a 6/8 pattern for a 4/4 sheet will produce nonsense.
- **`build_voicings` gating:** if the rhythm select has a value but `build_voicings` is false, ignore the rhythm. The modal should also disable/hide the rhythm select when `build_voicings` is off (already specified in §3.6.6).
- **Round-trip:** create a sheet with a rhythm pattern, save, reload in the editor. The strokes must play back identically through the existing audio engine path (which reads `tab_xml` + the `Leadsheet.rhythm` slug independently). If audio diverges from the standalone rhythm-pattern player, the bug is almost certainly in the string-numbering convention — see §3.6.3 docblock.

##### J. Tests (concrete)

`tests/Unit/RhythmMaterializerTest.php` — cover §3.6.8 first list. Concrete shapes:

```php
// Example: thumb-only quarter-note pattern → bass strings only
public function test_separates_thumb_strings_4_to_6_from_finger_strings_1_to_3(): void
{
    $pattern = new RhythmPattern([
        'thumb_pattern'  => 'X.x.X.x.',
        'rhythm_pattern' => '........',
        'grid_type'      => 'eighth',
        'beats'          => 8,
        'time_signature' => '4/4',
    ]);
    $voicing = ['frets' => 'x35453', 'position' => 5]; // Cmaj7
    $strokes = (new RhythmMaterializer)->expand($voicing, $pattern, 480, 4);

    foreach ($strokes as $s) {
        foreach ($s['strings'] as $str) {
            $this->assertGreaterThanOrEqual(4, $str, 'thumb strokes must be on bass strings (4–6)');
        }
    }
}
```

`tests/Unit/VoicingMaterializerRhythmTest.php` — cover §3.6.8 second list. The `it_falls_back_to_whole_notes_when_no_rhythm_supplied` test is the most important: capture the current `applyProgression`-equivalent output as a fixture, then assert byte equality when `materialize($selections, $ts, null)` is called. This guards against accidental whole-note-path regressions.

##### K. PR checklist
- [ ] `RhythmMaterializer` created with the §3.6.3 API; pure (no DB, no HTTP).
- [ ] Unit tests in §3.6.8 (first list) all pass.
- [ ] `VoicingMaterializer` accepts and uses `?RhythmPattern $rhythm`. Whole-note path is byte-identical when `$rhythm` is null (verified by fixture test).
- [ ] Unit tests in §3.6.8 (second list) all pass.
- [ ] Rhythm select rendered on the Layout step; preview line shows expected stroke count; select gated by `build_voicings`.
- [ ] Time-signature filter logic implemented (and documented in PR description).
- [ ] End-to-end: create "ii–V–I in C" with `joao-gilberto-bossa` selected; reload in editor; audio playback matches the standalone rhythm-pattern player.
- [ ] No L1 regressions (blank-sheet creation still works).
- [ ] No L2 regressions (no-rhythm path still produces whole notes).
- [ ] No DB migration introduced.

##### L. Things NOT to do
- Don't add multi-bar patterns. If a `RhythmPattern` exists with `beats > 8` for sixteenth grid (i.e. > 1 bar at 4/4), filter it out of the dropdown for now and add a note to the deferred-features section (§11).
- Don't compute or write `<dynamics>` to MusicXML in v1. `accent` and `velocity` go into the `melody[]` entries (or get ignored); MusicXML stays as it is for L2.
- Don't touch the editor's tab/voicing rendering paths. If something looks wrong after creation, the bug is in `RhythmMaterializer` or the controller wire-up.
- Don't change the L1 blank-sheet flow or its scaffolder.
- Don't introduce a queue/job for rhythm materialization. It's deterministic and fast — synchronous is correct.

### 3.7 L2 As-Built Notes & Cleanup Tasks

**Shipped surface:**
- `app/Services/VoicingMaterializer.php` — extracted from `LeadsheetController::applyProgression`; same divisions, string convention, melody shape. Accepts an optional `$rhythm` parameter (currently ignored — wired in L2.5).
- `app/Services/ChordSequenceParser.php` — three-way auto-detection (`|` → bars, `[…]` → ChordPro, else whitespace). Validates via `ProgressionDetector::parseChordName`; falls back to `?` for invalid tokens.
- `app/Services/LeadsheetScaffolder::scaffoldFromSequence` — sequence → measures.
- `LeadsheetController::createFromSequence` — wires scaffolder + parser + materializer together.
- `resources/views/admin/leadsheets/_progression-modal.blade.php` — Alpine 2-step wizard.
- Tests: `tests/Unit/ChordSequenceParserTest.php`, `tests/Feature/LeadsheetProgressionTest.php`.

**Preview rules (as built):**
- Chord sequence count, validation warnings, suggested measure count — client-side, debounced.
- Numeral resolution preview — server roundtrip via `POST /admin/progressions/resolve-numerals`.
- No chord-diagram previews in the wizard.

**Cleanup tasks:**

1. ~~**Remove unused constructor parameter.**~~ ✅ DONE — constructor no longer accepts `LeadsheetScaffolder`.

2. ~~**Consolidate numeral detection.**~~ ✅ DONE — `NumeralResolver` service created; controller calls one method.

3. ~~**Deduplicate slug generation.**~~ ✅ DONE — `Leadsheet::generateUniqueSlug()` extracted; both controller methods use it.

4. ~~**Verify empty-`chordVoicings` cast.**~~ ✅ DONE — both scaffolder paths cast to `(object)[]`.

5. ~~**Voicing-style selector.**~~ ✅ DONE — modal exposes popular/shell/drop2/archetype select; default is `popular`.

6. **Saved Progression source — DIRECTION CHANGED.** The original spec asked for a 5th tab. New direction (2026-05-04): remove the 4 free-text tabs entirely, make Saved Progression the *primary* (and initially only) source. Jazz Standards DB entries become the second source when built. See revised §3.1.

**Deferred from L2 (see §11):** clone-source redesign (may become standalone "Duplicate" button on index) and key transposition for concrete chords.

---

### 3.8 L2 + L2.5 polish spec (ready for a coding AI)

> **Briefing for the implementer:** this section bundles the §3.7 cleanup tasks with two genuine spec gaps (Saved Progression source, voicing-style selector) and one small `RhythmMaterializer` fix. Single PR. None of these need a migration. Keep the diff small — no editor changes, no `applyProgression` rewrites, no L3 work.

#### 3.8.1 Goal

Polish L2 + L2.5 so the modal matches the spec, voicings have user-controllable style, and rhythm output is musically clean.

#### 3.8.2 In scope (single PR)

- **A. Cleanup tasks** §3.7 #1–#4 (constructor param, numeral consolidation, slug helper, empty-`chordVoicings` cast).
- **B. Voicing-style selector** in the wizard (replaces the hardcoded `'shell'` category).
- **C. Saved Progression source** as the missing 5th source tab.
- **D. `RhythmMaterializer` thumb fix** — one string per stroke instead of all bass strings.

The duration / multi-bar / per-bar-iteration concerns from the post-L2.5 review are **deferred** — current rhythm output tested correct, so don't touch.

#### 3.8.3 Out of scope (do NOT do)

- Don't change the editor or any tab-rendering paths.
- Don't refactor `applyProgression` (the in-place admin endpoint).
- Don't add the clone-source redesign or key transposition (deferred — see §11.1, §11.2).
- Don't add L2.5 v2 features (ties, swing, dynamics, multi-bar patterns — see §11.3).
- Don't add voicing previews / chord diagrams in the modal.
- Don't introduce a queue, a migration, or an API change beyond the routes already in place.

#### 3.8.4 Files to modify

- `app/Http/Controllers/Admin/LeadsheetController.php`
- `app/Services/ChordSequenceParser.php` (or new `app/Services/NumeralResolver.php` — see A2)
- `app/Services/LeadsheetScaffolder.php` (verify A4)
- `app/Services/ProgressionBuilder.php` (B — see below)
- `app/Services/RhythmMaterializer.php` (D)
- `resources/views/admin/leadsheets/_progression-modal.blade.php`

#### 3.8.5 Files to create

- `app/Models/ChordProgression.php` — only if not already present. (Likely present — `ProgressionBuilderController` already references it. Verify before creating.)
- Test file(s) per §3.8.11.

---

#### Section A — Cleanup (§3.7 #1–#4)

**A1. Drop unused constructor parameter** in `LeadsheetController::__construct`. Remove the `LeadsheetScaffolder $scaffolder` argument. The two methods that need it already inject it method-level.

**A2. Consolidate numeral detection.** Move the regex `/^(b|#)?(III|iii|VII|vii|II|ii|IV|iv|VI|vi|I|i|V|v)(.*)$/` into one place. Two acceptable shapes:

- (preferred) New `app/Services/NumeralResolver.php` with two methods:
  ```php
  public function isNumeral(string $token): bool;
  public function resolveSequenceItems(array $items, string $key, bool $isBars): array;
  ```
  `resolveSequenceItems` walks the array (handling both flat sequence and nested bars shape), runs `numeralToChordName` for tokens where `isNumeral` returns true, and **leaves the original token in place if `numeralToChordName` returns falsy** (defensive fix).
- (acceptable) Same two methods on `ChordSequenceParser` if you'd rather not add a new file.

The controller's two duplicated regex blocks in `createFromSequence` collapse to one call:

```php
$parsedSequence['items'] = $resolver->resolveSequenceItems(
    $parsedSequence['items'],
    $key,
    $parsedSequence['mode'] === 'bars'
);
```

**A3. Deduplicate slug generation.** Both `createBlank` and `createFromSequence` carry this block:

```php
$slug = Str::slug($validated['title']);
$originalSlug = $slug;
$counter = 1;
while (Leadsheet::where('slug', $slug)->exists()) {
    $slug = $originalSlug . '-' . $counter++;
}
```

Extract to a static helper on the `Leadsheet` model:

```php
public static function generateUniqueSlug(string $title): string
```

Both controller methods call `Leadsheet::generateUniqueSlug($validated['title'])`. (Don't make this a Saving observer — the slug must exist before the row is created and the controller passes it explicitly.)

**A4. Verify `scaffoldFromSequence` casts empty `chordVoicings` to `(object)[]`.** Open `LeadsheetScaffolder` and check the no-voicings path. If `chordVoicings` is left as `[]`, change it to `(object)[]` (same fix L1's `scaffoldBlank` got — see §10). Run the existing scaffolder tests; add one assertion if missing:

```php
$this->assertSame('object', gettype(json_decode($result['json_data'])->chordVoicings));
```

---

#### Section B — Voicing-style selector

**B1. Backend: drop the hardcoded category default.**

In `app/Services/ProgressionBuilder.php`, current behavior of `selectVoicingsForSequence` is "filter to category=shell first, fall back to `$pool[0]` (popular)." Change to:

- If `$options['category']` is provided and non-empty: filter pool to that category, fall back to `$pool[0]` if no match.
- If `$options['category']` is empty/missing: skip the filter entirely, return `$pool[0]` (the most popular by `orderByDesc('popularity')`).

Concretely, replace:

```php
$preferCategory = $options['category'] ?? 'shell';
// ...
$preferred = array_filter($pool, fn($v) =>
    strcasecmp($v->voicing_category ?? '', $preferCategory) === 0
);
$pick = !empty($preferred) ? array_values($preferred)[0] : $pool[0];
```

with:

```php
$preferCategory = $options['category'] ?? '';
// ...
if ($preferCategory !== '') {
    $preferred = array_filter($pool, fn($v) =>
        strcasecmp($v->voicing_category ?? '', $preferCategory) === 0
    );
    $pick = !empty($preferred) ? array_values($preferred)[0] : $pool[0];
} else {
    $pick = $pool[0];
}
```

This makes "no preference → most popular" the natural default. Verify no other call sites of `selectVoicingsForSequence` rely on the implicit shell default — grep for `selectVoicingsForSequence(` and update callers if needed.

**B2. Controller: accept `voicing_style` from the request.**

In `createFromSequence`, add to the validator:

```php
'voicing_style' => 'nullable|string|in:popular,shell,drop2,archetype',
```

(Adjust the `in:` list to whatever values actually exist in `sbn_chord_diagrams.voicing_category`. Run a quick `SELECT DISTINCT voicing_category FROM sbn_chord_diagrams` and use exactly those values; `popular` should map to `''` — empty category to mean "no filter, most popular.")

Map `popular` → empty string when calling `selectVoicingsForSequence`:

```php
$style = $validated['voicing_style'] ?? 'popular';
$category = $style === 'popular' ? '' : $style;
$selections = $builder->selectVoicingsForSequence($chordsList, $key, ['category' => $category]);
```

**B3. Modal: voicing-style select.**

In `_progression-modal.blade.php`, replace the hint line "Voicings will be generated as standard shell voicings" with a real select shown when `buildVoicings` is true:

```html
<div class="sbn-form-group" x-show="buildVoicings">
  <label for="voicing_style">Voicing Style</label>
  <select id="voicing_style" name="voicing_style" class="sbn-select" x-model="voicingStyle">
    <option value="popular">Most popular</option>
    <option value="shell">Shell (3-note)</option>
    <option value="drop2">Drop-2</option>
    <option value="archetype">Archetype</option>
  </select>
  <div style="font-size: 11px; color: #6b7280; margin-top: 2px; padding-left: 2px;">
    "Most popular" picks the highest-popularity voicing for each chord regardless of category.
  </div>
</div>
```

Add to the Alpine state: `voicingStyle: 'popular',`. Default is `'popular'` — verified to give consistent results for jazz extensions.

**B4. Tests for B.**

`tests/Unit/ProgressionBuilderVoicingStyleTest.php`:
- `it_picks_most_popular_when_no_category_supplied` — pool has shell + drop2 + popular voicing; verify the highest-`popularity` row is picked when `category` is empty.
- `it_filters_to_requested_category_with_popular_fallback` — when `category=shell` and shell exists, pick shell; when `category=shell` and no shell exists, fall back to `$pool[0]`.

Feature test (extend existing `LeadsheetProgressionTest.php`):
- POST with `voicing_style=shell` produces a sheet whose `chordVoicings` use shell voicings where available.
- POST with `voicing_style=popular` (or omitted) produces a sheet whose `chordVoicings` are the per-chord most-popular.

---

#### Section C — Saved Progression source

**C1. View data.** Pass `$progressions` from `LeadsheetController::index` to the index view, alongside the existing `$rhythms` / `$cloneSources`:

```php
$progressions = ChordProgression::orderBy('category')->orderBy('name')
    ->get(['id', 'name', 'category', 'numerals', 'tonality']);
```

Add to the `compact()` call.

**C2. Modal: 5th source tab.**

In `_progression-modal.blade.php`, add a fifth `.sbn-tab-btn` to the source-tabs row:

```html
<button type="button" class="sbn-tab-btn" :class="{ 'active': sourceType === 'progression' }" @click="setSourceType('progression')">Saved Progression</button>
```

Add a corresponding input section, shown when `sourceType === 'progression'`:

```html
<div class="sbn-form-group" x-show="sourceType === 'progression'">
  <label for="progression_id">Saved Progression <span class="required">*</span></label>
  <select id="progression_id" class="sbn-select" x-model="progressionId" @change="updateProgressionPreview">
    <option value="">— Pick a progression —</option>
    @foreach($progressions->groupBy('category') as $category => $items)
      <optgroup label="{{ ucfirst($category) }}">
        @foreach($items as $p)
          <option value="{{ $p->id }}" data-numerals="{{ $p->numerals }}" data-tonality="{{ $p->tonality }}">{{ $p->name }} ({{ $p->numerals }})</option>
        @endforeach
      </optgroup>
    @endforeach
  </select>
  <input type="hidden" name="progression_id" :value="progressionId">
  <div class="sbn-preview-box" x-show="progressionPreview" style="margin-top: 8px;">
    <strong>Numerals:</strong> <span x-text="progressionPreview"></span>
  </div>
</div>
```

Alpine state additions:
```js
progressionId: '',
progressionPreview: '',
allProgressions: @json($progressions),

updateProgressionPreview() {
    const p = this.allProgressions.find(x => String(x.id) === String(this.progressionId));
    this.progressionPreview = p ? p.numerals : '';
},
```

`canProceed` getter: extend to require a selected progression when `sourceType === 'progression'`.

**C3. Controller: progression branch.**

In `createFromSequence`, add to the validator:

```php
'progression_id' => 'nullable|integer|exists:sbn_progressions,id',
```

(Or whatever the actual table name is — `ChordProgression` model defines it.)

Add a branch alongside `clone`:

```php
if ($validated['source_type'] === 'progression' && !empty($validated['progression_id'])) {
    $progression = ChordProgression::findOrFail($validated['progression_id']);

    // Numerals string like "IIm7,V7,Imaj7" → array
    $numerals = array_values(array_filter(array_map('trim', explode(',', $progression->numerals))));

    $parsedSequence = [
        'mode' => 'sequence',
        'items' => $numerals,
        'invalid_count' => 0,
    ];
    // Numerals will be resolved to chord names by NumeralResolver in the existing block (A2).
}
```

Place this **before** the existing `clone` branch (or in an `elseif` chain) so it's reached cleanly. The numerals get resolved against the wizard's `song_key` by the consolidated A2 resolver — no special handling needed.

**C4. Tests for C.**

Feature test in `LeadsheetProgressionTest.php`:
- POST with `source_type=progression`, `progression_id=<seeded-id>`, `song_key=Bb` → leadsheet created with chord names resolved into B♭ from the progression's numerals. Assert chord count matches numeral count and key/title match input.

---

#### Section D — `RhythmMaterializer` thumb fix

**D1. The bug.** Currently every thumb stroke sounds **all** non-muted bass strings of the voicing simultaneously (`'strings' => $availableBass`). For a Cmaj7 voicing `x35453`, that's strings 5+4 on every thumb beat — sounds like a half-strum, not a fingerstyle thumb.

**D2. The fix.** Thumb fires **one** string per stroke: the **lowest non-muted bass string** of the voicing (= the first non-`x` character in the fret string when reading low-E → high-E, i.e. voicing index 0 → 1 → 2). One-line change in `expand()`:

```php
// in the thumb processing loop, replace:
'strings' => $availableBass,
// with:
'strings' => empty($availableBass) ? [] : [min($availableBass)],
```

Wait — `$availableBass` holds *tab string numbers* (1–6, where lower index = higher pitch). The lowest-pitch string has the **highest** number. So `[max($availableBass)]` is correct (gives string 6 for a voicing where it's not muted, else 5, else 4).

Verify by re-reading the build of `$availableBass` in the current code:

```php
$stringNum = 6 - $i;   // i=0 → string 6 (low E), i=2 → string 4 (D)
if ($i >= 3) {
    $availableTreble[] = $stringNum;
} else {
    $availableBass[] = $stringNum;   // strings 4, 5, 6
}
```

Confirmed: bass strings are 4–6, where 6 is the lowest pitch. Use `max()`:

```php
'strings' => empty($availableBass) ? [] : [max($availableBass)],
```

**D3. No new tests required for this** — extend `RhythmMaterializerTest::it_separates_thumb_strings_4_to_6_from_finger_strings_1_to_3`:

```php
// Existing assertion stays.
// Add:
foreach ($strokes as $s) {
    if ($s['is_thumb'] ?? false || in_array(6, $s['strings']) || in_array(5, $s['strings']) || in_array(4, $s['strings'])) {
        $this->assertCount(1, $s['strings'], 'thumb strokes must hit exactly one string');
    }
}
```

(Adjust if `is_thumb` was unset at return time — it currently is. Detect thumb strokes by checking that all strings are in {4,5,6}.)

Also add a positive test:

```php
public function test_thumb_fires_lowest_non_muted_bass_string(): void
{
    $pattern = new RhythmPattern([
        'thumb_pattern'  => 'X.......',
        'rhythm_pattern' => '........',
        'grid_type'      => 'eighth',
        'beats'          => 8,
        'time_signature' => '4/4',
        'category'       => 'fingerstyle',
    ]);

    // Cmaj7: x35453 → low E muted, A=3, D=5 → bass strings = [5, 4], lowest = 5
    $strokes = (new RhythmMaterializer)->expand(
        ['frets' => 'x35453', 'position' => 5],
        $pattern, 480, 4
    );
    $thumbs = array_filter($strokes, fn($s) => count($s['strings']) === 1 && $s['strings'][0] >= 4);
    $this->assertNotEmpty($thumbs);
    foreach ($thumbs as $t) {
        $this->assertSame([5], $t['strings'], 'expected lowest non-muted bass string (A = string 5) for x35453');
    }
}
```

#### 3.8.6 Validation rules to verify before merging

- Existing `LeadsheetProgressionTest` and `RhythmMaterializerTest` and `VoicingMaterializerRhythmTest` still pass unchanged (except for assertions you intentionally extend).
- `it_falls_back_to_whole_notes_when_no_rhythm_supplied` still passes — D1 only changes the rhythm-on path.
- A4: open the L1 blank-sheet flow and the L2 progression flow, confirm both still write `chordVoicings: {}` (object, not array) when no voicings are built.
- B3: switching `voicing_style` in the modal and submitting produces visibly different voicings in the editor for chords like `Dm7`/`G7`/`Cmaj7` where multiple categories exist in the DB.
- C2: switching to "Saved Progression" tab, picking a seeded progression, submitting in a different key produces a sheet whose chords are the progression's numerals resolved into the chosen key.
- D2: a created sheet using a fingerstyle pattern shows single thumb notes on bass strings (not chord-stacks of bass strings).

#### 3.8.7 PR checklist

- [ ] A1: constructor parameter dropped.
- [ ] A2: numeral regex appears in exactly one file.
- [ ] A3: `Leadsheet::generateUniqueSlug` exists and both controller methods call it.
- [ ] A4: empty `chordVoicings` are objects in both scaffolder paths.
- [ ] B1: `selectVoicingsForSequence` defaults to most-popular when no category supplied.
- [ ] B2: controller validates and threads `voicing_style`.
- [ ] B3: modal renders the voicing-style select; default = "popular".
- [ ] B4: tests added.
- [ ] C1: `$progressions` passed to view.
- [ ] C2: 5th source tab + select rendered; preview shows numerals.
- [ ] C3: controller branch resolves progression numerals via the consolidated NumeralResolver.
- [ ] C4: feature test added.
- [ ] D2: thumb stroke = single lowest-bass string; existing tests pass.
- [ ] No DB migration introduced.
- [ ] L1 blank-sheet flow still works.
- [ ] L2 no-rhythm + no-voicings flow still works.

#### 3.8.8 Things NOT to do

- Don't change `voicing_category` values in the DB. Use whatever exists.
- Don't introduce per-chord voicing overrides in the modal (that's L4-style polish).
- Don't replace the source-tab UI with a different control (radios, etc.) — keep tabs to match the current pattern.
- Don't fix the deferred clone-source spec drift (§11.1) in this PR — it's a separate decision.
- Don't rewrite `RhythmMaterializer` beyond the one thumb-strings line.
- Don't touch `LeadsheetParser`.

#### 3.8.9 Post-ship calls (resolved)

Decisions made after the L2.5 polish PR landed; minor cleanup, not blocking L3.

1. **Voicing-style validator narrowed** to the four modal options (`popular | shell | drop2 | archetype`). The shipped PR widened it to 11 values without DB verification; collapse back to the modal-exposed set so users can't pick categories that silently fall through to `$pool[0]`. When the modal exposes more options later, widen the validator at the same time.
2. **`json_data.rhythmPattern` kept (intentional improvement over spec).** The shipped PR adds `$jsonDataArray['rhythmPattern'] = $rhythmModel->toPlayerData()` when a rhythm is selected. This matches the shape the editor's playback path consumes and avoids a slug-keyed re-fetch on load. Treat as load-bearing; do not remove. (Original §3.6.6 said "the slug write to `Leadsheet.rhythm` is enough" — that was wrong; the pattern body needs to ride along.)
3. **Cosmetic blanks** in `LeadsheetController.php` (between constructor signature and brace, between `use` blocks) — sweep in the next L-area PR. Not blocking.
4. **Numeral regex stricter than before** in `NumeralResolver` — verified against `ChordSequenceParserTest`; no test relied on garbage-tail tokens being accepted.

---

## 4. L3 — Song Lookup (LLM)

**Status:** ✅ SHIPPED | ⚠️ MAINTENANCE MODE — functional, not primary path forward

**Goal:** admin types a song title (+ optional artist/version hint) and lands in the editor with a structurally-correct draft — key, tempo, time-sig, sections, chords per bar — pulled from the LLM's training data.

> **Honest assessment (2026-05-04):** L3 works for generating chord grids but results require manual verification. For jazz standards (~90% of the admin's real workflow), a **local Jazz Standards DB** would produce the same output instantly, for free, with zero hallucination. For pop/rock, the LLM path is the only automated option but is used infrequently. Quick Draft and Transcribing Assistant modes are merged into a single lookup — the distinction was artificial and added UI complexity without proportional value. Audio transcription (mode=audio) remains embedded in the same modal as an experimental feature.
>
> This path is kept operational but is **not the focus of new investment.** The Jazz Standards DB is the better answer for the core use case.

### 4.1 Why this works (and where it doesn't)
For the actual songs the admin teaches:
- **Jazz/bossa standards** ("Alone Together", "Summertime", "Wave"): LLMs are reliable because training data is saturated. **However, a local DB is better** — same data, zero cost, zero latency, zero hallucination risk.
- **Pop/rock for students** ("Shape of You", "Wonderwall"): This is where L3 still has genuine value — these songs aren't in standard jazz databases.

The original Quick Draft / Transcribing Assistant split is collapsed into one mode. The "assistant" research features (notable versions, voicing hints, suggested videos) remain specced in §4.9.21 but are not prioritized — they can be revisited if the LLM path proves more valuable than expected.

### 4.2 Entry UX
- "+ New" → "From song lookup" opens a modal:
  - **Required:** Song title.
  - **Optional:** Artist/version hint (`"Herb Ellis / Oscar Peterson"`, `"acoustic Ed Sheeran version"`, `"Real Book changes"`).
  - **Optional preferences:** preferred key (else use canonical/most-common), version preference (`real_book` / `original` / `most_common`).
- Submit triggers a backend call (synchronous if fast, queue + polling if slow). On completion, lands in editor with draft populated and a banner showing **`source_note`** ("Real Book 6th edition changes; key transposed to F to match preference").

### 4.3 Backend pipeline
```
LookupRequest ──► LLM (web search enabled, JSON schema)
              ──► IntermediateAnalysis
              ──► AnalysisToLeadsheet ──► sections/measures/chords + meta
              ──► (optional) VoicingMaterializer ──► chordVoicings/melody/tab_xml
              ──► Leadsheet row
              ──► editor
```

- **`IntermediateAnalysis` contract** (new, shared with future L4 extractors):
  ```
  {
    title, composer?, key, tempo?, timeSignature,
    sections: [{ name, bars: [{ chords: [{ label, beats }] }] }],
    melody?: [...],            // optional, future
    rhythm_hint?: string,      // freeform LLM suggestion ("bossa nova, alternating thumb")
    rhythm_slug?: string,      // L3 mapper's best-match RhythmPattern slug (or null)
    source_note: string,       // human-readable provenance
    confidence: 'high'|'medium'|'low',
    alternatives?: [...]       // other valid versions the LLM saw
  }
  ```
- **`AnalysisToLeadsheet`** (new service): turns the contract into the existing `json_data` + `shortcode_content` shape. Identical output surface to L1/L2 scaffolders — the editor cannot tell which path produced the sheet.
- **LLM call**: Claude API with web search tool + strict JSON schema response. System prompt enforces "return the most commonly taught version; cite which version in `source_note`; if uncertain, set `confidence: low` and return `alternatives`."

### 4.4 Connection to Progression Builder (L2 ↔ L3 bridge)
After the LLM returns a chord sequence, the user gets the **same "build voicings now?" toggle** as L2, plus the **same rhythm-pattern selector** (§3.6). If "build voicings now" is on:
- The chord sequence is fed to `VoicingMaterializer` → voicings + `tab_xml` populated.
- If a rhythm slug was returned (or the user picked one in the modal), `VoicingMaterializer` calls `RhythmMaterializer` per bar — same path as L2.5. No L3-specific rhythm code.
- Style/extensions/root-only options exposed inline in the lookup modal, mirroring the existing Progression Builder UI.

A small `RhythmHintMapper` service translates the LLM's `rhythm_hint` string into a `RhythmPattern` slug (lookup table on category + `gridType`; falls back to null and shows a "couldn't match — pick manually?" prompt in the modal).

This means a single lookup can produce: **structure + chords + voiced playable tab + rhythm-aware notation** in one shot. The user lands in the editor with a fully fleshed-out sheet to refine.

### 4.5 Optional: melody/tab suggestion (stretch within L3)
Same LLM call can be asked: "If a well-known melody transcription exists, return it as a list of `{ pitch, octave, durationBeats, bar }` notes for the head only." The LLM either returns it (jazz heads, traditional songs) or sets `melody: null` (recent pop). When present, feed into the existing MusicXML construction in `applyProgression`. Treat as best-effort — it's the part most likely to be wrong, and the user is editing anyway.

### 4.6 Acceptance
- "Alone Together" lookup returns 44-bar AABA in D minor with Real-Book-equivalent changes; lands in editor; `source_note` cites the source.
- "Shape of You" lookup returns a 4-chord loop (likely C♯m–F♯m–A–B equivalent) at ~96 BPM; user can build voicings inline.
- Low-confidence results are flagged clearly; user can reject and start blank without confusion.
- Output is byte-equivalent to a hand-built sheet — editor has no L3-specific code paths.

### 4.7 Estimated size
~1 week. Claude SDK integration + JSON schema design + modal UI + `AnalysisToLeadsheet` service. The bulk of the time is **prompt iteration** — tuning the LLM to return clean, schema-conformant `IntermediateAnalysis` for both jazz and pop reliably.

### 4.8 Risks
- **Hallucinated chord changes** for obscure songs. Mitigation: web search tool, `confidence` gating, `alternatives` array shown in UI.
- **Wrong-version returns** (e.g. Bill Evans changes when user wanted Real Book). Mitigation: `version` preference + `source_note` so user immediately sees what they got.
- **API cost.** A single lookup is well under $0.10 even with web search; not material at this volume.

### 4.9 L3 implementation spec (ready for a coding AI)

> **Briefing for the implementer:** this section is self-contained. You don't need to read all of §4 to ship — but §4.3 (pipeline) and §4.4 (L2 ↔ L3 bridge) describe how this slots into the existing infrastructure. **Do not invent new musical logic.** L3 is a *thin* layer: an LLM call + JSON-shape conversion + reuse of everything L2/L2.5 already built. If you find yourself writing a chord parser, voicing builder, or MusicXML emitter, stop — that work already exists.

#### 4.9.1 Goal

Add a **"From song lookup"** entry to the "+ New leadsheet" dropdown. Admin types a song title (+ optional artist hint, + optional preferences), backend asks Claude with web search to return canonical chord structure as `IntermediateAnalysis` JSON, that gets converted into the same `json_data` + `shortcode_content` shape L1/L2 produce, and lands the user in the editor.

If "build voicings now" is checked (default on), the same `VoicingMaterializer` + `RhythmMaterializer` path L2.5 uses runs against the LLM-returned chord sequence — fully voiced, rhythm-aware tab in one shot.

#### 4.9.2 In scope (single PR)

- **A.** New service `app/Services/AnalysisToLeadsheet.php` — turns `IntermediateAnalysis` → `{ shortcode_content, json_data, measure_count }`, mirroring `LeadsheetScaffolder` shape.
- **B.** New service `app/Services/SongLookup.php` — calls Claude API with web search, returns validated `IntermediateAnalysis` array.
- **C.** New service `app/Services/RhythmHintMapper.php` — translates LLM `rhythm_hint` string → `RhythmPattern` slug (or null).
- **D.** New controller method `LeadsheetController@createFromLookup` — orchestrates B → C → A → optional `VoicingMaterializer` → `Leadsheet::create` → redirect.
- **E.** New modal `resources/views/admin/leadsheets/_lookup-modal.blade.php` — title + hint + preferences; "build voicings now" toggle reusing the L2.5 voicing-style + rhythm controls.
- **F.** New cache table `sbn_lookup_cache` (migration) so identical lookups don't re-hit the API.
- **G.** Tests per §4.9.16.
- **H.** Enable the "From song lookup" option in the index dropdown (currently disabled placeholder per §2.1).

#### 4.9.3 Out of scope (do NOT do)

- Don't build `IntermediateAnalysis` extractors for L4 sources (audio/PDF/etc.).
- Don't change `VoicingMaterializer`, `RhythmMaterializer`, `LeadsheetScaffolder`, or the editor.
- Don't add the melody/tab suggestion stretch from §4.5 in v1 — set `melody: null` always, ignore any melody the LLM returns. Add a TODO with `// L3 stretch — see §4.5` so it's findable later.
- Don't write to `sbn_chord_diagrams` or `sbn_rhythm_patterns` from L3 — pure read.
- Don't introduce a queue/job system. Synchronous is fine; cap LLM call at 30s and show a spinner. If it actually takes longer than that in practice, add an async path in v2.
- Don't add user authentication / API key UI — `ANTHROPIC_API_KEY` from `.env` is the only config.
- Don't fix any L1/L2/L2.5 issues in this PR.

#### 4.9.4 Files to create

- `app/Services/LLM/LookupClient.php` — interface (§4.9.7).
- `app/Services/LLM/LookupClientException.php`
- `app/Services/LLM/GeminiLookupClient.php` — primary adapter, with grounding.
- `app/Services/LLM/DeepSeekLookupClient.php` — fallback adapter, no search.
- `app/Services/LLM/FakeLookupClient.php` — for tests; canned responses, no network.
- `app/Services/AnalysisToLeadsheet.php`
- `app/Services/SongLookup.php`
- `app/Services/SongLookupException.php`
- `app/Services/RhythmHintMapper.php`
- `app/Models/LookupCache.php`
- `app/Providers/LLMServiceProvider.php` — binds `LookupClient` based on config.
- `database/migrations/YYYY_MM_DD_create_sbn_lookup_cache_table.php`
- `resources/views/admin/leadsheets/_lookup-modal.blade.php`
- `tests/Unit/AnalysisToLeadsheetTest.php`
- `tests/Unit/RhythmHintMapperTest.php`
- `tests/Unit/GeminiLookupClientTest.php` — uses HTTP fake to assert request shape.
- `tests/Unit/DeepSeekLookupClientTest.php` — same.
- `tests/Feature/LeadsheetLookupTest.php` (uses `FakeLookupClient` — see §4.9.16)

#### 4.9.5 Files to modify

- `app/Http/Controllers/Admin/LeadsheetController.php` — add `createFromLookup` method, add `index()` view-data for lookup-modal needs (rhythms list already passed, voicing-style options already on the modal — verify).
- `routes/web.php` — `POST /admin/leadsheets/create-from-lookup` → `admin.leadsheets.create-from-lookup`.
- `resources/views/admin/leadsheets/index.blade.php` — enable the "From song lookup" dropdown item; include `_lookup-modal.blade.php`.
- `config/services.php` — add the `llm` config block (§4.9.9).
- `config/app.php` — register `LLMServiceProvider`.
- `.env.example` — add `LLM_PROVIDER`, `GEMINI_API_KEY`, `GEMINI_MODEL`, `DEEPSEEK_API_KEY`, `DEEPSEEK_MODEL` placeholders.

#### 4.9.6 The `IntermediateAnalysis` contract (locked schema)

This is what `SongLookup::lookup()` returns and what `AnalysisToLeadsheet::convert()` consumes. **Keep it stable** — L4 extractors will target the same shape later.

```php
[
    'title'          => 'Alone Together',
    'composer'       => 'Arthur Schwartz',           // nullable
    'key'            => 'Dm',                         // canonical "Cm" / "F#" / etc.
    'tempo'          => 120,                          // int, nullable
    'timeSignature'  => '4/4',                        // string
    'sections'       => [
        [
            'name'  => 'A1',                          // 'Verse' / 'Chorus' / 'A' / 'A1' / etc.
            'bars'  => [
                ['chords' => [
                    ['label' => 'Dm7b5', 'beats' => 4, 'confidence' => null /* 0.0–1.0; L4a populates, L3 leaves null */],
                ]],
                ['chords' => [
                    ['label' => 'G7',    'beats' => 4, 'confidence' => null],
                ]],
                // ...
            ],
        ],
        // ...
    ],
    'melody'         => null,                         // L3 v1: always null
    'rhythm_hint'    => 'jazz ballad, rubato',        // freeform LLM text, nullable
    'rhythm_slug'    => null,                         // L3 v1: SongLookup leaves null; RhythmHintMapper fills in
    'source_note'    => 'Real Book 6th edition; key: Dm; transposed from original Eb minor',
    'source_audio'   => null,                         // L4a-only: { url, format, duration_seconds, bar_offsets: [secs,…] }
                                                       // Reserved for audio extraction; L3 leaves null.
                                                       // Bar offsets enable Phase D video-sync without re-analysis.
    'confidence'     => 'high',                       // 'high' | 'medium' | 'low' — sheet-level summary
    'alternatives'   => [                              // optional; same schema (truncated, no nested alternatives)
        ['source_note' => 'Original Schwartz score', 'key' => 'Ebm', 'sections' => [/* … */]],
    ],
]
```

**Validation rules** (apply in `SongLookup` after the API returns):
- `key` matches `/^[A-G][#b]?m?$/`. If not, set `confidence = 'low'` and put the raw value in `source_note`.
- `timeSignature` matches `/^\d+\/\d+$/`. If not, default to `'4/4'` and append `"(time-sig fallback)"` to `source_note`.
- `tempo` clamped to `[20, 300]` or null.
- Each section has at least one bar; each bar has at least one chord with `beats >= 1`.
- Total `beats` per bar should equal `timeSignature` numerator. If not, log + set `confidence = 'low'`. Don't reject — admin will fix.
- Chord `label` must parse via `ProgressionDetector::parseChordName()` (already used by `ChordSequenceParser`). If not, leave the label as the raw LLM string and bump invalid count.

#### 4.9.7 Provider-agnostic architecture (`LookupClient` interface)

L3 must not be tied to a single LLM vendor. Reasons: cost (Claude is ~10× more expensive than DeepSeek for this task), kill-switch flexibility, and the realistic prospect of swapping providers as the landscape moves. Build the abstraction from day one — it's ~30 extra lines and an interface file, not a rewrite later.

**Interface:**

```php
namespace App\Services\LLM;

interface LookupClient
{
    /**
     * Send a structured lookup request to the LLM.
     *
     * @param string $systemPrompt
     * @param string $userPrompt
     * @param array  $jsonSchema   Schema for the expected response shape (provider-agnostic;
     *                              adapter translates to provider's response_format / tool_use mechanism)
     * @param array  $opts {
     *   useWebSearch:    bool,    // request grounding/search tool if the provider supports it
     *   maxSearchUses:   int,     // safety cap, default 3
     *   timeoutSeconds:  int,     // default 30
     * }
     * @return array {
     *   data:        array,        // parsed JSON response matching $jsonSchema
     *   citations:   array,        // [{title, url, snippet}, ...] if grounding was used; else []
     *   usage:       array,        // {input_tokens, output_tokens, search_count}
     *   model:       string,       // identifier of model that answered
     * }
     * @throws LookupClientException
     */
    public function complete(string $systemPrompt, string $userPrompt, array $jsonSchema, array $opts = []): array;
}
```

**Provider matrix (web search availability is the deciding factor):**

| Provider | Web search? | Cost (input / output / search) | When to pick |
|---|---|---|---|
| **Gemini 2.5 Flash** | ✅ Google Search grounding | Free up to 1500 grounded req/day, then ~$0.30 / $2.50 / $35 per 1k searches | **v1 default.** Best cost/feature ratio. Citations included. |
| **DeepSeek V3** | ❌ API doesn't expose it | $0.27 / $1.10 per M tokens | Cost-floor option; lean on training data + `confidence: low` gating. |
| **OpenAI GPT-4o + web_search_preview** | ✅ Built-in tool | $2.50 / $10 / $25 per 1k searches | If you already have OpenAI infrastructure. |
| **Claude Sonnet 4.6 + web_search** | ✅ Built-in tool | $3 / $15 / $10 per 1k searches | If accuracy on edge cases dominates cost concern. |
| **Perplexity sonar-pro** | ✅ Search IS the model | ~$3/1k req + tokens | Best at retrieval-grounded answers; overkill for well-known songs. |

**v1 ships two adapters:**
1. `GeminiLookupClient` — primary, with grounding enabled.
2. `DeepSeekLookupClient` — fallback / no-search option.

Claude, OpenAI, Perplexity adapters are documented as "future swap-ins" — one new class file each (~80 lines), takes a day if/when needed.

#### 4.9.8 `SongLookup::lookup()` API

```php
namespace App\Services;

use App\Services\LLM\LookupClient;

class SongLookup
{
    public function __construct(
        private LookupClient $client,    // bound via config (§4.9.9)
        private LookupCache $cache,
    ) {}

    /**
     * @param array $opts {
     *   title:          string,    // required
     *   artist_hint:    ?string,   // nullable
     *   preferred_key:  ?string,   // nullable; if set, ask LLM to transpose
     *   version:        ?string,   // 'real_book' | 'original' | 'most_common' (default)
     * }
     * @return array IntermediateAnalysis (§4.9.6)
     * @throws SongLookupException on API failure (caller renders friendly error)
     */
    public function lookup(array $opts): array;
}
```

**Implementation outline:**
1. Build a stable cache key from `opts` (lowercase title + sorted hash). Check `LookupCache::find($key)`. If hit, return cached.
2. Build the system prompt (see §4.9.10).
3. Call `$this->client->complete($system, $user, $schema, ['useWebSearch' => true, 'maxSearchUses' => 3])`.
4. Parse + validate response per §4.9.6. If `citations` non-empty, append top-1 to `source_note` if the LLM didn't already cite it.
5. Store result in `LookupCache` with TTL = 30 days.
6. Return.

**Failure modes:**
- API timeout / 5xx: throw `SongLookupException`. No caching.
- Schema parse failure: retry once with "your previous response was malformed JSON; return only valid JSON matching the schema" appended. If second attempt also fails, throw.
- Rate limit: throw with retry-after info in the exception message.
- Provider returns non-grounded result when grounding was requested (DeepSeek case): set `confidence: low` if it would have been `high`, since we couldn't verify.

#### 4.9.9 Provider adapter setup

**Config block** in `config/services.php`:

```php
'llm' => [
    'provider' => env('LLM_PROVIDER', 'gemini'),  // 'gemini' | 'deepseek'
    'gemini' => [
        'key'   => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
    ],
    'deepseek' => [
        'key'   => env('DEEPSEEK_API_KEY'),
        'model' => env('DEEPSEEK_MODEL', 'deepseek-chat'),
        'base'  => env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com/v1'),
    ],
],
```

**Service provider binding:**

```php
// app/Providers/AppServiceProvider.php (or a dedicated LLMServiceProvider)
$this->app->bind(\App\Services\LLM\LookupClient::class, function ($app) {
    return match (config('services.llm.provider')) {
        'gemini'   => new \App\Services\LLM\GeminiLookupClient(
            apiKey: config('services.llm.gemini.key'),
            model:  config('services.llm.gemini.model'),
        ),
        'deepseek' => new \App\Services\LLM\DeepSeekLookupClient(
            apiKey:  config('services.llm.deepseek.key'),
            model:   config('services.llm.deepseek.model'),
            baseUrl: config('services.llm.deepseek.base'),
        ),
        default    => throw new \RuntimeException('Unknown LLM provider'),
    };
});
```

**Adapter implementation guidance:**

- **`GeminiLookupClient`:** use the official `google-gemini-php/laravel` package or direct HTTP via Laravel's `Http` facade. Pass `tools: [{google_search: {}}]` when `useWebSearch=true`. Response will include `groundingMetadata.groundingChunks[]` for citations.
- **`DeepSeekLookupClient`:** install `openai-php/client` (`composer require openai-php/client`). Construct with `OpenAI::factory()->withBaseUri('https://api.deepseek.com/v1')->withApiKey(...)->make()`. DeepSeek is OpenAI-compatible so the SDK works unmodified. Set `response_format: ['type' => 'json_object']` for schema enforcement. Web search opts are silently ignored (return empty `citations` array).

**Use prompt caching where the provider supports it.** Gemini's implicit caching kicks in for repeated context above 32k tokens — the L3 system prompt is small, so caching saves marginal cost only. DeepSeek has automatic context caching with a 50% discount on cache hits — meaningful for the system prompt across many lookups.

**`.env.example` additions:**
```
LLM_PROVIDER=gemini
GEMINI_API_KEY=
GEMINI_MODEL=gemini-2.5-flash
DEEPSEEK_API_KEY=
DEEPSEEK_MODEL=deepseek-chat
```

**Spend safety:** when using a paid tier, create a dedicated API key per provider with a monthly cap (Gemini console + DeepSeek dashboard both support this). Gemini's free tier covers expected admin usage; DeepSeek's pay-as-you-go is so cheap that a $10/month cap is plenty.

#### 4.9.10 System prompt (starting point)

The prompt must work across providers — don't reference Claude-specific features. Web search instructions phrase as "if grounding is available," because the DeepSeek path won't have it.

```
You are a music librarian assisting a guitar teacher. Given a song title and optional artist/version hint, return the canonical chord structure as strict JSON matching the IntermediateAnalysis schema.

Rules:
- If web search / grounding is available, verify chord changes against authoritative sources. Prefer Real Book / iReal Pro / well-known fake-book changes for jazz standards. Prefer top-rated Ultimate Guitar tabs for pop/rock.
- If grounding is not available, rely on training data and set `confidence` honestly: `high` for songs you're certain about (well-known standards, top-100 pop), `medium` for songs you've seen referenced but can't verify the specific version, `low` for obscure or contested songs.
- Return the most commonly taught version unless the user specifies otherwise via `version`.
- Always populate `source_note` (e.g. "Real Book 6th edition", "Ultimate Guitar top-rated v3", "based on training data; not verified").
- Use canonical chord notation: 'Cmaj7' not 'CΔ7', 'Dm7b5' not 'Dø7', 'G7' not 'G7dom'.
- If multiple valid versions exist, return the most-taught in `sections` and put the others in `alternatives` (max 2).
- Section names: use the song's actual form labels (A/B for AABA, Verse/Chorus for pop, etc.).
- For 32-bar AABA jazz standards, expect exactly 32 bars unless the song has known extensions/codas.
- `melody` must be null for v1.
- `rhythm_hint` should be one short phrase (e.g. "bossa nova", "shuffle", "ballad rubato"). Don't elaborate.
- Return ONLY valid JSON. No prose, no markdown, no commentary.
```

Iterate this prompt during PR development — the bulk of L3 work is prompt tuning, not code. Test against: "Alone Together", "Summertime", "Wave", "Shape of You", "Wonderwall", and one obscure song to verify low-confidence behavior. Test on **both** Gemini and DeepSeek so the prompt isn't accidentally provider-specific.

#### 4.9.11 `RhythmHintMapper::map()` API

```php
namespace App\Services;

class RhythmHintMapper
{
    /**
     * @param ?string $hint LLM rhythm_hint string ('bossa nova', 'shuffle', etc.)
     * @return ?string slug of best-matching RhythmPattern, or null
     */
    public function map(?string $hint): ?string;
}
```

Implementation: case-insensitive keyword table. Walk all `RhythmPattern` rows once, build a `[keyword => slug]` map at construction (small, cacheable). Match the first keyword from `$hint` that hits.

**Starter keyword table** (extend as patterns get added):
```php
[
    'bossa'      => 'joao-gilberto-bossa',     // adjust to actual seeded slugs
    'samba'      => 'samba-basic',
    'ballad'     => 'ballad-quarter',
    'shuffle'    => 'blues-shuffle',
    'swing'      => 'swing-comping',
    'rock'       => 'rock-eighth',
    'pop'        => 'pop-strum',
    'folk'       => 'folk-strum',
    'reggae'     => 'reggae-skank',
    'waltz'      => 'waltz-strum',
]
```

If no match: return null. Caller falls back to "no rhythm" (whole notes).

#### 4.9.12 `AnalysisToLeadsheet::convert()` API

```php
namespace App\Services;

class AnalysisToLeadsheet
{
    public function convert(array $analysis): array;
    // returns ['shortcode_content' => string, 'json_data' => string, 'measure_count' => int]
    // shape identical to LeadsheetScaffolder::scaffoldFromSequence
}
```

**Implementation:** mirror `LeadsheetScaffolder::scaffoldFromSequence` exactly — same `json_data` keys (`title, composer, key, tempo, timeSignature, displayBeats, sections, chordVoicings: (object)[], melody: [], repeatMarkers: []`), same shortcode shape. The only difference is the input: instead of a flat chord list + bars-per-chord, you walk `analysis.sections[].bars[].chords[]` directly.

Round-trip rule (same as L1 §8.9): `LeadsheetParser::parse($result['shortcode_content'])` must equal `json_decode($result['json_data'], true)`. Add a unit test that asserts this.

#### 4.9.13 `LeadsheetController@createFromLookup` outline

```php
public function createFromLookup(
    Request $request,
    SongLookup $lookup,
    RhythmHintMapper $rhythmMapper,
    AnalysisToLeadsheet $converter,
    VoicingMaterializer $materializer,
    ProgressionBuilder $builder,
) {
    $validated = $request->validate([
        'title'         => 'required|string|max:255',
        'artist_hint'   => 'nullable|string|max:255',
        'preferred_key' => 'nullable|string|max:10',
        'version'       => 'nullable|string|in:real_book,original,most_common',
        'build_voicings'=> 'nullable|boolean',
        'voicing_style' => 'nullable|string|in:popular,shell,drop2,archetype',
        'rhythm_override' => 'nullable|string|max:50', // user-picked slug if LLM hint didn't match
    ]);

    try {
        $analysis = $lookup->lookup($validated);
    } catch (SongLookupException $e) {
        return back()->withErrors(['lookup' => $e->getMessage()]);
    }

    // Resolve rhythm: user override > mapper(LLM hint) > none
    $rhythmSlug = $validated['rhythm_override'] ?? $rhythmMapper->map($analysis['rhythm_hint'] ?? null);
    $rhythmModel = $rhythmSlug ? RhythmPattern::where('slug', $rhythmSlug)->first() : null;

    $scaffold = $converter->convert($analysis);
    $jsonDataArray = json_decode($scaffold['json_data'], true);
    $tabXml = null;

    if (!empty($validated['build_voicings'])) {
        // Flatten chords for selectVoicingsForSequence
        $chordList = [];
        foreach ($analysis['sections'] as $sec) {
            foreach ($sec['bars'] as $bar) {
                foreach ($bar['chords'] as $c) {
                    if (!empty($c['label']) && $c['label'] !== '?') $chordList[] = $c['label'];
                }
            }
        }
        if (!empty($chordList)) {
            $style = $validated['voicing_style'] ?? 'popular';
            $category = $style === 'popular' ? '' : $style;
            $selections = $builder->selectVoicingsForSequence($chordList, $analysis['key'], ['category' => $category]);

            $clean = array_values(array_filter(array_map(fn($s) => $s['frets'] ? [
                'chord_name' => $s['chord_name'],
                'frets'      => $s['frets'],
                'position'   => $s['position'] ?? 1,
            ] : null, $selections)));

            if (!empty($clean)) {
                $materialized = $materializer->materialize($clean, $analysis['timeSignature'], $rhythmModel);
                $tabXml = $materialized['tab_xml'];
                $jsonDataArray['chordVoicings'] = $materialized['voicings'];
                $jsonDataArray['melody'] = $materialized['melody'];
                if ($rhythmModel) {
                    $jsonDataArray['rhythmPattern'] = $rhythmModel->toPlayerData();
                }
            }
        }
    }

    $leadsheet = Leadsheet::create([
        'title'             => $analysis['title'],
        'slug'              => Leadsheet::generateUniqueSlug($analysis['title']),
        'composer'          => $analysis['composer'] ?? '',
        'song_key'          => $analysis['key'],
        'tempo'             => $analysis['tempo'] ?? 120,
        'time_signature'    => $analysis['timeSignature'],
        'rhythm'            => $rhythmSlug ?? '',
        'measure_count'     => $scaffold['measure_count'],
        'shortcode_content' => $scaffold['shortcode_content'],
        'json_data'         => json_encode($jsonDataArray, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'tab_xml'           => $tabXml,
        'description'       => $analysis['source_note'] ?? '',
        'harmony_notes'     => '',
        'form_notes'        => '',
        'voicing_notes'     => '',
        'popularity'        => 0,
    ]);

    return redirect()
        ->route('admin.leadsheets.edit', $leadsheet)
        ->with('success', 'Leadsheet drafted from lookup. Source: ' . ($analysis['source_note'] ?? 'unknown'))
        ->with('lookup_confidence', $analysis['confidence'] ?? 'medium')
        ->with('lookup_alternatives', $analysis['alternatives'] ?? []);
}
```

The flash data (`lookup_confidence`, `lookup_alternatives`) is read by the editor's existing flash-message infrastructure and rendered as a banner. If the editor doesn't yet have a banner slot, just show `success` text — the alternatives can be ignored in v1.

#### 4.9.14 Modal UX

`_lookup-modal.blade.php` — single-step (not a wizard), Alpine, included from index.

| Field | Type | Required | Default | Notes |
|---|---|---|---|---|
| `title` | text | yes | — | song title |
| `artist_hint` | text | no | empty | artist or version hint |
| `preferred_key` | select | no | "use canonical" | 12 keys + minors, plus "use canonical" |
| `version` | select | no | `most_common` | `real_book` / `original` / `most_common` |
| `build_voicings` | checkbox | no | `true` | reuse L2 default |
| `voicing_style` | select | no | `popular` | shown when `build_voicings` is on; same options as L2 |
| `rhythm_override` | select | no | empty | shown after lookup completes if `rhythm_hint` returned but `RhythmHintMapper` returned null; offers manual pick |

**Two-stage UX:** title + hint → Submit → spinner → if `confidence: low` or `rhythm_override` needed, show a confirm step with `source_note` and `alternatives` previewed → final Submit. For v1, skip stage 2 entirely; submit always goes straight to creation. Stage 2 is a v1.1 polish.

#### 4.9.15 Cache table

```php
Schema::create('sbn_lookup_cache', function (Blueprint $t) {
    $t->id();
    $t->string('cache_key', 64)->unique();        // sha256 of normalized opts
    $t->string('title', 255);                      // for inspection / cache busting
    $t->json('analysis');                          // stored IntermediateAnalysis
    $t->timestamp('expires_at')->index();
    $t->timestamps();
});
```

`LookupCache::find($key)` returns the analysis array or null (and respects `expires_at`). `LookupCache::put($key, $title, $analysis, $ttlDays = 30)` upserts.

#### 4.9.16 Tests

`tests/Unit/AnalysisToLeadsheetTest.php`:
- `it_converts_a_simple_aaba_analysis_into_scaffold_shape` — feed a synthetic `IntermediateAnalysis`, assert `json_data` decodes with the right section count and `measure_count`.
- `it_round_trips_through_LeadsheetParser` — convert → parse `shortcode_content` → assert equal to `json_data`. Mandatory.
- `it_emits_object_for_empty_chordVoicings` — same A4 fix as L2.
- `it_handles_uneven_beats_per_bar_without_throwing` — degraded analysis with `beats: 3` in a `4/4` bar should still scaffold (with `?` placeholder if needed).

`tests/Unit/RhythmHintMapperTest.php`:
- Each keyword in the table maps to its slug.
- Unknown / null hint returns null.
- Hint with multiple keywords returns the first match deterministically.

`tests/Unit/GeminiLookupClientTest.php` and `tests/Unit/DeepSeekLookupClientTest.php`:
- Use Laravel's `Http::fake()` to capture the outbound request.
- Assert the request body shape matches what the provider expects (Gemini: `tools: [{google_search: {}}]` when `useWebSearch=true`; DeepSeek: `response_format: {type: 'json_object'}`).
- Assert that a canned response is parsed into the common `complete()` return shape (data + citations + usage + model).
- Assert that `useWebSearch=true` against DeepSeek doesn't throw — it just returns empty `citations`.

`tests/Feature/LeadsheetLookupTest.php`:
- Bind `LookupClient::class` to `FakeLookupClient` in the test container. The fake returns canned `IntermediateAnalysis` arrays for known titles. Don't hit the real API in tests.
- POST to `/admin/leadsheets/create-from-lookup` with `title=Alone Together` → 302 to edit page → DB row exists with the canned analysis's title/key/tempo.
- POST with `build_voicings=true` and a chord vocabulary present in the seeded `sbn_chord_diagrams` → `tab_xml` is non-null.
- POST with the fake throwing `LookupClientException` → 302 back with error in flash.
- Cache hit: POST same payload twice; second call hits `LookupCache`, fake client called once.

#### 4.9.17 Validation rules to verify before merging

- LLM call works with a real key against "Alone Together", "Summertime", "Shape of You", and one deliberately obscure song.
- Cache hit on second identical lookup (verify by inspecting `sbn_lookup_cache` row count or query log).
- Round-trip: lookup → save → reload in editor produces a sheet whose `chordVoicings` and `tab_xml` survive identically.
- L1, L2, L2.5 paths still work — no shared service was modified.
- Confidence `low` results still create a sheet (don't block); confidence visible somewhere (flash banner or description field).

#### 4.9.18 PR checklist

- [ ] `LookupClient` interface created; both `GeminiLookupClient` and `DeepSeekLookupClient` implement it.
- [ ] `FakeLookupClient` exists for tests.
- [ ] `LLMServiceProvider` binds `LookupClient` based on `services.llm.provider`.
- [ ] All five env vars documented in `.env.example`.
- [ ] `SongLookup::lookup()` validates the schema and caches results.
- [ ] `AnalysisToLeadsheet::convert()` round-trips through `LeadsheetParser`.
- [ ] `RhythmHintMapper` table seeded with at least the 10 starter keywords.
- [ ] `createFromLookup` controller method working end-to-end.
- [ ] Cache table migration runs cleanly.
- [ ] Modal renders; "From song lookup" enabled in dropdown.
- [ ] `voicing_style` and `rhythm_override` plumbed through to materializer.
- [ ] All tests in §4.9.16 pass.
- [ ] Manual smoke test: same prompt run against **both** Gemini and DeepSeek (via env switch) returns valid `IntermediateAnalysis` for at least 3 well-known songs.
- [ ] No regressions in L1/L2/L2.5.
- [ ] Confidence shown to user (flash or description); alternatives ignored for v1.
- [ ] `melody` is always `null` (TODO comment for §4.5).

#### 4.9.19 Things NOT to do

- Don't hit the live LLM API in unit/feature tests. Use `FakeLookupClient`.
- Don't iterate the system prompt by hand-running it against the live API a hundred times. Build with the fake client; only burn real API calls for the final 5–10 sanity-check songs across both providers.
- Don't reference Claude or Anthropic-specific features (web_search tool, prompt caching beta header, Anthropic SDK types) anywhere outside a future `ClaudeLookupClient` adapter.
- Don't bake provider-specific response parsing into `SongLookup` — keep the adapter responsible for translating to the common return shape (§4.9.7).
- Don't add per-section regeneration ("re-do just the bridge") — that's v2.
- Don't store `alternatives` in the leadsheet row — only flash them.
- Don't hard-code any chord names or song data — everything comes from the LLM.
- Don't implement the L4 standby tracks "while you're in there."
- Don't refactor `LeadsheetController` beyond adding the new method.
- Don't widen the `voicing_style` validator beyond the four modal options (see §3.8.9 #1).
- Don't bypass the cache "for testing" — use the fake client. Cache hits are part of correctness.

#### 4.9.20 L3 as-built notes & post-ship polish

**Status:** ✅ SHIPPED (with polish tasks below).

##### Shipped surface
- `app/Services/LLM/{LookupClient, LookupClientException, GeminiLookupClient, DeepSeekLookupClient, FakeLookupClient}.php`
- `app/Services/{SongLookup, SongLookupException, AnalysisToLeadsheet, RhythmHintMapper}.php`
- `app/Models/LookupCache.php` + migration `2026_04_30_create_sbn_lookup_cache_table.php`
- `app/Providers/LLMServiceProvider.php` (registered in `bootstrap/providers.php`)
- `LeadsheetController::createFromLookup`
- `resources/views/admin/leadsheets/_lookup-modal.blade.php`
- Tests: `LeadsheetLookupTest`, `AnalysisToLeadsheetTest`, `RhythmHintMapperTest`, `GeminiLookupClientTest`, `DeepSeekLookupClientTest`

##### Spec deviations worth recording
- **`SongLookup` constructor** gained a `ProgressionDetector` dependency (used in `validateAndFix` to flag chord names that don't parse cleanly). Not in §4.9.8; good improvement, keep.
- **Web search disabled by default** at [SongLookup.php:120](../app/Services/SongLookup.php#L120) (`useWebSearch => false`). Deliberate cost decision — Gemini's grounded free tier (1500/day) is more than enough but the implementer chose ungrounded to stay fully inside the simpler quota. Documented here so it's not mistaken for a bug. Toggle to `true` if grounding becomes wanted.
- **Schema-vs-tools workaround** in `GeminiLookupClient` ([line 38-43](../app/Services/LLM/GeminiLookupClient.php#L38)): when `useWebSearch=true`, Gemini doesn't accept `response_mime_type: application/json` + `response_schema` simultaneously with tools. Workaround appends the schema as text in the system prompt. Works ~85% of the time; a markdown-stripping regex catches another ~10%. Worth knowing if/when grounding gets re-enabled.

##### Polish tasks (open, do as one tidy commit)

1. **429/503 handling with backoff in `GeminiLookupClient`.** Currently any non-2xx blows up immediately as a generic `"Gemini API Error"`. Catch 429 *and* 503 specifically, sleep ~5s, retry once. If the retry also fails, throw with a user-friendly message ("Rate-limited; retry in a moment"). About 10 lines. Without this, transient overloads bubble up as scary errors when they should self-heal.

2. **`validateAndFix` confidence downgrade is too aggressive.** [SongLookup.php:180-182](../app/Services/SongLookup.php#L180): `if ($barBeats !== $displayBeats) { $analysis['confidence'] = 'low'; }` flips the *whole sheet* to `low` for any single mis-counted bar. Real-world LLMs frequently emit `[Cmaj7,A7,Dm7,G7]` with `beats: 1,1,1,1` (correct, sums to 4) but occasionally emit `beats: 2,2,2,2` (wrong sum, but chord identity fine). **Fix:** track per-bar warnings instead of demoting the whole sheet. Or normalize beat sums to `displayBeats` (proportional rescale) and leave `confidence` alone. Either is fine; pick one.

3. **`AnalysisToLeadsheet::buildPlaceholderMelody` emits phantom rest-melody.** [AnalysisToLeadsheet.php:85-109](../app/Services/AnalysisToLeadsheet.php#L85) generates one rest entry per measure with a placeholder `notes` array (string=1, fret=0, pitch=0, octave=4). The L1/L2 path leaves `melody: []` for chord-only sheets. **Fix:** change `buildPlaceholderMelody()` to return `[]`. Let `VoicingMaterializer` populate it when (and only when) the user opts to "build voicings now." Verify in editor: an L3 sheet without voicings should render as a clean chord grid, no rest stems.

4. **Prompt-fallback if grounded JSON parse fails twice.** Tied to (1): when grounding is on and the inline-schema JSON parse fails repeatedly (the ~5% Gemini ignores the instruction), fall back to one ungrounded retry with strict `response_schema`. Single attempt, no infinite loop. Improves the grounded path's reliability without giving up on it.

5. **DeepSeek error messages lose the upstream body.** [DeepSeekLookupClient.php:39](../app/Services/LLM/DeepSeekLookupClient.php#L39) catches `\Exception` and rethrows with `$e->getMessage()`. The OpenAI-PHP SDK's exception types carry HTTP status + response body separately; preserving them in the `LookupClientException` makes 402-no-credit / 429-rate-limit much easier to diagnose. ~5 lines.

##### §4.9.20a Free-tier testing notes (Gemini → Groq escape hatch)

**Why "high demand" errors aren't actually outages.** `gemini-2.5-flash` ungrounded free tier: 10 RPM, 250 RPD. Gemini returns HTTP 503 `"model is overloaded"` for **both** genuine outages *and* per-minute rate limits. Looks like a Google-side problem when it's almost always your traffic. Three rapid retries while iterating in the modal eats a chunk of the minute budget; the next attempt hits the cap.

**Two free-tier escape hatches for testing without topping up DeepSeek:**

**Option A — Stay on Gemini, swap to flash-lite.** Lowest-friction:
- Set `GEMINI_MODEL=gemini-2.5-flash-lite` in `.env`.
- Free tier is 15 RPM (vs. 10 on `flash`), 1000 RPD, faster, lighter.
- Quality slightly below `flash` but plenty for chord lookup. Jazz-standard accuracy unchanged.
- **Zero code change. Restart server, retry.**

**Option B — Add Groq as a third provider.** Most generous free tier of any current LLM API: **30 RPM, 14400 RPD** for Llama 3.3 70B. ~30 minutes of work:

1. Get a free API key at console.groq.com.
2. Copy `app/Services/LLM/DeepSeekLookupClient.php` → `GroqLookupClient.php`. Two changes:
   - Default `baseUrl = 'https://api.groq.com/openai/v1'`
   - Default `model = 'llama-3.3-70b-versatile'`
3. Add a `'groq' => …` branch to `LLMServiceProvider::register()` matching the DeepSeek branch shape.
4. Add to `config/services.php`:
   ```php
   'groq' => [
       'key'   => env('GROQ_API_KEY'),
       'model' => env('GROQ_MODEL', 'llama-3.3-70b-versatile'),
       'base'  => env('GROQ_BASE_URL', 'https://api.groq.com/openai/v1'),
   ],
   ```
5. Add `GROQ_API_KEY=` and `GROQ_MODEL=` to `.env.example`.
6. Set `LLM_PROVIDER=groq` in `.env`.

Groq is OpenAI-compatible, so the existing `openai-php/client` SDK works unchanged. No web search (same as DeepSeek). Llama 3.3 70B's training data covers the same jazz/pop corpus as Gemini and DeepSeek; quality differences for L3's lookup task are small.

**Quality / availability ranking for the ungrounded free-tier path:**
1. **Groq + Llama 3.3 70B** — most generous quota; recommended for testing.
2. **Gemini 2.5 Flash-Lite** — already wired; smallest config change.
3. **Gemini 2.5 Flash** (current) — works, but the per-minute cap bites during iterative testing.

**When to top up DeepSeek (or another paid provider):** $5 of DeepSeek credit ≈ ~50,000 L3 lookups, which is effectively unlimited for an admin tool. Decision is "do I want a card on file" not "does the cost matter." Groq + flash-lite cover all testing needs without requiring it.

**Other free-tier options** (documented for completeness; not recommended over Groq):
- **OpenRouter free models** (`meta-llama/llama-3.3-70b-instruct:free`, `google/gemini-2.0-flash-exp:free`): universal OpenAI-compatible API; daily caps tighter than Groq (50–200 RPD per model). Useful for A/B-ing models behind one adapter.
- **Cerebras** (Llama 3.3 70B, ~2000 tok/sec): same free tier as Groq, different host. Fast but no real advantage for L3's tiny prompts.
- **Mistral La Plateforme** (`mistral-small-latest`): 1 RPS, 500k tok/min. Less generous; also no jazz-standard saturation in training.
- **Cohere Command R**: 1000 lookups/month trial, has built-in web search via "connectors." Less battle-tested in production.

##### PR checklist (polish PR)

- [ ] 429/503 retry-with-backoff in `GeminiLookupClient`.
- [ ] Per-bar warnings (or beat normalization) replaces sheet-level confidence downgrade.
- [ ] `buildPlaceholderMelody` returns `[]`; verified in editor on a no-voicings L3 sheet.
- [ ] Optional grounded-JSON fallback retry implemented (or explicitly deferred with a TODO).
- [ ] DeepSeek error messages preserve upstream status + body.
- [ ] `GroqLookupClient` added (only if going the Groq route).
- [ ] `.env.example` lists all three provider env-var groups.
- [ ] No regressions in L1 / L2 / L2.5 / L3 happy path.

##### Things NOT to do (polish PR)

- Don't re-enable `useWebSearch` in `SongLookup.php` without first verifying the schema-vs-tools workaround is producing valid JSON in production traffic. Grounded mode is functional but fragile; ungrounded is the safer default given current free-tier constraints.
- Don't add more than one retry per call. Backoff loops can amplify rate-limiting under bursty traffic.
- Don't add provider-specific code paths in `SongLookup`. Adapters carry that responsibility.
- Don't ship a second LLM call to "verify" the first. If grounded JSON parse fails twice, surface a friendly error and let the user retry — don't rack up token spend on auto-retries the user didn't ask for.

#### 4.9.21 Transcribing Assistant (v1)

**Status:** ❄️ DEFERRED — merged conceptually into L3 single-mode lookup. Research features (notable versions, voicing hints, videos) are parked until the LLM path proves its value beyond the Jazz Standards DB.

> **Decision (2026-05-04):** The Quick Draft / Transcribing Assistant split was artificial. The assistant-mode research bundle (notable versions, voicing hints with attribution, transcription notes, suggested videos) is interesting but represents significant investment in a path whose future is uncertain. If the Jazz Standards DB covers the admin's core workflow, the research features may never be needed. Keeping the spec below as reference if this direction is revisited.

##### Why this existed (historical context)

The chord grid is one piece. The research bundle is what makes this worth shipping: notable versions to study, voicing hints with attribution, transcription notes, and suggested videos that can be wired straight into the existing **Phase D video panel**.

##### Phase D integration (the cleanest part)

Phase D is shipped. It stores video data inside the leadsheet's `json_data` envelope:
```js
json_data.videoSync = { videoId, videoType, mappings: [{ measureIndex, videoTime }] }
```
The composable [`useVideoSync.js`](../resources/js/tab-editor/composables/useVideoSync.js) exposes `setVideoId(id, type)` as the public setter.

This means **a "use this video" button on a research-panel suggested video is literally one call**: parse the YouTube URL → `videoId` → `videoSync.setVideoId(id, 'youtube')`. No new persistence, no new component plumbing, no new sidebar real estate. The user picks a video from the LLM's suggestions; the existing video panel lights up; tap-to-mark begins.

##### Schema extension (locked)

`IntermediateAnalysis` (§4.9.6) gains one nullable field, populated only in assistant mode:

```php
'research' => [
    'mode' => 'assistant',                          // 'quick' (null research) | 'assistant'
    'canonical_changes_source' => 'Real Book 6th',
    'notable_versions' => [
        [
            'artist'      => 'Bill Evans Trio',
            'recording'   => 'Sunday at the Village Vanguard',
            'year'        => 1961,
            'differences' => 'Bridge: subs Bm7-E7 for Bm7b5-E7. Outro: tag with Em-A7-Dm.',
            'source_url'  => 'https://...',          // null if not cited
            'source_type' => 'transcription',        // 'transcription'|'analysis'|'forum'|'general_knowledge'
        ],
    ],
    'voicing_hints' => [
        [
            'chord'       => 'Dm7b5',
            'suggestion'  => 'x5656x',               // 6-char fret string when given
            'description' => 'Drop-2 on top 4 strings; Ellis-style comping',
            'attribution' => 'general Ellis vocabulary; not transcribed from this recording',
            'source_url'  => null,
        ],
    ],
    'transcription_notes' => "Form is AABA 44 bars. Watch for the bVI sub at bar 7…",
    'research_links' => [
        ['url' => '...', 'title' => '...', 'relevance' => '...', 'type' => 'transcription'],
        // type: 'transcription'|'analysis'|'forum'|'video'|'other'
    ],
    'suggested_videos' => [
        [
            'url'             => 'https://www.youtube.com/watch?v=...',
            'title'           => '...',
            'channel'         => '...',
            'rationale'       => 'Canonical Ellis/Peterson 1958 studio recording',
            'recording_match' => 'exact',            // 'exact'|'similar'|'tutorial'|'unrelated'
        ],
    ],
],
```

Quick mode leaves `research: null`. Assistant mode populates it. Both modes share the rest of the schema unchanged.

##### v1 ships

1. **Modal mode toggle: "Quick draft" / "Transcribing assistant".** Default = Quick (current behavior, free-tier-friendly). Assistant forces `useWebSearch=true`, makes the longer call, populates `research`.
2. **Schema extension** as locked above.
3. **Prompt extension** — assistant-mode system prompt adds:
   - Version-handling rules: default to Real Book 6th for jazz unless user overrides; never invent reharmonizations to match an artist's "style" — only return what's verifiable from cited transcriptions; if no transcription found for a specific recording, return canonical changes and explain in `source_note`.
   - Research-block rules: each `notable_versions` and `voicing_hints` entry must include either a `source_url` *or* a `source_type: 'general_knowledge'` flag. No silent generalization as fact.
   - Suggested-videos rules: 2–4 YouTube URLs ranked by relevance; mark each with `recording_match` so the user knows whether it's the exact recording, a similar performance, or a tutorial.
4. **Polish items from §4.9.20** ship in the same PR as prerequisites (429/503 backoff, grounded-JSON fallback retry, `buildPlaceholderMelody → []`, DeepSeek error preservation). Without these, grounded mode is too unreliable to lean on.
5. **Song-level cache reshape** — keyed by `normalize(title)` only (lowercased, whitespace-normalized, no hint). Cache holds the full assistant-mode response (with all alternatives + research). Quick-mode and assistant-mode lookups for the same title share the cache entry; quick-mode hits the cached response and discards the research field. Hint becomes a re-rank input on cached `alternatives`, not a new API call.
6. **Editor: new "Research" sidebar tab** — third tab alongside Educational + Video panels. Renders the `research` block as collapsible sections:
   - **Notable versions** — each as a card with artist/recording/year/differences. Each card is collapsible.
   - **Voicing hints** — chord + suggestion + attribution; "Copy to chord picker" button on each.
   - **Transcription notes** — single freeform paragraph at the top.
   - **Suggested videos** — list with title/channel/rationale/match-type. Each has a **"Load in video panel"** button that calls `videoSync.setVideoId(extractYouTubeId(url), 'youtube')`.
   - **Research links** — bottom of panel, grouped by `type`.

##### v1 explicitly does NOT include

- YouTube Data API search. LLM-suggested videos cover the primary path.
- Manual YouTube URL entry from the research panel (user can already paste into the existing video panel).
- Chat input or follow-up queries.
- Edit-request mode / diff preview / per-section regeneration.
- Voicing-hint preview rendering (the "copy to chord picker" button is enough; rendering chord diagrams in-panel is v1.5).
- Storing research as queryable rows. Research lives in `json_data.research` — ad-hoc, not searchable across the library.

##### Cost & quality (honest read)

Per assistant-mode lookup with grounding: ~2k input + 1500–3000 output tokens, 3–6 web searches. **~$0.05–0.10 per lookup on Gemini paid tier; 1 grounded request consumed on free tier.** Realistic admin usage 5–10 assistant-mode lookups per session ≈ 1% of daily free quota.

Quality picture:
- **Canonical changes + structure:** high.
- **Notable versions list:** medium. Famous recordings reliably named; specifics on what each version *changes from canonical* are often plausible-sounding generalizations — `source_url` is the user's reality check.
- **Voicing hints:** medium-low for "Herb Ellis specifically on this song" (rarely transcribed); higher for "drop-2 on top strings of Dm7♭5" (general technique, well-documented). The prompt rule "never claim specific transcription unless cited" is the load-bearing fix.
- **Suggested videos:** high for canonical recordings; lower for "the specific take you want." User judges via `recording_match`.
- **Transcription notes:** medium-high. Where the LLM is genuinely strong — explaining *why* a chord is what it is, what to listen for, where students typically trip. Reliably useful.

Net: the user gets a draft they don't fully trust on niche specifics, but with citations and rationale that turn it from "wrong answer" into "starting point for verification." That's the right shape for a transcribing assistant.

##### Files to create / modify

**New:**
- `resources/js/tab-editor/components/ResearchPanel.vue` — the third sidebar tab (mirror `EduPanel.vue`'s shape).
- `resources/js/tab-editor/composables/useResearchPanel.js` — reads/writes `json_data.research`.
- `tests/Unit/SongLookupAssistantModeTest.php` — assistant-mode prompt/response handling via `FakeLookupClient`.
- `tests/Feature/LeadsheetLookupAssistantTest.php` — full pipeline including research-block round-trip into `json_data`.

**Modified:**
- `app/Services/SongLookup.php` — assistant-mode branch (different system prompt, `useWebSearch=true`, schema includes `research`).
- `app/Services/AnalysisToLeadsheet.php` — pass `research` through to `json_data.research`. No transformation; it's already the right shape.
- `app/Models/LookupCache.php` — change cache key shape (normalize title only); migration for the existing rows can be a one-time `truncate` since it's a 30-day cache.
- `resources/views/admin/leadsheets/_lookup-modal.blade.php` — add the Quick / Assistant toggle.
- `resources/js/tab-editor/TabEditor.vue` — register the third sidebar tab.
- `app/Http/Controllers/Admin/LeadsheetController.php::createFromLookup` — accept `mode: 'quick'|'assistant'`, thread to `SongLookup::lookup()`.

##### Path forward beyond v1

Documented so v1's data shape supports the path without committing to it now:

- **v1.5 — "Refresh research" button.** Single follow-up call with current leadsheet state + a preset question. Not chat — a button. First test of context-aware re-queries.
- **v1.5 — Templated follow-ups.** Buttons / dropdown of canned questions on the research panel: "Tell me more about [section]," "Suggest voicings for [chord]," "What's known about [artist]'s version." Each is a single-shot call. Still not chat.
- **v1.5 — YouTube Data API search.** Fallback when LLM-suggested videos don't match. Search-and-pick UI in the research panel; selected video flows into the same `videoSync.setVideoId()` path.
- **v2 — Free-form chat panel.** Persistence layer for conversation history exists by then; context-passing pattern proven by v1.5 templates; UI affordances designed. Adding a text input becomes the smallest layer.
- **v3 — Edit requests with preview.** "Rebuild the A-section in F" → LLM proposes a diff → user previews → accepts/rejects. This opens the real action surface: needs diff/preview UI, undo integration with the existing chord-edit undo stack, and the read/write distinction enforced ("research questions" stay in the research block; "edit requests" are gated by preview+confirm).
- **v3+ — "Add melody in tabs."** Stretch from §4.5; lives here once v3's edit-request infrastructure is in place. Per-bar melody suggestions go through the same diff/preview gate.

The data shape locked in v1 (`research` as a structured object, suggested videos with `recording_match` typing, hints with attribution) means v2/v3 are extensions, not rewrites.

##### PR checklist (v1)

- [ ] Modal toggle Quick / Assistant; default Quick.
- [ ] §4.9.20 polish items (429/503 backoff, grounded-JSON fallback, melody fix, DeepSeek error body) shipped in this PR.
- [ ] `useWebSearch=true` only when mode=assistant; quick mode keeps current ungrounded behavior.
- [ ] `IntermediateAnalysis` schema extension implemented; quick mode emits `research: null`, assistant mode populates.
- [ ] Assistant-mode system prompt enforces version-handling, attribution, and recording-match rules.
- [ ] Cache key reshaped to normalized-title-only; existing cache truncated on deploy.
- [ ] `ResearchPanel.vue` renders all four sections; "Load in video panel" button calls `videoSync.setVideoId()` correctly.
- [ ] "Copy to chord picker" button on voicing hints works.
- [ ] Quick mode still works end-to-end (no regression).
- [ ] L1 / L2 / L2.5 unaffected.
- [ ] Tests: `FakeLookupClient` returns canned assistant-mode response; full pipeline writes `research` into `json_data` and editor renders it.

##### Things NOT to do (v1)

- Don't add chat. Templates and buttons first; chat earned by v2.
- Don't make assistant mode the default. Free-tier costs, longer wait, more output to review — Quick stays the friction-light default.
- Don't render chord diagrams inline in voicing hints. Copy-to-clipboard or copy-to-chord-picker is enough for v1.
- Don't auto-pick a video. Let the user choose; auto-picking the first `recording_match: 'exact'` would feel magical when it works and infuriating when it picks a tutorial.
- Don't store research separately from `json_data`. Keeping it in the JSON envelope means it round-trips through `LeadsheetParser` and persists with the leadsheet automatically.
- Don't bake YouTube parsing into `ResearchPanel.vue`. Use a small `extractYouTubeId(url)` utility (likely already exists somewhere in `useVideoSync.js` neighborhood — grep first).
- Don't ship voicing-hint validation in v1. If the LLM returns `xxxxxx` (no notes), the panel just shows it; user judges. Validating against the chord database is v1.5.

---

## 5. L4 — Source-driven extractors (add-on track, on standby)

**Status:** ❄️ ON STANDBY — except L4a (audio) which is partially implemented as L3a experimental.

> **Note (2026-05-04):** Audio transcription (L4a) has been partially built and embedded in the L3 modal as an experimental mode (`mode=audio`). It works end-to-end but quality is inconsistent. The **Jazz Standards DB** would significantly improve audio transcription accuracy by providing structural anchoring (force correct bar count, harmonic bias). Other L4 tracks remain theoretical.

Kept as a future track. The `IntermediateAnalysis` contract from L3 is what every L4 extractor would target — so L3 lays the architectural groundwork for free.

| Track | Source | Sketch | Standby trigger |
|---|---|---|---|
| L4a | Audio analysis (YouTube, MP3) | Python sidecar: madmom (beats) + chord-recognition model (BTC/CRNN) → `IntermediateAnalysis`. ChordMini-equivalent. | When admin needs recording-specific transcriptions L3 can't produce. |
| L4b | PDF / image OCR/OMR | Audiveris (OMR → MusicXML → `IntermediateAnalysis`). | When admin has a stack of scanned PDFs to ingest. |
| L4c | Soundslice-style scan | Soundslice's scan feature is the gold standard the admin already uses. Not directly integrable, but its UX (mark bars, confirm chords) is the model for any future L4a/b correction UI. | Reference for L4a/b UX design when those ship. |
| L4d | URL crawl (Ultimate Guitar, etc.) | Per-site scrapers, fragile. Mostly subsumed by L3's web-search tool, which the LLM uses transparently. | Probably never as a standalone feature. |

**Decision rule:** add an L4 track only when a concrete admin workflow is hitting an L3 wall. Don't pre-build.

### 5.1 L4a (audio analysis) — architectural prep notes

L4a is the most likely L4 track to actually ship someday — admin's workflow includes "transcribe a YouTube version of a pop song for a student" and "draft a recording-specific arrangement of a jazz tune." Worth recording what's already in place vs. what's missing, so future-you starts from a clear baseline.

**Already paving the way (no extra work needed):**
- **`IntermediateAnalysis` contract (§4.9.6)** is the integration point. Any audio extractor — self-hosted Python sidecar, hosted API, future model — must produce that exact JSON shape to plug into `AnalysisToLeadsheet`. Round-trip through `LeadsheetParser` is already tested. No schema drift will ambush L4a.
- **`LookupClient` adapter pattern (§4.9.7)** is the exact shape an audio backend wants. Future `AudioAnalysisClient` interface mirrors it: `analyze(audioFile, opts) → IntermediateAnalysis`, with adapters swappable via config (Sonauto / AudD / custom Python sidecar / future model).
- **`RhythmHintMapper`** is reusable — audio extraction produces tempo/style hints the same way the LLM does.
- **Per-chord `confidence` field** in the schema (§4.9.6, added during L3) is the L4a-critical primitive. L3 leaves it null; L4a populates it per chord (typical model output is 0.0–1.0).
- **`source_audio` slot** in the schema lets a leadsheet remember its origin and exposes bar-offset data for **Phase D video sync** without re-analysis.

**The one architecturally important thing to know now:**
**L4a's UX is draft + review, not just draft.** Audio chord-recognition is ~75–85% accurate on chord identity (and degrades sharply on jazz extensions/slash chords); beat detection is ~95%. That ~15% chord-error rate means **every L4a result needs human review before it becomes a leadsheet.** ChordMini's review screen with confidence-highlighted chords is the right model.

This implies a **shared editor feature worth designing now, building later: low-confidence chord highlighting**. Both L3 (sheet-level `confidence: low`) and L4a (per-chord `confidence < 0.7`) want it. A simple visual treatment in the existing chord grid — colored border, tooltip with alternatives — would serve both. Don't build it speculatively, but when either L3 user feedback or L4a planning comes up, this is the thing to ship alongside.

**Empirical guidance for when L4a actually starts:**
- **Beat detection quality > chord vocabulary.** A backend that gets correct beats with mostly major/minor chords is more useful than one with rich extensions and wrong beats. Wrong beats break the whole grid; wrong chords are one-cell fixes.
- **Stem separation matters.** Modern audio analysis (ChordMini, Sonauto, etc.) runs Demucs/Spleeter first to isolate harmonic content from drums/vocals. ~50% of processing time. Means **GPU access matters** — CPU-only sidecars are 5–10× slower.
- **Defer backend choice.** Chord-recognition models in 2026 are likely much better than today's open-source SOTA. Pick the backend when L4a actually starts, not now.

**Still NOT prepping speculatively:**
- No `AudioAnalysisClient` interface yet (premature; build it when there's a concrete adapter to fit).
- No queue infrastructure yet (one config change away in Laravel).
- No `sbn_leadsheet_drafts` table yet (wait until persisted draft state is actually needed).
- No upload UI / file-storage decisions yet.

---

## 6. Sequencing recommendation (revised 2026-05-04)

1. ~~**L1 — done.**~~ ✅ Manual blank sheets shipped.
2. ~~**Refactor `applyProgression` → `VoicingMaterializer`.**~~ ✅ Extracted.
3. ~~**Ship L2 base.**~~ ✅ Shipped.
4. ~~**Ship L2.5.**~~ ✅ `RhythmMaterializer` + wizard rhythm selector shipped.
5. ~~**Ship L3.**~~ ✅ LLM lookup + audio transcription shipped.
6. ~~**Jazz Standards DB**~~ ✅ Shipped. Seeded ~1400 standards. Local-first lookup in L3 (skip LLM).
7. ~~**L2 modal simplification.**~~ ✅ Shipped. Only Progressions + Jazz Standards sources remain.
8. ~~**L3 modal simplification.**~~ ✅ Shipped. Merged AI modes.
9. **Audio transcription improvements.** Reference anchoring from Jazz Standards DB (force correct bar count, harmonic bias during chord ID). Extract audio pipeline from `createFromLookup` into dedicated service.
10. **Evaluate.** Decide whether L3 (LLM) path is worth further investment based on usage patterns.

---

## 7. Open questions (resolved / remaining)

- ~~**Slugging:** does `Leadsheet` have a slug observer/trait?~~ ✅ RESOLVED — `Leadsheet::generateUniqueSlug()` static helper.
- ~~**Voicing defaults for L2/L3:**~~ ✅ RESOLVED — default to `popular` (most-popular voicing), user can switch.
- ~~**Rhythm pattern in L1:**~~ ✅ RESOLVED — defaults to "none".
- ~~**L3 LLM provider:**~~ ✅ RESOLVED — Gemini primary, DeepSeek/Groq/Cohere as alternatives via `LLMServiceProvider`.
- ~~**L3 caching:**~~ ✅ RESOLVED — `sbn_lookup_cache` table with 30-day TTL.
- ~~**Jazz Standards DB source:**~~ ✅ RESOLVED — Mike Oliphant's JazzStandards JSON repo is the authoritative source.
- ~~**L2 modal redesign scope:**~~ ✅ RESOLVED — Stripped to Progressions + Jazz Standards.

---

## 8. L1 implementation spec (ready for a coding AI)

> **Briefing for the implementer:** this section is self-contained. You don't need to read the rest of the doc to ship L1 — but §1 is useful background on what already exists. Do not modify L2/L3/L4 work; just L1.

### 8.1 Goal
Add a "+ New leadsheet" entry point on the admin leadsheets index that opens a **"Blank sheet"** modal. Submitting the modal creates a `Leadsheet` row scaffolded with empty measures and redirects to the existing edit view. The editor must work on the new row with no special-casing.

### 8.2 In scope
- Index-page entry point (dropdown button).
- Blank-sheet modal (form).
- New controller method + route.
- New `LeadsheetScaffolder` service.
- Tests for the scaffolder.

### 8.3 Out of scope
- L2 "From progression", L3 "From song lookup", L4 "From source" — leave these as **disabled** menu items in the dropdown with a `title="Coming soon"` tooltip. Do not implement.
- Changes to the editor itself.
- Any database migrations.

### 8.4 Files to create
- `app/Services/LeadsheetScaffolder.php` — see §8.7.
- `tests/Unit/LeadsheetScaffolderTest.php` — see §8.10.

### 8.5 Files to modify
- `app/Http/Controllers/Admin/LeadsheetController.php` — add `createBlank()` method (§8.8).
- `routes/web.php` (or wherever admin leadsheet routes are registered — find via `php artisan route:list | grep leadsheets`) — add `POST /admin/leadsheets/create-blank` → `LeadsheetController@createBlank`, named `admin.leadsheets.create-blank`.
- `resources/views/admin/leadsheets/index.blade.php` — replace any existing single "New" / "Import" button with a dropdown (§8.6).
- `resources/views/admin/leadsheets/_blank-modal.blade.php` (new partial, included from index) — the modal form (§8.6).

### 8.6 UX spec

**Index page button area** (replace whatever "New" button exists today):
- A dropdown labeled `+ New leadsheet` with options:
  - **Blank sheet** — opens the blank-sheet modal (this PR).
  - **From progression** — disabled, tooltip "Coming soon (L2)".
  - **From song lookup** — disabled, tooltip "Coming soon (L3)".
  - **From source…** — disabled, tooltip "Coming soon (L4)".
  - (Keep the existing **Import** button visible as a separate top-level action — do not move it inside the dropdown.)

**Blank-sheet modal fields:**
| Field | Type | Required | Default | Notes |
|---|---|---|---|---|
| `title` | text | yes | — | max 255 |
| `composer` | text | no | empty | max 255 |
| `song_key` | select | yes | `C` | use existing key list from `Leadsheet::getDistinctKeys()` plus the standard 12 (C, C#, D, …) and minor variants if the editor uses them |
| `tempo` | int | yes | `120` | 20–300 |
| `time_signature` | select | yes | `4/4` | offer `4/4`, `3/4`, `2/4`, `6/8`, `12/8` |
| `rhythm` | select | no | empty | populate from `RhythmPattern::orderBy('category')->orderBy('name')->get()` (same as `create()` does) |
| `structure_mode` | radio | yes | `simple` | `simple` or `sectioned` |
| `simple_bar_count` | int | when `mode=simple` | `16` | 1–256 |
| `sections` | repeatable rows | when `mode=sectioned` | `[{name:"Verse",bars:8},{name:"Chorus",bars:8}]` | each row: `name` (string, required, max 50), `bars` (int 1–64). Min 1 row, max 20. |
| `pickup_bar` | bool | no | `false` | adds an unnumbered pickup measure before the first section |

Validation errors render inline; submit is a regular form POST (not AJAX) for now — this matches the rest of the admin section.

### 8.7 `LeadsheetScaffolder` API

```php
namespace App\Services;

class LeadsheetScaffolder
{
    /**
     * Build the json_data + shortcode_content for an empty sheet.
     *
     * @param array $opts {
     *   title:          string,
     *   composer:       string,
     *   song_key:       string,
     *   tempo:          int,
     *   time_signature: string,
     *   rhythm:         string,
     *   structure:      array,   // see below
     *   pickup_bar:     bool,
     * }
     *
     * structure (one of):
     *   ['mode' => 'simple', 'bar_count' => int]
     *   ['mode' => 'sectioned', 'sections' => [['name' => string, 'bars' => int], ...]]
     *
     * @return array {
     *   shortcode_content: string,
     *   json_data:         string (JSON-encoded),
     *   measure_count:     int,
     * }
     */
    public function scaffoldBlank(array $opts): array;
}
```

**Output shape requirements:**
- `json_data` decoded must be `{ sections: [...], chordVoicings: {}, melody: [], repeatMarkers: [] }`.
- Each section: `{ name: string, measures: [...] }`.
- Each measure: `{ chords: [], beats: <derived from time_sig numerator> }` — match exactly what `LeadsheetParser` emits for the equivalent hand-authored shortcode. **Verification step: before finalizing, run an empty hand-authored shortcode through `LeadsheetParser::parse()` and ensure your scaffolder's output matches that structure key-for-key.**
- `shortcode_content` is the canonical `[sbn_leadsheet …]` body that, if parsed by `LeadsheetParser`, produces the same `json_data`. Round-trip must be lossless.
- Pickup bar: if `pickup_bar=true`, prepend a measure to the first section with a `pickup: true` flag (check whether `LeadsheetParser` already supports this convention; if not, leave pickup_bar **out of v1** and remove the field from the modal).

### 8.8 `LeadsheetController@createBlank`

```php
public function createBlank(Request $request, LeadsheetScaffolder $scaffolder)
{
    $validated = $request->validate([
        'title'                => 'required|string|max:255',
        'composer'             => 'nullable|string|max:255',
        'song_key'             => 'required|string|max:10',
        'tempo'                => 'required|integer|min:20|max:300',
        'time_signature'       => 'required|string|max:10',
        'rhythm'               => 'nullable|string|max:50',
        'structure_mode'       => 'required|in:simple,sectioned',
        'simple_bar_count'     => 'required_if:structure_mode,simple|integer|min:1|max:256',
        'sections'             => 'required_if:structure_mode,sectioned|array|min:1|max:20',
        'sections.*.name'      => 'required|string|max:50',
        'sections.*.bars'      => 'required|integer|min:1|max:64',
        'pickup_bar'           => 'nullable|boolean',
    ]);

    $structure = $validated['structure_mode'] === 'simple'
        ? ['mode' => 'simple', 'bar_count' => $validated['simple_bar_count']]
        : ['mode' => 'sectioned', 'sections' => $validated['sections']];

    $scaffold = $scaffolder->scaffoldBlank([
        'title'          => $validated['title'],
        'composer'       => $validated['composer'] ?? '',
        'song_key'       => $validated['song_key'],
        'tempo'          => $validated['tempo'],
        'time_signature' => $validated['time_signature'],
        'rhythm'         => $validated['rhythm'] ?? '',
        'structure'      => $structure,
        'pickup_bar'     => (bool)($validated['pickup_bar'] ?? false),
    ]);

    $leadsheet = Leadsheet::create([
        'title'             => $validated['title'],
        'composer'          => $validated['composer'] ?? '',
        'song_key'          => $validated['song_key'],
        'tempo'             => $validated['tempo'],
        'time_signature'    => $validated['time_signature'],
        'rhythm'            => $validated['rhythm'] ?? '',
        'measure_count'     => $scaffold['measure_count'],
        'shortcode_content' => $scaffold['shortcode_content'],
        'json_data'         => $scaffold['json_data'],
        'tab_xml'           => null,
        'description'       => '',
        'harmony_notes'     => '',
        'form_notes'        => '',
        'voicing_notes'     => '',
        'popularity'        => 0,
    ]);

    return redirect()
        ->route('admin.leadsheets.edit', $leadsheet)
        ->with('success', 'Blank leadsheet created.');
}
```

### 8.9 Symmetry verification (critical)
Before merging, run this manually and include the result in the PR description:
1. Use the scaffolder to build a 4-bar blank in `C 4/4`.
2. Take its `shortcode_content` and feed it through `LeadsheetParser::parse()`.
3. Diff that parser output against the scaffolder's `json_data`. They must match.
4. Open the created sheet in the editor; confirm: 4 empty measures, no errors, can add a chord, can save, reload preserves it.

If step 3 diverges, the scaffolder is wrong — fix the scaffolder, not the parser.

### 8.10 Tests
`tests/Unit/LeadsheetScaffolderTest.php`:
- `it_scaffolds_a_simple_blank_in_C_4_4` — 16-bar simple mode, asserts `measure_count === 16`, asserts one section with 16 empty measures, asserts `chordVoicings` is `{}` and `melody` is `[]`.
- `it_scaffolds_sectioned_layout` — 2 sections (Verse:8, Chorus:8), asserts measure_count 16, two sections with correct names and bar counts.
- `it_round_trips_through_parser` — scaffold → take `shortcode_content` → parse → assert equal to original `json_data` (the §8.9 check, automated).
- `it_validates_structure_input` — bad `mode`, missing fields, out-of-range bar counts all throw.

Feature test (optional but recommended):
- `tests/Feature/CreateBlankLeadsheetTest.php` — POST to the route as an admin, assert 302 to edit page, assert DB row exists with expected values.

### 8.11 PR checklist
- [ ] `+ New leadsheet` dropdown on index page; only "Blank sheet" is enabled.
- [ ] Modal validates client-side and server-side.
- [ ] `LeadsheetScaffolder` service created with the API in §8.7.
- [ ] Round-trip with `LeadsheetParser` verified (§8.9).
- [ ] Unit tests in §8.10 passing.
- [ ] Manually opened a created sheet in the editor — works, saves, reloads.
- [ ] L2/L3/L4 dropdown items present but disabled with tooltips.
- [ ] No DB migration introduced.
- [ ] `php artisan route:list` shows `admin.leadsheets.create-blank`.

### 8.12 Things NOT to do
- Don't add a feature flag — this is a small additive change.
- Don't refactor `LeadsheetController::store()` or the import path.
- Don't touch `LeadsheetParser`. If you find the parser doesn't handle empty measures correctly, **stop and report back** rather than fixing it — that's a Phase 9 concern and needs separate review.
- Don't add an "AI suggestions" hint, draft autosave, or any L2/L3-flavored polish. Ship the blank.
- Don't widen scope to "while I'm here, let me also fix X" — keep the diff small.

---

## 9. Definition of done (whole phase)

- L1, L2, L3 shipped and used by admin to author new sheets.
- `IntermediateAnalysis` contract documented; adding an L4 extractor is a single-file change.
- No regressions in existing import/edit flows.
- This document updated with as-built notes per layer (mirroring the Phase-9 doc style).
- L4 remains documented but unbuilt; revisit only when a concrete workflow demands it.

---

## 10. As-Built Implementation Notes (L1 Addendum)

During final testing of the L1 manually created blank sheets, several architecture gaps relating to state sync and serialization logic required explicit resolution.

### Modified Files:
1. **`app/Services/LeadsheetScaffolder.php`**
   - Cast `chordVoicings` as an explicit `(object) []` during empty data scaffolding to guarantee encoding as a standard JSON object `{}` instead of a sequential array `[]`.

2. **`resources/js/tab-editor/composables/useTabModel.js`**
   - Enhanced `cloneChordVoicings(src)` to intercept PHP's sequential empty arrays `[]` and normalize them into empty `{}` containers, protecting against property-dropping bugs in `JSON.stringify`.

3. **`resources/js/tab-editor/composables/useVoicingPickerStore.js`**
   - Injected global `sbn-tab-sections-sync` event dispatches into the close state paths of `applyVoicing()` and `removeVoicing()` so Alpine receives Vue model mutations cleanly.

4. **`app/Services/LeadsheetParser.php`**
   - Protected the internal evaluation flow from standard `stdClass` type restrictions by maintaining native array support, applying cast transitions exclusively prior to return statements.

5. **`resources/views/admin/leadsheets/edit.blade.php`**
   - Synchronized the automated skeletal generation algorithms to read positional override keys (`${name}@${gi}.${ci}`).

6. **`tests/Unit/LeadsheetScaffolderTest.php`**
   - Calibrated test expectations against Phase L baseline targets.

---

## 11. Deferred features (parked, revisit when needed)

Features that are real improvements but lower priority than L2.5/L3. Documented so they aren't lost; not roadmapped. Pick up when a concrete workflow demands them.

### 11.1 Clone source — recipe-based redesign

**Status:** ❄️ deferred (origin: post-L2 review).

**Problem with current shipped clone:** "From existing leadsheet" currently flattens the source into chord names only and re-scaffolds. The label suggests "duplicate this sheet" (which is what the original spec said via `replicate()`), but the behavior is closer to "import chord names." Either rename or redesign — not both at once.

**Proposed direction (when revisited):** rename UI to **"From existing leadsheet (simplify)"** and add a recipe select:

| Recipe | What it does | Use case |
|---|---|---|
| **Chord names only** (default, current behavior) | Keep section structure + chord names. No voicings, melody, tab. | Clean slate over the same form. |
| **Archetypes** | Reduce each chord to its quality archetype (`Dm7♭5 → Dm7`, `Cmaj9#11 → Cmaj7`, slash chords lose bass). | Teach a simpler version of a complex song. |
| **Triads only** | Reduce each chord to a triad (`Cmaj7 → C`, `G7♭9 → G`). | Beginner students. |
| **Voiced — shell** | Names + auto-build shell voicings. | Quick-start with playable defaults. |
| **Voiced — drop-2 on top strings** ("Wes Montgomery style") | Names + auto-build drop-2 biased to strings 1–4. | Comping practice. |

**Implementation surface (when revisited):**
- New `app/Services/ChordSimplifier.php` with `simplifyToArchetype(string)` and `simplifyToTriad(string)` — pure string ops, ~40 lines + tests.
- Recipe routing in `LeadsheetController::createFromSequence` clone branch.
- Voiced recipes reuse `ProgressionBuilder::selectVoicingsForSequence` with different `category` options.

**Recommended v1 scope when picking this up:** ship "Chord names only" (rename + match label) and "Archetypes" (the genuinely useful new feature). Stub the rest as disabled options with `title="Coming soon"`.

### 11.2 Key transposition for concrete-chord input

**Status:** ❄️ deferred (origin: post-L2 review).

**Problem:** the wizard's "Key" field is only used to resolve numerals. When the user types concrete chord names (`Dm7 G7 Cmaj7`) and picks `F` as the key, the chords are passed through unchanged — the sheet ends up labeled `F` but its chords are still in `C`.

**Proposed direction (when revisited):**
- New `app/Services/ChordTransposer.php` with `transpose(string $chordName, int $semitones): string`. Splits chord into root + quality + bass note, transposes root and bass via `HarmonicContext`'s `NOTE_TO_SEMI` / `SEMI_TO_NOTE` tables, leaves quality untouched. Handles slash chords (`C/E → F/A`), enharmonic spelling (prefer flats in flat keys), passthrough on invalid input.
- Wizard adds an optional **"Transpose from…"** toggle that reveals a second select. Off by default. When on: input is interpreted as being in the "from" key; output gets transposed to the "Key" field. Numerals are unaffected — they keep resolving in the target key directly.
- `createFromSequence`: if `from_key` ≠ `song_key`, compute the semitone delta once and map every concrete chord through `ChordTransposer::transpose` before scaffolding.

**Tests when shipped:** simple roots, accidentals, enharmonic preference, slash chords, invalid input passthrough.

**Why deferred:** the issue is real but the workaround (type chords directly in the target key, or use numerals) is acceptable for now. The clone redesign (§11.1) and key transposition share the "What does the key field mean?" UX question, so they're best tackled together when we come back to this code.

### 11.3 L2.5 v2 stretch list (when v1 ships)

Rolled up here so they're tracked. None are urgent. See §3.6.7.
- Voicing-aware bass-note detection (thumb hits the actual chord bass, not a fixed string set).
- Ties across rests (sustain a struck chord through subsequent `.` steps).
- Swing-eighths feel transformation.
- Palm mute, ghost notes, dynamics encoded as MusicXML `<dynamics>`.
- Multi-bar patterns (when `pattern.beats` exceeds one bar of the sheet's time signature).

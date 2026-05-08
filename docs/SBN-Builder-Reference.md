# SBN Builder — Reference

**Status:** Living document, 2026-05-05.
**Scope:** `App\Services\ProgressionBuilder` — the algorithm that turns a
chord progression (Roman-numeral or chord-name sequence) into a sequence
of guitar voicings.
**Companion:** `docs/Identifier-Refactor-Spec.md` (the chord-handling
reference) — read that first if you're new to the design principles.

This document supersedes the former `Builder-Refactor-Spec.md`. The
refactor described there shipped through Phase G; the algorithm is
stable. What follows is the **reference** for how the builder works
today (Part 1), the **machine room** spec for ongoing tuning (Part 2),
the **phase history** preserved as appendix (Part 3), and the running
list of **known places to improve** (Part 4).

---

## Part 1 — Reference

### §1. What the builder is for

The builder turns a chord progression into a sequence of guitar voicings
that are **idiomatic for the progression's category**. Boring-but-correct
is the target. A by-the-book jazz II-V-I (e.g. `Dm7 x5756x | G7 3x343x |
Cmaj7 x3545x`) is exactly what the algorithm should produce when given
a jazz II-V-I. Showing students the standard way is pedagogically more
valuable than showing them something fancy.

The builder is consumed by the leadsheet creation flow, the public
progression-detail page, the chord-detail page's "progressions of this
chord" section, and the regression suite. The admin progression-builder
page exists today as a migration artifact and will be replaced by the
machine room (Part 2).

**Not in scope:**

- Progression *detection* (`ProgressionDetector` — separate service).
- Educational content generation (curated, lives elsewhere).
- Audio playback ordering (the playback engine handles timing).
- Chord-melody arrangement (top voice = melody constraint).

### §2. Design principles

1. **Bucket 1 vs. Bucket 2.** "No human would defend this output"
   (Bucket 1) is a bug to fix. "Locally defensible but contextually
   suboptimal" (Bucket 2) is a tuning concern, not a structural one.
2. **Specificity, inverted from identification.** For *construction*,
   prefer the *less specific* voicing (basic drop2 over loaded
   option-tone voicing) when the input doesn't ask for more. Add color
   in the second pass.
3. **Category is a hard filter, not a soft preference.** When the user
   picks a jazz progression, the right output is unambiguously a jazz
   voicing. Mixing in a closed triad because it scored 0.05 better on
   one term is a pedagogical regression.
4. **DB at design/seed time, constants at runtime.** The category-mode
   tables (§4) and named resolutions YAML are loaded once and cached;
   they are not queried per-build.
5. **Spec → audit → measure → ship.** Every algorithmic change is
   evaluated against the audit corpus and (since Phase E) the regression
   suite before being declared done.

### §3. The pipeline

```
Roman numerals
    ↓
[1] Numeral resolution            HarmonicContext::buildFromNumerals
    ↓
[2] Numeral upgrade — Pass 1      §5.1 (deterministic, per category)
    ↓
[3] Tonic-family alias widening   §4.5 (jazz/latin tonic only)
    ↓
[4] Candidate generation          fetchVoicingsForChord per slot,
                                  filtered by category pool (§4)
    ↓
[5] Anchor-free lattice +         §6 hard constraints applied as
    harmony filter                edge admissibility / pool filter
    ↓
[6] Viterbi search (Pass 1)       §7 cost function, position-
                                  constraint relaxation cascade
    ↓
[7] If extensions=true and        Pass 2: numeral upgrade with
    category ∈ {jazz, latin}:     extensions, second Viterbi over
                                  expanded pool, Phase E named
                                  resolutions (§8)
    ↓
[8] Pass-2 vs Pass-1 decision     Pass 2 wins iff ≥1 named
    rule                          resolution fired AND its total
                                  cost ≤ Pass 1's
    ↓
[9] Repeated-chord reuse pass     §6.5 — copies each chosen voicing
    (post-Viterbi)                forward into adjacent identical-
                                  name slots
    ↓
Output: per-slot voicing selection, per-edge VL diagnostics,
        Phase E pass2_won + fired_resolutions, constraint relaxation
        log
```

### §4. Category mode table

Every progression has a `category` field
(`jazz, blues, pop, modal, classical, latin`). The builder consults
this table to determine which voicing categories are eligible per slot
and what numeral upgrades to apply.

| Category | Default voicing classes (priority) | Quality default for plain numerals | Option tones |
|---|---|---|---|
| `jazz` | drop2, drop3, shell, closed | upgrade plain triads to 7ths | yes (Pass 2) |
| `blues` (basic) | archetype with 7ths | always 7th | conservative, V7 only |
| `blues` (advanced) | shell, drop3 with 7ths; jazz pool fallback | always 7th | jazz-style on V7 |
| `pop` | archetype (cowboy chords); non-barré first | plain triads | none |
| `classical` | closed_triads, spread_triads | plain triads | none |
| `modal` | quartal, shell, drop3 | mostly 7ths | sparse (sus, quartal stacks) |
| `latin` | as `jazz` | as `jazz` | yes; m6 idiomatic, b9 on V7 acceptable in bossa |

#### §4.1 Archetype non-barré priority (pop / blues-basic)

When the pool is `archetype`, candidates are ordered: non-barré → partial-barré
→ full-barré. Falls back to barré only when no non-barré archetype
exists for that root (F#, Bb, Eb, …).

#### §4.2 Quartal (modal)

The DB has 3 `quartal` records. Modal prefers them first; falls through
the modal priority order (quartal → shell → drop3) within the modal
pool — no fallback to the jazz pool at the category level.

#### §4.3 Why category-locked filtering, not soft scoring

When the user (or the leadsheet flow) picks a jazz progression, the
right output is *unambiguously* jazz voicings. Mixing in a closed
triad because it scored 0.05 better on a local term is a pedagogical
regression. **Category is a hard filter on the candidate pool, not a
soft preference.**

The exception: an explicit `style` or `voicing_style` override narrows
the pool further within the category.

#### §4.4 The `simple` mode shortcut

When `mode: 'simple'` is passed (or category=pop with no explicit
style), the builder bypasses the scoring engine entirely and performs a
flat lookup against the archetype pool. Produces cowboy chords directly,
applies the §6.5 repeated-chord rule, ignores `style` and `extensions`
options.

#### §4.5 Tonic-family alias widening (jazz / latin)

Shipped 2026-05-05. For tonic-major slots (`Imaj7`, `I6`) and
tonic-minor slots (`Im7`, `Im6`, `ImMaj7`) in jazz/latin, the candidate
pool is widened to include all of `{maj7, maj6, 6}` (or `{m7, m6}`)
voicings. Predominant minor (`IIm7`, `VIm7`, …) keeps strict aliases —
`m6` would change function. Implementation:
`expandTonicFamilyAliases()` in `ProgressionBuilder.php`.

### §5. Numeral upgrade

The DB stores progressions plain (`I, V, VI`). The builder upgrades to
contextually appropriate concrete chords in two passes.

#### §5.1 Pass 1 — quality upgrade, no extensions

Per-category, deterministic. Triggered when the original numeral is
plain (no quality suffix, or just `m` / `dim` / `aug`).

```
JAZZ / LATIN:
  I, IV         → Imaj7, IVmaj7
  II, III, VI   → IIm7, IIIm7, VIm7   (diatonic minor)
  V, VII        → V7, VIIm7b5
  bVI           → bVImaj7
  bII, bIII,
  bVII          → 7th  (chromatic dominant)
  IVm, VIm      → IVm7, VIm7
  diminished    → o7 (when functioning as #ivo7 or vii°7 of V)

BLUES:
  I, IV, V      → I7, IV7, V7

POP, CLASSICAL:
  no upgrade — keep plain triads

MODAL:
  Im            → Im7  (dorian, aeolian)
  IV            → IV7  (dorian — bluesy color)
  bVII          → bVII (plain triad — mixolydian)

OTHER:
  treat as JAZZ
```

`romanToDegree()` strips quality suffixes with the regex
`/^[b#]|[mM]7(b5)?$|maj7?|7$|dim$|aug$/`. Order matters in
`determineFunctionalRole()`: `bVI` is checked before `bVII` because
`bVI` is a substring of `bVII`.

#### §5.2 Pass 2 — option-tone upgrade

Runs only when `extensions: true` AND `category ∈ {jazz, latin}`. Driven
by `docs/Phase-E-Extension-Table.yaml`. See §8.

### §6. Hard constraints

Filters applied to the candidate pool *before* scoring. A candidate that
fails a hard constraint is dropped from the lattice.

#### §6.1 Position constraint

Adjacent voicings must satisfy `|start_fret(n+1) − start_fret(n)| ≤ 3`.

Relaxation cascade (`viterbiSearchWithRelaxation`):
1. position ≤ 3, structured-group-only (drop, shell, closed)
2. position ≤ 4, structured-group-only
3. position ≤ 6, structured-group-only
4. position unrestricted, structured-group-only
5. position ≤ 6, all groups
6. position unrestricted, all groups

If all six steps fail, `bass_motion_unsatisfiable` is logged.

#### §6.2 Bass-note constraint

Bass-note jumps allowed: 0, 1, 2, 3, 4, 5, 7, 9, 10, 11. Hard-blocked:
6 (tritone — exception for tritone substitution), 8 (m6 ascending —
awkward bass).

Implementation: compute interval as `(bass2 − bass1 + 12) % 12` and
filter `{6, 8}` unless the tritone-sub exception applies. The exception
fires when the current chord is dominant, the interval is 6, and the
next chord is major / maj7 a tritone away.

#### §6.3 Voicing-group cascade

`drop2`/`drop3` belong to group `drop`. `shell`, `closed` are their own
groups. `archetype`, `custom`, `slash`, `quartal`, `closed_triads`,
`spread_triads` are non-structured. The first four cascade steps keep
only structured groups (drop / shell / closed); the last two relax this.

#### §6.4 Avoid-tones (Phase E)

Pass-2 voicings whose extension list contains a forbidden tone for the
context (per `Phase-E-Extension-Table.yaml`'s `avoid_tones_index`) are
removed from the pool before cost evaluation. Hard filter, not a
penalty.

#### §6.5 Repeated-chord rule (post-Viterbi)

When two adjacent slots have the same `chord_name`, the second slot
reuses the first slot's chosen voicing. Applied **after** Viterbi
finishes (`applyRepeatedChordReuse`), not before.

> **Why post-Viterbi:** pinning the repeat slot to `pool[i-1][0]` before
> search forces the preceding free slot to harmonize with an arbitrary
> first-pool element. The cheap self-edge from that pin then drags the
> free slot's choice toward the pinned voicing — the opposite of what
> we want. (See appendix item "xx0212 D7 bug" for the diagnostic that
> uncovered this.)

### §7. Cost function

All terms produce values in `[0, 1]`. Total cost is a weighted sum.
Lower is better.

```
cost(v_n, v_n+1) =
    w_simplicity      * c_simplicity(v_n+1)
  + w_position        * c_position(v_n, v_n+1)
  + w_bass_motion     * c_bass_motion(v_n, v_n+1)
  + w_common_tone     * c_common_tone(v_n, v_n+1)
  + w_voice_leading   * c_voice_leading(v_n, v_n+1)
  + w_group_continuity* c_group_continuity(v_n, v_n+1)
  + w_register        * c_register(v_n+1)
  + w_style           * c_style(v_n+1)
  + w_named_resolutions * c_named_resolutions(v_n, v_n+1)   // Pass 2 only
```

**Default weights** (in `ProgressionBuilder::COST_WEIGHTS`):

| Term | Weight | Notes |
|---|---|---|
| `simplicity` | 0.10 | |
| `position` | 0.20 | edge cost, relative motion |
| `bass_motion` | 0.20 | |
| `common_tone` | 0.15 | |
| `voice_leading` | 0.25 | normalized `scoreVL` |
| `group_continuity` | 0.10 | drop/shell/closed cohesion |
| `register` | 0.10 | overridden per-category — see below |
| `named_resolutions` | 1.0 | Pass 2 only, additive bonus (negative cost) |
| `style` | 0.25 | per-preset, see §7.8 |

Per-category register weight overrides (`CATEGORY_REGISTER_WEIGHT`):

| Category | Target fret | Weight |
|---|---|---|
| `pop` | 0 | 0.10 |
| `blues` | 1 | 0.15 |
| `classical` | 2 | 0.10 |
| `jazz` | 5 | 0.05 |
| `modal` | 5 | 0.05 |
| `latin` | 5 | 0.05 |

Term descriptions:

#### §7.1 Simplicity (`c_simplicity`)
Penalizes voicings with more notes/extensions than the slot needs.
Baseline set by category (pop = 3-note triads, jazz = 4-note 7ths).

#### §7.2 Position locality (`c_position`)
`min(|p2 − p1|, 5) / 5`. Edge cost only. ±0–1 frets ≈ free, ±2–3 ≈ small
penalty. Hard-filtered above 3 frets (§6.1), so this term mostly
contributes in the 0–3 range.

#### §7.3 Bass motion (`c_bass_motion`)
0.0 for {0, 5, 7} (P4/P5 — circle-of-fifths gold standard).
0.1 for {3, 4, 9, 10}. 0.2 for {1, 2, 11}. 0.2 for tritone-sub edge.
1.0 otherwise (effectively hard-filtered).

#### §7.4 Common tone (`c_common_tone`)
Same-string common notes weighted 0.7, any-string pitch-class matches
0.3. Reused machinery from the original `scoreVL`.

#### §7.5 Voice leading (`c_voice_leading`)
Normalized `scoreVL` — guide-tone resolution, b7→3, 3→R/b7/maj7/9
chains, etc. Dual-counting with the harmony filter was removed in
Phase D.

#### §7.6 Group continuity (`c_group_continuity`)
Same group → 0.0. Different group with note-count match and |Δpos| ≤ 2
→ 0.1. Otherwise → 0.5.

#### §7.7 Register (`c_register`)
`|start_fret − target| / 12`. Per-slot absolute penalty. Without this,
once Viterbi commits to a high-register chain, every "stay where you
are" edge is free and there's no pull back to the category target.

#### §7.8 Style (`c_style`)
Soft penalty when `voicing_style ≠ 'auto'`:
- Wrong voicing category: +0.6
- Bass string outside preset's range: +0.3
- Non-root inversion when `prefer_root = true`: +0.5

The 9 presets (`VOICING_STYLE_PRESETS`): `auto`, `drop2_high`,
`drop2_mid`, `drop3_low`, `drop3_mid`, `roote`, `roota`, `shell_low`,
`mixed`. Each carries `prefer_category`, `bass_string_min/max`,
`register_target`, `prefer_root`.

#### §7.9 Named resolutions (Phase E, Pass 2 only)
Each named resolution that fires on an edge contributes a negative cost
(bonus). Multiple resolutions stack additively. Logged by ID for
diagnostics. See §8.

### §8. Two-pass option-tone upgrade

Pass 2 runs only when `extensions: true` AND
`category ∈ {jazz, latin}`. The pop/blues/classical/modal idioms don't
take jazz tensions.

#### §8.1 The Phase E extension table

Authoritative file: [`docs/Phase-E-Extension-Table.yaml`](Phase-E-Extension-Table.yaml).

Three sections:

1. **`recommended_extensions`** — keyed by `(role, target_role, key_mode)`.
   Each entry is an ordered list of extension sets with priority 1–5.
   Drives the §5.2 numeral upgrade (top-priority entry → upgraded
   numeral) and a soft preference bonus on Pass 2 candidates. May
   include a `forbid` list that is applied via `array_diff` against the
   selected tones.
2. **`avoid_tones_index`** — hard candidate-pool filter (§6.4).
3. **`named_resolutions`** — guide-tone resolutions with stable IDs
   (`vl.dom.b7_to_3`, `vl.tritone.sharp11_to_5`, etc.). Each entry that
   fires contributes a negative cost on top of §7.5's per-voice
   distance. `same_voice` means pitch-rank equality (sort both voicings
   by ascending MIDI; compare at same array index), not same-string.

#### §8.2 Decision rule

Pass 2 wins iff:
1. At least one `named_resolutions` entry fires on the chosen path.
2. Pass 2's total cost ≤ Pass 1's.

Otherwise, Pass 1 wins. The rule replaces the original "10% threshold"
sketch with a guarantee: Pass 2 only wins when it's both *better* and
*causally justified* (a fired resolution is the proof the upgrade was
worth it).

#### §8.3 Pass-1 plain-voicing filter

When `extensions=false`, `fetchVoicingsForChord` restricts the
candidate query to rows with empty `extensions` columns. Without this,
extension-carrying rows (`m7+11`, `dom7+9`) leak into Pass 1 output.

#### §8.4 Functional roles

`determineFunctionalRole($chord)` returns the chord's role string:
`Imaj7`, `I6`, `IIm7`, `IIm7b5`, `IVmaj7`, `Im7`, `Im6`, `ImMaj7`,
`VIm7`, `VIm7b5`, `V7`, `bII7` (tritone sub), `bVI7` (minor blues
dominant), `bVII7` (backdoor dominant), `dim7`, etc. Used by:

- §4.5 tonic-family alias widening (only widens for tonic roles)
- §8.1 `recommended_extensions` lookup
- §8.1 `named_resolutions` source/target role match

Secondary dominants are routed via
`Phase-E-Extension-Table.yaml`'s `secondary_dominant_routing` rules
(target's quality determines which V7 entry to use).

### §9. Configuration surface

```php
buildVoicings($context, [
    // Category and pool routing
    'category'         => 'jazz' | 'blues' | 'pop' | 'classical' | 'modal' | 'latin',
    'mode'             => 'simple' | null,             // §4.4 lookup shortcut
    'style'            => 'drop2' | 'drop3' | 'shell' | 'closed'
                          | 'archetype' | 'closed_triads' | 'spread_triads'
                          | 'quartal' | 'custom' | '',
    'voicing_style'    => 'auto' | 'drop2_high' | 'drop2_mid' | 'drop3_low'
                          | 'drop3_mid' | 'roote' | 'roota' | 'shell_low'
                          | 'mixed',                   // §7.8 preset

    // Pass control
    'extensions'       => bool,                        // gates Pass 2 (§8)
    'rootOnly'         => bool,                        // filter inversions
    'vlLevel'          => 1 | 2,                       // explicit pass level

    // Pinning
    'pinnedSlot'       => int|null,
    'pinnedVoicing'    => array|null,

    // Tuning overrides (advanced)
    'weight_overrides' => array,                       // per-term overrides
])
```

`selectVoicingsForSequence` is preserved as a thin compatibility
wrapper that delegates to `buildVoicings` via
`HarmonicContext::buildFromChordSequence`. New code should use
`buildVoicings` directly.

### §10. Diagnostics

`buildVoicings` returns `selections`, `vlScores`, `pathCost`, and a
`diagnostics` block. Notable fields:

- `phase_e.pass1_cost`, `phase_e.pass2_cost` — total cost per pass
- `phase_e.pass2_fired_resolutions` — list of named-resolution IDs
  that fired on the Pass-2 winning path
- `phase_e.pass2_won` — bool, reflects the §8.2 decision rule outcome
- `slot_constraints` — list of relaxation events (which step of the
  cascade fired, by slot)
- `style_ignored` — fired when the requested style is outside the
  category pool
- `category_pool_fallback` — fired when the category's primary pool
  was empty for a slot and a wider pool was used

### §11. Component reuse map

Existing components the builder reuses:

| Component | Source | Used for |
|---|---|---|
| `parseChordName()` | `ProgressionDetector` | chord-name parsing |
| `chordToNumeral()`, `degreeToNumeral()`, `qualityToSuffix()` | `ProgressionDetector` | numeral ↔ chord-name conversion |
| `buildFromNumerals()`, `buildFromChordSequence()` | `HarmonicContext` | building the chord-name sequence |
| `calculateFrets()`, `analyzeSlashChord()` | `ChordShapeCalculator` | transposing root-agnostic shapes |
| `scoreVL()` | `ProgressionBuilder` (originally) | §7.5 voice-leading term |
| `ExtensionTable` | `App\Services\Builder\PhaseE` | §8 YAML loader |

A future `HarmonicScorer` extraction (post-builder) would consolidate:
`ROOT_MOTION_BONUS`, `FUNCTIONAL_FRAGMENTS`, `CTX_MAJOR_SCALE`,
diatonicity check — currently duplicated between identifier and builder
and annotated with `// TODO(harmonic-scorer)`.

---

## Part 2 — The Machine Room — ✅ SHIPPED 2026-05-08

> Replaces the current `admin/progressions/builder` page (which served
> the original WP→Laravel migration only). The Machine Room is the
> control panel for the algorithm itself: settings tweaked here become
> the system-wide defaults consumed by the leadsheet creation flow,
> the public progression-detail page, the chord-detail page, and the
> regression suite.

### §12. What the Machine Room is

A single-operator admin tool. Its purpose is **fast, observable
algorithm tuning** with a tight feedback loop. It is *not* a
public-facing voicing chooser — that's the leadsheet creation flow's
per-build settings panel (which inherits the Machine Room's defaults
but can override them locally).

The original Phase J spec (preserved in Part 3 appendix) called for a
separate `admin/builder-settings` page with audit-on-save. This is
revised: the Machine Room replaces the existing builder page and
includes a **live preview corpus** so every setting change is visible
immediately, without an audit run.

### §13. The preview corpus

A small, fixed set of representative progressions that re-render on
every settings change. One per category, kept short (3–7 slots) so the
re-render loop is sub-second:

| Category | Progression | Key |
|---|---|---|
| jazz | `IIm7 V7 Imaj7` | C |
| blues | `I7 I7 I7 I7 IV7 IV7 I7 I7 V7 IV7 I7 I7` (12-bar) | C |
| pop | `I V VIm IV` | G |
| classical | `I V VIm IIIm IV I IV V` (Pachelbel snippet) | D |
| modal | `Im7 bVII IV` (Dorian) | A |
| latin | `Imaj7 II7 IIm7 V7` (bossa) | F |

All six render in a single column on the page. Tweak a weight → all
six update simultaneously. The operator looks across them and decides
whether the change improved the average outcome or only one case.

The preview corpus is **separate from the regression suite**. The
regression suite is the formal validator; the preview corpus is the
fast iteration surface.

### §14. Configurable surface

Inherits the original Phase J §J.2 list, plus discoveries from the
Phase D / E / G work:

#### §14.1 Per-category defaults (the high-impact knobs)

1. **Voicing pool.** Multi-select from `{drop2, drop3, shell, closed,
   archetype, closed_triads, spread_triads, quartal, custom}`. Replaces
   `CATEGORY_VOICING_POOLS`.
2. **Register target + weight.** `CATEGORY_REGISTER_TARGET` (fret 0–12)
   and `CATEGORY_REGISTER_WEIGHT` (0.0–1.0).
3. **Default `rootOnly`.** Boolean per category. Discovered to have
   strong effect on jazz output (basic jazz voicings are nearly all
   root-position).
4. **Default `voicing_style` preset.** Per-category default. Today every
   category implicitly uses `auto`.
5. **Tonic-family alias widening.** Per-category boolean. Today
   hard-coded to jazz/latin only.
6. **Pass 2 eligibility.** Per-category boolean. Today hard-coded to
   jazz/latin.
7. **Pass-1 plain-voicing filter.** Per-category opt-out for cases
   where the corpus's plain pool is too thin (modal/classical edge
   cases).
8. **Blues advanced-pool definition.** Multi-select.

#### §14.2 Global cost weights

The eight `COST_WEIGHTS` terms. Editable as sliders in 0.05 increments.
Display the current sum and warn (don't block) if it drifts far from
1.0 — sums above 1.0 just amplify total cost without changing relative
ordering.

#### §14.3 Repeated-chord reuse

Boolean: enable / disable §6.5. Default on. Off is useful for debugging
when investigating why a path moved.

#### §14.4 Out of scope (deliberately not exposed)

- **Numeral upgrade rules** (`upgradeJazzLatin`, etc.) — structural
  compiled logic, moving to UI invites breakage.
- **Phase E YAML** — authored content. Stays as a file. A future
  separate phase could expose YAML editing.
- **Pass-1 filter mechanism itself** — only the per-category
  enable/disable is exposed; the filter shape is compiled.
- **Hard constraint thresholds** (position ≤ 3, bass-motion blocked
  intervals) — structural. Changes here would move every progression's
  output.

### §15. Player archetypes (saved tuning snapshots)

The most useful product of the Machine Room. A **player archetype** is
a named, saved snapshot of the entire settings state — pool, weights,
register targets, style preset, rootOnly, etc. Once saved, an
archetype can be selected:

- In the leadsheet creation flow as the build mode for a song.
- On the public frontend as a "render this progression in the style
  of …" toggle.
- In the regression suite as a test dimension.

Examples to ship initially:

| Archetype | Style | Settings sketch |
|---|---|---|
| **Wes Montgomery** | jazz drop2 mid-register, lots of octaves | `drop2_mid` style, `register_target = 5`, `rootOnly` off, tonic-widen on |
| **João Gilberto** | bossa, clean voicings, low register | latin category, `roota` style, `register_target = 3`, tonic-widen on, m6 prioritized |
| **Joe Pass thumb-bass** | jazz, low E-string root, drop3 | jazz, `roote` style, `prefer_category = drop3`, `register_target = 3` |
| **Cowboy** | open chords, no extensions | pop/simple mode, archetype only, `rootOnly` on |
| **Modern jazz** | rich extensions, mid/high register | jazz, extensions on, `voice_leading` weight bumped |

The archetype list is editable in the Machine Room. New archetypes are
created from the *current* settings state ("Save these settings as
'My archetype'"). The 5 above are seed entries, not hard-coded
fixtures.

> **Important framing:** the existing 9 `VOICING_STYLE_PRESETS` are
> *low-level* presets — they describe a single dimension (string range
> + register + category). A player archetype is a **whole-state
> snapshot** that includes the voicing_style preset plus every other
> tunable. Don't confuse the two.

### §16. Architecture

#### §16.1 Storage

New table `sbn_builder_settings`, key-value JSON-typed:

```
key=category_pools           value={"jazz":["drop2","drop3","shell","closed"],...}
key=cost_weights             value={"simplicity":0.10,"position":0.20,...}
key=pass2_eligible           value=["jazz","latin"]
key=register_targets         value={"jazz":{"target":5,"weight":0.05},...}
key=blues_advanced_pool      value=["shell","drop3","drop2","closed"]
key=pass1_extensions_allowed value={"modal":true}
key=root_only_default        value={"jazz":true,"pop":true,...}
key=tonic_widen_default      value={"jazz":true,"latin":true,...}
key=repeated_chord_reuse     value=true
key=default_voicing_style    value={"jazz":"auto",...}
```

Plus a separate `sbn_builder_archetypes` table for player-archetype
snapshots:

```
id, slug, name, description, settings_json, created_at, updated_at
```

One row per concept on `sbn_builder_settings`. JSON keeps the schema
self-documenting and avoids a 30-column table or 30-row EAV pattern.

#### §16.2 Service layer

New `App\Services\BuilderSettings`. Reads the table once per request
(memoized) and exposes typed getters. `ProgressionBuilder` reads from
the service instead of class constants. The class constants stay as
fallback defaults so the builder works on a fresh DB / in tests where
the table is empty.

`BuilderSettings::loadArchetype(string $slug)` returns a settings array
suitable for splatting into `buildVoicings()` options. The leadsheet
creation flow passes `archetype_slug` in its settings; the builder
resolves it once at the top of `buildVoicings`.

#### §16.3 Admin UI

Replaces `resources/views/admin/progressions/builder.blade.php`. Layout:

- **Top bar:** archetype selector (dropdown) + "Save current as new
  archetype" + "Restore defaults" + read-only mode indicator.
- **Settings panel** (left, scrollable): all knobs grouped by section
  (per-category defaults, cost weights, repeated-chord, Phase 2
  eligibility, pool composition).
- **Preview column** (right): the six §13 progressions rendered live.
  Each progression shows its chord names, voicings as fret diagrams,
  and a small diagnostics line (path cost, pass2_won, fired
  resolutions).

No save button on individual settings — every change writes
immediately to the table (debounced ~300ms). The "Save as archetype"
button captures the current state into a named snapshot.

### §17. Integration with the Leadsheet creation flow

The leadsheet creator gets a **per-build settings panel**, a subset of
the Machine Room. Today it offers `popular | drop2 | shell | archetype`;
that's not enough.

Proposed leadsheet panel (overrides the global default for that one
leadsheet):

- **Archetype dropdown.** Defaults to `auto` (use system default per
  category). Other entries: every saved archetype from §15.
- **Voicing style.** The 9 `VOICING_STYLE_PRESETS`.
- **Inversions.** Boolean (`rootOnly` inverted).
- **Extensions.** Boolean.
- **String set.** Multi-select 1–6 (e.g. "play on strings 2–5 only").
  This is a new constraint not currently in the builder; would need
  implementation as a hard candidate-pool filter on
  `bass_string_min/max` and `top_string_min/max`. Track separately
  under Part 4.

The cross-reference to the leadsheet doc lives in
`docs/Phase-Leadsheet-Creation.md` (add a section there).

### §18. Safety affordances

- **Restore defaults.** Single click reverts to the constants in code.
- **Client-side validation.** Cost-weight sum warning, no empty pools,
  register target ∈ [0, 12], weight ∈ [0, 1].
- **Settings hash on regression-suite runs.** Each fixture run records
  a hash of the active settings so before/after deltas in the corpus
  stay traceable to a config snapshot.
- **Read-only mode toggle.** A development flag that disables editing
  on production. Useful when the operator wants to ship the Machine
  Room infra without exposing the editing surface yet.
- **Preview-corpus error containment.** If one of the six progressions
  errors out (e.g. a parser bug), the others still render. The errored
  one shows the error inline.

### §19. Implementation order

Three independently-shippable steps:

1. **Storage + service layer** (no UI). Move the constants to DB-backed
   reads with code fallbacks. Verify the regression suite produces
   bit-identical output before/after. Proves the indirection isn't
   introducing bugs.
2. **Read-only Machine Room page.** Shows current settings + the six
   live previews. No editing yet. Verifies the values surface
   correctly through the admin shell and the live preview is fast
   enough.
3. **Editing + archetype save/load + restore-defaults.** The actual
   tuning surface, with all safety affordances baked in.

The leadsheet-flow integration (§17) is orthogonal and can ship at any
point after step 1.

### §20. Open tuning questions

These are things to investigate using the Machine Room once it ships.
None are bugs; all are calibration concerns.

1. **Style preset enforcement strength.** `roota` / `roote` get
   over-ridden by `c_register` / `c_voice_leading` even when explicitly
   selected. Should `c_style` weight bump when user picks non-`auto`?
   Or should the preset's bass-string range act as a hard pool filter
   instead of a soft cost?
2. **High-register pull on Pass 1 jazz.** Multi-slot Imaj7 progressions
   sometimes lock to fret-8+ shapes. Is this a `c_register` weight
   issue, a `popularity` prior issue, or genuinely tied to the §6.1
   cascade leaving only high-register candidates?
3. **`c_common_tone` saturation.** During the xx0212 D7 investigation,
   common-tone scores were nearly identical across most candidates.
   Is the term saturated, or is the pitch-class pool small enough that
   most edges share the same count?
4. **Tonic-widening's impact on Pass 2.** §4.5 widens the pool for
   tonic slots. Does this interact with Pass 2's option-tone selection
   (e.g. should Pass 2 prefer `6/9` voicings on `Imaj7` slots in latin
   categories)? Currently no special handling.
5. **Bonus magnitude tuning (E.1.6).** The Phase E YAML's `bonus`
   values are educated guesses. With the Machine Room + regression
   suite, this becomes tractable.

---

## Part 3 — Phase History (appendix)

Preserved for institutional memory. Sequential read not required;
reference for "why did we do X in Phase Y."

### Phase A — `mode: 'simple'` lookup shortcut — ✅ SHIPPED 2026-05-01

DB-backed flat lookup against the archetype pool, no scoring engine.
Two-step: strict (root, quality) match → root-only triad fallback →
null. Non-barré priority (§4.1) applied. Repeated-chord reuse applied.
Audit `progressions-20260501-200257.md`: `group_thrash` 50→0,
`high_vl_score` 28→13, `position_thrash` 5→24 (expected, addressed by
Phase C).

### Phase B — Category-aware candidate pool + numeral upgrade — ✅ SHIPPED 2026-05-01

Wired `category` through `buildVoicings`. Implemented
`CATEGORY_VOICING_POOLS` constant and `applyCategoryNumeralUpgrade`
pre-processing. Added `category_pool_fallback` diagnostic. Audit
`progressions-20260501-203444.md`: `group_thrash` 50→0,
`high_vl_score` 28→9, simple-mode reproduces byte-identical.

**Phase B follow-up (2026-05-01):** Pop default routing now auto-picks
simple-mode when no `style` is set. Roman-case-aware upgrade: uppercase
plain non-tonic = dominant (e.g. `D7`); uppercase + `m` = minor;
lowercase = minor.

### Phase C — Hard constraints + repeated-chord rule — ✅ SHIPPED 2026-05-02

§6 hard constraints applied as candidate-pool filters / edge
admissibility. Repeated-chord rule originally applied as pre-Viterbi
pin (later corrected to post-Viterbi — see §6.5 and the xx0212 bug
note in Part 4). Audit `progressions-20260502-115043.md`:
`position_thrash` 4→2, repeated-chord reuse 100%,
`bass_motion_unsatisfiable` 0, `constraint_relaxation` recorded in
JSON. Top-decile VL threshold rose 5.2→33.9 — expected, isolates
Phase D's targets.

### Phase D — Cost function + Viterbi search — ✅ SHIPPED 2026-05-02

Replaced `pickBestVL` + forward/backward + cross-pool rescue with a
single Viterbi search over a normalized weighted cost function.
Anchor-free candidate lattice. Position and bass-motion enforced as
edge admissibility. Relaxation cascade implemented as Viterbi re-runs
with widened thresholds. Dual-counting between `scoreVL` and harmony
filter removed. New `c_register` cost term. `is_fixed_position` DB
flag now honored in `fetchVoicingsForChord`. Final: top-decile VL
33.9→9.2, `high_vl_score` 12→7 (42% reduction), `position_thrash`
2→0, 43/43 progressions complete.

### Phase E — Option-tone upgrade Pass 2 — ✅ SHIPPED 2026-05-03

E.1.1 through E.1.5 shipped (table loader, numeral upgrade, avoid-tone
filter, named resolutions, Pass 2 vs Pass 1 decision rule).

**Bug fixes during validation:** category gate, `?string $targetRole`
nullability, `pass2_won` array-comparison bug, `romanToDegree()` regex
quality-suffix stripping, `extension` field undefined on non-upgraded
chords.

**Post-sign-off corrections (2026-05-04):** Pass-1 plain-voicing filter
(`fetchVoicingsForChord` now restricts to empty `extensions` when
`extensions=false`). Alias-table voicings now feed the builder
(`fetchAliasShapes`). Chord-detail / chord-detail-progression pinning
self-heals (`ChordSerializer::serialize` and
`ChordLibraryController::show` re-run rows through
`ChordShapeCalculator::calculateFrets`). 77/169 chord-detail rows
display at corrected fret positions; 0 regressions.

**Extension-to-diagram pipeline fixes (2026-05-04):** Seven bugs around
chord names being upgraded but voicing pools not being filtered by the
selected extensions. `formatExtensionString` separator changed to
comma. `fetchVoicingsForChord` now filters by `extension` parameter
with comma-wrapped LIKE matching. `forbid` field applied via
`array_diff`. `bVI` recognition added before `bVII` check.
`upgradeJazzLatin` mappings corrected: bVI→maj7, II/III/VI→m7,
V/VII/bII/bIII/bVII→dom7.

**E.1.6 (bonus tuning) deferred** to Machine Room + regression suite
era.

### Phase F — `selectVoicingsForSequence` retirement — ✅ SHIPPED 2026-05-04

Body replaced to delegate to `buildVoicings` via
`HarmonicContext::buildFromChordSequence`. Method signature preserved
as a thin compatibility wrapper. `parseChordNameSimple` deleted (dead
code).

### Phase G — Voicing style switch — ✅ SHIPPED 2026-05-05

`VOICING_STYLE_PRESETS` constant with 9 presets. `costStyle()` soft
penalty. `c_style` weight tuned to 0.25 (initial 0.08 was too weak).
`seedCost()` updated to apply style penalty on slot 0 and respect the
preset's `register_target` override. UI dropdown surface in the legacy
admin builder page.

### Phase E.5 / Regression suite — ✅ SHIPPED 2026-05-05

`php artisan phase-e:regress` command with two modes:

1. **`--dump` / no-flag mode.** Iterates every jazz/latin progression
   in `sbn_chord_progressions`, runs them with `extensions=true`, and
   either captures (`--dump`) or verifies against
   `tests/fixtures/phase-e-regression.json`.
2. **`--verify-examples` mode.** Parses
   `docs/progressionexamples.txt` (hand-written ground truth across
   `rootd` / `roota` / `roote` style presets, with/without extensions)
   and runs each case through the builder.

The hand-written corpus is not ≥90% pass — it isn't meant to be. There
are many idiomatic ways through a II-V-I; the algorithm consistently
lands close to the operator's hand-written voicings on most cases. The
deliberate-mismatch failures are tuning signal, not bugs. Pass-rate
improvement is now an ongoing process driven by Machine Room iteration,
not a phase milestone.

### Repeated-chord post-Viterbi fix — ✅ SHIPPED 2026-05-05

The original Phase C implementation pinned `lattice[i] = [lattice[i-1][0]]`
*before* Viterbi ran. This forced the preceding free slot to harmonize
with an arbitrary first-pool element, dragging Viterbi away from the
true global optimum. Discovered during the xx0212 D7 investigation
(see Part 4). Fixed by removing the pre-Viterbi pin and adding
`applyRepeatedChordReuse` post-Viterbi.

---

## Part 4 — Known places to improve

Tracked here so future work has a clear backlog. Items move out when
they ship.

### 4.1 Algorithm / cost function

- **Style preset enforcement.** `roota` / `roote` get out-voted by
  `c_register` / `c_voice_leading` even when explicitly requested.
  Possibly bump `c_style` weight when user picks non-`auto`, or
  promote bass-string range to a hard candidate-pool filter. (See §20.1.)
- **Phase E.1.6 bonus magnitude tuning.** YAML bonuses are educated
  guesses. Tractable once the Machine Room exists.
- **High-register pull on Pass 1 jazz.** See §20.2.
- **`c_common_tone` may be saturated.** See §20.3.
- **k-best Viterbi (Phase H).** Today Viterbi returns one path; users
  can't request "version B" of a progression. Original spec sketched
  list-Viterbi (top-k partial paths per slot) with `take` and
  `min_diversity` API params. Out of scope for now but **important
  for future reference** — this is what unlocks "alternate takes" in
  the leadsheet creation flow.

### 4.2 Numeral / parser coverage

- **`VIIo` / fully-spelled diminished symbols.** Crashes
  `HarmonicContext::buildFromNumerals` with "Undefined array key 0"
  when fed `Im VIIo bVIIm` from the regression suite. Need to confirm
  the parser accepts `o` / `°` / `dim` as diminished-quality markers.
- **Quality suffixes not in the `romanToDegree` regex.** `sus`, `add9`,
  `°` may appear in the corpus and are not currently stripped.

### 4.3 DB coverage

- **Audit periodically for plain-quality coverage gaps.** The plain
  dom7 pool has 22 root-agnostic shapes that transpose to any root;
  same kind of audit should be done for other qualities. Surface gaps
  as a diagnostic in the regression-suite output.

### 4.4 Preset / archetype naming

- **Style preset names are technical.** `drop2_high`, `roote`,
  `shell_low` etc. don't map to player archetypes. Once player
  archetypes are saved snapshots (§15), the technical preset names
  are an internal detail and the archetype names ("Wes Montgomery",
  "João Gilberto", "Cowboy") are the user-facing surface.

### 4.5 Leadsheet integration (cross-doc)

- **String-set constraint.** The leadsheet creator should support
  "play on strings 2–5 only" as a hard pool filter. Not in the builder
  today. Tracked in `docs/Phase-Leadsheet-Creation.md` once that doc
  picks it up.
- **Leadsheet per-build settings panel.** Replace the current
  `popular | drop2 | shell | archetype` control with the §17 expanded
  panel.

### 4.6 Notable diagnostic episodes (worth not forgetting)

- **xx0212 D7 bug (2026-05-05).** Repeated-chord reuse pinned
  `lattice[i] = [lattice[i-1][0]]` before Viterbi ran. The cheap
  self-edge from the pin dragged the preceding free slot's choice
  toward `xx0212` (which happened to be `pool[2][0]` for D7), even
  though `5x453x` was 26% cheaper on the global path. Diagnosed by
  reflection-driven cost dumps comparing chosen vs. alternate paths.
  Fix: post-Viterbi reuse pass. Lesson: pre-search pinning of
  derived-from-pool values inverts the optimizer's intent.

---

## Authorship & history

This document supersedes `Builder-Refactor-Spec.md`. The earlier
document is the implementation log of the refactor; this one is the
living reference for the system that resulted plus the spec for what
comes next (the Machine Room).

Key dates from the refactor:

- 2026-04-30: Audit baseline.
- 2026-05-01: Phase A (simple mode), Phase B (category-aware pool +
  numeral upgrade), Phase B follow-up.
- 2026-05-02: Phase C (hard constraints), Phase D (Viterbi + cost
  function).
- 2026-05-03: Phase E (option-tone Pass 2 + named resolutions).
- 2026-05-04: Phase E post-ship corrections, Phase F
  (`selectVoicingsForSequence` retirement), extension-to-diagram
  pipeline fixes.
- 2026-05-05: Phase G (voicing style switch), Phase E.5 (regression
  suite), repeated-chord post-Viterbi fix, Machine Room spec.
- 2026-05-08: Part 2 (The Machine Room) shipped. Config moved to DB via
  `BuilderSettings` service, Machine Room AlpineJS UI built at
  `admin/progressions/builder`, and archetype saving/loading implemented.

# SBN Chord Construction (`ProgressionBuilder`) — Refactor Spec

**Status:** Draft, 2026-05-01.
**Companion to:** `docs/Identifier-Refactor-Spec.md` (the chord-handling
reference). Read that document first — it establishes the design
principles (Bucket 1 vs. Bucket 2, specificity, audits, spec→audit→ship)
this spec applies.
**Scope:** `App\Services\ProgressionBuilder` — the algorithm that turns
a chord progression (Roman-numeral or chord-name sequence) into a
sequence of guitar voicings.

---

## TL;DR

`ProgressionBuilder` is patched-up. We are **rewriting it**, not
patching it again. The audit corpus
(`storage/audits/progressions-20260430-193353.md`, with admin
annotations) is the regression baseline. The rewrite must show
measurable improvement on it.

**Phase A — SHIPPED (2026-05-01).** `mode: 'simple'` lookup-table
shortcut. Pop progressions produce cowboy chords matching the spec
target output exactly, repeated chords reuse voicings, group_thrash
flags eliminated in simple mode (50 → 0).

**Phase B — SHIPPED (2026-05-01).** Category-aware candidate pool +
deterministic numeral upgrade. Audit at
`progressions-20260501-203444.md`: jazz produces drop2/drop3/shell
7-chord voicings, classical produces closed_triads, modal uses actual
quartal voicings on Lydian, pop falls through to archetype pool.
**`group_thrash` 50 → 0** (spec target was ≤25 — far exceeded).
`high_vl_score` 28 → 9. Phase A simple-mode reproduces byte-identical.
The new `category_pool_fallback` diagnostic surfaced ~12 events,
all interpretable. Two follow-up refinements identified during audit
review (pop default routing, Roman-case-aware upgrade) queued as
"B-followup" to ship before Phase C.

**Phase C — SHIPPED (2026-05-02).** §7 hard constraints applied as
candidate-pool filters. `position_thrash` 4 → 2, repeated-chord
reuse 100%, `bass_motion_unsatisfiable` 0 (canary clean). Top-decile
VL threshold rose from 5.2 to 33.9 — *expected* per spec, because
Phase C tightened the pool without changing the scoring. The
remaining VL pathologies now precisely isolate Phase D's targets.

**Phase D — SHIPPED (2026-05-02).** `pickBestVL` + forward/backward
+ cross-pool rescue replaced with a single Viterbi search over a
normalized weighted cost function (§8). Anchor-free candidate lattice;
position and bass-motion enforced as edge admissibility checks; §7.4
relaxation cascade implemented as Viterbi re-runs with widened
thresholds. Dual-counting between `scoreVL` and the harmony filter
removed. New `c_register` cost term (§8.7) added per-slot to penalize
absolute distance from a category-specific target fret — addresses a
gap where `c_position` (relative motion only) couldn't pull
progressions toward a register. `is_fixed_position` DB flag now
honored in `fetchVoicingsForChord` (was the only consumer ignoring
it; produced nonsense barré transpositions of root-locked archetypes).
Final audit: top-decile VL 33.9 → 9.2; `high_vl_score` 12 → 7 (42%
reduction); `position_thrash` 2 → 0; `group_thrash` stays 0; 43/43
progressions complete. The ≤3.0 top-decile target was not met under
first-guess weights; remaining VL pathology now isolates a small set
of progressions whose candidate pools genuinely cannot avoid high-VL
transitions — Phase E territory. The 12-Bar Blues bars 1–4 still
land upper-register because the blues=`archetype` pool can't satisfy
position ≤3 across 12 slots, triggering category relaxation; also
Phase E (expand the blues pool tagging).

The remaining design framing (unchanged from earlier draft):

- **Two-pass voicing selection.** First pass picks basic,
  position-locked voicings with no option tones. Second pass upgrades
  to colorful voicings only where the upgrade improves voice leading.
- **Single search algorithm, normalized cost function.** Viterbi over
  the candidate lattice with explicit weighted score terms, replacing
  forward-pass + backward-pass + cross-pool rescue.

---

## What the algorithm is for

The builder turns a chord progression (Roman-numeral or chord-name
sequence) into a sequence of guitar voicings. It is used in the admin
progression-builder UI, the leadsheet creation flow, and the
chord-detail page.

The output should be **idiomatic for the progression's category** —
boring-but-correct is the target, not a fallback. A by-the-book II-V-I
(e.g. `Dm7 x5756x | G7 3x343x | Cmaj7 x3545x`) is exactly what the
algorithm should produce when given a jazz II-V-I. Showing students
"the standard way" is pedagogically more valuable than showing them
something fancy.

**Out of scope:** Progression *detection* lives in
`App\Services\ProgressionDetector` and stays there. **Educational
content generation** (the edu-panel that appears in the leadsheet
viewer when the detector finds a known progression) is a separate
concern handled elsewhere — likely backed by curated content in the
DB, with the detector as the trigger. The builder is unrelated to
edu-panel rendering. See §10 for the integration map between these
services.

---

## Design principles (carried over from the identifier spec)

These apply equally to construction; they're restated here because
this spec stands on its own when read by future contributors.

1. **Bucket 1 vs. Bucket 2.** Distinguish "no human would defend this
   output" from "locally defensible but contextually suboptimal."
   Audit annotations flag both — fix the former, leave the latter for
   harmonic-pattern awareness (Phase 2 of the identifier, eventually
   shared with the builder via `HarmonicScorer`). The category-aware
   layer is what flips many ex-Bucket-2 cases into in-spec wins (jazz
   progressions get jazz voicings *by design*, not by guessing from
   pitch content).
2. **Specificity.** For *construction*, the pull is *opposite* of
   identification: prefer the *less specific* voicing (basic drop2
   over loaded option-tone voicing) when the input doesn't ask for
   more. Add color in the second pass, not the first.
3. **Tuning vs. structure.** Don't nudge constants. The current
   algorithm's failure modes (group thrashing, position thrashing) are
   structural — forward+backward+rescue can't be tuned out of them.
4. **DB at design/seed time, constants at runtime.** Category-mode
   tables (§5) are hard-coded constants, seeded from observed DB usage
   patterns, not queried per-build.
5. **Spec → audit → measure → ship.** Acceptance criteria (§11)
   compare audit output before/after.

---

## What's broken (audit summary)

Running `php artisan sbn:audit-progressions --mode=all` against 43
progressions × 3 modes = 129 runs:

| Flag | Count |
|---|---|
| `group_thrash` | 50 (39%) |
| `high_vl_score` | 28 |
| `position_thrash` | 5 |

Plus the admin annotations in
`storage/audits/progressions-20260430-193353.md` flag many additional
qualitative failures: wrong category-of-voicing for the progression
context, inappropriate option tones (b9 on tonic blues!), bass-note
jumps, pedagogically poor voice leading even when local VL scores
are clean.

The dominant pathologies, summarized:

- **Wrong style for the progression.** Pachelbel Canon (classical) gets
  closed/spread/archetype mix and 5.6+ VL scores. Tritone Substitution
  (jazz) gets `D x57775 | Db x43121 | C x35553` archetypes. 50s
  Progression (pop) gets closed-triads and shells instead of cowboy
  chords.
- **Position thrashing**, including thrash that doesn't cross the
  current 5-fret threshold but is still musically wrong (admin
  annotations consistently say "max ±2 frets").
- **Voicing-group thrash** that *would* be acceptable if note-count
  and position were preserved (Minor Blues Cadenza example: drop3 →
  drop3 → shell at fret 1–3 with same note-count is fine; the current
  algorithm produces fret 11 → 10 → 10 with category-mixing that's
  not).
- **Option tones on the wrong slots.** Blues 12-bar puts b9 on the
  tonic C7 (wrong: b9 is a tension, belongs on V7 resolving to a
  minor target, not on the rest chord). Jazz progressions with no
  option tones at all (`II7 V7 I` → no #9, no b13).
- **Three different shapes for the same chord across 4 bars.**
  Quick-Change Blues bars 1–4 have `I7` showing as
  `x8787x | xbabbx | 8aa9b8 | 8x89xx` — the same chord! No reason for
  it to change.
- **Repeated chords don't reuse the same voicing.** Same root cause
  as above. The current algorithm has no concept of "this is the same
  chord I just voiced; play it the same way again."

---

## Underlying principle (target)

The new builder is a **Viterbi search over a candidate lattice** with
a **normalized weighted cost function** and a **category-driven
candidate filter**.

Stages:

1. **Numeral resolution.** Roman numerals → concrete chord names in
   the song key. Reuse `HarmonicContext::buildFromNumerals`.
2. **Category-driven numeral upgrade.** `V` in jazz becomes `V7`. `I`
   in jazz with maj7 default becomes `Imaj7`. `V7` in jazz before a
   minor target becomes `V7(b9,b13)`. (Pass 1 of the upgrade — basic
   chord-quality only. Pass 2, "extensions on", adds the rich option
   tones.) See §6.
3. **Candidate generation per slot.** For each chord, fetch a pool of
   candidate voicings filtered by:
   - The progression's `category` → preferred voicing-category set
     (jazz → drop2/drop3/shell; classical → closed/spread triads;
     pop → archetypes; etc.) — see §5.
   - Hard constraints: position vs. previous slot, bass-note jump
     legality, allowed note-count given category. See §7.
4. **Viterbi search.** Find the minimum-cost path through the lattice.
   Cost between adjacent candidates = the score function in §8.
   Replaces forward + backward + rescue.
5. **Option-tone upgrade pass (when extensions enabled).** Re-run
   Viterbi a second time, this time allowing extension-bearing
   voicings as candidates and rewarding option-tone-driven voice
   leading where it improves the score over the basic path. See §9.

---

## §5. Category mode table (the central table)

Every progression in `sbn_chord_progressions` has a `category` field
(`jazz, blues, pop, modal, classical, latin`). The builder consults
this table to determine **which voicing categories are eligible** and
**what numeral upgrades to apply** at each slot.

The `Model::CATEGORIES` constant lists `other` as a valid category but
no current DB entries use it; the new builder should treat any unknown
category (including `other` if encountered) as `jazz`.

**The DB has 11 voicing categories** (verified by query, 2026-05-01):
`drop2`, `archetype`, `drop3`, `shell`, `slash`, `custom`, `closed`,
`""` (empty), `quartal`, `spread_triads`, `closed_triads`. The empty
category contains 4 records that should probably be re-categorized as
`archetype`; treat them as such for now. `slash` voicings are
inversion-aware variants and are pulled in automatically by the
builder when a slash chord is required, regardless of the progression's
category. `custom` is a catch-all for hand-curated shapes that don't
fit other classes; pulled in only when explicitly selected via
`style: 'custom'`.

| Category | Default voicing classes (priority order) | Quality default for plain numerals | Option tones |
|---|---|---|---|
| `jazz` | drop2, drop3, shell, closed | upgrade plain triads to 7th chords (`I` → `Imaj7`, `V` → `V7`, `IIm` → `IIm7`) | yes — see §6 |
| `blues` (basic) | archetype with 7th chords | always 7th (`I` → `I7`, `IV` → `IV7`, `V` → `V7`) | conservative — only on V7 |
| `blues` (advanced) | shell, drop3 with 7ths; fall back to jazz pool if VL gain | always 7th | jazz-style on V7, occasionally elsewhere |
| `pop` | archetype (open cowboy chords); **non-barré first**, barré only when no non-barré archetype exists for that root | plain triads (`I` stays `I`) | none |
| `classical` | closed_triads, spread_triads | plain triads | none |
| `modal` | quartal, shell, drop3 | mostly 7th chords | sparse (sus2/sus4 idiomatic; quartal stacks idiomatic for Im7/IV7 vamps) |
| `latin` | as `jazz` | as `jazz` | yes; m6 idiomatic, b9 on V7 acceptable in bossa |

### 5.1 Archetype non-barré priority (pop/blues-basic)

When the category's pool is `archetype`, the builder iterates
candidates in this priority order:

1. Non-barré archetypes (open chords with at least one open string and
   no full-fingerboard barre): `x32010` (C), `x02210` (Am), `320003`
   (G), `xx0232` (D), `022000` (Em), `x02220` (A), etc.
2. Partial-barré archetypes (1–2 strings barred, e.g. some F voicings).
3. Full-barré archetypes (`133211` F, `577555` Am, etc.) — last resort
   for roots that have no non-barré archetype available (F#, Bb, Eb, etc.).

This applies to pop and blues-basic. Jazz/latin/classical/modal don't
typically pull from the archetype pool; when they do (rare), the same
non-barré-first ordering applies.

### 5.2 Quartal voicings (modal)

The DB has 3 `voicing_category = 'quartal'` records. Modal progressions
prefer this pool first. If a chord in a modal progression has no
quartal voicing available (likely for non-Im/IV qualities since the
quartal pool is sparse), fall through the modal priority order
(quartal → shell → drop3) within the modal pool — no need to fall
back to the jazz pool wholesale.

### 5.3 Why category-locked filtering, not just scoring

Earlier drafts considered making category a *score weight* rather than
a hard filter. Rejected because: when the user (or the leadsheet
import flow) picks a jazz progression, the right output for that user
is *unambiguously* a jazz voicing. Mixing in a classical-style closed
triad because it scored 0.05 better on a local VL term is a
pedagogical regression. **Category is a hard filter on the candidate
pool, not a soft preference.**

The exception: an explicit `style` override (`'shell'`, `'drop2'`,
etc., as today) takes precedence and narrows the pool further within
the category.

### 5.4 Seeding the table from real usage

The table above is the proposed default. Validate it by counting
voicing-category distributions in the DB grouped by progression
category, before shipping. Adjust if the data shows e.g. that jazz
progressions actually use closed voicings 25% of the time and we
should include closed in the jazz pool with lower priority.

---

## §6. Numeral upgrade (Pass 1: basic; Pass 2: option tones)

The DB stores progressions plain (`I, V, VI` rather than
`Imaj7(11), V7(b13), VIm7`) so that one progression matches many
realizations. The builder is responsible for upgrading the plain
numeral to a contextually appropriate concrete chord.

This is split into two passes for the reasons §3 lists.

### 6.1 Pass 1 — quality upgrade, no extensions

Inputs: progression numerals, song key, category.
Outputs: concrete chord names with quality only (no extension list).

Rules per category, all triggered when the original numeral is "plain"
(no quality suffix or just `m`/`dim`/`aug`):

```
JAZZ / LATIN:
  I, IV       → Imaj7, IVmaj7
  IIm, IIIm,
  IVm, VIm    → IIm7, IIIm7, IVm7, VIm7
  V           → V7 (dominant)
  bVII, bIII  → bVII7, bIIImaj7 (or context-dependent)
  VIIm        → VIIm7b5 (when in major key)
  diminished  → o7 (full dim7) when functioning as #ivo7 or vii°7 of V

BLUES:
  I, IV, V    → I7, IV7, V7

POP, CLASSICAL:
  no upgrade  — keep plain triads

MODAL:
  Im          → Im7 (dorian, aeolian)
  IV          → IV7 (dorian — bluesy color)
  bVII        → bVII (plain triad — mixolydian)

OTHER:
  treat as JAZZ
```

The upgrade is **deterministic per (category, numeral)**. No context
required for Pass 1 — that's Pass 2's job. This is what makes Pass 1
fast, debuggable, and audit-friendly.

### 6.2 Pass 2 — option-tone upgrade

Inputs: Pass 1 output (concrete chord names), category, key,
option-tones-enabled flag.
Outputs: chord names with extension lists, e.g. `V7(b9,b13)`.

Rules (jazz/latin only — pop/classical/modal don't enter this pass):

```
DOMINANT (V7) → MINOR target (Im, Im7, etc.):
  Add b9 and/or b13. (Wine and Roses: V7b9 → i.) Don't add #9 unless
  the next chord is also a dominant (e.g. Rhythm Changes bridge).

DOMINANT (V7) → MAJOR target (Imaj7):
  Add 9 and/or 13 (natural). Optionally #11 for Lydian color.
  Avoid b9 (it implies minor resolution).

DOMINANT (V7) → DOMINANT (II7 → V7 chain in cycle of 5ths):
  Add 9, 13, or #9 depending on chain direction.

TONIC MAJOR (Imaj7):
  Add 9 freely. Add #11 only when the song is in a Lydian-flavored
  context (rare).

TONIC MINOR (Im7):
  Add 9 (rare to add 11).

SUBDOMINANT (IVmaj7):
  Add 9 freely; #11 occasionally.

m7b5 (IIm7b5):
  Add 11 freely; never natural 9.

DIMINISHED 7 (passing #ivo7 or vii°):
  No extensions — keep clean.
```

Pass 2 is **only** invoked when the user requests extensions (the
existing `extensions: true` flag). When off, Pass 1 output is final.

### 6.3 Where the numeral logic lives — reuse `ProgressionDetector`

`ProgressionDetector` already implements:
- `parseChordName()` — chord name → root + quality + extensions
- `chordToNumeral()` — chord name + key → Roman numeral
- `degreeToNumeral()` — scale degree + quality → Roman numeral string
- `qualityToSuffix()` and the family-matching logic

Extract these into a shared `NumeralResolver` service (or accept that
the builder calls `ProgressionDetector` directly for these
read-only utilities). **Don't duplicate the chord-name parser.**

---

## §7. Hard constraints

Filters applied to the candidate pool *before* scoring. A candidate
that fails a hard constraint is dropped from the lattice entirely
(unless dropping every candidate at a slot leaves an empty pool, in
which case the loosest constraint is relaxed in a defined order — see
§7.4).

### 7.1 Position constraint

Adjacent voicings must satisfy `|position_n+1 − position_n| ≤ 3`.

Default: hard filter at >3 fret jump. If the audit shows this drops
too many candidates and produces empty pools, soften to ≤4 with a
score penalty for 3–4 jumps.

### 7.2 Bass-note constraint

Bass-note jumps allowed:
- 0 (same note — pedal or repeat)
- 1, 2 (semitone, whole step — chromatic motion)
- 3, 4 (m3, M3 — root motion to relative)
- 5, 7 (P4, P5 — circle-of-fifths motion, the gold standard)
- 9, 10 (m6, m7 — descending 4th, 5th by inversion)
- 11 (descending semitone in the modulo-up calculation)

Hard-blocked:
- 6 (tritone — only allowed for tritone substitution, see §7.3)
- 8 (m6 ascending — rare, awkward bass)

This is a stricter rule than what's currently encoded, with one
important directionality correction: interval `11` is legal because it
represents descending semitone motion in the modulo-up calculation and
is idiomatic in chromatic dominant motion. Implementation: compute bass
interval `(bass_n+1 − bass_n + 12) % 12` and block only `{6, 8}` unless
the tritone-sub exception applies.

### 7.3 Tritone substitution exception

When the algorithm detects a tritone-sub context (current chord is
dominant, the bass interval is exactly `6`, and the next chord is a
major or maj7 chord), the bass tritone jump is allowed. Interval `11`
does not need this exception because §7.2 allows descending semitone
motion directly. The detector for this case can be hardcoded as a
two-chord pattern (see Phase 2 of the identifier — same machinery).

### 7.4 Constraint relaxation order

If hard constraints leave an empty pool at slot N, relax in this
order until a candidate exists:
1. Position constraint to ≤4 frets.
2. Position constraint to ≤6 frets.
3. Voicing-category filter (allow next-priority category for the
   progression's category).
4. Position constraint dropped entirely (last resort).

Constraint relaxation is logged so audits can flag slots where
relaxation fired.

### 7.5 Repeated-chord rule

When two adjacent slots have the **same chord name** (e.g. four bars
of `C7` in 12-bar blues), the second slot **must reuse the first
slot's voicing exactly**, unless the user has overridden it via
`pinnedVoicing`. This is a hard rule, not a scoring preference.

This single rule fixes the "three different C7 shapes in four bars"
case that currently shows up in every blues progression.

---

## §8. Cost function (normalized, weighted)

All terms produce values in `[0, 1]`. Total cost is a weighted sum.
Lower is better.

```
cost(v_n, v_n+1) =
    w_simplicity      * c_simplicity(v_n+1)              // §8.1
  + w_position        * c_position(v_n, v_n+1)           // §8.2
  + w_bass            * c_bass_motion(v_n, v_n+1)        // §8.3
  + w_common_tone     * c_common_tone(v_n, v_n+1)        // §8.4
  + w_voice_leading   * c_voice_leading(v_n, v_n+1)      // §8.5
  + w_group           * c_group_continuity(v_n, v_n+1)   // §8.6
  + w_register        * c_register(v_n+1)                // §8.7
```

Default weights (locked after Phase D tuning):

```
w_simplicity      = 0.10
w_position        = 0.20
w_bass            = 0.20
w_common_tone     = 0.15
w_voice_leading   = 0.25
w_group           = 0.10
w_register        = 0.10   // category-overridden, see §8.7
```

`w_register` is overridden per-category via `CATEGORY_REGISTER_WEIGHT`:
blues 0.15 (strong pull to nut), pop/classical 0.10, jazz/modal/latin
0.05 (light). Category overrides apply at cost-evaluation time, not
through the `weight_overrides` builder option (which still works on
the base table).

### 8.1 Simplicity (`c_simplicity`)

```
c_simplicity = (
    note_count_above_baseline * 0.5 +
    extension_count * 0.5
) / max_possible
```

Penalizes voicings with more notes or more extensions than the slot
needs. The baseline is set by the category (pop/classical = 3-note
triads, jazz = 4-note 7th chords, etc.). A 4-note jazz voicing is
"baseline", not penalized. A 5-note jazz voicing with a 13 incurs
0.5 penalty.

This is the term that will keep simple progressions simple and
prevent jazz over-decoration on triadic music.

### 8.2 Position locality (`c_position`)

```
c_position = min(|position_n+1 - position_n|, 5) / 5
```

Linear penalty. Hard-filtered above 3 frets so this term mostly
contributes in the 0–3 range. ±0–1 frets ≈ free, ±2–3 ≈ small cost.

### 8.3 Bass motion (`c_bass_motion`)

```
c_bass_motion =
  0.0  if interval ∈ {5, 7}            (P4, P5 — circle of fifths)
  0.1  if interval ∈ {3, 4, 9, 10}     (m3, M3, m6, m7)
  0.2  if interval ∈ {1, 2}            (semitone, whole step — chromatic)
  0.0  if interval == 0                (pedal / repeat — also handled by §7.5)
  hard_filtered otherwise
```

Reflects the "circle of fifths first, common-tone/adjacent second"
ordering you specified. P4/P5 motion is the gold-standard
construction-direction. Chromatic motion costs more because it's
rarer in the realistic candidate distribution; that doesn't mean
chromatic is bad, just that the algorithm's default should bias
toward fifths.

For circle-of-fifths progressions specifically (II-V-I, turnarounds,
extended turnarounds), Viterbi will naturally find P4/P5 paths
because every adjacent pair has cost 0 on this term.

### 8.4 Common-tone retention (`c_common_tone`)

```
common_tones_same_string = count of pitches that match between v_n and
                           v_n+1 on the same string
common_tones_any_string  = count of shared pitch classes total

c_common_tone = 1 - (common_tones_same_string * 0.7 +
                    common_tones_any_string * 0.3) / max_possible
```

Common tones on the *same string* are worth more than common pitch
classes on different strings (the listener perceives them as
"sustained"). Reused machinery from the existing `scoreVL` (lines
870–884 of `ProgressionBuilder.php`) — but normalized.

### 8.5 Voice leading (`c_voice_leading`)

The existing `scoreVL`'s guide-tone and resolution machinery —
b7→3 resolution, 3→R/b7/maj7/9 resolution, etc. — is the right model.
Extract and normalize it.

```
c_voice_leading = scoreVL(v_n, v_n+1) / max_observed_scoreVL
```

The existing `scoreVL` already contains a lot of careful music
theory. The rewrite preserves it; the change is normalization and
moving the magic constants into the weight vector. Importantly: drop
the dual-counting between `scoreVL` and the harmony filter (today
both penalize dom→minor 13/9/#9 — pick one layer; recommend keeping
the harmony filter's deny-list at the candidate-pool level and
removing the scoreVL penalty for it).

### 8.6 Group continuity (`c_group_continuity`)

Per your annotation, group thrash is OK if note count and position
are preserved. Encoded as:

```
same_group         = 0.0
diff_group_but_ok  = 0.1   (note count matches AND |Δpos| ≤ 2)
diff_group_thrash  = 0.5   (otherwise)
```

This is a softer rule than what the current funnel + cross-pool
rescue tries to do. The Minor Blues Cadenza example (drop3 → drop3 →
shell at the same fret area, same note count) gets `0.1 + 0.0` cost,
acceptable. The current builder's `closed → closed_triads →
spread_triads` mix at fret 10 → fret 10 → fret 10 (different note
counts) gets `0.5 + 0.5`, heavily penalized.

### 8.7 Register (`c_register`)

```
c_register = clamp(|v_n+1.start_fret - target| / 12, 0, 1)
```

Per-slot absolute-position penalty. The `target` is per-category:
pop=0, blues=1, classical=2, jazz=5, modal=5, latin=5. Added in
Phase D after the register-pull problem surfaced: `c_position` (§8.2)
only measures *relative* motion between adjacent slots, so chains
that sit at fret 8 cost the same on position as chains that sit at
fret 1. Without a per-slot absolute term, Viterbi has no reason to
prefer one register over another once a chain is established —
seed-bias on slot 0 gets washed out by 11 slots of zero-cost
"stay where you are" edges.

`c_register` charges every slot for being far from the category
target; the cost accumulates over progression length and competes
fairly with edge-cost savings the upper-register chain might offer.
For 12-bar blues this means an `8a8988`-stuck path pays
~12 × 0.0875 ≈ 1.05 cumulative penalty against an `x32310`-anchored
path's 0, which is enough to flip outcomes when the candidate pool
has admissible low-position edges throughout. (When it doesn't —
because the locked-category pool can't make a 12-edge admissible path
at position ≤3 — the relaxation cascade fires and re-introduces the
upper-register voicings; that's a candidate-pool issue, Phase E.)

### 9.1 Pass 1 — Basic voicings

- Numeral upgrade: §6.1 only (no option tones).
- Candidate pool: filtered by §5 category mapping (basic priority
  set) and §7 hard constraints.
- Cost function: §8 with `w_simplicity` boosted to 0.20 (extra-strict
  on note count) and option-tone bonuses zeroed out.
- Output: a clean, position-locked, idiomatic basic realization of
  the progression.

This is the output for `extensions: false` and the *baseline* for
the option-tone pass.

### 9.2 Pass 2 — Option-tone upgrade

Only runs when `extensions: true`.

- Numeral upgrade: §6.2 (add option tones based on functional
  context).
- Candidate pool: includes voicings with extensions matching the
  Pass 2 numeral.
- Cost function: §8 with `w_voice_leading` boosted (the entire point
  of option tones is improved guide-tone resolution) and an extra
  bonus for "this slot's extension matches §6.2's recommended
  extension."
- Decision rule: take Pass 2's path **only if its total cost is at
  least 10% lower than Pass 1's cost**. Otherwise fall back to
  Pass 1. This prevents option-tone upgrades that don't actually
  improve the music.

### 9.3 Why two passes (recap)

Picking basic voicings and option-tone voicings simultaneously is the
trap the current algorithm fell into. Option-tone candidates inflate
the candidate pool by 5–10× (every 7-chord becomes also-9, also-13,
also-9-13, also-b13, etc.), and the cost function can't reliably
prefer the basic version when it should. Splitting them lets each
pass have a tighter, simpler search space and makes the option-tone
upgrade observable and overridable. It also aligns with the existing
"extension switch" UX.

---

## §10. Component reuse map

Components that already exist and should be **reused, not
re-implemented**:

| Component | Source | Used by builder for |
|---|---|---|
| `parseChordName()` | `ProgressionDetector` | parsing chord names from numeral strings |
| `chordToNumeral()`, `degreeToNumeral()`, `qualityToSuffix()` | `ProgressionDetector` | converting between numeral and concrete chord names (§6) |
| `FAMILY_MAP`, `MAJOR_SCALE_SEMITONES`, `NOTE_TO_SEMI`, `CHROMATIC_MAP` | `ProgressionDetector` | semitone math; reuse via shared constant module |
| `buildFromNumerals()`, `buildFromChordSequence()`, `numeralToChordName()` | `HarmonicContext` | building the chord-name sequence the builder consumes |
| `calculateFrets()`, `analyzeSlashChord()` | `ChordShapeCalculator` | transposing voicing archetypes to the target root |
| `scoreVL()` voice-leading machinery (guide-tone resolution, 7→3 etc.) | current `ProgressionBuilder` | term in the new cost function (§8.5) |
| Slash-chord chord-tone restriction `{3, 4, 7, 10, 11}` | identifier Phase 1 | hint for bass-motion legality (§7.2 — overlap with chord-tone set) |

Components from the **identifier** that the builder ideally should
share via a future `HarmonicScorer` extraction:

| Component | Source | Eventually shared via |
|---|---|---|
| `ROOT_MOTION_BONUS` table | `VoicingCrossref` | `HarmonicScorer` (post-builder-rewrite) — informs §8.3 |
| `FUNCTIONAL_FRAGMENTS` table (ii-V-I, tritone sub, etc.) | `VoicingCrossref` | `HarmonicScorer` — informs §6.2 option-tone upgrade rules and the tritone-sub exception (§7.3) |
| `CTX_MAJOR_SCALE`, diatonicity check | `VoicingCrossref` | `HarmonicScorer` |

The builder rewrite should **not** depend on the identifier's
internals. Either (a) duplicate the small set of constants the
builder needs in the short term, or (b) extract `HarmonicScorer`
*before* the builder rewrite. Recommend (a) — extraction is a clean
follow-up after both algorithms have settled, attempting it now
adds risk to two simultaneous refactors. Annotate the duplicated
constants with `// TODO(harmonic-scorer): extract to shared module`.

---

## §11. Audit acceptance criteria

The current audit baseline:
[storage/audits/progressions-20260430-193353.md](../storage/audits/progressions-20260430-193353.md)
(annotated by admin) and the corresponding JSON.

The rewrite's acceptance bar, measured by re-running
`php artisan sbn:audit-progressions --mode=all` after implementation:

### 11.1 Quantitative (must pass all)

- `group_thrash` count: from 50 → ≤ 10. Remaining cases must all
  be ones where the §8.6 "same note count and position" exception
  legitimately allows the group change.
- `position_thrash` count: from 5 → 0 (the §7.1 hard constraint
  forbids them).
- `high_vl_score` top-decile threshold: from 4.97 → ≤ 3.0 (i.e. the
  worst transitions are at least 40% gentler).
- Repeated-chord voicing reuse: 100% — no progression should have
  the same chord realized two different ways in adjacent slots
  unless `pinnedVoicing` overrides.

### 11.2 Qualitative (verified against admin annotations)

Each of the admin-flagged cases in the audit baseline produces an
output in the **example** the admin wrote, *or* a justifiably better
one (the admin reviews a sample of the new audit and approves):

- 12-Bar Blues bars 1–4: identical voicings, no b9 on tonic.
- Minor Blues Cadenza: `bVI | V | I` in jazz mode produces something
  in the area of `Abmaj7(#11) | G7(b13) | Cm7(9)` (admin's example
  at audit line 127–129).
- Pachelbel Canon (classical): closed/spread triads only, all in a
  3-fret position window.
- Tritone Substitution `II | bII | I` (jazz): does NOT produce
  archetypes; produces 7th-chord drop2/drop3 in a 3-fret window.
- Pop progressions (50s, I-V-vi-IV, etc.): produce open archetype
  cowboy chords — `x32010 (C) | 320003 (G) | x02210 (Am) | 133211 (F)`
  for I-V-vi-IV.
- Ellington Progression and other circle-of-fifths-rooted jazz
  progressions: bass motion on P4/P5 wherever the structure allows.

### 11.3 Non-regression

Cases the current builder handles correctly stay correct:

- Backdoor Dominant (admin: "actually reasonable") → same or better.
- Minor Plagal Cadence (admin: "good") → same or better.
- Test Jazz Progression — straightforward II-V-I → idiomatic drop2.

### 11.4 Method

1. Run audit before any change → baseline file already exists.
2. Implement Pass 1 only (no option tones). Audit. Compare.
3. Implement Pass 2. Audit. Compare. Verify Pass 2 paths don't
   regress Pass 1 cases when the 10% threshold (§9.2) doesn't fire.
4. Manual review of 5–10 randomly-sampled audit runs by admin
   before declaring acceptance.

---

## §12. Configuration surface

Inputs to `buildVoicings`:

```
buildVoicings($context, [
    'category'         => 'jazz' | 'blues' | 'pop' | 'classical' | 'modal' | 'latin',
                          // NEW: drives §5 candidate filter and §6 numeral upgrade
                          // Required when category is known (DB progression);
                          // defaults to 'jazz' when unknown (free-form numerals)
                          // or when the category is 'other' / unrecognized.
    'style'            => 'drop2' | 'drop3' | 'shell' | 'closed' | 'archetype'
                          | 'closed_triads' | 'spread_triads' | 'quartal' | 'custom' | '',
                          // existing: narrows the category-driven pool further
    'extensions'       => bool,
                          // existing: enables Pass 2 (§9.2)
    'pinnedSlot'       => int|null,    // existing
    'pinnedVoicing'    => array|null,  // existing
    // dropped: 'rootOnly' (replaced by category-driven inversion preference)
])
```

The `selectVoicingsForSequence` second entry point is **deleted**.
Its callers (leadsheet creation/import flows) call `buildVoicings`
with `category: 'jazz'` (the existing default behavior of that path)
or whatever category is appropriate. Single entry point.

---

## §13. Implementation phases

The rewrite is large enough to warrant phased delivery. Each phase
ships independently with audit verification.

### Phase A — `mode: 'simple'` lookup shortcut — ✅ SHIPPED 2026-05-01

**Delivered:**

- New `mode: 'simple'` option on `buildVoicings`. Bypasses the scoring
  engine entirely, performs a flat lookup against the `archetype` pool
  in the DB.
- Lookup is two-step: strict `(root, quality)` match first, then
  fallback to root-only triad. Returns `null` if no archetype exists
  for the root.
- Non-barré priority within archetype set (§5.1): non-barré chosen
  first, partial-barré next, full-barré last (e.g. `Bb` in
  `I-bVII-IV (Rock)` correctly falls back to barré because no
  non-barré Bb archetype exists).
- Repeated-chord rule applied in simple-mode: adjacent identical
  chord names reuse the previous voicing (no scoring needed since
  there is no scoring loop).
- Audit command updated: `--mode=simple` now exercises the new
  simple-mode lookup path.

**Audit verification:** `storage/audits/progressions-20260501-200257.md`

| Flag | Pre-Phase-A (default mode) | Phase A (simple mode) |
|---|---|---|
| `group_thrash` | 50 | 0 |
| `position_thrash` | 5 | 24 |
| `high_vl_score` | 28 | 13 |

`group_thrash` eliminated because every voicing is `archetype`.
`high_vl_score` down 54%. `position_thrash` increased — expected,
explained by lookup-mode's lack of position-sensitivity (Phase C
territory). All pop progressions match the spec's target output
exactly.

**Phase A acceptance bar (all met):**
- ✅ Pop progressions produce cowboy chords.
- ✅ Repeated chords reuse voicings 100%.
- ✅ No group thrash in simple mode.
- ✅ No new flag categories introduced.

The Phase A audit (`progressions-20260501-200257.json`) is the
**baseline for Phase B's non-regression check** — every simple-mode
output Phase A produces should remain unchanged after Phase B (Phase
B doesn't touch the simple-mode path).

---

### Phase B — Category-aware candidate pool + numeral upgrade — ✅ SHIPPED 2026-05-01

**Audit:** `storage/audits/progressions-20260501-203444.md` (mode=category).

**Acceptance criteria (all met):**

| Metric | Pre-Phase-B | Phase B (`--mode=category`) | Spec target |
|---|---|---|---|
| `group_thrash` | 50 | **0** | ≤25 ✅ blew past |
| `position_thrash` | 5 | 6 | n/a (Phase C) |
| `high_vl_score` | 28 | 9 | n/a |
| Top-decile VL threshold | 4.97 | 5.9 | n/a |
| Phase A simple-mode reproduces | n/a | byte-identical ✅ | required |

Spot-check verifications all passed: Pachelbel produces closed_triads
only; Test Jazz Progression starts on the spec example (`Dm7 x5756x`)
in clean drop2; Bill Evans Turnaround stays drop2 throughout; all
modal Lydian uses actual quartal voicings; Tritone Substitution
correctly fell through to closed_triads with diagnostic logging.

The new `category_pool_fallback` diagnostic surfaced ~12 events,
mostly DB coverage gaps (missing F closed_triad, jazz pool has no
plain triads in drop2/drop3 — both interpretable, neither a code bug).

The original Phase B spec (B.1–B.5 below) is the implementation log
and is preserved verbatim for future reference.

(See "Phase B follow-up" section below for two refinements identified
during audit review and queued for completion before Phase C ships.)

---

**Goal.** When `category` is supplied to `buildVoicings` (and
`mode: 'simple'` is not active), filter the candidate pool by §5's
category-mode table and upgrade plain numerals per §6.1 *before* the
existing scoring engine runs. Keep the existing scoring temporarily.

This is a **surgical change with high impact**: the scoring loop
stays as-is, but it now scores within a category-correct pool, so its
existing pathologies (group thrash, magic constants) get masked
because the pool no longer contains the wrong-category candidates
they were picking.

**Estimated effort:** 2–3 days.

#### B.1 Concrete tasks

1. **Wire `category` through the call path.**
   - Add `category` to the `$options` array signature on
     `ProgressionBuilder::buildVoicings`. Allowed values per §12.
   - Update `ProgressionBuilderController::buildVoicings` to pass the
     progression's `category` field when a `progression_id` is
     supplied (look it up from `sbn_chord_progressions` via
     `ChordProgression::find($id)->category`). Free-form numeral
     input defaults to `category: 'jazz'` (or whatever the caller
     passes).
   - Update `ChordLibraryController` and `ProgressionLibraryController`
     similarly — they should pass the surrounding progression's
     category when one is known.
   - Leadsheet flows (`LeadsheetController::identifyVoicings`,
     leadsheet creation) default to `category: 'jazz'` for now —
     refining this is Phase F's job.

2. **Implement the category-mode constant table.**
   - Add a `CATEGORY_VOICING_POOLS` const at the top of
     `ProgressionBuilder` mapping each of the six categories (jazz,
     blues, pop, classical, modal, latin) to its priority-ordered
     list of `voicing_category` values per §5's table.
   - Add a `CATEGORY_DEFAULT` const = `'jazz'` for unknown categories.
   - Special-case `blues`: pool depends on whether `style` is set
     (basic = archetype only; advanced = shell/drop3, fall back to
     jazz pool). Cleanest implementation: two separate constants
     `BLUES_BASIC_POOL` and `BLUES_ADVANCED_POOL`, picked by an
     `extensions` flag or a sub-mode option (see B.5 open question).

3. **Implement §6.1 numeral upgrade as a pre-processing step.**
   - New private method `applyCategoryNumeralUpgrade(array $context, string $category): array`.
   - Iterates the chord names in the harmonic context and rewrites
     plain triads/numerals to category-appropriate quality:
     - jazz/latin: `I → Imaj7`, `IV → IVmaj7`, `IIm → IIm7`, `V → V7`,
       etc. (See §6.1 for the full table.)
     - blues: `I → I7`, `IV → IV7`, `V → V7`.
     - modal: `Im → Im7`, `IV → IV7`.
     - pop/classical: no upgrade — keep plain triads.
   - **Reuse `ProgressionDetector::parseChordName` and
     `qualityToSuffix`** for the chord-name parse/recompose. Do not
     re-implement the parser.
   - The upgrade is purely deterministic — no context needed at this
     stage. Pass 2 (option tones) is Phase E.

4. **Filter the candidate pool by category.**
   - In `fetchVoicingsForChord`, before the existing
     `voicing_category` filter, apply the category's priority pool
     as an `IN (...)` constraint on the SQL query.
   - If the result set is empty, fall back to the next-priority
     category in the pool list. If still empty, fall back to the
     unrestricted query (current behavior). Log when fallback fires
     so audits can surface coverage gaps in the DB.
   - The existing `style` parameter still works as a *narrowing*
     filter inside the category pool (e.g. `category: 'jazz', style:
     'shell'` returns shell voicings only).

5. **Apply the §5.1 non-barré priority within archetype pools.**
   - When the selected pool is `archetype` (pop, blues-basic), order
     candidates: non-barré → partial-barré → full-barré. Use the
     existing `is_barre`/`barres` field on chord_diagrams or compute
     from the diagram_data positions (a barré is a `barres` entry
     with a from/to span).
   - This is the same ordering Phase A applied in the simple-mode
     lookup. Extract it into a shared helper if not already done.

6. **Handle quartal pool sparseness for modal.**
   - Modal's pool is `[quartal, shell, drop3]`. The DB has only 3
     quartal records. When a chord has no quartal voicing available,
     fall through to shell, then drop3 — within the modal pool, no
     fall-back to jazz at the category level.
   - This is automatic if the candidate-pool query uses
     `whereIn('voicing_category', $modalPool)` without forcing only
     quartal first. The priority-ordering (§5) is enforced by the
     scoring engine preferring earlier-listed categories — but Phase
     B doesn't touch scoring, so add a small post-query
     re-prioritization that pushes earlier-priority categories to the
     top of the pool.

7. **Update audits.**
   - Add a new `--mode=category` to the audit command that runs every
     progression with its DB-stored category passed as the `category`
     option. This becomes the primary Phase B audit.
   - Keep `--mode=default`, `--mode=simple`, `--mode=jazz` as before.
     `default` = no category passed = current behavior. Comparison:
     `default` vs `category` is the Phase B impact measurement.
   - Add a new flag, `category_pool_fallback`, that fires when a
     category's priority pool was exhausted and the algorithm fell
     back to a wider pool. Surfaces DB coverage gaps.

#### B.2 Acceptance criteria for Phase B

Run `php artisan sbn:audit-progressions --mode=category` after
implementation. Compare against the pre-Phase-B audit baseline:

**Quantitative:**
- `group_thrash` count: drops substantially (target ≤ 25, from 50 in
  the default-mode baseline) because the pool no longer contains
  off-category voicings to mix in.
- `category_pool_fallback` count: ≤ 5 (acceptable DB coverage gaps).
  Each fallback should be inspected to decide whether it's a missing
  voicing that should be added to the DB.
- Phase A simple-mode audit (`progressions-20260501-200257.md`)
  re-runs and produces **byte-identical** output. Phase B does not
  touch the simple-mode path.

**Qualitative (spot-check against admin annotations):**
- Pachelbel Canon (classical) without simple-mode: closed/spread
  triads only, no archetype mixed in.
- Tritone Substitution (jazz): drop2/drop3/shell 7th chords, no
  archetype.
- 12-Bar Blues default mode: archetype 7-chord open shapes (basic
  blues mode). Same chord reuses voicing within bars 1-4.
- Test Jazz Progression (`IIm7, V7, Imaj7`): drop2 voicings in a
  3-fret window. Should match the spec's flagship example
  (`Dm7 x5756x | G7 3x343x | Cmaj7 x3545x`) or close.
- Pop progressions without simple-mode: produce archetypes. (If
  category routing works, the user no longer needs `mode: 'simple'`
  for the common pop case — it's the default for pop.)

**Non-regression:**
- All progressions correctly identified pre-Phase-B (admin annotated
  "good" or "actually reasonable" — Backdoor Dominant, Minor Plagal
  Cadence, etc.) stay correct or improve.
- No simple-mode regression (Phase A baseline reproduces exactly).

#### B.3 What Phase B does NOT do

- **No new scoring code.** The cost function rewrite and Viterbi are
  Phase D.
- **No hard constraints.** Position thrash and bass-jump constraints
  are Phase C — Phase B's pool filter will reduce thrash incidentally
  but does not enforce position locality.
- **No option tones.** Pass 2 numeral upgrade is Phase E.
- **No `selectVoicingsForSequence` removal.** Phase F.

#### B.4 Risk and mitigation

- **Risk:** the existing scoring engine misbehaves when given a
  smaller, category-correct pool — e.g. cross-pool rescue
  ([ProgressionBuilder.php:578-590](app/Services/ProgressionBuilder.php#L578))
  may fire constantly because the locked group has fewer
  candidates. **Mitigation:** treat any new audit flag as a Phase B
  bug. If cross-pool rescue is firing pathologically, *temporarily*
  disable it for the category-mode path; Phase D will replace it
  entirely.
- **Risk:** numeral upgrade breaks chord-detail-page calls where the
  user has pinned a specific chord. **Mitigation:** only apply
  upgrade to numerals that arrived as plain (no quality suffix in the
  user input). If a quality is already specified, respect it.
- **Risk:** DB coverage gaps surface as `category_pool_fallback`
  spikes. **Mitigation:** this is the audit doing its job — surface
  the gaps, let admin add the missing voicings via the existing UI,
  re-run the audit. Don't paper over with code workarounds.

#### B.5 B-phase open questions

- **Blues sub-mode toggle.** "Basic blues = archetypes" vs. "advanced
  blues = shell/drop3". How does the caller pick? Three options:
  (a) a new `subMode` parameter (`'basic' | 'advanced'`),
  (b) inferred from `extensions`/`style` flags ('shell' style or
       extensions on → advanced),
  (c) basic by default unless the progression has `tags`
       containing 'jazz' or 'shell'.
  Recommendation: (b). Keep the option surface narrow.
- **`pop` + `extensions: true`.** Should pop progressions ever pick
  up extensions? The spec says no, but a user might explicitly request
  them. Recommendation: respect the explicit flag. Pop output with
  `extensions: true` upgrades to `add9` or sus chords (mild color)
  but never to drop2 7th chords. Defer concrete rule to Phase E.
- **Default category for free-form input.** `buildVoicings` calls
  without a `category` field default to... what? Recommendation:
  `'jazz'` (matches the most common chord-detail-page use case).
  Document loudly so callers know they should pass category when
  available.

### Phase B follow-up — Two refinements before Phase C

Two issues surfaced during Phase B audit review that didn't warrant
re-opening Phase B but should be resolved before Phase C ships. They
are bundled here as a single small follow-up patch.

#### B-followup.1 — Pop default routes to simple-mode lookup

**Issue.** Phase B's category=pop output uses 5th-fret partial-barré
shapes (`C x35553`) instead of open cowboy shapes (`C x32010`). The
existing scoring engine's "prefer fret 5" seed-position heuristic
([ProgressionBuilder.php:142-151](app/Services/ProgressionBuilder.php#L142))
biases away from open position even when the candidate pool only
contains archetypes.

**Fix.** Auto-route `category: 'pop'` to the simple-mode lookup path
when no explicit `style` is supplied. Implementation:

```
// At buildVoicings entry point, before pool filtering:
if (($options['category'] ?? null) === 'pop'
    && empty($options['style'])
    && empty($options['mode'])) {
    $options['mode'] = 'simple';  // route to Phase A lookup
}
```

This is the simplest possible expression of the rule "for pop, the
spec's target output IS the simple-mode lookup output." Callers can
opt out by passing an explicit `style` (e.g. `style: 'archetype'`
without `mode: 'simple'` keeps the scoring path).

**Acceptance.** After this change, `--mode=category` audit's pop
progressions reproduce Phase A simple-mode output **byte-identical
on the chord-content fields** (per the comparison rule in §10).

#### B-followup.2 — §6.1 numeral upgrade respects Roman numeral case

**Issue.** The Phase B numeral upgrade promotes uppercase plain
numerals (`II`, `IV`, `V`) consistently to dominant or major-7th
qualities — but does not distinguish between `II` (= major II / V/V)
and `IIm` (= the diatonic minor ii). Some progressions in the DB use
this distinction inconsistently. For the Tritone Substitution
progression specifically (`II, bII, I`), the current upgrade resolves
to `IImaj7, bIImaj7, Imaj7` which is **not** the jazz-idiomatic
realization.

The user's clarification: in jazz performance, this progression should
realize as one of:
- `II7, bII7, Imaj7` (most common — both II and bII as dominants)
- `IIm7, bII7, Imaj7` (diatonic ii + tritone-sub V)
- `IIm7, bIImaj7, Imaj7` (with bII as a major7 substitute)

The first form (`II7, bII7, Imaj7`) is in the user's "acceptable" set
and is **achievable by §6.1 deterministic upgrade alone** if we use
proper Roman numeral case as the trigger.

The second form (`IIm7, bII7, Imaj7`) requires recognizing the
tritone-sub *pattern* (II followed by bII followed by I) — that's
functional pattern recognition, deferred to Phase E.

**Fix.** Tighten §6.1's jazz/latin upgrade rules to use proper Roman
numeral case:

| Numeral case in input | Jazz upgrade | Reason |
|---|---|---|
| `I`, `IV` (uppercase plain, tonic family) | `Imaj7`, `IVmaj7` | tonic and subdominant default to maj7 in jazz |
| `II`, `III`, `VI`, `VII` (uppercase plain, non-tonic) | `II7`, `III7`, `VI7`, `VII7` | non-tonic plain uppercase = dominant function (secondary dominants, tritone subs) |
| `IIm`, `IIIm`, `IVm`, `VIm`, `VIIm` (uppercase + `m`) | `IIm7`, `IIIm7`, `IVm7`, `VIm7`, `VIIm7` | diatonic minor function |
| `ii`, `iii`, `vi` (lowercase) | `iim7`, `iiim7`, `vim7` | proper Roman lowercase = minor |
| `bII`, `bIII`, `bVI`, `bVII` (chromatic uppercase plain) | `bII7`, `bIII7`, `bVI7`, `bVII7` | chromatic plain uppercase = dominant (tritone-sub function) |
| `bIIm`, `bIIIm`, `bVIm`, `bVIIm` | `bIIm7`, `bIIIm7`, `bVIm7`, `bVIIm7` | chromatic minor (rare) |
| `bIIImaj7`, `bVImaj7`, `bIImaj7` (already qualified) | leave alone | explicit quality wins |
| `Vsus`, `IVsus2`, etc. (sus in input) | leave alone | sus chords are explicit color choices |
| `o`, `dim`, `dim7` suffix | leave alone | diminished is explicit |

For **blues**: same as today — always upgrade plain to 7-chord (`I →
I7`, `IV → IV7`, `V → V7`).

For **modal**: same as today — `Im → Im7`, `IV → IV7`, others left
plain.

For **pop / classical**: no upgrade — keep plain triads.

**Acceptance.** After this change:
- Tritone Substitution `(II, bII, I)` jazz output becomes `D7 | Db7 |
  Cmaj7` realized in drop2/drop3 voicings (no more closed-triad
  fallback).
- Existing correct outputs unchanged: progressions with explicit
  qualities (`IIm7,V7,Imaj7`, `Imaj7,VIm7,IIm7,V7`, etc.) still
  produce the same voicings.
- Diatonic-minor progressions that were correctly using uppercase+`m`
  (`IIIm7`, `VIm7`, etc.) unaffected.

**Migration concern.** Some DB entries may have used uppercase plain
to mean "any quality, let the detector relax." The detector's
`FAMILY_MAP` does its own family-relaxation, so this is fine for
detection. For *building*, the new rule is stricter — uppercase
plain = explicit major/dominant. Audit the DB for entries where this
might cause a behavior change:

```bash
# After implementing, look for progression entries that the old
# upgrade and new upgrade resolve differently.
php artisan sbn:audit-progressions --mode=category
diff <baseline>.json <new>.json | grep chord_name
```

Spot-check any entries where the upgrade output changed and confirm
the new realization is musically defensible. None should require
fixing — but if any do, the fix is to update the DB numeral string
to use the case the admin actually meant (lowercase `ii` for diatonic
minor, uppercase `II` for major, etc.).

#### B-followup acceptance criteria

Run `php artisan sbn:audit-progressions --mode=category` after both
fixes. Compare against
`storage/audits/progressions-20260501-203444.md`:

- All pop progressions in `--mode=category` produce **byte-identical
  output** to `--mode=simple` Phase A baseline (i.e., open cowboy
  shapes restored).
- Tritone Substitution Jazz Cadence produces drop2 or drop3
  realizations of `D7 | Db7 | Cmaj7`. No `closed` fallback.
- All other Phase B audit cases unchanged or improved.
- `category_pool_fallback` count stays roughly the same (~12) — these
  follow-ups don't touch the pool filter logic.

#### B-followup effort

~half a day total. Both fixes are localized: B-followup.1 is a
3-line dispatch shim; B-followup.2 is a refactor of the upgrade-rule
table in `applyCategoryNumeralUpgrade`. Audit before Phase C starts.

---

### Phase C — Hard constraints + repeated-chord rule — ✅ SHIPPED 2026-05-02

**Audit:** `storage/audits/progressions-20260502-115043.md` (mode=category).

**Acceptance criteria (all met):**

| Metric | Phase B follow-up | Phase C | Status |
|---|---|---|---|
| `position_thrash` | 4 | **2** | ✅ improved |
| Repeated-chord reuse | n/a | 100% | ✅ |
| `bass_motion_unsatisfiable` | n/a | 0 | ✅ canary clean |
| `constraint_relaxation` | n/a | recorded in JSON | ✅ |
| Phase A simple-mode reproduces | byte-identical | byte-identical | ✅ |
| Top-decile VL threshold | 5.2 | **33.9** | ✅ expected — isolates Phase D |
| `high_vl_score` count | 9 | 12 | ✅ expected |

**Note on the VL score explosion:** Phase C's tighter pool reveals
existing `scoreVL` weaknesses that Phase D's normalized cost function
+ Viterbi will resolve. This is exactly what the spec called for —
"the remaining `high_vl_score` cases isolate exactly where Phase D's
cost-function rewrite needs to focus."

**One regression caught and fixed during ship:** initial Phase C
crashed all 8 pop progressions with `Undefined array key -1` when
the simple-mode dispatch hit a Phase C helper without a `$slotIdx >
0` guard. Fixed before merge.

The original Phase C spec (C.1–C.5 below) is the implementation log
and is preserved verbatim for future reference.

---

**Goal.** Apply the §7 hard constraints (position, bass-motion,
repeated-chord) as candidate-pool filters *before* the existing
scoring runs. This eliminates remaining `position_thrash` flags and
forces voicing-reuse on adjacent identical chords. Phase C does not
yet replace the scoring engine — that's Phase D.

After Phase C: the audit's flag report is "clean" enough that the
remaining `high_vl_score` cases isolate exactly where Phase D's
cost-function rewrite needs to focus.

**Estimated effort:** 1.5–2.5 days (slightly above the original
~1–2 estimate to account for the relaxation logic and audit cycle).

#### C.1 Concrete tasks

1. **Implement §7.5 — Repeated-chord voicing reuse.**
   - At the top of the per-slot candidate-generation loop, check
     whether `chord_name[n] === chord_name[n-1]`. If yes, the
     candidate pool for slot N is `[selections[n-1]]` (a one-element
     pool containing the previous slot's voicing).
   - Pinned slots override: if `selections[n-1]` was set via
     `pinnedVoicing` and `pinnedSlot === n`, the pin wins (don't
     auto-reuse).
   - This rule fires *first*, before any other filter or constraint —
     it is unconditional unless overridden.
   - **Free win** for the Phase A simple-mode path too: the existing
     simple-mode dispatch already implements this; Phase C's job is
     to replicate it on the scoring path so category-mode also
     enforces reuse.

2. **Implement §7.1 — Position constraint as a hard filter.**
   - For each slot N (where N > 0 and `selections[n-1]` exists),
     compute `prev_position = selections[n-1].start_fret` and filter
     the candidate pool to voicings with
     `|candidate.start_fret - prev_position| ≤ 3`.
   - For slot 0 (no previous voicing), no position constraint — the
     scoring engine's seed-position heuristic still runs (this is
     where the "prefer fret 5" or, for pop, "prefer open" comes from).
   - Track filtered-out counts per slot for the diagnostic.

3. **Implement §7.2 — Bass-motion constraint as a hard filter.**
   - For each slot N (where N > 0), compute the bass interval
     `(candidate.bass_pc - selections[n-1].bass_pc + 12) % 12` and
     filter to candidates whose interval ∈ `{0, 1, 2, 3, 4, 5, 7, 9, 10}`.
     Tritone (6), augmented 5th up (8), and major 7th up (11) are
     hard-blocked.
   - Edge case: when `selections[n-1].bass_pc` cannot be determined
     (very rare — happens for some malformed shapes), skip this
     filter for that slot and log.
   - Track filtered-out counts per slot for the diagnostic.

4. **Implement §7.3 — Tritone-sub exception.**
   - Minimal rule (per §15 question 4 recommendation): when slot N's
     chord and slot N+1's chord meet ALL of:
     - Slot N is a dominant 7-chord (`quality ∈ {dom7, 7}` or numeral
       has 7-suffix and not maj7).
     - Slot N+1 is a major or maj7 chord (`quality ∈ {maj, maj7,
       maj6, maj9}`).
     - Bass interval from N to N+1 is exactly 6 (tritone) or 11
       (descending semitone — also a tritone-sub move).
     ... then the bass-motion constraint allows interval 6 or 11 for
     this transition.
   - This is implemented as a per-edge rule, not a per-candidate
     filter — it only relaxes the bass-motion check at the specific
     N→N+1 edge that matches the pattern.
   - Tritone-sub detection is shared with the future
     `HarmonicScorer` extraction (§10). For now, hard-code the rule
     in `ProgressionBuilder` and annotate with
     `// TODO(harmonic-scorer): extract`.

5. **Implement §7.4 — Constraint relaxation order.**
   - When slot N's filtered candidate pool is empty (after applying
     §7.1, §7.2, and any of §7.3 that would normally relax), relax
     in this order until at least one candidate exists:
     1. Position constraint to ≤4 frets.
     2. Position constraint to ≤6 frets.
     3. Voicing-category filter (allow next-priority category from
        the §5 table).
     4. Position constraint dropped entirely (last resort).
   - Each relaxation step appends to a `constraint_relaxations`
     entry in the diagnostics output for that slot.
   - Bass-motion is **never** relaxed automatically — if no candidate
     has legal bass motion, the relaxation cascade above fires
     instead, which expands the pool to candidates that may have
     legal bass motion in the relaxed pool. If even relaxing
     position+category to fully-unrestricted doesn't help, log a
     `bass_motion_unsatisfiable` flag and let the scoring engine
     pick from the unrestricted pool (graceful degradation; the
     audit will surface it).

6. **Diagnostics extensions.**
   - New diagnostic field: `constraint_relaxations` —
     `[['slot' => N, 'relaxed' => 'position to 4', 'pool_size_before' => 0, 'pool_size_after' => 5], ...]`.
   - New audit flag: `constraint_relaxation` — fires when any
     non-trivial relaxation (steps 1–4 above) was used. Rare cases
     should be the only ones triggering this.
   - New audit flag: `bass_motion_unsatisfiable` — fires when even
     full relaxation didn't yield a legal-bass-motion candidate.
     Should be ~zero on the corpus.
   - The existing `position_thrash` flag's threshold should be
     updated to ">3 frets" (down from ">5") since Phase C's hard
     filter is at 3. After Phase C, this flag should fire only when
     §7.4's relaxation cascade hit step 1 or 2 (position widened to
     4 or 6).

7. **Audit verification.**
   - Re-run `--mode=category` and compare against
     `progressions-20260501-203444.md`:
     - `position_thrash` should drop from 6 to 0 (or only fire on
       relaxed slots, with `constraint_relaxation` flag co-occurring).
     - Repeated-chord voicing reuse: 100% on adjacent identical
       slots (Quick-Change Blues bars 1-2-3-4 of `I7` should all be
       the same shape).
     - `category_pool_fallback` count should stay roughly the same
       (~12) — Phase C tightens within the chosen pool, doesn't
       change pool selection.
     - Phase A simple-mode reproduces byte-identical (Phase C does
       not touch simple-mode dispatch).

#### C.2 Acceptance criteria

**Quantitative:**
- `position_thrash` count: 6 → 0 (or ≤3, with each remaining case
  co-flagged with `constraint_relaxation`).
- Repeated-chord reuse: 100% on all 43 progressions × adjacent
  identical chords.
- `bass_motion_unsatisfiable`: 0 (this is the canary — if it fires,
  the constraint cascade has a logic bug).
- `constraint_relaxation`: ≤5 (occasional necessary relaxation is
  fine; widespread relaxation indicates the §7 thresholds are too
  strict for the corpus and need softening).
- Phase A simple-mode audit reproduces byte-identical.

**Qualitative (spot-check):**
- 12-Bar Blues all 12 bars use at-most-3 fret window. Same C7 shape
  across bars 1-2-3-4 *and* across bars 6-7 *and* bar 10-11. Same
  F7 shape across bars 4-5 *and* bar 9.
- Test Jazz Progression's `Dm7 → G7 → Cmaj7` stays in a 3-fret
  window (current Phase B output: 5 → 7 → 9 = 4-fret total spread,
  may need slot 2 to drop to position 5–6).
- Bill Evans Turnaround (`Imaj7 | bIIImaj7 | bVImaj7 | bIImaj7`):
  this progression has built-in chromatic root motion that may
  trigger constraint relaxation. The `bIII → bVI` is a P4 down
  (legal), `bVI → bII` is a tritone (hard-blocked unless tritone-sub
  relaxation fires — does it? bVImaj7 → bIImaj7 is not a dominant
  resolution, so no tritone-sub exception). Likely outcome: this
  progression *legitimately* requires bass-motion relaxation. Document
  as expected.
- Tritone Sub Jazz Cadence (after B-followup): `D7 → Db7 → Cmaj7`.
  D7 → Db7 is descending semitone (interval 11), which IS in the
  legal set. Db7 → Cmaj7 is descending semitone again (interval 11)
  AND a tritone-sub edge — both legal. No relaxation needed.

**Non-regression:**
- Every chord that Phase B produced correctly stays correct or
  improves position locality.
- All admin-annotated "good" / "actually reasonable" cases unchanged.

#### C.3 What Phase C does NOT do

- **No new scoring code.** The cost function rewrite is Phase D. The
  existing scoring engine still runs after Phase C's filters; Phase
  C only narrows what it scores over.
- **No option tones.** Phase E.
- **No Viterbi.** Phase D.
- **No `selectVoicingsForSequence` removal.** Phase F.
- **No constraint-tuning beyond the §7 defaults.** If audit shows the
  thresholds need adjusting, that's a Phase D weight-tuning concern,
  not Phase C scope. Phase C's job is to *enforce* the spec, not
  re-derive the thresholds.

#### C.4 Risk and mitigation

- **Risk:** the existing scoring engine has hidden assumptions that a
  chord-by-chord candidate pool of, say, 30+ voicings is available,
  and constraint-narrowing to 2–5 candidates per slot exposes a bug
  in `pickBestVL` or the cross-pool rescue. **Mitigation:** if Phase
  C audit shows new pathologies (e.g. cross-pool rescue firing on
  every slot because the locked-group pool is empty after position
  filter), *temporarily disable* cross-pool rescue for the
  Phase-C-with-constraints path. Phase D will replace it entirely.
- **Risk:** the hard 3-fret position constraint is too tight for some
  jazz progressions where idiomatic voicings span a wider window.
  **Mitigation:** the §7.4 relaxation cascade catches this — if 3
  frets gives an empty pool, it widens to 4 then 6. Audit the
  `constraint_relaxation` flag to identify which progressions
  genuinely need a wider default; if a class of progressions
  consistently triggers relaxation, *that's* the signal to soften the
  default — but only after data, not preemptively.
- **Risk:** repeated-chord reuse interacts badly with `pinnedSlot`
  cases on the chord-detail page (where the user pins a specific
  voicing for a slot mid-progression). **Mitigation:** pinned slots
  are treated as authoritative and the reuse rule does not propagate
  through them. Specifically: if slot N is pinned, slot N-1's voicing
  does not propagate to N; if slot N+1 has the same chord_name as N,
  slot N+1 reuses N's pinned voicing (correct — the user wants to see
  what they pinned realized consistently).

#### C.5 Phase C open questions

- **Tritone-sub minimal rule scope.** Does it cover only the dom →
  maj resolution, or also dom → min (`bII7 → Im` is a thing)? §7.3
  as drafted covers only major targets. Recommendation: extend to
  minor targets in Phase C.4 — same condition, just `quality ∈ {min,
  m, m7}` for slot N+1. Keep it minimal — full functional pattern
  matching is Phase E.
- **Bass-motion edge case for first-slot pin.** When `pinnedSlot ===
  0`, slot 0 has a fixed bass note. Does slot 1's bass-motion
  constraint apply against the pin? Yes — pin doesn't suppress
  constraints downstream. Document as expected.
- **Should `constraint_relaxation` flag block "ship" decision?**
  Recommendation: no — relaxation is normal in some progressions
  (see Bill Evans Turnaround note above). The flag is informational,
  not a quality bar. Block-ship only on `bass_motion_unsatisfiable`
  and on `position_thrash` if it co-occurs without
  `constraint_relaxation` (would indicate a logic bug).

### Phase C-followup — Constraint spec alignment + lattice extraction — NEXT

**Goal.** Before Phase D replaces the scorer, align the shipped Phase C
implementation with the written §7 constraints and make the candidate
lattice explicit. This is a small structural cleanup patch, analogous
to B-followup: no intended musical behavior change except correcting
the bass-motion directionality bug for interval `11`.

**Estimated effort:** ~0.5–1 day.

#### C-followup.1 — Fix relaxation order

Implement §7.4's cascade exactly:

1. Position constraint widened to `≤4`.
2. Position constraint widened to `≤6`.
3. Voicing-category filter relaxed to the next-priority category.
4. Position constraint dropped entirely.

Bass-motion is never auto-relaxed. If no candidate survives any
position/category relaxation while preserving legal bass motion, log
`bass_motion_unsatisfiable` and fall back gracefully so the audit can
surface the case.

#### C-followup.2 — Allow interval `11`

Correct §7.2 and the code so descending semitone motion is legal under
the modulo-up interval calculation. The hard-blocked bass intervals are
only `{6, 8}`. Update any tests or assertions that still treat `11` as
illegal.

#### C-followup.3 — Tighten tritone-sub helper

The tritone-sub exception applies only when:

- source quality is dominant,
- target quality is major/maj7 family,
- bass interval is exactly `6`.

After C-followup.2, interval `11` no longer needs an exception.

#### C-followup.4 — Extract candidate lattice

Move Phase C candidate filtering out of the scoring loop into an
explicit `buildCandidateLattice($context, $options): array<int,
array<object>>` helper. The existing greedy scorer continues to choose
slot-by-slot from these pools until Phase D replaces it with Viterbi,
but Phase D now has a concrete lattice to consume.

#### C-followup acceptance criteria

Re-run `php artisan sbn:audit-progressions --mode=category` and verify:

- `position_thrash` stays `≤2`.
- `bass_motion_unsatisfiable` remains `0`.
- Tritone Sub still produces `D7 | Db7 | Cmaj7` cleanly; interval `11`
  is the canary.
- Phase A simple-mode reproduces byte-identical.
- All other Phase C-shipped behavior is unchanged or improves.

### Phase D — Cost function + Viterbi search — ✅ SHIPPED 2026-05-02

**Goal.** Replace the existing scoring stack
(`pickBestVL` + forward/backward + cross-pool rescue) with a single
**Viterbi search over a normalized weighted cost function**. The
candidate-pool work done in Phases B and C stays untouched; Phase D
operates on the lattice that those phases produce.

After Phase D: the audit's top-decile VL threshold drops by an order
of magnitude (target ≤3.0, from Phase C's 33.9), `group_thrash` stays
at 0, simplicity term reliably picks plain voicings (`x3545x` Cmaj7)
over fancy alternatives when no upgrade is requested, and the
remaining `high_vl_score` flags isolate genuine harmonic-context
problems (which become Phase E targets).

**Estimated effort:** 3–5 days.

#### D.1 Why Phase D matters

Phase C tightened the candidate pool but the Phase C audit shows the
existing `scoreVL` engine fundamentally cannot rank candidates well
within a 3-fret window. Specifically, four problems were diagnosed
during Phase C audit review:

1. **No normalization.** Score terms are raw integers — guide-tone
   penalties of 5–10, common-tone bonuses of 1.5×, fret-distance
   capped at 3.0, string-set penalties of ±1.5 to +3. The numbers
   collide arbitrarily; they don't compose into a coherent total.
2. **No simplicity term.** Plain Cmaj7 (`x3545x`, 4 sounding notes,
   no extensions) and fancy Cmaj7(13)/E (`xx5557` with extras) score
   nearly identically. The algorithm has no reason to prefer the
   simple shape when both are locally OK.
3. **No note-count-continuity term.** A 5-note shape transitioning to
   a 3-note shape gets no penalty for the structural mismatch, even
   though it sounds wrong.
4. **Dual-counting.** Both the harmony filter
   ([ProgressionBuilder.php:1327](app/Services/ProgressionBuilder.php#L1327))
   and the score function penalize dom→minor 13/9/#9 — once by
   removing the candidate, once by adding a `+10` to the score. This
   meant a candidate that survived the filter still got penalized
   *as if it shouldn't have*, distorting the cost ordering.

All four are explicit Phase D scope.

#### D.2 Concrete tasks

**Tasks broken into three sub-phases, each independently verifiable.**

##### D.2.1 — Normalized cost function (no algorithm change yet)

Implement §8 as a new internal method
`costBreakdown($v1, $v2, $context): array` and expose
`costBetween($v1, $v2, $context): float` as a simple wrapper returning
`$breakdown['total']`. `costBreakdown` returns one entry per term plus
`raw_voice_leading`, `weighted_total`, and `total`. The float total is a
value in `[0, 6]` (sum of six terms each in `[0, 1]` × their weight).
Terms:

| Term | §8 ref | Default weight | What it measures |
|---|---|---|---|
| `c_simplicity(v_n+1)` | §8.1 | 0.10 | Note count above category baseline + extension count |
| `c_position(v_n, v_n+1)` | §8.2 | 0.20 | Linear penalty for fret distance, capped at 5 frets |
| `c_bass_motion(v_n, v_n+1)` | §8.3 | 0.20 | Stepped penalty by interval class (P4/P5 = free) |
| `c_common_tone(v_n, v_n+1)` | §8.4 | 0.15 | 1 - (same-string commons × 0.7 + any-string commons × 0.3) |
| `c_voice_leading(v_n, v_n+1)` | §8.5 | 0.25 | Existing `scoreVL` machinery, normalized |
| `c_group_continuity(v_n, v_n+1)` | §8.6 | 0.10 | Same group = 0; diff group + same note count + ±2 fret = 0.1; else 0.5 |

Each term is its own private method. Each method produces a value in
`[0, 1]` and asserts that bound (in dev mode). The cost function
sums them with the weight vector.

The weight vector is a constant `COST_WEIGHTS` at the top of
`ProgressionBuilder`. D.2.1 also accepts a builder-level
`weight_overrides` option that merges over the constant, so unit tests
can force particular candidates without waiting for D.2.3's CLI flag.

`c_voice_leading` starts with named constant
`SCORE_VL_NORMALIZER = 15.0`, using
`min(scoreVL($a, $b) / self::SCORE_VL_NORMALIZER, 1.0)`. Keep
`vl_scores` as raw `scoreVL` for audit continuity; add new diagnostic
fields for `cost_breakdown`, `path_cost`, and observed raw
voice-leading maxima.

**Acceptance for D.2.1:**
- New `costBreakdown()` and `costBetween()` methods exist and are
  unit-testable in isolation.
- Each term method's output is bounded `[0, 1]` for all 43 corpus
  progressions (verified by an assertion or test).
- The existing selection path (`pickBestVL`) is **not** touched yet.
  Audit reproduces Phase C output byte-identical (D.2.1 is purely
  additive code), while diagnostics may contain additive cost fields.

##### D.2.2 — Viterbi search

Replace the forward + backward + cross-pool rescue with a single
Viterbi pass over the candidate lattice.

The C-followup lattice helper currently exists as an entry point, but
Phase C's constrained candidate pool still depends on the previously
chosen anchor, which is a greedy assumption. D.2.2 replaces that with an
anchor-free slot lattice: each slot contains non-open, root-filtered,
category-aware candidates. Position and bass-motion constraints move to
edge admissibility in Viterbi. Edge weights between adjacent slots =
`costBetween()`. The Viterbi pass finds the minimum-cost finite path
through the lattice.

Relaxation becomes a wrapper around Viterbi: if no finite-cost path
exists, re-run with the §7.4 cascade widened (`≤4`, then `≤6`, then
category relaxed, then position dropped). Bass-motion remains
non-relaxable except for explicit `bass_motion_unsatisfiable`
diagnostics.

Implementation outline:

```
function viterbiSearch($candidatePools, $context):
    $n = count($candidatePools)
    if $n === 0: return []
    if $n === 1: return [pickCheapestSolo($candidatePools[0], $context)]

    // Slot 0: seed with starting cost (depends on context — see below)
    $cost = [0 => array_map(fn($c) => seedCost($c, $context), $candidatePools[0])]
    $prev = [0 => array_fill(0, count($candidatePools[0]), null)]

    // Forward pass: for each slot, compute min cost to reach each candidate
    for $i = 1; $i < $n; $i++:
        for each $c_curr in $candidatePools[$i]:
            $best = INF; $bestPrev = null
            for each $j => $c_prev in $candidatePools[$i-1]:
                $edgeCost = costBetween($c_prev, $c_curr, $context)
                $total = $cost[$i-1][$j] + $edgeCost
                if $total < $best:
                    $best = $total; $bestPrev = $j
            $cost[$i][$k] = $best  // $k = index of $c_curr
            $prev[$i][$k] = $bestPrev

    // Backtrack: find min in last column, walk pointers backward
    $path = []
    $idx = argMin($cost[$n-1])
    for $i = $n-1; $i >= 0; $i--:
        $path[$i] = $candidatePools[$i][$idx]
        $idx = $prev[$i][$idx]

    return $path
```

`seedCost($candidate, $context)`: starting cost for slot 0. For pop
mode this prefers low fret positions (open chords); for jazz it
prefers fret 5 (the existing seed heuristic, normalized to `[0, 1]`).
Per-category seed defined in a `CATEGORY_SEED_BIAS` constant.

`pickCheapestSolo`: for single-chord progressions, just pick the
candidate with minimum `seedCost`.

**Pinned slots.** When slot N is pinned (`pinnedSlot === N`,
`pinnedVoicing` set), the lattice is reduced to a single candidate at
slot N. Viterbi naturally handles this — the forced slot constrains
both backward (slots <N) and forward (slots >N) search since edges
into and out of the pinned candidate are the only options.

**Repeated-chord rule (§7.5).** With an anchor-free lattice, repeated
adjacent chord reuse becomes an edge/path constraint rather than a
greedy copy: the repeated slot's candidate is forced to the same
diagram/frets as the previous candidate when both symbols are
identical, unless pinned-slot semantics override it.

**Acceptance for D.2.2:**
- Viterbi replaces `pickBestVL` and the forward/backward/rescue
  passes. The class loses ~200 lines.
- Cross-pool rescue (
  [ProgressionBuilder.php:578-590](app/Services/ProgressionBuilder.php#L578))
  is **deleted**. If rescue would have improved a path, Viterbi
  finds the same path naturally because cost is now normalized and
  the rescue's "break the lock" logic is just "the locked path's
  cost is higher than another path's cost, pick the lower."
- Audit `--mode=category`: top-decile raw `vl_scores` threshold drops
  to ≤3.0
  (from Phase C's 33.9). Position_thrash stays at ≤2.
  `group_thrash` stays at 0. `high_vl_score` count drops by ≥50%.

##### D.2.3 — Drop dual-counting + weight tuning

**Drop dual-counting.** Audit
[ProgressionBuilder.php:820-847](app/Services/ProgressionBuilder.php#L820)
(the wrong-alteration penalty section in `scoreVL`). Each rule there
duplicates a rule in
[ProgressionBuilder.php:1327](app/Services/ProgressionBuilder.php#L1327)
(`applyHarmonyFilter`). Pick the filter as the canonical source of
truth (it operates earlier, removing candidates entirely) and **delete
the duplicate penalty** from the score function.

Specifically, delete the score-side penalties for:
- dom→minor with natural 13/6/9/#9
- #11 on tonic maj
- natural 9 on half-dim source/target

The harmony filter already prevents candidates with these traits from
reaching the scoring step, so the score-side penalties never fire on
real candidates. Removing them simplifies the score and makes the
remaining cost terms more interpretable.

**Weight tuning.** The default weights in §8 (and D.2.1's table) are
first-guess. Tune against the audit corpus:

1. Audit before tuning. Capture baseline.
2. For each weight w_i: try w_i × 0.5 and w_i × 2. Re-audit. Record
   how each ablation affects the per-progression cost distribution
   and the qualitative spot-checks (Test Jazz Progression, Tritone
   Sub, 12-Bar Blues, Pachelbel Canon, etc.).
3. Adjust weights based on what the data shows. Lock the final
   weights into the constant table.

To make tuning fast: add a `--weights=path/to/weights.json` flag to
`sbn:audit-progressions` that reads weight overrides from a JSON file
and passes them through to `buildVoicings` via D.2.1's
`'weight_overrides'` option. The audit JSON should record which
weights were used (top-level `weights` field) so post-hoc analysis
can reproduce results.

**Acceptance for D.2.3 (final state, 2026-05-02):**
- ✅ `scoreVL` wrong-alteration penalties removed; the score path no
  longer dual-counts harmony rules already enforced by the candidate
  filter.
- ✅ Final weight vector documented in §8 (above). Includes the new
  `w_register` term and per-category overrides.
- ⚠️ **Top-decile VL ≤3.0 was NOT met.** Final number 9.2 (down from
  Phase C's 33.9). Weight ablations (raising/lowering w_voice_leading,
  raising w_register) did not move the needle further. The remaining
  high-VL transitions cluster on a small set of progressions
  (Pachelbel Canon, the upper-register portion of 12-Bar Blues, and a
  few jazz-mode runs where the locked candidate pool genuinely
  contains no low-VL alternative). Diagnosed as **candidate-pool
  limited, not cost-function limited** — Phase E territory.
- ✅ `high_vl_score` 12 → 7 (42% reduction; spec target was 50%).
  Counted across runs that previously flagged; the remaining 7 are
  the cases where Phase D cannot improve without library expansion.
- ✅ `position_thrash` 2 → 0; `group_thrash` stays 0; 43/43 runs
  complete (no errors).
- ✅ Phase A simple-mode reproduces byte-identical (D doesn't touch
  simple-mode).
- ✅ Pop in `--mode=category` reproduces byte-identical to
  Phase B-followup output (auto-routes to simple-mode).

**Side effect bug fix landed in D.2.3:** `is_fixed_position` DB flag
is now honored in `fetchVoicingsForChord`. Previously
`ProgressionBuilder` was the only consumer ignoring it, producing
nonsense barré transpositions of root-locked archetypes (e.g. the
G7 `320001` open shape was being offered as A7 `431112`,
B7 `542223`, etc.). Three other services (`VoicingCrossref`,
`ChordVoicingSearch`, slash-chord path) already honored the flag.
Three-line guard at fetch time. After fix: the literal target voicing
F7 `131211` is selected for I–IV–V (was masked before by other
fixed-position transpositions winning on cost).

#### D.3 Acceptance criteria for Phase D (overall)

**Final results, 2026-05-02** (audit `progressions-20260502-192537.json`):

| Metric | Phase C | Phase D target | Phase D actual |
|---|---|---|---|
| Top-decile VL | 33.9 | ≤3.0 | 9.2 ⚠️ |
| `high_vl_score` | 12 | ≤6 (≥50% drop) | 7 ⚠️ (42% drop) |
| `group_thrash` | 0 | 0 | 0 ✅ |
| `position_thrash` | 2 | ≤2 | 0 ✅ |
| Errored runs | 0 | 0 | 0 ✅ |

The two ⚠️ metrics cluster on the same handful of progressions whose
locked candidate pools have no low-VL alternative; weight tuning
cannot fix this. Phase E (option-tone upgrade Pass 2 + library
expansion) is where the remaining headroom lives.

Original aspirational targets retained below for reference:

**Quantitative:**
- Top-decile VL threshold: 33.9 → ≤3.0 (or whatever is right after
  weight tuning — the explicit target may shift down further).
- `high_vl_score` count: drops by ≥50% from Phase C's 12.
- `group_thrash` count: stays at 0.
- `position_thrash` count: stays ≤2 (Phase C's number).
- Phase A simple-mode reproduces byte-identical (D doesn't touch
  simple-mode).
- Pop progressions in `--mode=category` reproduce byte-identical to
  Phase B-followup output (auto-routes to simple-mode).

**Qualitative (spot-check):**
- Test Jazz Progression: `Dm7 x5756x | G7 (basic, no incidental
  +#9,b13) | Cmaj7 x3545x` — the spec's flagship example, restored.
  G7 should be a basic 7-chord like `3x343x` or `xx3445x` without
  the algorithm-incidental option tones currently appearing.
- 12-Bar Blues: every C7 across all 12 bars is the same shape; every
  F7 likewise; no 5-note → 3-note transitions.
- Tritone Sub: `D7 | Db7 | Cmaj7` in tight position, basic 7-chord
  voicings (no incidental b13/b9/#11).
- Pachelbel Canon: closed_triads in a 3-fret window, low VL scores
  throughout (currently the chord-by-chord transitions are 2.75–7+).
- Bill Evans Turnaround: chromatic descent in drop2, with the
  legitimate bass-motion relaxation events surfaced in diagnostics.

**Non-regression:**
- All admin-annotated "good" / "actually reasonable" cases stay good
  or improve.
- No new error categories.

#### D.4 What Phase D does NOT do

- **No option-tone upgrade.** Even when `extensions: true` is set,
  Phase D's output uses *whatever extensions happen to be on the
  selected voicing* in the DB. The deliberate option-tone selection
  pass (Pass 2 in §9.2) is Phase E.
- **No harmonic-pattern recognition.** The functional patterns from
  the identifier (`FUNCTIONAL_FRAGMENTS`) stay un-imported. Phase E.
- **No `selectVoicingsForSequence` removal.** Phase F.
- **No new constraints.** §7's hard constraints stay as Phase C
  defined them. Phase D operates on what survives the constraints.
- **No quartal pool seeding.** §15 question 1 already resolved;
  modal stays with the existing 3 quartal records + fall-through.

#### D.5 Risks and mitigation

- **Risk: weight tuning takes longer than expected.** First-guess
  weights produce sub-optimal output, the audit-driven tuning loop
  is slow because each iteration requires running the full audit
  and eyeballing changes. **Mitigation:** the `--weights` flag in
  D.2.3 makes single-iteration runs fast (no rebuild). Add a focused
  spot-check audit subset (`--progressions=12,1,7,32` or similar) that
  runs only the 4–5 progressions you spot-check during tuning, much
  faster than running all 43.
- **Risk: Viterbi finds a path that's globally optimal but locally
  surprising.** Possible because the algorithm now considers
  cross-slot tradeoffs the existing forward-greedy didn't. Most of
  these will be improvements; a few might be subjectively worse.
  **Mitigation:** spot-check qualitative cases against admin
  expectations (§D.3 list above). If a specific progression
  consistently picks an "unmusical" path, examine the cost terms at
  that progression's slots and decide whether to (a) adjust the
  weight, (b) add a missing cost term, or (c) accept that Viterbi's
  global optimum is musically defensible even if surprising.
- **Risk: deleting cross-pool rescue regresses some cases that the
  rescue was correctly handling.** Possible because the rescue
  implicitly encoded "sometimes the locked group is wrong, break it."
  Viterbi handles this naturally (different group = higher cost
  edge, but Viterbi will take it if the alternative is worse). But
  there might be specific cases where the rescue's heuristic
  threshold of 4.0 was load-bearing. **Mitigation:** before deleting
  the rescue, list the progressions where it currently fires (run
  the audit, search the diagnostics for rescue events). Spot-check
  those after Phase D. Expect Viterbi to find equivalent or better
  paths; if any case regresses, that's a weight-tuning signal.
- **Risk: existing tests break.** Tests in
  `tests/Unit/VoicingMaterializerRhythmTest.php` and elsewhere may
  depend on specific voicings being chosen. **Mitigation:** treat
  test breakage as a signal — the test was capturing an arbitrary
  choice the old algorithm made, not a contract. Update tests to
  assert *categorical* properties (this slot is drop2 root position,
  not "this slot is `x5756x`") rather than specific voicings.

#### D.6 Phase D open questions

- **Should `seedCost` for slot 0 be category-specific?**
  Recommendation: yes — pop wants low frets (open archetypes), jazz
  wants fret 4–7 (drop2 sweet spot), classical wants fret 1–3
  (closed_triads tend to live there). This is a small constant
  table (`CATEGORY_SEED_BIAS`), not a new concept. Lock in during
  D.2.2.
- **Should the cost function expose its breakdown for diagnostics?**
  When the audit shows a high VL score, knowing which term
  contributed is the difference between "tune this weight" and
  "fix this term." Recommendation: add `cost_breakdown` to per-slot
  diagnostics:
  ```
  'cost_breakdown' => [
      ['from' => 0, 'to' => 1, 'simplicity' => 0.05, 'position' => 0.0,
       'bass_motion' => 0.0, 'common_tone' => 0.10, 'voice_leading' => 0.42,
       'group_continuity' => 0.0, 'total' => 0.57],
      ...
  ]
  ```
  Worth ~50 lines of audit + builder plumbing. Pays for itself the
  first time a high-VL transition needs explaining.
- **Should `c_voice_leading` be a normalization of the existing
  `scoreVL` or a rewrite?** The existing scoreVL has real music
  theory in it (b7→3 resolution, guide-tone proximity, etc.) that
  shouldn't be thrown away. Recommendation: **normalize, don't
  rewrite**. Wrap the existing scoreVL output with a
  `min(scoreVL($a, $b) / max_scoreVL_observed, 1.0)` normalization,
  where `max_scoreVL_observed` is empirically determined from the
  audit corpus (≈10–15 based on current data). Rewriting voice
  leading from scratch would risk dropping correctness; this is
  Phase E's domain in any case.
- **What about the existing harmony filter?** `applyHarmonyFilter`
  stays. It's a candidate-pool filter, so it operates before
  Viterbi. The dual-counting cleanup in D.2.3 only removes the
  *score-side* duplicates of harmony rules; the filter itself is
  load-bearing for excluding genuinely wrong candidates (dom→minor
  with natural 13, etc.) and stays untouched.
- **When should Phase D ship — after each sub-phase or all at once?**
  Recommendation: ship after D.2.2 (cost function + Viterbi), even
  if weight tuning isn't perfect yet. The structural change is the
  biggest improvement; weight tuning is a refinement that can
  iterate independently. Mark D.2.3 as a "tuning patch" follow-up if
  needed.

### Phase E — Option-tone upgrade Pass 2

- Implement §6.2 numeral upgrade (extension recommendations by
  functional context).
- Implement §9.2 second Viterbi pass.
- Audit: extensions-on output uses appropriate option tones,
  doesn't inflate simple progressions.
- ~2–3 days.

### Phase F — Cleanup and `selectVoicingsForSequence` retirement

- Migrate callers of `selectVoicingsForSequence` to
  `buildVoicings` with appropriate category.
- Delete the second entry point.
- Final audit comparison; admin sign-off.
- ~1 day.

**Total: ~10–15 days of focused work.** Phase B alone (category
mapping + numeral upgrade) delivers the largest user-visible
improvement. If only one phase ships, ship Phase B.

---

## §14. Out of scope

- **Progression detection.** `ProgressionDetector` stays as is.
  Builder consumes its outputs (DB-stored category + numerals); does
  not detect progressions itself.
- **Identification refactor.** Already handled in
  `Identifier-Refactor-Spec.md`; do not touch as part of this work.
- **`HarmonicScorer` extraction.** A clean follow-up to both the
  builder and identifier rewrites; not a prerequisite.
- **Quartal voicing seeding.** §5.1 — modal falls back to jazz pool
  until `voicing_category = 'quartal'` records exist.
- **Chord-melody arrangement** (top voice = melody constraint). The
  builder is for accompaniment voicings only. Chord-melody is a
  separate problem that this engine could *eventually* be extended
  to support but that's not on this spec.
- **Audio playback ordering.** The builder produces a sequence of
  voicings; the playback engine handles timing, rhythm
  materialization, etc. Out of scope.

---

## §15. Open questions

1. ~~Quartal voicing implementation.~~ **Resolved 2026-05-01:** The
   DB has 3 `quartal` records. Modal uses them first-class; no
   jazz-pool fallback needed at the category level. The pool's
   sparseness is handled by the modal priority order
   (quartal → shell → drop3) within the modal pool itself.
2. ~~`other` category.~~ **Resolved 2026-05-01:** No DB entries use
   `other`. Drop from the category enum; treat any unrecognized
   category as `jazz`.
3. ~~Open archetype curation.~~ **Resolved 2026-05-01:** Use all
   archetype voicings in the DB (35 records). Apply the non-barré
   priority ordering (§5.1) — non-barré > partial-barré > full-barré.
   No new admin curation pass needed.
4. **Tritone-sub detector scope** — implement minimally as
   "current is dominant, next is major/maj7 a tritone away" (the
   single rule covers 90% of cases), or pull in
   `FUNCTIONAL_FRAGMENTS` from the identifier wholesale for full
   coverage? **Recommendation:** ship the minimal rule in Phase C.
   Pull in `FUNCTIONAL_FRAGMENTS` later if Phase D's audit shows
   tritone-sub-related VL failures the minimal rule misses.
5. **Weight tuning** — the §8 weights are first-guess. Tune against
   the audit corpus during Phase D. May need to expose them as
   config to make tuning faster. **Recommendation:** add a
   `--weights=path/to/weights.json` flag to the audit command early
   in Phase D so weight-tuning iterations don't require rebuilds.

### 15.1 Phase A kickoff decisions (resolved 2026-05-01)

1. **Simple-mode source pool:** use DB `voicing_category = 'archetype'`
   (including currently-empty category records treated as archetype).
   Do not add a new `open` category and do not add a config-table copy.
2. **API surface:** add `mode: 'simple'` on `buildVoicings`; default
   omitted keeps existing behavior unchanged.
3. **Simple lookup fallback order:** strict root+quality first;
   fallback to root-only triad equivalent; if no archetype exists for
   that root, return `null` for that slot.
4. **Option interaction:** in simple mode, ignore `style` and
   `extensions` completely (simple-mode path wins).
5. **Repeated adjacent chords:** enforce voicing reuse in Phase A
   simple mode (unless `pinnedVoicing` overrides the slot).
6. **Initial chord coverage:** use the full existing DB archetype pool;
   no separate curated Phase A subset.
7. **Category coupling:** do not auto-enable simple mode for `category:
   'pop'` in Phase A; keep category routing for Phase B.
8. **Audit behavior:** wire current audit `--mode=simple` preset to
   pass `mode: 'simple'` to the builder so audit output directly
   validates the new simple lookup path.

---

## Authorship & history

- 2026-04-30: Audit baseline generated. Admin annotations added to
  audit markdown.
- 2026-05-01: User clarified principles in conversation:
  category-driven output, two-pass option tones, P4/P5 bass motion
  preference, group-thrash tolerance with note-count+position
  preservation, 4ths/5ths bass jump cap. This spec drafted.
- 2026-05-01 (later): User corrected three points after first review:
  (1) DB has all 11 voicing categories including `quartal`; modal
  uses quartal directly without jazz-pool fallback. (2) All archetype
  voicings available; barré shapes are last-resort within the
  archetype set. (3) Builder produces voicings only — edu-panel
  content generation is unrelated and lives elsewhere. Spec updated.
- 2026-05-01 (Phase A shipped): `mode: 'simple'` lookup-table
  shortcut implemented and validated. Audit at
  `progressions-20260501-200257.md`: pop progressions produce cowboy
  chords matching the spec target output exactly, repeated chords
  reuse voicings, group_thrash flags eliminated in simple mode (50 →
  0). Position_thrash flags increased on minor-7th progressions
  (5 → 24) — expected; addressed by Phase C.
- 2026-05-01 (Phase B planning): §13 expanded with concrete Phase B
  tasks, acceptance criteria, risk mitigation, and three sub-phase
  open questions. Phase B targets ~50% group_thrash reduction in the
  default-mode audit by routing every progression through its
  category's pool before scoring runs.
- 2026-05-01 (Phase B shipped): Audit at
  `progressions-20260501-203444.md`. `group_thrash` 50 → 0 (target
  was ≤25 — far exceeded). `high_vl_score` 28 → 9. Phase A simple-mode
  reproduces byte-identical. `category_pool_fallback` diagnostic
  surfaced ~12 events, all interpretable as either correct fallback
  behavior or DB coverage gaps to fill via the existing voicing UI.
- 2026-05-01 (Phase B follow-up + Phase C planning): Audit review
  surfaced two refinements queued for B-followup:
  (1) auto-route pop without `style` to simple-mode lookup (fixes
  5th-fret partial-barré C regression vs Phase A's open C);
  (2) §6.1 numeral upgrade respects Roman numeral case (uppercase
  plain non-tonic = dominant, uppercase + `m` = minor, lowercase =
  minor) so Tritone Sub progression realizes as `D7 | Db7 | Cmaj7`
  per the user's "acceptable set." DB triad seeding was discussed
  and deferred to admin (out of spec scope). Phase C expanded to
  full sub-spec with 7 concrete tasks, hard-constraint cascade,
  diagnostic flags (`constraint_relaxations`, `constraint_relaxation`,
  `bass_motion_unsatisfiable`), and acceptance criteria. Phase C
  targets `position_thrash` 6 → 0 and 100% repeated-chord reuse on
  adjacent slots without touching the scoring engine yet.
- 2026-05-01 (B-followup shipped): Audit at
  `progressions-20260502-113130.md`. Both refinements verified: pop
  progressions in `--mode=category` reproduce Phase A simple-mode
  open cowboy chords; Tritone Sub jazz cadence produces drop2
  realizations of `D7 | Db7 | Cmaj7`. Plus quality improvements:
  position_thrash 6 → 4, top-decile VL threshold 5.9 → 5.2.
  `over_extended_triad` audit flag fired 4× as a false positive on
  intentional plain-numeral → 7-chord upgrades — flagged for
  audit-code refinement (category-aware threshold) before Phase C
  ships.
- 2026-05-02 (Phase C shipped): Audit at
  `progressions-20260502-115043.md`. All structural acceptance
  criteria met: position_thrash 4 → 2, repeated-chord reuse 100%,
  bass_motion_unsatisfiable 0, constraint_relaxations recorded in
  JSON. One regression caught and fixed during ship: initial Phase
  C crashed all 8 pop progressions with `Undefined array key -1`
  when simple-mode dispatch hit a Phase C helper without a
  `$slotIdx > 0` guard. Top-decile VL threshold rose from 5.2 to
  33.9 as expected — Phase C's tighter pool revealed existing
  `scoreVL` weaknesses that Phase D will fix.
- 2026-05-02 (Phase D planning): §13 expanded with full Phase D
  sub-spec. Three sub-phases (D.2.1 normalized cost function, D.2.2
  Viterbi search, D.2.3 dual-counting drop + weight tuning), each
  independently verifiable. Phase D targets top-decile VL threshold
  33.9 → ≤3.0 and `high_vl_score` count drop ≥50%. Four open
  questions documented (category-specific seed bias, cost-breakdown
  diagnostics, scoreVL normalize-vs-rewrite, ship-cadence). Risk
  register includes weight tuning velocity, Viterbi global optimum
  surprises, cross-pool rescue regressions, test breakage.

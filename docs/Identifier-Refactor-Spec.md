# SBN Chord Identification & Construction — Reference

**Status:** Living document. Phase 1 shipped; Phase 2 shipped 2026-05-06
and live in the **MusicXML import flow** in the leadsheet editor
(browser-side `MusicXMLParser` → `POST /api/admin/leadsheets/identify-voicings`
→ `VoicingCrossref::identifyVoicingsBatch` → `ContextualReranker`).
Also live on the editor's interactive "identify voicings" path. Not
called from the shortcode-based `LeadsheetController::store` path
(which uses pre-named chords).
**Last update:** 2026-05-06.
**Scope:** Both halves of SBN's chord-handling algorithm —
**chord identification** (frets → chord name) and **chord construction**
(chord progression → voicings). This document is the reference for what
each algorithm does, why it does it, what's working, what's broken, and
how to evolve it without breaking what works.

If you're touching either of these algorithms, **read this first**.
For known issues and the queue of planned improvements, see
**Appendix D**.

---

## TL;DR

- **Chord identification** (`App\Services\VoicingCrossref::identifyFromFrets`
  and friends) — fundamentally sound. **Phase 1 (slash-chord first-class
  candidates) shipped 2026-05-01. Phase 2 (harmonic-pattern awareness)
  shipped 2026-05-06** with five sub-passes: top-K candidate exposure,
  pedal detection, diminished-as-rootless-V7(b9) recognition, diminished
  root resolution, and cadence/fragment matching with diatonicity
  re-rank. Wired through `VoicingCrossref::identifyVoicingsBatch` —
  fires on **MusicXML import** (browser-side parser → server identify
  endpoint) and on the editor's interactive identify-voicings path.
- **Chord construction** (`App\Services\ProgressionBuilder`) — fundamentally
  patched-up. Wants a clean rewrite, not another patch. Separate spec.
- **Audit infrastructure** exists for both, in `app/Console/Commands/`.
  Use it before and after every change. Specifically:
  `php artisan sbn:audit-identifier <file>`,
  `sbn:audit-identifier-context <file>`, and `sbn:audit-progressions`.
- **Editing progressions:** the Phase 2 fragment table is a generated
  PHP constants file. After any edit to `sbn_chord_progressions` or its
  `alt_numerals`, run `php artisan sbn:reseed-fragments` (or click the
  **Rebuild Fragment Index** button in the progression admin page) and
  commit the regenerated `storage/app/harmonic-fragments.generated.php`.
- **Design principle that emerged from this work, write it on your wall:**
  every change to these algorithms must distinguish **Bucket 1** failures
  (algorithmic artifacts that no human would defend, e.g. `Dmin(b13)`)
  from **Bucket 2** failures (locally-defensible outputs that only a
  contextual reading would improve, e.g. `Am6` for what's really
  `D7(9)/A` over an A pedal in a ii–V–i in F). Fix Bucket 1. Leave
  Bucket 2 alone unless you're explicitly working on the harmonic layer.

---

## Part 1: Chord identification

### 1.1 What it does

Given a 6-character fret string (e.g. `x5756x`, `0` = open, `x` = muted,
hex `a-f` = frets 10–15) and a position marker, produce a chord name
plus structured metadata (root, quality, extensions, bass note,
inversion, confidence, diagram match if any).

Used during MusicXML import to label every chord stack the user pastes
in; also exposed via the admin "identify voicings" endpoint for ad-hoc
queries.

### 1.2 Underlying principle

The identifier is a **template-matching scoring algorithm with rescue
passes and contextual re-ranking**. In academic terms: MAP estimation
over `P(chord | pitch_classes)` with hand-coded likelihood, followed by
a CRF-style sequence pass when neighbor context is available.

The scoring step iterates every `(root, quality)` pair, scores how well
each fits the pitch-class set, and picks the highest. Rescue passes
catch known failure modes the main scorer mis-handles by design
(rootless voicings, plain triads with jazz interpretations,
tritone-implies-dominant). A separate context pass re-scores the result
against neighbor chords and the song key.

**This structure is the right structure for the problem. Do not
replace it.**

### 1.3 Pipeline

The full identification pipeline, in order:

| Pass | What it does | Where in code |
|---|---|---|
| 1 | Score all `(rootPc, quality)` pairs. Bass-boost ×4 when root = bass. Exact-bonus ×3 for 4+ tone exact matches. **Slash-bonus when a non-bass root passes the chord-tone test (Phase 1 fix — see §1.5).** | `identifyFromFrets`, lines ~1396–1451 |
| 3a | Rootless rescue: if Pass 1 found nothing, try interval sets *from an absent root* (e.g. `[3,7,10]` = m7 missing root) | lines ~1453–1460 |
| 3b | Rootless upgrade: if Pass 1 picked a plain triad from only 3 PCs and ≤3 fretted notes, also check rootless templates and prefer them when they match | lines ~1462–1470 |
| 3c | Tritone-dominant rescue: if Pass 1 winner is non-dominant but the PC set contains a tritone, re-score dominant interpretations with bonus ×5. Motivating example in code comment: `xx5676` in bossa | lines ~1472–1554 |
| 4 | Slash-chord post-detection: if `bestRoot ≠ bassPc`, append `/BassNote` and set `inversion` | lines ~1575–1592 |
| Context pass | When called via `identifyFromFretsWithContext` / `identifyVoicingsBatch`, re-rank against neighbor chord names + song key using `ROOT_MOTION_BONUS`, `FUNCTIONAL_FRAGMENTS`, diatonicity check | `identifyFromFretsWithContext`, line ~1634 |

### 1.4 What is load-bearing — do not change without strong reason

Future readers should treat the following as **deliberate and earned**,
not as scaffolding:

- The four-pass design (Pass 1 → 3a → 3b → 3c → context). Each pass
  exists for a documented failure mode.
- The `IDENTIFY_QUALITY_INTERVALS` table, sorted longest-first.
  Specificity matters — `maj7` beats `maj` on tied evidence.
- The `IDENTIFY_EXTENSION_INTERVALS` table mapping leftover pitch
  classes to named extensions. This is what lets the algorithm tolerate
  added 9s/11s/13s without losing the underlying chord identity.
- The `IDENTIFY_ROOTLESS_TEMPLATES` table for jazz voicings without a
  bass root. Crucial for `Cm7` over `Eb` cases.
- The tritone-dominant rescue at Pass 3c. Keep the `xx5676` comment.
- The context pass with `ROOT_MOTION_BONUS`, `ROOT_MOTION_PENALTY`,
  `FUNCTIONAL_FRAGMENTS`, `CTX_MAJOR_SCALE`. This is real harmonic
  knowledge refined against real songs.
- The slash-chord post-detection (Pass 4). It correctly adds `/BassNote`
  **once the right root is picked**.
- **The Phase 1 slash-bonus and its chord-tone restriction**
  (intervals `{3, 4, 7, 10, 11}`). See §1.5.
- **The two-tier slash-bonus values** (`slashBonus7th = 3.5`,
  `slashBonusTriad = 2.5`). See §1.5.

### 1.5 Phase 1 — Slash-chord first-class candidates (SHIPPED)

**Status:** Implemented and validated against the audit corpus (141
chord stacks across 3 representative MusicXML files). Pattern 1 cases
flip to correct outputs; no regressions on Bucket 2 or
currently-correct chords.

#### 1.5.1 The bug it fixed (Pattern 1)

Across the audit corpus, eight cases shared one signature: an inversion
of a major-or-maj7 chord — where the bass is the 3rd, 5th, or b7 of the
actual root — was being identified as an exotic interpretation rooted
on the bass note instead.

| Voicing | Frets | Notes | Was identified as | Should have been |
|---|---|---|---|---|
| `x5533x` | D-G-Bb-D | D, G, Bb | `Daug(11)` | `Gm/D` |
| `ax8aax` | D-Bb-F-A | D, Bb, F, A | `Dmin(b13)` | `Bbmaj7/D` |
| `xx333x` | F-Bb-D | F, Bb, D | `Fsus4(13)` | `Bb/F` |
| `8xaaax` | C-F-A | C, F, A | `Csus4(13)` | `F/C` |
| `7x577x` | B-G-D-F# | B, G, D, F# | `Bmin(b13)` | `Gmaj7/B` |
| `5x355x` | A-F-C-E | A, F, C, E | `Amin(b13)` | `Fmaj7/A` |
| `3x133x` | G-Eb-Bb-D | G, Eb, Bb, D | `Gmin(b13)` | `Ebmaj7/G` |
| `xxaaax` | C-F-A | C, F, A | `Csus4(13)` | `F/C` |

The signature is unmistakable: outputs of the form `Xmin(b13)`,
`Xsus4(13)`, or `Xaug(11)` are essentially never how a jazz reader
would write these chords. They were algorithmic artifacts.

#### 1.5.2 Mechanical cause (the score collision)

Traced on chord #9 of *Days of Wine and Roses* (`ax8aax`, PCs `{D, Bb,
F, A}`):

**D-min iteration (bass-boosted ×4):**
- `min` = `[0,3,7]` → expected `{D, F, A}`. Match: 3/3 exact. Leftover
  `{Bb}`; interval 8 from D = `b13` (in extension table) → unexplained = 0.
- `raw = 3 × 100 - 0 × 50 = 300`. Total < 4, no exact-bonus.
- Bass-boosted: `300 × 4 = 1200`.

**Bb-maj7 iteration (not bass):**
- `maj7` = `[0,4,7,11]` → expected `{Bb, D, F, A}`. Match: 4/4 exact.
  Leftover `{}` → unexplained = 0.
- `raw = 4 × 100 - 0 × 50 = 400`. Total ≥ 4, exact-bonus ×3 → **1200**.

**Both score exactly 1200.** The tie was broken by candidate-root
iteration order, which puts the bass first. D iterated first → reached
1200 → became `bestScore`. Bb reached 1200 later, but the comparison
`> bestScore` is strict, so it lost.

This was **structural, not a tuning issue.** The `bassBoost` (×4) for a
3-note partial match with all extensions explained produced *exactly*
the same score as the `exactBonus` (×3) for a 4-note perfect match. The
two reward structures collided at the same numerical value, and the
algorithm relied silently on enumeration order to break the tie.

The deeper issue: **slash-chord candidates were not enumerated as
first-class scored options.** The slash-chord pathway existed only as a
post-hoc relabeling (Pass 4) — by the time it ran, the algorithm had
already committed to a non-slash interpretation. So `Bbmaj7/D` never
got to compete in the scoring pass at all.

#### 1.5.3 The fix

Inside the Pass 1 scoring loop, after computing `raw`:

```
$bassIv = ($bassPc - $rootPc + 12) % 12;
$isSlashCandidate = !$isBass
    && in_array($bassIv, [3, 4, 7, 10, 11], true)
    && ($matched === $total || $unexplained === 0);

if ($isBass) {
    $score = $raw * $bassBoost;        // ×4
} elseif ($isSlashCandidate) {
    $score = $raw * ($total >= 4 ? $slashBonus7th : $slashBonusTriad);
                                       // ×3.5 for 7-chords, ×2.5 for triads
} else {
    $score = $raw;
}
```

Plus the constants:

```
$slashBonus7th   = 3.5;  // strong: "this is a clean 7-chord with bass-as-chord-tone"
$slashBonusTriad = 2.5;  // moderate: "this is a clean triad with bass-as-chord-tone"
```

#### 1.5.4 Why the chord-tone restriction matters

The interval set `{3, 4, 7, 10, 11}` is **the most important piece of
this fix**. It allows slash-bonus to fire only when the bass is a
**genuine chord tone** of the candidate root (m3, M3, P5, m7, M7). It
*excludes* cases where the bass is an extension (2 = 9, 5 = 11, 6 = #11,
8 = b13, 9 = 13).

This is the line that separates Bucket 1 from Bucket 2:

- `Bbmaj7/D` — bass D is the **3rd** of Bb. Slash-bonus fires. Bug fixed.
- `E7(11)/A` (Wine chord #6) — bass A is the **11th** of E. Slash-bonus
  does **not** fire. Output stays `E7(11)/A`. (An analyst, knowing the
  surrounding `Am7b5 → ... → Gm` cadence in F, would actually call this
  `D7(9#11)/A` — but reaching that conclusion requires harmonic-pattern
  reasoning, which is Phase 2 territory.)

Earlier drafts of the spec proposed a global triad exact-bonus (Option
B) and an explicit tiebreaker (Option C). Both were dropped because
they would have inflated bass-rooted exact triads enough to start
flipping Bucket 2 cases. The chord-tone restriction is more precise: it
encodes the *musical* concept of "the bass is the 3rd/5th/7th of the
real root" rather than relying on score-magnitude tuning.

#### 1.5.5 Why two tiers (the `xx5768` story)

The first implementation of the fix used a single `slashBonus = 3.5`.
This caused a regression on `xx5768` (PCs `{G, D, F, C}`, bass G,
appears 4× in *Wine and Roses*), which had been correctly identified as
`Gm7(11)`:

- **G-m7** (bass-boosted): expected `{G, Bb, D, F}`. Match 3/4
  near-exact, leftover `{C}` interval 5 = `11` ✓ in table. unexp = 0.
  raw = 250. ×4 = **1000**.
- **C-sus4** (slash, bass G = perfect 5th of C): expected `{C, F, G}`.
  Match 3/3 exact. Leftover `{D}` interval 2 = `9` ✓. unexp = 0.
  raw = 300. ×3.5 (slash) = **1050**.

`Csus4(9)/G` edged out `Gm7(11)` by 50. Both readings are technically
valid PC interpretations, but jazz convention strongly prefers the
m7-with-extension reading because:

1. `m7` is a more specific quality (4 expected notes) than `sus4` (3).
   When both are valid readings of the same PC set, the more-specific
   one is the better answer — this is already a principle elsewhere in
   the algorithm (`uasort` sorts qualities longest-first so `maj7`
   beats `maj`).
2. m7 chords commonly carry an 11 in jazz; sus4 chords don't have a
   strong convention around 9s. So "leftover note matches a known
   extension *of this chord type*" is stronger evidence for m7 than for
   sus4.

The fix to the fix: **slash-bonus is two-tiered**. A 7-chord slash
candidate (`total >= 4`) gets `slashBonus7th = 3.5`. A triad slash
candidate (`total <= 3`) gets `slashBonus7th = 2.5`. This encodes:

> "A clean 7-chord with bass-as-chord-tone is decisive evidence
> against a bass-rooted partial read.
> A clean triad with bass-as-chord-tone is suggestive but should not
> override a more-specific 4-tone rooted reading."

Re-traced with the two-tier rule:

- `xx5768`: G-m7 stays at 1000. C-sus4 slash with triad bonus 2.5: 300 ×
  2.5 = 750. **G-m7 wins. Gm7(11) preserved. ✓**
- `x5533x`: D-aug bass-boosted = 600. G-min slash with triad bonus 2.5:
  300 × 2.5 = 750. **G-min wins. Gm/D fix preserved. ✓**
- `ax8aax`: D-min bass-boosted = 1200. Bb-maj7 slash with 7th bonus 3.5:
  1200 × 3.5 = 4200. **Bb-maj7 wins. Bbmaj7/D fix preserved. ✓**

This refinement matters because **it makes the spec self-consistent**:
the existing `uasort` "longest quality wins ties" rule and the new
slash-bonus now cooperate rather than fight. They both encode the same
principle (specificity is preferred), just at different layers.

### 1.6 Phase 2 — Harmonic-pattern awareness (SHIPPED 2026-05-06)

**Status:** Shipped 2026-05-06. Wired through
`VoicingCrossref::identifyVoicingsBatch`, which is hit by both:

- **MusicXML import** in the leadsheet editor — the browser-side
  `MusicXMLParser` (in [resources/views/admin/leadsheets/edit.blade.php](../resources/views/admin/leadsheets/edit.blade.php))
  extracts `Tab\d+` voicings, then `identifyTabVoicings()` POSTs them
  to `/api/admin/leadsheets/identify-voicings`, which routes to
  `LeadsheetController::identifyVoicings` and through Phase 2 before
  renaming the chords in-place.
- **Interactive identify-voicings** in the leadsheet editor (per-chord
  identification UI).

Other ingestion paths (the shortcode-based
`LeadsheetController::store`) operate on pre-named chords and do not
call the identifier.
**Modality:** This section specs Phase 2 for **tab/MusicXML inputs only**.
Audio-transcription bindings live in §1.7.2 and reuse the same machinery
when that path is built.
**Known issues and follow-ups:** See Appendix D.

Phase 1 fixed the structural bug. Phase 2 elevates the identifier from
"locally correct on each chord" to "musically aware across a passage."

**As-shipped sub-pass order** (final, supersedes the earlier draft
ordering in §1.6.2):

```
Phase 1 (per-chord, unchanged) → top-K candidates per slot
   ↓
Phase 2a:  Pedal-point detector              (PedalDetector)
   ↓
Phase 2c′: Diminished-as-rootless-V7(b9)     (DiminishedAsDominantResolver)
   ↓
Phase 2c:  Diminished-root resolver          (DiminishedResolver)
   ↓
Phase 2b:  Cadence/fragment matcher          (HarmonicPatternMatcher)
   ↓
Phase 2d:  Key-aware enharmonic re-rank      (in ContextualReranker)
   ↓
Final per-slot result with: name, root, quality, candidates[], 
                            reinterpreted, reinterpret_reason,
                            matched_progression_id, matched_variant_index
```

Sub-pass 2c′ (DiminishedAsDominantResolver) was added during
implementation and was not in the original §1.6 draft — see Appendix D.1
for why it was needed.

#### 1.6.1 What Phase 2 fixes

Real edge cases observed in production after Phase 1 shipped, all
**Bucket 2**:

| Voicing | Phase 1 says | Analyst says | Why |
|---|---|---|---|
| `ax8aax` (Wine, was Pattern 1) | `Bbmaj7/D` | `Gm7(9)/D` | Sits between Gm chords; the Bb-as-root reading is locally clean but the *passage* is plainly Gm with the 9 (A) added |
| `xx799a` (Wine #6) | `E7(11)/A` | `D7(9#11)/A` | Sandwiched between `Am7b5` and `Gm` — a textbook ii–V–i in F, where the V slot must be D7 |
| `xx7898` (Wine #7) | `F7(#9)/A` | `D7(b9#11)/A` | Same passage as #6, more altered V7 voicing |
| `xx7978` (Wine #8) | `Am6` | `D7(9)/A` | `Am6` and `D9` are PC-equivalent (`{A,C,E,F#}`); only functional context disambiguates |
| Misty bridge (TBD frets) | `Edim7` (or one of its symmetric inversions) | `C#dim7` between C and Dm | Phase 1 picks dim7 root by enumeration order; only context disambiguates |
| Easy Living turnaround | `I VI7 IIm7 V7` (literal) | could equally be `I #Idim IIm7 V7` | The dim variant is the same teachable progression; needs to be *recognized as a variant of progression #5*, not as four unrelated chords |

All cases share the shape "local pitch content has multiple defensible
readings, and the analyst picks the one that fits the cadential pattern
or voice-leading device."

#### 1.6.2 Architecture overview

Phase 2 is implemented as a **single contextual re-ranking pass** over
the output of Phase 1, with three capability populators feeding one
shared signal: an `expectedChord` prior per slot.

```
Phase 1 (per-chord, unchanged) → top-K candidates per slot
   ↓
Phase 2a: Pedal-point detector       (scans bass-note runs)
   ↓
Phase 2b: Cadence/fragment matcher   (HarmonicPatternMatcher: seeded from DB)
   ↓
Phase 2c: Diminished-root resolver   (handles dim7 inversional symmetry)
   ↓
Phase 2d: Key-aware enharmonic re-rank (only for slots flagged by 2a–2c
                                         OR where top-2 candidates are
                                         within Δ of each other)
   ↓
Final per-slot result with: name, root, quality, candidates[], 
                            reinterpreted: bool, reinterpret_reason: string,
                            matched_progression_id: int|null,
                            matched_variant_index: int|null
```

The four sub-passes are **purely additive** over Phase 1. They never
produce a chord whose PCs aren't in Phase 1's top-K — they only re-rank.
This is the critical property that makes Phase 2 safe: any wrong
reinterpretation can be reverted by ignoring the re-rank, and Phase 1's
local correctness is preserved as the lower bound.

#### 1.6.3 Prerequisite: top-K candidate plumbing

Phase 2 cannot work over winner-only output. The first implementation
step is non-breaking and standalone:

**Modify `VoicingCrossref::identifyFromPcSetFull()`** (and downstream
return shape) to expose top-K candidates alongside the winner. Acceptance
target: `K = 5` candidates, sorted by score descending.

```php
// Existing winner fields (unchanged):
'name'      => 'Bbmaj7/D',
'root'      => 'Bb',
'quality'   => 'maj7',
'extensions'=> [],
'bass'      => 'D',
'inversion' => '1st',
'confidence'=> 0.84,
// New:
'candidates' => [
    ['name' => 'Bbmaj7/D', 'root' => 'Bb', 'quality' => 'maj7', 'score' => 4200, ...],
    ['name' => 'Dmin(b13)','root' => 'D',  'quality' => 'min',  'score' => 1200, ...],
    ['name' => 'Dm9',      'root' => 'D',  'quality' => 'min7', 'score' =>  900, ...],
    // ... up to K=5
],
```

Acceptance for this step alone: run `php artisan sbn:audit-identifier`
on all four fixtures (§Appendix C). The diff against pre-change baseline
must be **zero functional changes** — only `candidates[]` added to JSON.
Markdown audit output unchanged.

#### 1.6.4 Sub-pass 2a — Pedal-point detection

**Trigger:** N ≥ 3 consecutive slots share the same bass pitch class
while upper-voice PCs change.

**Action:** for each slot in the run:

1. Compute upper-voice PCs (all PCs except the pedal bass).
2. Re-run Phase 1 against the upper-voice PCs alone (no bass-boost,
   slash-bonus disabled).
3. Take Phase 1's top-K from the upper-voice PCs as the new candidate
   list, with each candidate's name suffixed by `/{pedalBass}`.
4. Set `expectedChord[slot]` to the new winner.
5. Mark `reinterpreted = true`, `reinterpret_reason = 'pedal'`.

**Wine m. 4–5 worked example:**
Bass A held across `xx799a | xx7898 | xx7978`. Upper PCs are
`{D, F#, G, C}` / `{D, F, G#, C}` / `{D, F#}` respectively. Re-running
Phase 1 on uppers yields D7-family chords for each. Output flips from
`E7(11)/A | F7(#9)/A | Am6` to `D7(9#11)/A | D7(b9#11)/A | D7(9)/A`.

**Bbmaj7/D worked example:**
Bass D held across `xxxx0x | ax8aax | xxxx0x` (Gm-rooted neighbors with
D as a common-tone bass). Upper PCs of middle slot are `{Bb, F, A}` —
re-runs as Bb major. With pedal interpretation, output becomes
`Gm/D | Gm7(9)/D | Gm/D` — the upper structure is read in the
Gm-with-9 frame rather than as an independent Bb chord. (This requires
the pedal detector to also weigh the *neighbor's identified root* —
when neighbors are Gm and the pedal is the 5th of Gm, Gm9 is a stronger
upper read than Bbmaj7. Implementation detail: the pedal sub-pass
consults the matched-progression context from 2b before finalizing
upper-voice interpretation.)

**Bounds:** N ≥ 3, max pedal length capped at 8 slots (longer runs are
typically intentional pedal passages where the user wants the literal
labels — the ≤8 bound is empirically motivated and may relax later).

#### 1.6.5 Sub-pass 2b — Cadence/fragment matching

**Data source:** the seeded `sbn_chord_progressions` table (43 rows)
plus the `alt_numerals` JSON column added in §1.8. At Phase 2 build
time, the `php artisan sbn:reseed-fragments` command flattens the
table into a constant PHP file checked into the repo:

```
storage/app/harmonic-fragments.generated.php
```

The generated file is a flat list of fragments, each tagged with
`progression_id`, `variant_index` (null = canonical), `variant_label`,
and `numerals[]`.

**Algorithm:**

1. Translate Phase 1's winner labels into Roman numerals against the
   song key (existing `song_key` field; if absent, infer from the
   sequence using the existing `CTX_MAJOR_SCALE` machinery).
2. Slide each fragment of length L across the numeral sequence
   (1 ≤ L ≤ 6 for current fragments).
3. **Soft match:** a slot is a "fuzzy hit" against a fragment slot when
   *any* of its top-K candidates produces the expected numeral, OR its
   PC set Jaccard-overlaps the expected chord's PC set by ≥ τ
   (τ = 0.75, empirically tuned during audit pass).
4. A fragment matches when ≥ ⌈L × 0.66⌉ slots are fuzzy hits
   AND the first and last slots are exact-numeral hits (anchors must
   be confident).
5. For each matched fragment, set `expectedChord[slot]` for each
   non-anchor slot to the candidate that scored the fuzzy hit.
6. Record `matched_progression_id` and `matched_variant_index` on each
   slot in the matched range.

**Conflict resolution:** when two fragments overlap, the longer fragment
wins. Ties go to the lower-`variant_index` (canonical preferred over
variant). The matcher emits at most one match per slot.

**Tier 1 fragments** (high-confidence rewrite rules — the matcher fires
these always when matched):

| # | Source | Numerals | Comment |
|---|---|---|---|
| 1 | DB #1 | `IIm7 → V7 → Imaj7` | Major ii-V-I |
| 2 | DB #2 | `IIm7b5 → V7 → Im` | Minor ii-V-i |
| 3 | DB #5 + variant | `I → VI7 → IIm7 → V7` (canonical) and `I → #Idim → IIm7 → V7` (variant) | Turnaround #2 |
| 4 | DB #6 | `bVII7 → Imaj7` | Backdoor |
| 5 | DB #7 | `II → bII → I` | Tritone sub |
| 6 | DB #9 + variant | `IIIm7 → VI7 → IIm7 → V7` and dim variants | Extended turnaround |
| 7 | DB #38 + variant | `I → VIm → IIm → V7` and dim variants | Turnaround |
| 8 | DB #35 | `V7 → VIm` | Deceptive (anchor-only, no rewrite) |

**Tier 2 — Diminished passing-chord rules (high priority, see §1.6.6):**
encoded as constraints on Tier 1 variant fragments and as a standalone
sub-pass §1.6.6.

**Tier 3 — Defensive (suppress wrong reinterpretations):**

| # | Source | Numerals | Action |
|---|---|---|---|
| T3.1 | DB #12/#13 | 12-bar blues skeleton | When matched, suppress IV7→IVmaj7+ext re-ranks |
| T3.2 | DB #26 | `Im7 → bIIm7` (So What) | Suppress functional-tonic re-ranks |

**Excluded from Tier 1** (too rare to hard-code; their constituent ii-Vs
will fire as Tier 1 #1 anyway): DB #4 Rhythm Changes Bridge, #8 Bill
Evans Turnaround, #11 Parker Blues, #36 Ellington, #41 Corcovado.
Pop progressions #16/#17/#21 are also excluded — Phase 2's value-add
on triadic pop progressions is near zero.

#### 1.6.6 Sub-pass 2c — Diminished-root resolver

**The structural problem:** dim7 chords are inversionally symmetric
under transposition by minor third — `Cdim7`, `Ebdim7`, `Gbdim7`, and
`Adim7` share the exact same PC set `{0,3,6,9}`. Phase 1 picks one of
the four roots by enumeration order, which is essentially random
relative to musical meaning. Phase 2c is the structural fix.

**When it fires:** any slot whose Phase 1 winner has `quality ∈ {dim7,
dim, °7}` AND has at least one neighbor slot.

**Rules in priority order:**

| Rule | Condition | Action |
|---|---|---|
| D1 | Slot's neighbors have roots `X` and `X+2` (whole step apart, ascending) AND one of the four symmetric dim7 roots equals `X+1` | Force dim7 root = `X+1`. Annotate `reinterpret_reason = 'dim_passing_ascending'` |
| D2 | Neighbors `X` and `X-2` (descending whole step) AND one symmetric root equals `X-1` | Force root = `X-1`. `reinterpret_reason = 'dim_passing_descending'` |
| D3 | Slot is followed by `Imaj7` or `Im` AND one symmetric root equals the tonic | Don't rewrite root; flag `common_tone_dim = true` in metadata so editor can offer the `°7 → I` spelling on request |
| D4 (default) | If none of D1/D2/D3 applies, AND slot has neighbors | Among the four symmetric roots, prefer the one that produces the smoothest voice-leading to/from neighbors (lowest sum of semitone motion to neighbor roots). Tie-break by ascending preference (D1 over D2 logic). Annotate `reinterpret_reason = 'dim_voice_leading'` |
| D5 (fallback) | No usable neighbor context | Leave Phase 1's root choice; mark `confidence = 'low'`, `reinterpret_reason = null` |

**Interaction with 2b fragment matcher:** the dim variant fragments
(progression #5/#9/#38 with `alt_numerals` containing `#Idim`/`#IIdim`/etc.)
match in 2b *after* 2c has assigned dim7 roots. So the order is:
2a (pedal) → 2c (dim resolution) → 2b (fragment match) → 2d (final
enharmonic re-rank). The pipeline at §1.6.2 reflects this; the order
matters because fragment matching reads the dim7's resolved root.

#### 1.6.7 Sub-pass 2d — Key-aware enharmonic re-rank

**When it fires:** any slot where (a) 2a/2b/2c flagged it, OR (b) Phase 1's
top-2 candidates are within Δ score of each other (Δ = 15% of top score,
empirically tuned).

**Inputs:** `expectedChord[slot]` (from 2a/2b), the slot's top-K, and
the song key.

**Algorithm:**

1. If `expectedChord[slot]` is set AND it appears in the slot's top-K:
   promote it to winner. Record `reinterpreted = true`.
2. Else, compute a diatonicity score for each top-K candidate against
   the song key (reuse existing `CTX_MAJOR_SCALE` and the diatonicity
   bonus from the Phase 1 context pass).
3. Re-rank top-K by `score + diatonicity_bonus`. If the new winner
   differs from the old, mark `reinterpreted = true`,
   `reinterpret_reason = 'enharmonic_diatonic'`.

**Critical guard:** 2d never promotes a candidate that wasn't already in
Phase 1's top-K. This is what bounds Phase 2's wrongness: if the audit
shows a slot where the "right" answer wasn't in Phase 1's top-5, that's
a Phase 1 issue (probably K too small, or scoring too narrow), not a
Phase 2 issue.

#### 1.6.8 The `ContextualReranker` service

**File:** `app/Services/HarmonicContext/ContextualReranker.php`
**Companion:** `app/Services/HarmonicContext/HarmonicPatternMatcher.php`
**Companion:** `app/Services/HarmonicContext/DiminishedResolver.php`
**Companion:** `app/Services/HarmonicContext/PedalDetector.php`

**Public API:**

```php
class ContextualReranker
{
    public function __construct(
        private HarmonicPatternMatcher $matcher,
        private DiminishedResolver $dimResolver,
        private PedalDetector $pedalDetector,
    ) {}

    /**
     * @param array<int, array> $phase1Results  Top-K results from Phase 1, in slot order
     * @param string|null       $songKey        e.g. 'F', 'Am', null
     * @param array<int, string>|null $expectedChords  Optional per-slot prior 
     *                                                  (reserved for §1.7.2 audio path; 
     *                                                  pass null for tab/XML)
     * @return array<int, array>  Re-ranked results with reinterpreted flags
     */
    public function rerank(
        array $phase1Results,
        ?string $songKey = null,
        ?array $expectedChords = null,
    ): array;
}
```

**Pure function:** no DB access at runtime, no I/O, no state. The
fragment table is loaded once at construction time from the generated
constants file. Fully unit-testable.

**Integration point:** `VoicingCrossref::identifyVoicingsBatch()` is
the existing batch entry point. Phase 2 replaces its current
context-pass call with:

```php
// Existing single-chord pipeline, now returns top-K
$phase1 = array_map(
    fn($stack) => $this->identifyFromPcSetFull($stack['pcs'], $stack['bass'], ...),
    $stacks
);

// Phase 2: re-rank with context
$reranked = $this->contextualReranker->rerank($phase1, $songKey);

return $reranked;
```

The existing `ROOT_MOTION_BONUS`, `ROOT_MOTION_PENALTY`,
`FUNCTIONAL_FRAGMENTS`, `CTX_MAJOR_SCALE` constants are **moved into**
the new services (matcher gets `FUNCTIONAL_FRAGMENTS`, reranker gets
`CTX_MAJOR_SCALE` and the motion bonuses). They are not duplicated.
The current `identifyFromFretsWithContext()` becomes a thin wrapper
that calls the reranker for backward compatibility, then collapses the
batch back to a single result.

#### 1.6.9 Implementation order

1. **Top-K candidate plumbing** (§1.6.3). Standalone PR.
   Audit must show zero functional diff.
2. **Schema PR** (§1.8). Standalone PR. `alt_numerals` column,
   variant occurrence columns, backfill the affected progression rows.
   Owner reviews the proposed `alt_numerals` content before merge.
3. **`HarmonicPatternMatcher` + `sbn:reseed-fragments` command.**
   Generated constants file checked in. Unit-tested in isolation
   against synthetic numeral sequences.
4. **`DiminishedResolver`.** Unit-tested in isolation; the four
   symmetric-roots case is the critical test.
5. **`PedalDetector`.** Unit-tested in isolation.
6. **`ContextualReranker`** wires the three sub-passes plus 2d.
   Integration tests against the four MusicXML fixtures.
7. **Wire into `identifyVoicingsBatch`.** Run audit; verify
   acceptance criteria (§Appendix C).
8. **New audit command:** `sbn:audit-identifier-context`
   (§Appendix C.3). Writes context-aware audit alongside Phase-1-only
   audit for comparison.

Editor surface (badge for `reinterpreted: true` slots) is **deferred
to a follow-up PR**, per owner's decision. The flag is emitted in the
result; the UI just doesn't render it yet.

#### 1.6.10 Why Phase 2 is separate work

- **Different state space.** Phase 1 operates on a single chord. Phase 2
  operates on a sequence with cross-chord dependencies.
- **Different risk profile.** Phase 2 will sometimes make wrong
  reinterpretations. The `reinterpreted` flag exists so that the
  editor can eventually show local + contextual readings side by side
  and let the user override.
- **Different audit needs.** Phase 1's audit asks "did the local chord
  come out right?" Phase 2's audit asks "did the *narrative* come out
  right across the passage?" Different fixtures, different acceptance
  bars (§Appendix C).

#### 1.6.11 Out-of-scope for Phase 2

- Anything in audio (§1.7.2 — uses Phase 2's machinery but ships
  separately, gated on upstream audio-pipeline improvements).
- Editor UI for `reinterpreted` badge (deferred to follow-up).
- Re-querying the DB at runtime — fragments are constants per §4.4.
- Sparse 2-note voicings on Barquinho returning `no_identification`.
- `aug` over-reach on 2-note voicings.
- Single-note "stacks."
- Builder integration (§2.3 mentions this; it's the Builder rewrite's
  job to consume Phase 2 output, not Phase 2's job to push to the
  Builder).

### 1.7 Phase 2 across input modalities

Phase 2's `ContextualReranker` is modality-agnostic by design. Its
`expectedChords` parameter is the single extension point through which
non-XML input modalities supply harmonic priors.

#### 1.7.1 Tab / MusicXML (this phase)

- `expectedChords = null` (no external prior).
- All four sub-passes 2a–2d derive context from Phase 1's own top-K
  outputs and the song key.
- Ships as part of the work specified in §1.6.

#### 1.7.2 Audio transcription (future, not implemented now)

**Scope-flag:** this section is architectural prep. No audio code is
written in the §1.6 PR. It exists so future audio work plugs in without
re-shaping Phase 2.

**The audio path produces noisy PC-sets per beat.** The
[Audio-Transcription-Architecture.md §7](Audio-Transcription-Architecture.md)
already names the integration point: a leadsheet (e.g. from the Jazz
Standards DB) provides an `expectedChord` per beat / measure, and the
identifier biases its scoring toward that chord when audio PCs Jaccard-
overlap by ≥ τ.

**How this maps to §1.6's machinery:**

1. The leadsheet→beat mapper (lives in the audio pipeline) produces an
   `expectedChords[]` array keyed by slot index, each entry a chord
   name like `Dm7` or `G7(9)`.
2. `ContextualReranker::rerank($phase1, $songKey, $expectedChords)`
   receives this array.
3. For any slot where `expectedChords[i]` is set, sub-pass 2d treats it
   as a strong prior: if the expected chord appears in Phase 1's top-K
   *or* its PCs Jaccard-overlap top-1 by ≥ τ, promote it. Mark
   `reinterpreted = true`, `reinterpret_reason = 'reference_anchor'`.
4. The `reinterpreted` flag and a new `confidence_source = 'reference'`
   flag flow to the editor, which (when the editor surface ships) shows
   anchored chords distinctly from free-Phase-1 chords.

**The Corcovado vision:** leadsheet loaded → rhythm template gives beat
boundaries → audio PCs bucketed per beat → Phase 1 produces top-K per
beat → leadsheet feeds `expectedChords` → reranker promotes the
leadsheet chord whenever audio supports it, falls back to Phase 1
otherwise. The framework is real; the accuracy ceiling is gated on
upstream audio-pipeline improvements (Demucs stem separation,
robust downbeat detection) which are outside Phase 2's scope.

#### 1.7.3 Why the audio populator is deferred

Reference-anchoring is a multiplier on Phase 1 quality. It fails
silently when Phase 1's PCs are wrong (basic-pitch overtones, ghost
notes, beat misalignment). Building the populator before fixing those
upstream sources of noise produces a brittle dependency: anchoring
*looks like* it works on clean fixtures and produces invisible wrong
answers on real audio.

The right sequence:
1. §1.6 (this phase): Phase 2 for tab/XML — clean inputs, clean wins.
2. Demucs stem separation in the Python sidecar (Audio-Transcription
   Architecture §A.5.1).
3. Downbeat detection (PLP or user-tap UI, Audio-Transcription
   Architecture §3.A/B).
4. Reference-anchoring populator wired into `ContextualReranker` via
   the `expectedChords` parameter that already exists.

By stage 4, Phase 2's machinery accepts the new input without code
changes — only the populator (a new service that consumes a leadsheet
and produces `expectedChords[]`) is new code.

### 1.8 Schema prerequisite — `alt_numerals` and variant-aware occurrences

**Status:** Spec ready, ships as standalone PR before §1.6 work begins.
**Owner review point:** the proposed `alt_numerals` content for the
affected progression rows must be reviewed and approved before the
backfill migration runs.

#### 1.8.1 Why this exists

Phase 2's fragment matcher (§1.6.5) needs to recognize substitutional
*variants* of teachable progressions as the same teachable thing. The
prime example: `I → #Idim → IIm7 → V7` is the same Turnaround #2 as
`I → VI7 → IIm7 → V7`. Without schema support, Phase 2 would either
miss the variant entirely or hard-code the variant→parent relationship
in code, creating two sources of truth.

The shipped solution: store variants on the parent row in JSON; track
variant identity on detected occurrences.

#### 1.8.2 Schema changes

**Migration A:** add `alt_numerals` JSON column to `sbn_chord_progressions`.

```php
Schema::table('sbn_chord_progressions', function (Blueprint $table) {
    $table->json('alt_numerals')->nullable()->after('numerals');
});
```

**`alt_numerals` shape** (JSON array, nullable, default null):

```json
[
  {
    "label": "with #Idim sub",
    "numerals": "I,#Idim7,IIm7,V7",
    "notes": "Leading-tone diminished passing chord substituting for VI7"
  },
  {
    "label": "with #IIdim passing",
    "numerals": "IIm7,#IIdim7,IIIm7,V7",
    "notes": "Diminished passing between ii and iii"
  }
]
```

Each variant entry has `label` (short, ≤ 100 chars), `numerals`
(comma-separated, same format as the parent column), and `notes`
(freeform, optional).

**Migration B:** add variant tracking columns to `sbn_progression_occurrences`.

```php
Schema::table('sbn_progression_occurrences', function (Blueprint $table) {
    $table->integer('variant_index')->nullable()->after('progression_id');
    $table->string('variant_label', 100)->nullable()->after('variant_index');
});
```

`variant_index = null` means canonical (matches the parent's `numerals`).
`variant_index = 0, 1, 2, ...` indexes into the parent's `alt_numerals`
array. `variant_label` denormalizes `alt_numerals[variant_index].label`
for query convenience (avoids joining-into-JSON on every read).

#### 1.8.3 Backfill — `alt_numerals` content (NEEDS OWNER REVIEW)

The migration adds the column; a separate seeder populates it on the
affected rows. Proposed content below — owner reviews before merge.

**Progression #1 — Major Jazz Cadence (`IIm7,V7,Imaj7`):**

```json
[
  {"label": "with #Vdim leading-tone",
   "numerals": "IIm7,V7,#Vdim7,Imaj7",
   "notes": "Leading-tone diminished resolving up to Imaj7"}
]
```

**Progression #2 — Minor Jazz Cadence (`IIm7b5,V7,Im`):**

```json
[
  {"label": "with #Vdim leading-tone",
   "numerals": "IIm7b5,V7,#Vdim7,Im",
   "notes": "Leading-tone diminished resolving up to Im"}
]
```

**Progression #5 — The Turnaround #2 (`I,VI7,IIm7,V7`):**

```json
[
  {"label": "with #Idim sub for VI7",
   "numerals": "I,#Idim7,IIm7,V7",
   "notes": "Diminished sub for the secondary dominant — same function as VI7"},
    {"label": "with bIIIdim passing",
   "numerals": "I,VI7,IIm7,bIIIdim7,V7",
   "notes": "Five-slot variant; diminished between ii and V"}
]
```

**Progression #9 — The Extended Turnaround (`IIIm7,VI7,IIm7,V7`):**

```json
[
  {"label": "with bIIIdim sub for VI7",
   "numerals": "IIIm7,bIIIdim7,IIm7,V7",
   "notes": "Diminished passing chord"}
]
```

**Progression #38 — The Turnaround (`I,VIm,IIm,V7`):**

```json
[
  {"label": "with #Idim passing",
   "numerals": "I,#Idim7,IIm,V7",
   "notes": "Diminished passing replacing the VIm"}
]
```



All other rows: `alt_numerals = null` (no variants).

#### 1.8.4 Detection-code changes

The existing progression-detection code (whichever job/service writes
to `sbn_progression_occurrences` — to be located during this PR) must
be updated to:

1. When matching a fragment derived from a variant, write
   `variant_index` and `variant_label` to the occurrence row.
2. When matching the canonical `numerals`, write
   `variant_index = null`, `variant_label = null` (existing behavior).

If the detection code doesn't currently match against variants at all
(likely — it pre-dates `alt_numerals`), this PR extends it to consider
the flattened canonical+variant fragment list. The owner is informed
during the PR if this turns out to be more invasive than expected.

#### 1.8.5 Acceptance

- Migration runs cleanly forward and backward.
- The 6 affected progression rows have `alt_numerals` populated as
  approved by owner.
- Existing `sbn_progression_occurrences` rows are unaffected
  (`variant_index = null`, `variant_label = null`).
- Library UI shows progressions unchanged (no new rows; one
  entry per parent).
- New unit test: `LoadProgressionWithVariantsTest` confirms a
  progression Eloquent model exposes `alt_numerals` as an array of
  arrays with `label`/`numerals`/`notes` keys.
- New unit test: `OccurrenceVariantTest` confirms an occurrence row
  with `variant_index = 0` correctly resolves back to its parent's
  variant.

#### 1.8.6 PR checklist

- [ ] Migration A: `alt_numerals` column on `sbn_chord_progressions`.
- [ ] Migration B: `variant_index` + `variant_label` on `sbn_progression_occurrences`.
- [ ] Seeder populates the 6 reviewed rows.
- [ ] `ChordProgression` model exposes `alt_numerals` as cast array.
- [ ] Detection code updated to write variant identity.
- [ ] Both unit tests pass.
- [ ] Library UI smoke-tested: progression list unchanged.

#### 1.8.7 Things NOT to do

- Don't add a `parent_id` column. Variants are facets, not first-class
  rows (Option B from the design discussion was rejected).
- Don't expose variants as separate progression-library entries.
- Don't change the `numerals` column shape — variants live in
  `alt_numerals`, the canonical stays where it is.
- Don't query `alt_numerals` JSON contents at runtime in Phase 2;
  the matcher reads the generated constants file (§1.6.5).
- Don't deduplicate against the canonical `numerals` — if a variant
  happens to equal the canonical for some progression (it shouldn't,
  but defensively), keep both. The matcher tolerates it.

#### 1.6.12 Out-of-scope edge cases (catalogued, not fixed)

Things visible in the audits that are **explicitly not in Phase 2** —
deferred until they actually matter to users:

- Sparse 2-note voicings on Barquinho returning `no_identification`.
- `aug` over-reach on 2-note voicings (Barquinho has 6 cases).
- Single-note "stacks" — currently filtered at audit time (≥2 notes)
  but the underlying algorithm doesn't have a graceful single-note
  path.

---

## Part 2: Chord construction (`ProgressionBuilder`)

### 2.1 Status

**Patched-up. Wants a clean rewrite.** This is a different conclusion
from the identifier — please don't conflate them.

The audit (`php artisan sbn:audit-progressions --mode=all`) ran 43
progressions × 3 modes = 129 runs. Findings:

- `group_thrash` (voicing categories switching mid-progression): 50
  cases (39%). The dominant pathology.
- `position_thrash` (adjacent voicings >5 frets apart): 5 cases.
- `high_vl_score` (top-decile transitions): 28 cases.

The 12-Bar Blues run is illustrative: the same `C7` gets three
different voicings across 12 bars, the same `V7` two different
voicings. The forward/backward pass + cross-pool rescue is making the
algorithm *forget* what it already chose.

### 2.2 Why it wants a rewrite, not a patch

Looking at the code:

- The cross-pool rescue at threshold 4.0 is a band-aid over the 4-pool
  funnel. Either trust the lock or don't.
- The harmony filter and the score function penalize the same things in
  different units, with no shared source of truth.
- Magic constants without provenance: `bestDist = 999`, `rescueThreshold
  = 4.0`, `prefer fret 5`, `commonSameString * 1.5`, `commonAny * 0.3`,
  `min(fretDist * 0.4, 3.0)`, `setA === setB ? -1.5 : +3`. Each is an
  answer to a past failure, none documented, none normalized.
- Two entry points (`buildVoicings` vs. `selectVoicingsForSequence`)
  share nothing meaningful. One is sophisticated, one is "first of
  pool."
- Three sequential passes (forward, backward, gap-fill) plus rescue
  plus harmony filter. The actual algorithm is buried under scaffolding.

This file has the unmistakable shape of **incremental patches against
specific failure cases, never consolidated**. A fresh implementation
would be substantially smaller and clearer.

### 2.3 Sketch of the rewrite (separate spec required)

Not implementation-ready, but the design direction is:

- **Single normalized cost function.** Each term in `[0, 1]`, explicit
  weight vector. Add a *simplicity term* (penalize note count +
  extension count) which the current algorithm lacks entirely.
- **Single search algorithm: Viterbi** over the candidate lattice.
  Replaces forward-pass + backward-pass + gap-fill + cross-pool rescue.
  Provably optimal given the cost function.
- **Configuration via a small set of clear options**: `pool` (the
  existing `style` selector), `extensions`, `allowBarres` (new),
  `maxFrettedNotes` (new), `simplicityWeight` (new). No strategy
  hierarchy — just config.
- **A "simple mode" for beginner content** — flat lookup against an
  open-chord curated table. Bypasses the whole algorithm when the user
  asks for `Am, C, G, D` with no extensions and no barres.
- **Integrate with the identifier's harmonic knowledge.** When the
  builder considers extending a triad, it should consult the same
  harmonic-pattern data Phase 2 of the identifier uses, so e.g. the
  V chord in a ii–V–I gets the right alterations and the I chord
  doesn't get an out-of-key #11.

The builder reference at `docs/SBN-Builder-Reference.md` documents the
shipped algorithm; before any further rewrite of the builder is attempted,
rewrite is attempted. The progression audit
(`storage/audits/progressions-*.json`) is the regression baseline — no
rewrite ships without showing measurable improvement against it.

---

## Part 3: Audit infrastructure

### 3.1 Why audits exist

Both algorithms are large, both have many edge cases, both have
constants that encode hard-won lessons. Audits are how we **measure
behavior change** across the corpus before and after every modification,
and how we **catalogue failure modes** systematically rather than by
reading bug reports.

**Discipline:** before changing either algorithm, run the relevant
audit. After changing, run it again and diff. If the diff doesn't
match what the change was supposed to do, **stop and investigate** —
either the change is wrong or there's a hidden interaction.

### 3.2 Identifier audit

```
php artisan sbn:audit-identifier "docs/WES MONTGOMERY - The Days Of Wine and Roses.musicxml"
php artisan sbn:audit-identifier "docs/BOSSA - O Barquinho.musicxml"
php artisan sbn:audit-identifier "docs/CHORD MELODY - Nature Boy.musicxml"
```

Each writes a JSON + Markdown pair to `storage/audits/`. The JSON is
diffable (commit hashes from before-and-after fix runs into git for
permanent comparison). The Markdown is human-readable.

The audit:

1. Parses MusicXML, groups notes into chord stacks (≥2 notes per onset).
2. Runs `identifyFromFrets` (Pass 1 only) on each stack.
3. Runs `identifyFromFretsWithContext` (full pipeline) on each stack
   with neighbor chord names.
4. Flags suspicious outputs: `rare_quality`, `exotic_extension` on
   non-dom chords, `context_changed_label` (informational), `no_identification`.

The current 3-file corpus covers jazz octaves (Wine, F major), bossa (G
major), and chord-melody (Nature Boy, D minor) — enough breadth for
most cases. Add new files when you encounter a domain the existing
files don't cover (modal, classical, folk, etc.).

### 3.3 Builder audit

```
php artisan sbn:audit-progressions --mode=all
```

Iterates every progression in `sbn_chord_progressions` × 3 modes
(`default` / `simple` / `jazz`). For each run, captures output voicings
and computes diagnostic flags:

- `position_thrash` — adjacent voicings >5 frets apart
- `group_thrash` — voicing_category changes within a progression
- `high_vl_score` — top decile of adjacent VL scores
- (additional flags planned but not yet active)

Use this as the regression baseline for any builder rewrite. The
acceptance bar for a rewrite: **fewer flags, same coverage, no new
failure categories.**

### 3.4 Where audits live

- Code: `app/Console/Commands/AuditChordIdentifier.php`,
  `app/Console/Commands/AuditProgressions.php`.
- Output: `storage/audits/*.{json,md}`. Timestamped filenames. Keep at
  least the last "known-good" baseline for each algorithm in git (or
  bundled with the relevant PR).

---

## Part 4: Design principles (lessons learned)

These principles emerged during Phase 1 work and apply to all future
work on these algorithms. **Read them before changing anything.**

### 4.1 Distinguish Bucket 1 from Bucket 2

When you find an output you think is "wrong," classify it:

- **Bucket 1: Algorithmic artifact.** No human would defend this output.
  Examples: `Dmin(b13)` for a Bbmaj7/D voicing, `Gm7(11)` morphing into
  three different shapes across 4 bars of the same chord. Fix.
- **Bucket 2: Locally defensible, contextually suboptimal.** The output
  is a valid reading of the local pitch content, but a contextual
  reading would be better. Examples: `Am6` for what's really `D7(9)/A`
  in a ii–V–i. **Don't fix this with a local change.** Either build the
  contextual layer (Phase 2) or leave it alone.

This distinction protects the algorithm from regressions caused by
"helpful" tuning. The chord-tone restriction in the slash-bonus fix is
the pattern: encode a *musical* rule that puts Bucket 1 inside the fix
and Bucket 2 outside it.

### 4.2 Specificity as a first-class principle

Both algorithms encode the principle "more specific interpretations
beat more general ones" but in different places:

- Identifier: `uasort` sorts qualities longest-first (`maj7` beats
  `maj` on tied evidence). The two-tier slash-bonus extends this: a
  4-tone slash candidate decisively beats a 3-tone one.
- Builder (when rewritten): the simplicity term will be the *opposite*
  pull — penalize over-specification when the input doesn't ask for it.
  These two pulls are not in conflict; they apply to different problems
  (identification = "what is this?" wants specificity; construction =
  "what should I play?" wants restraint).

When in doubt, prefer the *more specific* quality match for
identification, the *less specific* voicing for construction.

### 4.3 Tuning vs. structure

When a magic constant produces a wrong output, the temptation is to
nudge the constant. **Resist this** until you've ruled out a structural
fix. The Phase 1 trace showed the bug was a *score collision* (1200 =
1200), broken by silent iteration order — no constant tuning could fix
that without breaking other cases. The structural fix (slash-chord as
first-class candidate) was both more correct and more bounded.

A heuristic: if the bug requires "tune constant X up by Y%," ask "what
would happen at the boundary?" If the answer is "different bugs on the
other side," the structural fix is the right one.

### 4.4 Don't query DBs at hot paths

The progression DB is great as a *source of truth for which patterns
matter*, but reading it during identification or construction would be
slow and tightly couples the algorithm to schema. The DB-seeded
hard-coded approach (§1.6.3) is the pattern: DB at design/seed time,
constants at runtime.

### 4.5 Spec → audit → measure → ship

The cadence that works for these algorithms:

1. **Spec the change** — write down the principle, the proposed fix,
   and the acceptance criteria.
2. **Run the audit before** — capture the baseline.
3. **Implement the change.**
4. **Run the audit after** — diff against baseline.
5. **Verify the diff matches the spec's acceptance criteria.** If it
   doesn't, the spec is wrong, the implementation is wrong, or both.
6. **Ship.**

The Phase 1 work followed this cadence and caught the `xx5768` (Gm7 →
Csus4/G) regression at step 5, leading to the two-tier slash-bonus
refinement. Without the audit, that regression would have shipped.

---

## Appendix A: Phase 1 history

- **2026-04-30**: Three-file audit performed on chord identification.
  Pattern 1 identified across 8 cases. Trace of chord #9 (Wine,
  `ax8aax`) confirmed exact-1200 score collision between D-min
  (bass-boosted partial) and Bb-maj7 (exact 4-note).
- **2026-05-01 morning**: First spec draft proposed three-option fix
  (A: slash-chord candidates; B: global triad exact-bonus; C:
  tiebreaker rule).
- **2026-05-01 afternoon**: User feedback distinguished real bugs
  (Pattern 1, e.g. `Dmin(b13)`) from defensible-but-contextually-wrong
  cases (Wine middle chords, e.g. `Am6` for `D7(9)/A`). Spec scoped
  down to Option A only with chord-tone restriction. Phase 2 sketched.
- **2026-05-01 evening**: Implementation deployed. Validation against
  audit corpus revealed regression on `xx5768` (`Gm7(11)` → `Csus4(9)/G`).
  Two-tier slash-bonus introduced (`slashBonus7th = 3.5`,
  `slashBonusTriad = 2.5`). Re-validated; all Pattern 1 cases fixed,
  no regressions on Bucket 2 or correctly-identified chords.
- **2026-05-01 night**: Doc restructured as full reference for both
  identifier and builder. Phase 2 expanded with DB-seeded hard-coded
  pattern approach and additional motivating examples (`Bbmaj7/D` →
  `Gm7(9)/D` from surrounding Gm context).

## Appendix B: Files this document references

- `app/Services/VoicingCrossref.php` — identification subsystem (lines
  ~1114–2140 within the file). Forward voicing-lookup half is unrelated.
- `app/Services/ProgressionBuilder.php` — construction algorithm.
- `app/Services/HarmonicContext.php` — chord-sequence-from-numerals
  builder used by both audits.
- `app/Console/Commands/AuditChordIdentifier.php` — identifier audit
  command.
- `app/Console/Commands/AuditProgressions.php` — builder audit command.
- `storage/audits/` — audit output directory.
- `database/migrations/2026_03_23_000001_create_progression_occurrences_table.php`
  — schema for the data Phase 2 will seed from.
- `app/Models/ChordProgression.php` — model for `sbn_chord_progressions`.

**New (created or modified by Phase 2 §1.6 work):**

- `app/Services/HarmonicContext/ContextualReranker.php` — orchestrator (NEW).
- `app/Services/HarmonicContext/HarmonicPatternMatcher.php` — fragment matcher (NEW).
- `app/Services/HarmonicContext/DiminishedResolver.php` — dim7 root disambiguator (NEW).
- `app/Services/HarmonicContext/PedalDetector.php` — pedal-point sub-pass (NEW).
- `app/Console/Commands/ReseedHarmonicFragments.php` — generates the constants file (NEW).
- `app/Console/Commands/AuditChordIdentifierContext.php` — context-aware audit (NEW).
- `storage/app/harmonic-fragments.generated.php` — generated constants (NEW, checked in).
- `database/migrations/YYYY_MM_DD_add_alt_numerals_to_progressions.php` — §1.8 (NEW).
- `database/migrations/YYYY_MM_DD_add_variant_to_occurrences.php` — §1.8 (NEW).

---

## Appendix C: Phase 2 audit fixtures and acceptance criteria

### C.1 Fixture corpus

Phase 2 acceptance is measured against four annotated MusicXML files
covering complementary harmonic territory:

| File | Path | Why this fixture |
|---|---|---|
| Wine | `docs/WES MONTGOMERY - The Days Of Wine and Roses.musicxml` | Phase 1 baseline (§1.5). Contains the four Bucket 2 cases (Wine #6/#7/#8 ii-V-i in F, plus the Bbmaj7/D pedal). Tests sub-passes 2a (pedal), 2b (cadence), 2d (enharmonic). |
| Misty | `docs/BARNEY KESSEL - Misty.musicxml` | Rich ii-V chains in Eb. Contains diminished passing chords on the bridge — primary fixture for sub-pass 2c (`DiminishedResolver`). |
| Easy Living | `docs/GEORGE BENSON - Easy Living.musicxml` | Modulating bridge with chromatic motion. Tests turnaround variants with dim subs (DB #5/#9 variant matching) and key-aware re-rank when the local key shifts. |
| Shadow | `docs/JOE PASS - The Shadow Of Your Smile.musicxml` | Descending chromatic dim7 motion (D2 rule). Tests the descending case of sub-pass 2c that the other three fixtures don't exercise. |

The four fixtures together cover: pedal (Wine), ascending dim passing
(Misty / Easy Living), descending dim passing (Shadow), turnaround
variants (Easy Living), and ii-V-i functional re-rank (Wine).

### C.2 Annotation file

Each fixture gets a sidecar JSON file at
`tests/fixtures/identifier-context/<basename>.expected.json`:

```json
{
  "fixture": "WES MONTGOMERY - The Days Of Wine and Roses.musicxml",
  "song_key": "F",
  "expected": [
    {
      "stack_index": 6,
      "frets": "xx799a",
      "phase1_label": "E7(11)/A",
      "phase2_label": "D7(9#11)/A",
      "reinterpret_reason": "pedal",
      "matched_progression_id": null,
      "matched_variant_index": null
    },
    {
      "stack_index": 7,
      "frets": "xx7898",
      "phase1_label": "F7(#9)/A",
      "phase2_label": "D7(b9#11)/A",
      "reinterpret_reason": "pedal"
    }
    // ... etc per chord stack the analyst expects to flip
  ]
}
```

Each annotated stack lists: index in the chord-stack sequence, fret
string for cross-reference, the Phase 1 label (must match what the
existing `sbn:audit-identifier` reports today), the Phase 2 expected
label, the expected `reinterpret_reason`, and matched progression
identity if applicable. Slots with no expected change are omitted from
the file (silent slots).

The annotation file is the **owner-curated ground truth**. It must be
written before any Phase 2 code lands. The owner produces it manually
for the four fixtures (estimated ~30 chords annotated total across the
four files — only the slots where Phase 2 should change something).

### C.3 New audit command — `sbn:audit-identifier-context`

```
php artisan sbn:audit-identifier-context "docs/WES MONTGOMERY - The Days Of Wine and Roses.musicxml"
```

Outputs to `storage/audits/context-<basename>-<timestamp>.{json,md}`.

For each chord stack:

1. Run `identifyFromFrets` (Phase 1 only).
2. Run the full `ContextualReranker` pipeline.
3. Cross-reference against the `.expected.json` annotation if present.
4. Flag each stack with one of:
   - `unchanged` — Phase 1 and Phase 2 agree (most stacks).
   - `phase2_match` — Phase 2 changed the label and matches annotation. ✅
   - `phase2_drift` — Phase 2 changed the label but no annotation
     present (informational; might be correct, might be regression).
   - `phase2_miss` — annotation expected a change, Phase 2 didn't
     produce it. ❌
   - `phase2_wrong` — annotation expected change to X, Phase 2 changed
     to Y. ❌

Both JSON and Markdown outputs include a summary header with counts
per flag. The Markdown output is human-scannable; the JSON is diffable
for git-tracked baselines.

### C.4 Acceptance criteria (gating Phase 2 merge)

**Hard gates** — all must hold before merge:

1. **Pre-existing identifier audit unchanged.** Running the original
   `sbn:audit-identifier` on the four fixtures produces the same JSON
   output as the pre-Phase-2 baseline. Phase 2 does not regress
   Phase 1 — Phase 1 still produces the same per-chord local labels
   when called via `identifyFromFrets`.
2. **No `phase2_wrong` flags** across the four fixtures.
3. **No `phase2_miss` flags** on annotated Wine cases (#6, #7, #8 plus
   the Bbmaj7/D pedal). These are the four cases that motivated
   Phase 2; they must flip.
4. **`phase2_drift` count ≤ 5 per fixture.** Phase 2 will sometimes
   reinterpret slots that the analyst didn't flag; that's expected.
   Soft cap of 5 per fixture forces the implementer to investigate
   anything more aggressive than that and either annotate it (if
   correct) or tune it down (if not).

**Soft gates** — desired but not blocking:

5. Misty: at least one ascending-dim case correctly resolved by D1.
6. Shadow: at least one descending-dim case correctly resolved by D2.
7. Easy Living: at least one turnaround matched as variant of DB
   progression #5 or #9, with `matched_variant_index != null`.

### C.5 Baseline capture (do this before any Phase 2 code lands)

Before starting work on §1.6 implementation:

1. Run `sbn:audit-identifier` on all four fixtures.
2. Commit the resulting JSON+Markdown files to the repo at
   `tests/fixtures/identifier-context/baseline/`.
3. The pre-Phase-2 baseline is now frozen. Hard gate #1 diffs against
   these committed files.

### C.6 Why these four fixtures and not more

Adding more fixtures has diminishing returns until Phase 2 ships
v1. Once it ships, real-world counter-examples will inevitably emerge;
those become the audit additions. The four fixtures are sufficient
breadth to validate the spec; they are not a complete corpus and were
not chosen to be one.

Owner adds fixtures when:

- A chord pattern shows up in production that none of the four cover.
- A user reports a Phase 2 misread that the four fixtures don't
  exercise.
- A new musical genre enters the library (modal jazz, classical,
  contemporary R&B, etc.) and Phase 2's behavior on it is unverified.

---

## Appendix D: Known bugs and follow-up improvements

This appendix is the **live punch list** for chord identification work
after Phase 2 shipped. Items are tracked here rather than in tickets so
the spec is the single source of truth for "what's wrong, what's
planned, and what's deferred." Update this section as items ship,
new bugs emerge, and priorities shift.

**Sections:**
- D.1 — Implementation deltas from the original Phase 2 spec (informational)
- D.2 — Known bugs (cases where shipped behavior is wrong)
- D.3 — Planned improvements (cases where shipped behavior is correct
        but a known better answer exists)
- D.4 — Deferred capabilities (planned but no concrete fix shape yet)
- D.5 — Workflow gotchas

### D.1 Implementation deltas from the original Phase 2 spec

These are spec-vs-shipped differences worth recording so future readers
don't get confused comparing §1.6 to the running code.

**D.1.1 — Sub-pass 2c′ added (DiminishedAsDominantResolver)**

The original §1.6 spec had four sub-passes (2a, 2b, 2c, 2d). During
implementation, `DiminishedResolver` (2c) was found to only address
*spelling* of dim7 chords (which of the four symmetric inversions to
prefer), not *function* (whether a dim7 is actually a rootless dominant
7♭9). The example that surfaced this: `Cm7 → Adim7 → Bb` where Adim7
is functionally `F7(b9)` rootless — A, C, Eb, F# is the upper structure
of F7(b9) with F missing.

A new sub-pass `DiminishedAsDominantResolver` was added that runs
*before* `DiminishedResolver`. For each dim7 slot:

- For each of the four symmetric roots `r`, compute the would-be
  dominant root `dom_root = (r - 4 + 12) % 12` (major third below `r`).
- If `dom_root` resolves down a perfect 5th to the next slot's root
  (i.e. `next_root === (dom_root - 7 + 12) % 12`), the dim7 is relabeled
  as `{dom_root_name}7(b9)` with `reinterpret_reason = 'dim_as_rootless_v7b9'`.
- Disambiguator when multiple symmetric roots yield valid dominants:
  prefer the one whose `dom_root` is **not** in the slot's own PC set
  (a real rootless voicing has the root absent).
- If no `dom_root` resolves down a 5th, fall through to
  `DiminishedResolver` unchanged.

`DiminishedResolver` skips slots already flagged by
`DiminishedAsDominantResolver` (mirror of the `reinterpreted` skip in
sub-pass 2d).

**D.1.2 — Final sub-pass order**

Spec §1.6.2 originally drafted: `2a → 2c → 2b → 2d`.
Shipped: `2a → 2c′ → 2c → 2b → 2d`.

The §1.6 status banner reflects shipped order. Future readers should
trust the banner over the historical narrative in §1.6.2's worked
examples — the worked examples are still valid as illustrations of
each sub-pass's role.

**D.1.3 — Wine acceptance gates relaxed**

Hard gate C.4 #3 originally required Wine slots #6/#7/#8 + the
`Bbmaj7/D` pedal to flip. In practice:

- The three pedal slots (#6/#7/#8) require `IIIm7b5 → VI7 → IIm` to be
  recognized as a tonicizing ii-V-i in G minor. The seeded F-major
  fragment table doesn't catch this directly. See D.4.1.
- The `Bbmaj7/D` slot is a single isolated D-bass slot in Wine
  (not surrounded by D-bass neighbors), so PedalDetector's N≥3
  requirement excludes it. Catching this case would require either
  pre-pedal pattern matching or a different mechanism. See D.4.2.

Acceptance for the Phase 2 ship was based on real-use judgment rather
than the original C.4 hard gates: Phase 2 is materially better than
Phase 1 alone on the four fixtures, no regressions on Phase-1-correct
cases, and the cases that don't flip are catalogued here in D.4 with
clear fix shapes.

### D.2 Known bugs

Cases where the algorithm's output is musically wrong and a fix is
known. Order: highest-impact first.

**D.2.1 — Slash chord over-eagerness on `7sus4` shapes**

| Voicing | Shipped output | Better output | Notes |
|---|---|---|---|
| `x3333x` | `Bbadd9/C` | `C7sus4` (or `Bb/C`) | Phase 1 reaches for slash |
| (similar `7sus4`-shaped voicings) | various slash labels | `7sus4` | Same mechanism |

**Root cause:** Phase 1's slash-bonus (§1.5) makes slash chords
first-class candidates, which fixed Pattern 1 but pulled in the
opposite over-correction: voicings whose simpler reading is `7sus4`
now get bass-rooted slash labels because the slash candidate scores
slightly higher.

**Fix shape:** Phase 1 tuning. Add a `7sus4` recognition gate that
fires before the slash-bonus is applied: if the slot's PC set matches
a `7sus4` template exactly (root-4-5-b7), prefer `7sus4` over any
slash interpretation that isn't decisively higher-scoring. Likely
needs a new `IDENTIFY_SUS_DOM_TEMPLATES` entry analogous to
`IDENTIFY_ROOTLESS_TEMPLATES`.

**Risk:** Touching Phase 1 risks regressing the §1.5 slash-bonus
fixes. Audit before/after must show all eight Pattern 1 cases still
flip correctly *and* the new sus cases now read as `7sus4`.

**Priority:** Medium. Affects every chart with sus voicings — common
in jazz comping, Brazilian music, modal vamps.

**D.2.2 — `noteNameToPc` silently maps unknown names to 0 (C)**

`PedalDetector::noteNameToPc()` and `DiminishedResolver::noteNameToPc()`
both return `0` for any unrecognized note name (e.g. double-flats,
double-sharps, malformed strings).

**Risk:** Latent footgun. If Phase 1 ever emits a non-standard note
name, both services silently treat it as C, which can produce
nonsensical pedal runs or dim7 resolutions. Hasn't been observed in
production but is the kind of bug that surfaces with a strange
fixture and is hard to diagnose.

**Fix shape:** Return `null` and have callers gracefully skip the slot.

**Priority:** Low (no observed failures), but trivial to fix when next
in the file.

### D.3 Planned improvements

Cases where shipped behavior is defensible but a known refinement
would catch more cases or produce better labels.

**D.3.1 — Add `IIIm7b5 → VI7 → IIm` as Tier 1 fragment**

The Wine A-pedal `Am7b5 → D7 → Gm` (in F major: `IIIm7b5 → VI7 → IIm`)
is a very common harmonic pattern — a secondary ii-V into IIm — but
not currently in the seeded fragment table. Adding it as a new
progression to `sbn_chord_progressions` and reseeding fragments would
let `HarmonicPatternMatcher` recognize this passage in Wine and any
other song that uses it.

**Steps:**
1. Add row to `sbn_chord_progressions`: name "Secondary ii-V to ii",
   numerals `IIIm7b5,VI7,IIm`. Variants: `IIIm7b5,VI7,IIm7` and
   `IIIm7b5,VI7,IIm6` for jazz spellings.
2. Click "Rebuild Fragment Index" in the progression admin page (or
   run `php artisan sbn:reseed-fragments`).
3. Commit `storage/app/harmonic-fragments.generated.php`.
4. Re-run `sbn:audit-identifier-context` on Wine and verify slots
   #6/#7/#8 now flip.

**Priority:** High. 30-minute change. Catches a common shape.

**D.3.2 — Bass-as-third heuristic for DiminishedAsDominantResolver**

The 2c′ rule currently picks the dominant whose `dom_root` is *not*
in the slot's PC set. A stricter (and more musically grounded)
disambiguator is "the bass note of the original dim7 should be the
third of the proposed dominant" — i.e. for Adim7→F7(b9), A is the 3rd
of F, which is the canonical rootless V7(b9) voicing.

**When to revisit:** if the eyeball-validation pass on the 9 Misty
`dim_as_rootless_v7b9` flips shows borderline-cases. Currently
deferred until production usage surfaces a wrong flip the existing
disambiguator allows.

**Priority:** Low. Existing rule may already be precise enough.

**D.3.3 — Dim7 disambiguation in isolated slots (no useful neighbors)**

`DiminishedResolver` rule D5 leaves Phase 1's root choice when no
neighbor context is usable, with `confidence = 'low'`. This is correct
default behavior but means single-chord queries on dim7s remain
inversion-symmetric (1-in-4 chance of "right" root).

**Fix shape:** when no neighbors, prefer the symmetric root that
matches the bass note (analogous to the bass-boost in Phase 1). A
voicing with A in the bass calling itself Adim7 is more likely
correct than the same voicing self-labeled Cdim7/A.

**Priority:** Low. Affects ad-hoc admin "identify single voicing"
queries, not full-chart imports.

### D.4 Deferred capabilities

Real harmonic phenomena Phase 2 doesn't catch, where the fix shape
exists in principle but is significant work and gated on production
demand.

**D.4.1 — Tonicization detection**

When a passage temporarily treats a non-tonic chord as a local tonic
(Wine's `Am7b5 → D7 → Gm` is a ii-V-i in G minor while the song is
in F major), `HarmonicPatternMatcher` translates against the song's
home key and misses the pattern entirely.

**Fix shape:** a tonicization detector that scans for "non-tonic chord
followed by its own dominant" sequences, lifts the local key for that
window, runs the standard fragment match against the lifted key, and
re-merges results. Out of scope for Phase 2; Phase 3 territory.

**When to revisit:** when production usage shows multiple charts where
tonicized passages are visibly mis-analyzed. Until then, D.3.1's
hand-seeded "secondary ii-V to ii" fragment covers the most common
case (and similar fragments can be seeded for "secondary ii-V to V,"
etc.) without needing tonicization detection per se.

**D.4.2 — Cross-pedal pattern matching for isolated bass-shared slots**

The Wine `ax8aax` (`Bbmaj7/D`) case: a single D-bass slot whose
neighboring chords are all D-as-the-5th of Gm. The analyst reads it
as `Gm7(9)/D` (continuation of Gm context with D pedal). PedalDetector
requires N≥3 same-bass slots — by definition can't catch this.

**Fix shape:** add a "neighbor harmonic context" pass that, for any
isolated slot whose bass appears as a chord tone of the surrounding
key's tonic-or-functional chord, considers the alternate reading.
This is more speculative than D.4.1 — the cases are genuinely
ambiguous and an analyst's judgment depends on phrase-level musical
intent that no local-rules system can capture cleanly.

**When to revisit:** if user feedback on production imports shows the
shipped local readings (Bbmaj7/D-style outputs) are reliably mis-read.
Don't pre-build.

**D.4.3 — Audio-transcription path (§1.7.2)**

Phase 2's `expectedChords` parameter exists and is reserved for the
audio-transcription path. The populator (a service that consumes a
leadsheet from the Jazz Standards DB and produces per-beat
`expectedChords[]`) is not built. See §1.7.3 for why the audio
populator is gated on upstream audio-pipeline improvements (Demucs
stem separation, downbeat detection) before it would be useful.

### D.5 Workflow gotchas

Operational details that bit us during implementation and are easy to
forget. Record them so the next person doesn't re-discover.

**D.5.1 — Fragment file is a generated artifact**

`storage/app/harmonic-fragments.generated.php` is regenerated by
`php artisan sbn:reseed-fragments` from the DB. The file **must be
committed to git** — it ships with deploys. The Laravel default
`.gitignore` may exclude `storage/app/`; verify the generated file is
tracked (`git ls-files storage/app/harmonic-fragments.generated.php`)
and add an exception in `.gitignore` if needed.

**D.5.2 — DB edits do not auto-update fragments**

Adding a row to `sbn_chord_progressions` or editing `alt_numerals`
on an existing row does **not** automatically update the fragment
table the matcher uses at runtime. The reseed step is manual on
purpose (mid-edit typos shouldn't auto-deploy). Click "Rebuild
Fragment Index" in the progression admin page after every meaningful
DB change.

**D.5.3 — Phase 1 must populate `bass_note` on every slot**

A Phase 1 bug discovered during Phase 2 implementation: dim7 chords
were sometimes returned with `bass_note: null` even when input had a
clear bass. The fix is in
[VoicingCrossref.php](../app/Services/VoicingCrossref.php) at the
slot output assembly. PedalDetector and DiminishedResolver both
depend on `bass_note` being authoritative. If a future Phase 1 change
introduces a code path that returns `bass_note: null` for a slot with
non-empty PCs, both services will silently degrade. Add a regression
test if you touch that code path.

**D.5.4 — The audit baseline is in the repo**

`tests/fixtures/identifier-context/baseline/` holds the pre-Phase-2
audit output. Hard gate C.4 #1 (Phase 1 unchanged) diffs against
these files. If you ever legitimately change Phase 1 output (new
templates, new tunings), regenerate the baseline as part of the same
PR — don't let the baseline drift silently.

**D.5.5 — MusicXML import is browser-parsed, server-identified**

The MusicXML import flow is split across the boundary in a way that's
easy to miss when grepping the codebase:

- The XML *parser* (`MusicXMLParser` class) lives in JavaScript,
  inside [resources/views/admin/leadsheets/edit.blade.php](../resources/views/admin/leadsheets/edit.blade.php)
  around line 345. It runs in the browser when the user drops a file
  on the upload zone.
- The chord *identifier* runs server-side via
  `POST /api/admin/leadsheets/identify-voicings` →
  `LeadsheetController::identifyVoicings` →
  `VoicingCrossref::identifyVoicingsBatch` → `ContextualReranker`.
- The bridge: after parsing, `identifyTabVoicings()` (around line 1592
  of the same blade file) collects every `Tab\d+`-named voicing the
  XML produced and posts the batch to the identify endpoint. Server
  returns named chords; browser renames in place.

A consequence: there is no PHP-side "import controller method" to
grep for. If you're auditing where Phase 2 fires on real user input,
the answer is "the JS dropzone in the leadsheet editor calls the
identify endpoint after parsing."

**D.5.6 — Re-identifying legacy leadsheets is a manual step**

Leadsheets imported before 2026-05-06 had their chords identified by
Phase 1 alone. The voicing→name mapping was persisted in `json_data`
at import time; nothing re-runs identification when Phase 2 ships. To
upgrade legacy leadsheets to Phase-2 identifications, an admin must
either:

- Re-import the original `.musicxml` file (overwrites prior names), or
- Use the editor's interactive "identify voicings" action on the
  leadsheet (re-runs through Phase 2).

There is no bulk re-identification command today. If demand surfaces,
the right shape is a small artisan command that walks every leadsheet,
extracts stored voicings, calls `identifyVoicingsBatch`, and writes
back changed names with a dry-run mode. Don't pre-build.

# Triplet Roundtrip — Analysis of Sonnet's Session + Remaining Bugs

## What Sonnet got right

The two fixes Sonnet landed are **correct** and address the core roundtrip corruption:

### Fix A — `musicXmlWriter.js` `ticksToDivDuration(ticks, event)`
For tuplet events, writes the *real* duration (actual time consumed) instead of
the nominal written duration. `event.ticks` for an 8th-triplet created via
`toggleTriplet` is already `160`, so the function passes it through; for XML
imports where `ev.ticks` was nominal (pre-Fix-B), it scales by
`normal/actual`. Correct.

### Fix B — `edit.blade.php` melody assembly (line 477)
On import, when a parsed note has `tupletActual != tupletNormal`, scale the
nominal `ticks` (from `durationToTicks('e') = 240`) down to the real tuplet
duration (`160`). This makes the in-memory model consistent: `ev.ticks` and
`ev.tick` are both in **real tick units**, so `repositionMeasure`,
`measureFill`, and the writer all agree.

**Verified roundtrip for a single 8th-triplet:**
- Import: XML `<duration>1</duration>` at divisions=3 → measureTick=0.333 → tick=160, ticks=160 ✓
- Save: ticksToDivDuration(160, ev) → 160 at divisions=480 (= 1/3 quarter) ✓
- Reload: measureTick=160/480=0.333 → tick=160, durationToTicks('e')×2/3=160 ✓

## Where Sonnet's analysis went wrong

Sonnet spent a lot of time chasing the "red bar but no overfill message"
symptom through `measureFill` / `isOverfilled` / `repositionMeasure` and
concluded Fix B would resolve it by making ticks consistent. That part is
plausible and probably true.

But Sonnet's trace of the **bracket-instead-of-beam** symptom gets tangled
and never lands on a concrete cause. The session runs out of context mid-trace
("[Message truncated]"). Let me finish the trace.

## The remaining bug: bracket-instead-of-beam after save+reload

**Symptom:** After edit + save + reload, bar 1 renders the 8th-triplets on
beats 2-3 as a *bracket-with-stems* (visually quarter-note-triplet style)
instead of a *beam-bar*, and the two regular 8th notes on beat 1 are not
beamed at all. Editing any note fixes it (because `recomputeBeams` runs and
rebuilds correctly).

### Root cause: writer never emits `<beam>` tags for edited events

After an edit, `repositionMeasure` → `recomputeBeams` sets `beamStart`,
`beamContinue`, `beamEnd`, `beamWith` — but **never sets `event.beam1`**.

The writer at [musicXmlWriter.js:392](resources/js/tab-editor/utils/musicXmlWriter.js#L392):

```js
if (isFirst && event.beam1) {
    lines.push(`  <beam number="1">${esc(event.beam1)}</beam>`);
}
```

`event.beam1` is `null` for every event that went through `recomputeBeams`, so
**the saved XML contains no `<beam>` elements at all**.

### Downstream effect on reload

[useTabModel.js:354](resources/js/tab-editor/composables/useTabModel.js#L354):

```js
const hasXmlBeams = beamable.some(e => e.beam1);
if (hasXmlBeams) beamsFromXml(beamable);
else             beamsHeuristic(beamable);
```

No `beam1` tags → `beamsHeuristic` runs. Heuristic groups by
`measureIdx-voice-beat` where `beat = floor(tickInMeasure / ticksPerBeat)`,
and `ticksPerBeat = 480` (quarter-note beats).

**In the post-edit Tarrega bar 1 (3/4):**
- 2 normal 8ths at ticks 0, 240 → beat 0 → group of 2 → beamed ✓
- 4 triplet 8ths at ticks 480, 640, 800, 960 →
  beats **1, 1, 1, 2** → split into group of 3 (beat 1) + lone (beat 2) ✗

The lone 4th triplet (tick 960, beat 2) has `beamWith=null` after heuristic.

### `linkUnbeamedTuplets` then makes it worse

[useTabModel.js:390-431](resources/js/tab-editor/composables/useTabModel.js#L390-L431) runs on all events and **unconditionally sets `noBeamBar=true` + `tupletBracket=true`** on any tuplet group it collects:

```js
m.noBeamBar     = true;
m.tupletBracket = true;  // unbeamed groups always need bracket
```

Walk through what it sees on bar 1:
- Events sorted by tick per measure+voice.
- The 3 triplets at ticks 480/640/800 **have `beamWith`** (from heuristic) → skipped by the `if (e.beamWith) return` guard at line 392. **But** — only if the guard catches them. Let's check: they're all in the `groups[key]` map **only if** `beamWith` was already set when the `forEach` ran. It was. So they're excluded.
- The lone triplet at tick 960 has `beamWith=null` → added to the group.
- But that group only has 1 event — `closeGroup()` needs `length >= 2`, so it does nothing.

So the lone triplet stays without `beamWith`. It renders as an isolated stem — which is probably what you're seeing for "not beamed" on the split triplet.

But wait — the user reports the **whole triplet block on beats 2-3 is rendered as a bracket**. That can't be the heuristic's group-of-3 (which sets `noBeamBar=false` implicitly by not touching it), unless `noBeamBar` is being flipped somewhere else.

### The actual culprit: `tupletBracket=true` survives the roundtrip

Trace `event.tupletBracket` on the Tarrega import:

1. Fresh import: start event has `<tuplet type="start" bracket="no"/>` → `tupletBracket = ('no' !== 'no') = false`. ✓ Stop event has `<tuplet type="stop"/>` → `getAttribute('bracket')` returns `null` → `tupletBracket = (null !== 'no') = true`. ✗ **Stop event gets `tupletBracket=true` on fresh import already.**
2. On fresh import, this is harmless because `noBeamBar=false` → `svgHelpers.js:223` `useBracket = noBeamBar ? bg[0].tupletBracket : false` = false. Beam drawn. ✓
3. On edit, [useReflow.js:180-183](resources/js/tab-editor/composables/useReflow.js#L180-L183): `recomputeBeams` sets `m.beamWith` and `m.noBeamBar = !needsBeamBar` — needsBeamBar is true (minTicks=160 < 320) → `noBeamBar=false`. Good. **But `tupletBracket` is never touched**, so the stop event still has `tupletBracket=true` leftover.
4. Save: writer line 407-410:
   ```js
   if (isFirst && isTuplet(event) && event.tupletType) {
       const bracketAttr = event.tupletBracket ? ' bracket="yes"' : '';
       notationParts.push(`<tuplet type="${esc(event.tupletType)}"${bracketAttr}/>`);
   }
   ```
   The stop event has `tupletType='stop'` and `tupletBracket=true` → writes `<tuplet type="stop" bracket="yes"/>`. The start event has `tupletBracket=false` → writes `<tuplet type="start"/>`.
5. Reload: parser re-reads bracket attr. Start: no bracket attr → `tupletBracket=(null !== 'no')=true`. Stop: `bracket="yes"` → `tupletBracket=('yes' !== 'no')=true`. **Both now true.**
6. `linkUnbeamedTuplets` doesn't run on them (they have `beamWith` from heuristic). `noBeamBar` stays false. So `useBracket` = false. **Beam should still draw.**

Hmm — so `tupletBracket` alone isn't the cause. Let me re-check what switches `noBeamBar` on.

### The real smoking gun: `linkUnbeamedTuplets` runs after `beamsHeuristic` on events that got split across beats

Re-examine the post-save Tarrega bar 1 triplets at ticks 480/640/800/960:

- Heuristic groups: `[480, 640, 800]` (beat 1) + `[960]` (beat 2 — lone).
- The 3-event group gets `beamWith = [ev480, ev640, ev800]`, `beamStart/Continue/End` set, `noBeamBar` untouched (stays false from `buildModel` default).
- The lone ev960 has `beamWith=null`.

Now `linkUnbeamedTuplets`:
- Guard filters out events with `beamWith`. So only `ev960` enters `groups['0-1']`.
- `ev960` has `tupletType='stop'` (it was the stop note in the original XML and the writer preserved that). So in the forEach at [useTabModel.js:420-428](resources/js/tab-editor/composables/useTabModel.js#L420-L428):
  ```js
  if (e.tupletType === 'start') { ... tupGroup = [e]; }
  else if (tupGroup.length)     { tupGroup.push(e); if (e.tupletType === 'stop') closeGroup(); }
  ```
  `tupGroup` is empty, `e.tupletType === 'stop'` doesn't match the 'start' branch, and the `else if` requires `tupGroup.length` > 0. So neither branch fires. `closeGroup()` at end also does nothing (`tupGroup.length === 0`).

So `ev960` stays without `beamWith`. `noBeamBar` stays false. `tupletBracket` is… true (from the re-parsed bracket="yes" on the stop event).

In `svgHelpers.js`, how does it render a tuplet event with `beamWith=null`? The beam-rendering path iterates over `beamWith` groups. A lone event with no `beamWith` draws no beam bar — it draws an isolated stem with a flag.

### So what's the user actually seeing?

Given the code trace, after save+reload the user should see:
- **Beats 2-3 triplet block:** First 3 triplets (ticks 480/640/800) **beamed** as a beam-bar group of 3 (via heuristic). Last triplet (tick 960) as a lone stem with flag. **No bracket.**
- **Beat 1 normal 8ths:** Grouped by heuristic (both in beat 0), beamed as a pair.

That contradicts the user's report of "bracket instead of beam" and "2 normal 8ths not beamed."

### Missing piece — I need to see the actual saved XML

The analysis above assumes the writer's output matches my understanding. Two scenarios could flip the result:

1. **Writer emits wrong `tupletType` on the "split-across-beats" triplet.** If `recomputeBeams` or `repositionMeasure` corrupts `tupletType` during the edit (e.g., clears it), the reloaded events won't have start/stop tags at all, and `linkUnbeamedTuplets` will see them with only `tupletActual` set. Then **the entire flow depends on whether the start-event identification survives.**
2. **`recomputeBeams` picks up the split triplet as a "tuplet group" via tick-adjacency** and calls `m.noBeamBar = !needsBeamBar` — but it only runs on edit, not on initial load. So this only matters if initial load calls it somehow. It doesn't.

### The verdict

There's a second bug I can't fully pin down from code reading alone — I need to see the actual saved XML. My strong hypothesis is one of:

- **Hypothesis 1:** The writer strips `tupletType='stop'` when the split triplet's tick is no longer adjacent to its group — but looking at the writer, it doesn't touch `tupletType` at all, so this is unlikely.
- **Hypothesis 2 (most likely):** After edit, `recomputeBeams`'s tick-adjacency walker at [useReflow.js:146-154](resources/js/tab-editor/composables/useReflow.js#L146-L154) groups the 4 triplets correctly (they're adjacent at 160-tick spacing post-Fix-B) and sets `beamWith` on all 4. But `tupletType` on the middle notes is `null` and on the last is 'stop' — so the writer emits `<tuplet type="stop" bracket="yes"/>` only on the last. On reload, only the start event (tick 480) has `tupletType='start'` and only the stop event (tick 960) has `tupletType='stop'`. The two middles (640, 800) have `tupletType=null`.
- **Hypothesis 3:** On reload with the heuristic beaming the triplets as two groups (3 + 1), the lone 4th with `tupletType='stop'` AND `tupletBracket=true` somehow triggers bracket rendering through a code path I haven't fully traced in `svgHelpers`.

## Recommended fix (regardless of the exact bracket cause)

**The correct, minimal, durable fix is to make the writer emit `<beam>` tags from the post-edit beam state.** After `recomputeBeams`, we have `beamStart/beamContinue/beamEnd` set correctly on every event. Translate those back to `beam1` at serialization time:

```js
// musicXmlWriter.js — in serializeNote, replace the beam1 block:
if (isFirst) {
    let beamLabel = null;
    if (event.beamStart)    beamLabel = 'begin';
    else if (event.beamEnd) beamLabel = 'end';
    else if (event.beamContinue) beamLabel = 'continue';
    else if (event.beam1)   beamLabel = event.beam1;  // preserve imported value
    if (beamLabel) lines.push(`  <beam number="1">${esc(beamLabel)}</beam>`);
}
```

This accomplishes three things:
1. The saved XML preserves beam intent, so reload takes the `beamsFromXml` path (reliable, deterministic) instead of the `beamsHeuristic` path (splits by beat and mis-beams tuplets that cross beats).
2. The "2 normal 8ths on beat 1 not beamed" symptom goes away — they're beamed post-edit, so `beamStart`/`beamEnd` are set, and the writer emits `<beam>begin</beam>` / `<beam>end</beam>`.
3. The "bracket-instead-of-beam" symptom goes away for the same reason — the 4 triplets have `beamStart/Continue/Continue/End` set by `recomputeBeams` (they're tick-adjacent), so on reload `beamsFromXml` beams all 4 as one group. `noBeamBar` stays false. Beam drawn.

## Secondary fix — normalize `tupletBracket` on save

Since the imported Tarrega XML sets `bracket="no"` on start but omits it on stop (and our parser interprets absence as `true`), the roundtrip unintentionally promotes `tupletBracket` to `true`. Fix the parser or normalize on save:

```js
// edit.blade.php parser — explicit default:
const tupletBracket = tupletEl && tupletEl.getAttribute('bracket') === 'yes';
```

This mirrors MusicXML semantics more faithfully (bracket defaults to editorial discretion; `yes` is explicit).

## Summary

| Bug | Root cause | Fix |
|---|---|---|
| 2/4 measure triplets on reload | `ticksToDivDuration` wrote nominal ticks, scrambling measureTick on reload | **Done (Sonnet Fix A)** |
| Ticks vs. tick spacing inconsistency | `durationToTicks` returned nominal (240) instead of real (160) for tuplets | **Done (Sonnet Fix B)** |
| Bracket instead of beam after save+reload | Writer doesn't emit `<beam>` tags post-edit → heuristic splits tuplets at beat boundaries on reload | **Emit `<beam>` from `beamStart/Continue/End` in writer** |
| Beat-1 8ths unbeamed after save+reload | Same — no `<beam>` tags saved, heuristic handles them OK only if same-beat, but the visual artifact suggests something else is off | **Same fix** |
| Spurious bracket on stop events | Parser reads missing `bracket` attr as `true` | **Treat only `bracket="yes"` as true in parser** |

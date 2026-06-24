# SBN Grace Notes — Phase 2: Editing (for Sonnet)

Status: **✅ SHIPPED 2026-06-24** (editing + grace-note layout overhaul). Written 2026-06-24.
Prereq: Phase 1 shipped (round-trip parse→model→render→export→audio). See `SBN-Grace-Notes-Plan.md`.
Phase 1 stores grace notes as `event.graceNotes[]` on the principal `TabEvent`; Phase 2 adds/edits/
deletes entries in that array and reworks how grace notes affect horizontal layout (see §8 As-built).

## Decisions locked (from product)
- **Entry: keyboard modifier + fret digit.** Cursor on an event → a modifier engages grace mode →
  the next fret digit(s) attach a grace note to the cursor event instead of the principal note.
- **Scope:** (1) add a single grace note, (2) delete a grace note, (3) grace **groups** (≥2 before
  one principal), (4) edit a grace note's **fret/string in place**.
- **OUT of scope:** slash/slur toggle UI (slash defaults to `true` = acciaccatura, slur `true` on
  entry; both already round-trip and render from Phase 1). Stacked/chord grace notes. Grace on a rest.

All edits live in **`resources/js/tab-editor/composables/useNoteInput.js`**, mirroring the existing
`commitFret` / `deleteNoteAtCursor` / `shiftNoteToString` pattern, plus a small cursor-state
addition and one undo-snapshot fix. No new files.

---

## 0. The undo bug to fix FIRST (blocking — do before any editing code)

`useUndo.js` → `snapshotMeasure` (~line 47) clones events with `{ ...ev, notes: ev.notes.map(...) }`.
The spread **shallow-copies `graceNotes`** — the snapshot holds the *same array reference* as the
live event. An in-place grace edit (e.g. change a grace fret) would mutate the snapshot too, so
undo wouldn't restore the old fret.

Fix: in `snapshotMeasure`, deep-clone grace notes alongside `notes`:
```js
events: measure.events.map(ev => ({
    ...ev,
    notes: ev.notes.map(n => { /* existing */ }),
    graceNotes: ev.graceNotes ? ev.graceNotes.map(g => ({ ...g })) : undefined,
    beamWith: null,
})),
```
Also check `restoreSnapshot` (just below) restores `graceNotes` — since it likely does
`measure.events = snap.events.map(...)` or similar, confirm grace notes come back. If it spreads
`...ev` per event on restore, deep-clone there too so a *second* undo can't alias the first.

Verify: add a grace note, change its fret, undo → fret reverts; redo → fret returns.

---

## 1. Grace mode — cursor state + key handling (`useNoteInput.js`)

### 1.1 Mode flag
Add a ref:
```js
const graceMode = ref(false);   // when true, fret digits attach grace notes to the cursor event
```
Export it (TabCursor can show a visual hint — see §4). Grace mode is **sticky until** it commits a
grace note or is cancelled, so a user can type a two-digit grace fret (e.g. `g` then `1` `2`).

### 1.2 Engage key
In `handleKeydown`, before the digit branch (~line 276), add:
```js
// 'g' toggles grace-entry mode for the next fret digit(s)
if (key === 'g' && !e.ctrlKey && !e.metaKey && !e.altKey) {
    e.preventDefault();
    const ev = getCursorEvent();
    if (ev && !ev.isRest) graceMode.value = !graceMode.value;  // only meaningful on a real event
    clearPending();                                            // don't mix a pending normal digit
    return true;
}
```
> Chosen key: **`g`** — **CONFIRMED FREE (audited 2026-06-24).** Full bare-key map of the editor:
> `Space` (play), `m`/`M` (video sync), `?` (overlay), `t`/`T` (tie), `a`/`A` (insert event),
> `+ = - .` (duration), `0`–`9` (fret), arrows/`Tab`/`Home`/`End`/`Esc` (nav). Everything else is
> Ctrl/Cmd-modified. `g` appears in none of `useCursor.js`, `useReflow.js` `handleDurationKey`, or
> TabEditor's `onKeydown`. No fallback needed. Grace mode never applies to a rest (grace notes
> attach to pitched events only).

### 1.3 Route digits while in grace mode
The digit branch currently calls `processDigit` → `commitFret`. While `graceMode` is on, route the
*committed* fret to a new `commitGraceFret` instead. Reuse the existing two-digit machinery so
`g` `1` `2` → fret 12 works — i.e. don't fork `processDigit`; fork only the commit target:

```js
if (/^[0-9]$/.test(key) && !e.ctrlKey && !e.metaKey) {
    e.preventDefault();
    const fret = processDigit(key);
    if (fret !== null) {
        if (graceMode.value) commitGraceFret(fret);
        else commitFret(fret);
    }
    return true;
}
```
**Catch:** `processDigit`'s timeout path (`startPendingDigit`) calls `commitFret` directly on
timeout (line 66) — that would commit a *normal* note even in grace mode. Fix by making the commit
target a small indirection both paths use:
```js
function commitPendingFret(fret) {
    if (graceMode.value) commitGraceFret(fret);
    else commitFret(fret);
}
```
and call `commitPendingFret(fret)` from the timeout in `startPendingDigit` (line 66) and from
`processDigit`'s out-of-range path (line 186) instead of `commitFret`. This keeps two-digit entry
correct in both modes.

### 1.4 Escape / Backspace
- `Escape` while `graceMode` → turn it off (add to the existing Escape branch ~298):
  `if (key === 'Escape' && graceMode.value) { graceMode.value = false; e.preventDefault(); return true; }`
- Backspace in grace mode with no pending digit → delete the **last** grace note on the cursor
  event (see §2.2), rather than deleting the principal note.

---

## 2. The grace mutators (`useNoteInput.js`)

All wrapped in `wrapCommand` against `[ev.measureIdx]` so undo snapshots the right measure.
**None of these call `repositionMeasure`** — grace notes consume no tick-space, so measure ticks/
xPos/actualTicks are unchanged. (This is the key difference from `commitFret`, which can grow a
chord but still doesn't reposition; deletion does reposition because it can remove an event. Grace
edits never add/remove events, so no reposition, no overfill recompute.)

### 2.1 Add / set a grace note
```js
function commitGraceFret(fret) {
    const ev = getCursorEvent();
    if (!ev || ev.isRest) return;
    if (isNaN(fret) || fret < MIN_FRET || fret > MAX_FRET) return;
    const stringIdx = cursor.value.stringIndex;

    const doCommit = () => {
        if (!ev.graceNotes) ev.graceNotes = [];
        // If a grace already exists on this string, update its fret (in-place edit, §req 4).
        const existing = ev.graceNotes.findIndex(g => g.string === stringIdx);
        if (existing !== -1) {
            ev.graceNotes[existing] = { ...ev.graceNotes[existing], fret };
        } else {
            // New grace note appended to the run (play order = array order, left→right).
            ev.graceNotes.push({
                string: stringIdx, fret, pitch: null, octave: null,
                slash: true, slur: true,
            });
        }
    };

    if (wrapCommand) wrapCommand('grace', [ev.measureIdx], doCommit);
    else doCommit();

    graceMode.value = false;  // one grace per engage; re-press 'g' for the next
}
```
Notes:
- **pitch:null/octave:null is fine** — the writer (`serializeGraceNote`) and audio (`noteToMidi`)
  both derive pitch from string+fret when pitch is absent, exactly like normal fret-entry notes
  (Phase 1 §7/§8 already handle this). No pitch derivation needed at entry time.
- **Grace groups (req 3):** press `g`, type fret, press `g` again, type next fret → two pushes →
  `graceNotes.length === 2`. The renderer already beams groups (Phase 1 §6). Array order is play
  order; appending puts each new grace to the right of the previous, nearest the principal — which
  matches the renderer's `slot = n-1-gi` layout. Good.
- After commit, leave the cursor on the same event/string (consistent with normal `commitFret`'s
  no-auto-advance rule).

### 2.2 Delete a grace note
Two entry points — both wrapped:
- **Backspace in grace mode** → remove the **last** grace note on the cursor event.
- A dedicated path for "delete grace on cursor string" if a grace exists there (preferred: targets
  what the user sees under the cursor).

```js
function deleteGraceAtCursor() {
    const ev = getCursorEvent();
    if (!ev || !ev.graceNotes?.length) return false;
    const stringIdx = cursor.value.stringIndex;

    const doDelete = () => {
        // Prefer the grace on the cursor string; else pop the last one.
        let idx = ev.graceNotes.findIndex(g => g.string === stringIdx);
        if (idx === -1) idx = ev.graceNotes.length - 1;
        ev.graceNotes.splice(idx, 1);
        if (ev.graceNotes.length === 0) delete ev.graceNotes;  // keep events clean when empty
    };

    if (wrapCommand) wrapCommand('grace-delete', [ev.measureIdx], doDelete);
    else doDelete();
    return true;
}
```
Wire into `handleKeydown`'s Delete/Backspace branch: **if `graceMode` (or the cursor event has
grace notes and the modifier is held), call `deleteGraceAtCursor()` first; only fall through to
`deleteNoteAtCursor()` if it returns false.** Decide the exact precedence so a user can still
delete the principal note normally — recommended: only intercept when `graceMode.value` is true.

### 2.3 Edit fret/string in place (req 4)
- **Fret:** already covered — `commitGraceFret` updates the grace on the cursor string if one
  exists (§2.1 `existing !== -1` branch). User: cursor on the string, `g`, type new fret.
- **String:** moving a grace note to another string. Two acceptable approaches:
  1. **Delete + re-add** (simplest, no new code): `g`+delete on old string, `g`+fret on new string.
     Acceptable for Phase 2 — document it.
  2. **Optional `shiftGraceToString(direction)`** mirroring `shiftNoteToString` (transpose fret by
     the tuning interval). Only build this if delete+re-add feels too clunky in testing. If built,
     it must operate on `ev.graceNotes` and recompute fret via the existing `intervalBetween` helper.

  **Recommendation:** ship approach 1; add approach 2 only if time allows.

---

## 3. Exports (`useNoteInput.js` return + TabEditor wiring)

Add to the returned object: `graceMode`, `commitGraceFret`, `deleteGraceAtCursor`
(and `shiftGraceToString` if built). `handleKeydown` already owns the routing, so TabEditor.vue
needs **no new keyboard wiring** — it just keeps calling `noteInput.handleKeydown(e)`. Confirm the
destructure at `TabEditor.vue:~1243` doesn't need the new names unless something outside the
composable references them (e.g. a toolbar button or the cursor hint in §4).

---

## 4. Visual affordance (optional but recommended)

Users need to know grace mode is on. Cheapest: pass `graceMode` into `TabCursor.vue` (already
receives `pendingDigit`) and tint the cursor or show a small "grace" badge when true. This is a
nice-to-have; the feature works without it, but blind modal state is a footgun. Keep it to a CSS
class toggle on the existing cursor ring — no new layout.

---

## 5. Things that DON'T need touching (and why)
- **Renderer** (`TabMeasure.vue`): Phase 1 already draws `graceNotes[]` (glyphs, stems, group
  beams, slur) and handles the first-event `graceShift`. New grace notes render automatically.
- **Writer / Audio:** consume `graceNotes[]` already. Editing just changes array contents.
- **`repositionMeasure` / overfill / beam passes:** grace notes are outside the tick grid — never
  invoke these for grace edits.
- **`useTabModel` build/serialize:** already carries grace notes (Phase 1 §5).

---

## 6. Definition of done (Phase 2)
1. Cursor on a pitched event → `g` → type `7` → a grace note (fret 7) appears left of the principal,
   smaller, with slur. Two-digit (`g` `1` `2` → 12) works.
2. `g` `5` then `g` `7` on the same event → a beamed two-note grace group renders in play order.
3. Cursor on the grace's string → `g` → new fret → grace fret changes in place (no second grace
   added).
4. Backspace in grace mode removes a grace note; removing the last one drops the `graceNotes` key.
5. Undo/redo across add, edit-fret, and delete all restore correctly (depends on §0 fix).
6. Grace edits never change the bar's tick layout: a bar's principal-note x-positions are identical
   before and after adding a grace mid-bar (except the intended first-event `graceShift` from
   Phase 1), and no overfill tint appears.
7. Save → reload round-trips every edited grace note (exercises Phase 1 writer/parser on
   editor-created data, not just imported data).
8. No regression to normal fret entry, two-digit entry, delete, or string-shift when grace mode is
   off.

---

## 7. Risk notes for the implementer
- **The two-digit timeout path is the sneaky one.** `startPendingDigit`'s `setTimeout` commits via a
  fixed target; if you only fork the synchronous path you'll silently commit normal notes for
  timed-out grace digits. §1.3's `commitPendingFret` indirection is the fix — don't skip it.
- **Empty `graceNotes` hygiene:** always `delete ev.graceNotes` when it hits length 0, so snapshots
  stay small and `graceNotes?.length` guards everywhere keep short-circuiting.
- **Key choice `g`:** confirm it's free in `useCursor.js` and the global editor keymap before
  committing to it. If a song-section or nav shortcut uses `g`, pick another (and update this doc).

---

## 8. As-built — grace-note layout overhaul (shipped 2026-06-24)

The original spec assumed Phase 1's render was good enough. It wasn't: grace notes overlapped
neighbours and didn't make room in the bar/row. This section is the as-built record of the layout
rework done alongside Phase 2.

### 8.1 Per-bar spacing — cumulative insert (TabMeasure.vue `svgContent`)
- Grace clusters consume **zero ticks**, so `xPos` (pure tick-fraction) can't make room. Fix: a
  precompute pass walks voice-1 events L→R keeping a running `cumShift`; each cluster adds its
  width to `cumShift` **before** its principal is placed, so the principal AND every later note in
  the bar shift right. No collapsing onto one note (the first wrong attempt nudged only the previous
  note left — that just relocated the collision).
- `cumShift` is stored two ways: `graceShiftById` (map, used in this component) and **stashed on the
  event as `ev._graceShift`** so other renderers can read it without the map.
- Cluster width constants (keep tight): `GRACE_DX=8` (between glyphs), `GRACE_PAD=8` (last grace →
  principal), `GRACE_GLYPH_W=3` (left clearance). `graceClusterWidth(count)` = `PAD + (count-1)*DX + GLYPH_W`.

### 8.2 Stems down
- Grace stems/flags draw **below** the strings (`bottomStringY+1`, `SMUFL.flag8thDown`) to match
  the tab-note convention. Previously stems pointed up into the chord row (looked wrong on a tab stave).

### 8.3 Row-level squeeze — proportional flex redistribution (TabEditor.vue)
- Bars in a row are flex-% shares of a fixed 100%-wide row (same lever as pickup bars). New
  `_stampGraceFlexPcts(row)` (called next to `_stampPickupFlexPcts` in all `measureRows` paths)
  gives each bar a **demand weight** = base (content-density-weighted: `0.6 + 0.1*min(events,8)`, so
  busy bars resist shrinking) + grace demand (`graceWidthPx / measureWidth`). Flex % = demand share.
- Result: the row stays exactly 100% wide; grace bars grow, **slack long-note bars give up width**.
  Stamped onto `row._gracePct[]` (+ `row._graceEmptyPct` for padding slots); template prefers it over
  the pickup/uniform pct. Skipped on pickup rows and grace-free rows (zero change to existing layouts).
- `_graceWidthPx` mirrors the §8.1 cluster-width constants — **they live in two files; keep in sync**
  (or hoist to `constants.js`). This is the main maintenance footgun.

### 8.4 Aligning beams / ties / cursor to the shift
- Everything that positioned a note read raw `getXm(ev.xPos)` and ignored the shift. Fixed by adding
  `+ (node._graceShift || 0)` at every consumer:
  - `svgHelpers.js` `renderBeams` (stems, primary + both secondary-beam paths) and `renderTies`
    (start, same-measure end, cross-measure end).
  - `TabCursor.vue` `getEvX` — so the navigation ring and click hit-targets track the shifted glyph.
- **Ordering note:** `_graceShift` is written in TabMeasure's `svgContent` computed and read by the
  child `TabCursor`. Parent template (`v-html="svgContent"`) evaluates before children, so it's set
  first in practice — but it's an implicit dependency. If cursor lag-by-a-frame ever appears, hoist
  the precompute into a `provide`d computed both consume.

### 8.5 Known deviations / follow-ups
- **In-place grace fret edit not implemented.** Spec §2.1 has an `existing !== -1` branch to update a
  grace on the same string; as-built `commitGraceFret` always **pushes**, so `g`+fret on a string
  that already has a grace adds a duplicate. Low priority; fix when convenient.
- **Render-space, not reflow.** The shift is added on top of `xPos` everywhere rather than baked into
  `xPos` during reflow. Consistent today, but any *new* xPos consumer must remember to add
  `_graceShift`. Proper engraving would fold it into reflow — the eventual path if grace layout grows.
- **Overfull bars:** grace width can push notes past the barline if a bar was already full. Row
  squeeze (§8.3) mitigates it; the heavier fix is widening via `actualTicks` like overfill.

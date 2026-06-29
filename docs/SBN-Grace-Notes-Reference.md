# SBN Grace Notes — Reference

Status: **✅ SHIPPED 2026-06-24** (both phases). Phase 1: round-trip parse→model→render→export→audio. Phase 2: keyboard editing + layout overhaul.

---

## Architecture — the one decision that drives everything

**Grace notes are stored as a `graceNotes[]` array on the *following* principal `TabEvent`. They are NOT standalone events.**

Every layer of this app is built on the invariant that *an event consumes tick-space*. A grace note consumes **zero** tick-space (it steals time from the principal note at render/playback). If modelled as its own event, it would shift the beat grid, break overfill math, and desync the two audio adapters. Attaching it to the principal note sidesteps all of that.

### `graceNote` shape

```js
// On a TabEvent:
event.graceNotes = [
  {
    string: 2, fret: 3,
    pitch: 'C', octave: 4,   // for audio + XML export; derived from string/fret when absent
    slash: true,              // acciaccatura (slashed stem) vs appoggiatura (false)
    slur: true,               // slur from grace → principal (almost always true)
    // multiple entries = a grace group, in play order
  },
];
```

Keep `graceNotes` absent (not `[]`) when empty so snapshots stay small and `graceNotes?.length` guards short-circuit everywhere.

---

## Phase 1 — Round-trip (parse → model → render → export → audio)

### Test fixture

`docs/GRACE - test fixture.musicxml` — versioned fixture containing:
1. A single acciaccatura (`<grace slash="yes"/>`) slurred into the next note.
2. A grace group (two grace notes before one principal).
3. An appoggiatura (`<grace/>` no slash).
4. A grace note with `<technical><string>/<fret>` tab data.

A grace `<note>` in MusicXML has **no `<duration>`**:
```xml
<note>
  <grace slash="yes"/>
  <pitch><step>C</step><octave>4</octave></pitch>
  <voice>1</voice><type>eighth</type><stem>up</stem>
  <notations>
    <slur type="start" number="1"/>
    <technical><string>2</string><fret>3</fret></technical>
  </notations>
</note>
```

### Parser — `edit.blade.php` `parseNotes` (~line 840)

The highest-risk spot: the current loop advances `currentTick` by `duration`; grace notes have no `<duration>`, so `parseDuration` defaulted to `1` and shifted everything after.

**Fix:**
1. Detect grace: `const isGrace = !!el.querySelector('grace');`
2. Grace notes do NOT advance `currentTick` or set `lastNoteTick`.
3. Parse pitch/string/fret + `slash` + `slur` from the element.
4. Buffer in `pendingGrace = []`; attach to next non-grace, non-chord note as `graceNotes: pendingGrace.length ? pendingGrace : undefined`. Reset after attach.
5. The secondary reader at ~line 1140 (chord/voicing extraction) skips grace elements with a `if (el.querySelector('grace')) continue;` guard.

### Model — `useTabModel.js` `buildModel` (~line 108)

In the pitched-note branch where the event is first created:
```js
if (note.graceNotes && note.graceNotes.length) {
  event.graceNotes = note.graceNotes.map(g => ({ ...g }));
}
```
`serializeModel`/`deserializeModel` already round-trip plain arrays via JSON — verified.

### Renderer — `TabMeasure.vue` `svgContent` (~line 689)

Grace notes render as smaller fret numbers to the LEFT of the principal, with a slur. They must NOT shift the principal's `xPos` (which is tick-locked).

See §As-built (Phase 2 §8) for the final layout approach — the original simple leftward offset was replaced by a cumulative-shift precompute pass.

### MusicXML export — `musicXmlWriter.js`

`serializeGraceNote(g, opts)` emits immediately before the principal's `<note>` elements:
- First child: `<grace${g.slash ? ' slash="yes"' : ''}/>` (no `<duration>`).
- Schema order: `<grace/>` → `<pitch>` → `<voice>` → `<type>` → `<stem>` → `<notations>`.
- `<type>` hardcoded `'eighth'` for Phase 1.
- `<technical><string>/<fret>` from `g.string`/`g.fret`.

### Audio — `tabMeasureToEvents.js`

Grace notes get a short duration stolen from the principal, played just before the beat:
```js
const GRACE_BEATS = 0.0625;  // ~64th; tune by ear
if (ev.graceNotes?.length) {
    const n = ev.graceNotes.length;
    ev.graceNotes.forEach((g, gi) => {
        const midi = noteToMidi(g, tuning);
        if (midi == null) return;
        const offset = (n - gi) * GRACE_BEATS;
        out.push({ time: Math.max(0, beatTime - offset), duration: GRACE_BEATS, velocity: 0.7, ... });
    });
}
```
The principal note's `time`/`duration` is unchanged — grace notes steal perceptually by sounding before the beat.

---

## Phase 2 — Keyboard editing

### Undo bug fix (do first — `useUndo.js` `snapshotMeasure`)

`{ ...ev, notes: ev.notes.map(...) }` shallow-copies `graceNotes` — the snapshot aliases the live array. Fix:
```js
graceNotes: ev.graceNotes ? ev.graceNotes.map(g => ({ ...g })) : undefined,
```
Also deep-clone on `restoreSnapshot` to prevent second-undo aliasing.

### Grace mode — `useNoteInput.js`

- `graceMode = ref(false)` — sticky until a grace is committed or Escape pressed.
- **Key: `g`** (confirmed free 2026-06-24 — audited full bare-key map).
- `g` toggles grace mode on a pitched event; Escape cancels; Backspace in grace mode → `deleteGraceAtCursor()`.
- Digit routing: `commitPendingFret(fret)` indirection covers both the synchronous path and the two-digit `startPendingDigit` timeout path — **don't skip this or timed-out two-digit grace entries commit as normal notes**.

### `commitGraceFret(fret)`

```js
if (!ev.graceNotes) ev.graceNotes = [];
const existing = ev.graceNotes.findIndex(g => g.string === stringIdx);
if (existing !== -1) {
    ev.graceNotes[existing] = { ...ev.graceNotes[existing], fret };
} else {
    ev.graceNotes.push({ string: stringIdx, fret, pitch: null, octave: null, slash: true, slur: true });
}
graceMode.value = false;
```
Grace groups: press `g`+fret repeatedly — each appends to the array in play order (left→right, nearest-principal last).

### `deleteGraceAtCursor()`

Prefers the grace on the cursor string; falls back to the last entry. Calls `delete ev.graceNotes` when array hits length 0.

### String editing

Ship as delete+re-add (no new code). Optional `shiftGraceToString` only if testing shows it's needed.

---

## As-built — layout overhaul (Phase 2, shipped 2026-06-24)

The original leftward-offset render was insufficient — grace notes overlapped neighbours and didn't make room.

### 8.1 Per-bar spacing — cumulative insert (`TabMeasure.vue`)

A precompute pass walks voice-1 events L→R keeping `cumShift`; each grace cluster adds its width to `cumShift` **before** its principal is placed, so the principal and every later note shift right. `graceShiftById` map + `ev._graceShift` stash (for cross-component reads).

Constants: `GRACE_DX=8` (between glyphs), `GRACE_PAD=8` (last grace→principal), `GRACE_GLYPH_W=3` (left clearance). `graceClusterWidth(n) = PAD + (n-1)*DX + GLYPH_W`.

### 8.2 Stems down

Grace stems/flags draw below the strings (`bottomStringY+1`, `SMUFL.flag8thDown`) to match the tab-note convention.

### 8.3 Row-level squeeze — `_stampGraceFlexPcts` (`TabEditor.vue`)

Each bar gets a **demand weight** = base (`0.6 + 0.1*min(events,8)`) + grace demand (`graceWidthPx / measureWidth`). Flex % = demand share. The row stays exactly 100% wide; grace bars grow, slack bars give up width. Skipped on pickup rows and grace-free rows.

**Maintenance footgun:** `_graceWidthPx` constants mirror the §8.1 cluster-width constants in two files — keep in sync or hoist to `constants.js`.

### 8.4 Consumers of `_graceShift`

Every xPos consumer must add `+ (ev._graceShift || 0)`:
- `svgHelpers.js` `renderBeams` (stems, both secondary-beam paths) and `renderTies` (start, same-measure end, cross-measure end).
- `TabCursor.vue` `getEvX` — navigation ring and click hit-targets track the shifted glyph.

### 8.5 Known follow-ups

- **In-place grace fret edit not fully wired.** `commitGraceFret`'s `existing !== -1` branch exists in spec but as-built always pushes — `g`+fret on a string with an existing grace adds a duplicate. Fix when convenient.
- **Render-space, not reflow.** The shift is added on top of `xPos` rather than baked into reflow. Any new xPos consumer must remember `_graceShift`. Proper fix: fold into `useReflow`.
- **Overfull bars with grace notes.** Row squeeze mitigates; heavier fix is widening via `actualTicks` like overfill does.

---

## Files touched

| Layer | File |
|---|---|
| Fixture | `docs/GRACE - test fixture.musicxml` |
| Parse | `resources/views/admin/leadsheets/edit.blade.php` `parseNotes` ~840 + guard ~1140 |
| Model | `resources/js/tab-editor/composables/useTabModel.js` ~108 |
| Render / layout | `resources/js/tab-editor/components/TabMeasure.vue` |
| Row squeeze | `resources/js/tab-editor/TabEditor.vue` `_stampGraceFlexPcts` |
| Beam/tie/cursor | `resources/js/tab-editor/utils/svgHelpers.js`, `TabCursor.vue` |
| CSS | tab-editor stylesheet — `.sbn-tab-grace-note` |
| Export | `resources/js/tab-editor/utils/musicXmlWriter.js` `serializeGraceNote` |
| Audio | `resources/js/audio/adapters/tabMeasureToEvents.js` ~73 |
| Undo | `resources/js/tab-editor/composables/useUndo.js` `snapshotMeasure` |
| Input | `resources/js/tab-editor/composables/useNoteInput.js` |

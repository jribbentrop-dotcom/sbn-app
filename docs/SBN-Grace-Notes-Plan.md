# SBN Grace Notes — Implementation Plan (for Sonnet)

Status: **✅ SHIPPED 2026-06-24** (round-trip parse→model→render→export→audio). Written 2026-06-24.
Editing (Phase 2) and the layout overhaul shipped the same day — see `SBN-Grace-Notes-Phase2-Plan.md`.
Audience: implementing model (Sonnet). This doc is now an as-built reference for the round-trip layer.

---

## 0. Goal & scope

Add **grace notes** (acciaccatura / appoggiatura — the small ornamental notes printed
before a principal note) to the tab editor.

Two phases, **shipped and verified separately**:

- **Phase 1 — Round-trip (this spec):** parse → model → display → MusicXML export → audio.
  Grace notes imported from a `.musicxml` survive a full load→save→reload cycle and play back.
- **Phase 2 — Keyboard entry/editing (separate, later spec):** add/remove grace notes in the
  editor by hand. Do NOT start this until Phase 1 is shipped and verified.

The whole feature is **import-driven** in Phase 1. If a grace note can't be entered by hand
yet, that's fine — that's Phase 2.

---

## 1. The one architectural decision (read this first)

**Grace notes are stored as a `graceNotes[]` array on the *following* principal `TabEvent`.
They are NOT standalone events.**

Why this is forced, not a preference — every layer of this app is built on the invariant that
*an event consumes tick-space*:

- `useTabModel.js` groups notes by `tick` and lays out measures by tick (`tickInMeasure`, `xPos`).
- `tabMeasureToEvents.js` (audio) times each event at `measureStartBeat + tickInMeasure / 480`
  and advances the timeline by `ev.ticks`.
- `TabMeasure.vue` overfill detection sums tick-span; capacity = `ticksPerMeasure`.
- `expandMeasureSequence` + repeat/volta logic all assume tick-addressable events.

A grace note consumes **zero** tick-space (it "steals" time from the principal note at render/
playback, not from the bar's capacity). If we modeled it as its own event with its own tick, it
would shift the beat grid, break overfill math, and desync the two audio adapters. Attaching it
to the principal note sidesteps all of that: the grace note rides along with an event that already
owns a tick slot.

### `graceNote` shape

A grace note is a minimal TabNote — string/fret/pitch/octave, plus grace metadata. **No `tick`,
no `ticks`, no `voice` of its own** (it inherits the host event's). Stored on the host event:

```js
// On a TabEvent:
event.graceNotes = [
  {
    string: 2, fret: 3,
    pitch: 'C', octave: 4,        // for audio + XML export, same as a normal note
    slash: true,                  // acciaccatura (slashed stem) vs appoggiatura (false)
    slur: true,                   // slur/tie from grace → principal (almost always true)
    // multiple entries = a grace *group* (e.g. a two-note run-up), in play order
  },
];
```

Most events have **no** `graceNotes` key at all (or an empty array) — keep it absent when empty so
serialized snapshots stay small and existing equality checks don't change.

> Chord grace notes (two grace notes sounding together) are out of scope for Phase 1. A
> `graceNotes[]` entry = one note in a melodic grace run. If real content needs stacked grace
> notes later, extend the entry to `{ notes: [...] }`; don't design for it now.

---

## 2. Checkpoint sequence (ship/verify in this order)

**Checkpoint 1 — Data round-trip, no rendering.** Parse → model → export. A grace note imported
from MusicXML lands on the right host event's `graceNotes[]`, and `modelToMusicXml` writes it back
out as a valid `<grace/>` `<note>`. Verify by load→save→reload and diffing the model (not the
screen). Audio can land here too — it's low-risk.

**Checkpoint 2 — Rendering.** Draw the grace notes in `TabMeasure.vue`. This is the only visually
risky part (horizontal layout — see §6). Land it on its own so a layout regression can't hide a
data bug.

Do Checkpoint 1 fully (incl. the test fixture) before touching `TabMeasure.vue`.

---

## 3. Test fixture (build this first — Checkpoint 1 depends on it)

Create a real MusicXML file with grace notes and use it as the round-trip fixture. Per
`CLAUDE.md`, MusicXML is the preferred source format; `scripts/extract_musicxml_harmony.py`
exists for harmony extraction (not needed here, but that's the workflow home).

Minimum the fixture must contain:
1. A single **acciaccatura** (one `<grace slash="yes"/>` note) slurred into the next note.
2. A **grace group** — two grace notes before one principal note.
3. An **appoggiatura** (`<grace/>` with no `slash`, or `slash="no"`) for the slash-vs-not branch.
4. A grace note that has `<notations><technical><string>/<fret>` (tab data) so the round-trip
   keeps fret info — this is a tab editor, fret is what's rendered.

A grace `<note>` in MusicXML looks like this — note the **absence of `<duration>`**:

```xml
<note>
  <grace slash="yes"/>
  <pitch><step>C</step><octave>4</octave></pitch>
  <voice>1</voice>
  <type>eighth</type>
  <stem>up</stem>
  <notations>
    <slur type="start" number="1"/>
    <technical><string>2</string><fret>3</fret></technical>
  </notations>
</note>
<note>
  <!-- principal note the grace belongs to -->
  <pitch><step>D</step><octave>4</octave></pitch>
  <duration>240</duration>
  <voice>1</voice>
  <type>eighth</type>
  <notations>
    <slur type="stop" number="1"/>
    <technical><string>2</string><fret>5</fret></technical>
  </notations>
</note>
```

Keep the fixture in `docs/` (e.g. `docs/GRACE - test fixture.musicxml`) so it's versioned with
the workflow docs.

---

## 4. Parser — `resources/views/admin/leadsheets/edit.blade.php` (`parseNotes`, ~line 840)

**This is the highest-risk correctness spot and the reason a naive attempt corrupts the bar.**

The current loop (line 853) does, for every non-chord note:
```js
noteTick = currentTick; lastNoteTick = currentTick; currentTick += duration;
```
and `parseDuration` (line 901) **defaults to `1` when there's no `<duration>`**. A grace note has
no `<duration>` → it would advance the tick cursor by a full quarter and shift everything after it.
That is the bug to prevent.

### Changes to `parseNotes`

1. **Detect grace at the top of the note branch** (after `tag !== 'note'` guard, before the tick
   math at 852–853):
   ```js
   const graceEl = el.querySelector('grace');
   const isGrace = !!graceEl;
   ```
2. **Grace notes do NOT advance `currentTick` and do NOT set `lastNoteTick`.** Skip the entire
   `noteTick`/`currentTick += duration` block for them. They have no tick of their own.
3. **Parse the grace note's pitch / string / fret** with the existing pitch+technical code
   (lines 868–876), plus:
   - `slash`: `graceEl.getAttribute('slash') === 'yes'`
   - `slur`: `!!el.querySelector('notations slur[type="start"]')` (best-effort; default true if a
     grace is present and the next note has slur stop — but a simple "has slur start" read is fine
     for Phase 1)
4. **Buffer pending grace notes** and attach them to the next principal note. Use a local
   `pendingGrace = []`:
   - When `isGrace`: push `{ pitch, octave, string, fret, slash, slur }` onto `pendingGrace`,
     then `continue` (do not push into `notes`, do not touch ticks).
   - When a **non-grace, non-chord** note is pushed (the principal), attach
     `graceNotes: pendingGrace.length ? pendingGrace : undefined` to that note object and reset
     `pendingGrace = []`.
   - Edge case: chord notes (`isChordNote`) should NOT consume pendingGrace — wait for the chord's
     first (non-chord-flagged) note. Since the principal is the first note of the chord and carries
     no `<chord/>`, attaching on the first non-grace note handles this correctly.
   - Edge case: grace notes at the very end of a measure with no following principal — drop them
     with a `console.warn` (malformed; not worth modeling in Phase 1).

> The melody array entries flow into `useTabModel.buildModel()` which copies fields onto the
> TabEvent. So whatever key you put on the principal note object in `parseNotes` must be carried
> through in step 5.

### Also check the second parser

There's a second, simpler note reader at **~line 1140–1156** (`parseNotes`-like, builds
`{tick,string,fret,pitch,octave,duration,tieStart,tieStop}`). Grep shows it's used for chord/
voicing extraction, not the melody grid. **Confirm** whether it feeds anything that needs grace
data. For Phase 1, it almost certainly should just **skip** grace `<note>` elements (so they don't
get mis-read as voicing notes). Add the same `if (el.querySelector('grace')) continue;` guard there
defensively.

---

## 5. Model — `resources/js/tab-editor/composables/useTabModel.js` (`buildModel`, ~line 65)

The melody loop builds the event. Grace notes arrive on the **principal** melody note object.

1. In the pitched-note branch where the event is **first created** (line 108+), after the event
   object exists, carry the grace data:
   ```js
   if (note.graceNotes && note.graceNotes.length) {
     event.graceNotes = note.graceNotes.map(g => ({ ...g }));
   }
   ```
   Put it next to the other per-note→event transfers (beam/tuplet/tie around 143–158). Only the
   first note of a chord carries grace notes (the parser guarantees this), so no merge logic needed.
2. **Serialize/deserialize** (`serializeModel` ~996, `deserializeModel` ~1018): `graceNotes` is
   plain data (no circular refs) so `JSON.parse(JSON.stringify(...))` already round-trips it. In
   `deserializeModel`'s `events.map`, grace notes come along via `...ev`. **Verify** they survive
   the spread — they will, since it's a plain array, but add a grace note to the undo test.
3. `_makeEmptyMeasure` (~929): no change (empty bars never have grace notes).
4. No change to beam/tie/tuplet passes — grace notes don't participate in beaming or the tick grid.

---

## 6. Renderer — `resources/js/tab-editor/components/TabMeasure.vue` (`svgContent`, ~line 689) — **CHECKPOINT 2, the risky one**

Grace notes render as **smaller fret numbers to the LEFT of the principal note**, with a slur into
it. They must NOT shift the principal note's x-position (which comes from `getXm(ev.xPos)` and is
locked to the beat grid).

The note-rendering loop is at **694–732**. For each non-rest event, after drawing its principal
notes, add a grace-note pass:

```js
// inside events.forEach(ev => { ... }), after the ev.notes.forEach block:
if (ev.graceNotes && ev.graceNotes.length) {
    const principalX = getXm(ev.xPos);
    const GRACE_DX   = 9;   // px gap between adjacent grace glyphs — tune at checkpoint
    const GRACE_PAD  = 7;   // px gap between last grace and the principal
    const fontSize   = LAYOUT.noteFontSize * 0.7;
    // Lay grace notes out leftward from the principal, in reverse so the
    // last-played grace sits nearest the principal.
    const n = ev.graceNotes.length;
    ev.graceNotes.forEach((g, gi) => {
        if (g.string == null || g.fret == null) return;
        // position: principal − pad − (distance from principal)
        const slot = (n - 1 - gi);              // 0 = nearest principal
        const x = principalX - GRACE_PAD - slot * GRACE_DX;
        const y = stringY(g.string);
        html += `<text x="${x}" y="${y}" dominant-baseline="central" text-anchor="middle" font-size="${fontSize}" class="sbn-tab-grace-note" data-measure="${m.index}" data-event-id="${ev.id}">${g.fret}</text>`;
        // optional: small slash through the stem area for acciaccatura (g.slash)
    });
    // optional slur: a thin quadratic curve from the last grace glyph up into the principal
}
```

### The hard part — horizontal space (where Opus may step in)

The first event in a bar sits at `xL` (`LAYOUT.xPadding` / `xPaddingFirst` / `xPaddingClef`). A
grace note placed at `principalX - pad` can land at a **negative x** or overlap the previous bar /
the clef. Three acceptable strategies, in order of preference:

1. **Render into `overflow:visible` slack (cheapest).** The SVG already has `style="overflow:visible"`
   (line 69) and bars are laid out with flex gaps. A grace note a few px left of `xL` will visually
   sit in the gutter before the bar and usually looks fine. Try this first — it may be entirely
   sufficient and needs no layout-engine change.
2. **Nudge the principal right only when it's beat-1 AND has grace notes.** A targeted special-case
   in `getXm`/`xPos` for the first event. Riskier — touches the grid.
3. **Reserve lead-in space in `useReflow.js`.** Proper but invasive. Avoid in Phase 1.

**Instruction to Sonnet:** implement strategy 1. If grace notes on the **first beat of a bar**
clip badly, STOP and hand back to Opus rather than reworking `useReflow`/`getXm`. Mid-bar grace
notes (the common case) have a preceding note to borrow space from and will look right with
strategy 1.

### CSS
Add `.sbn-tab-grace-note` near the existing `.sbn-tab-note-text` rules (grep for that class to find
the file — likely a tab-editor stylesheet). Smaller font is already set inline; CSS just needs fill/
opacity to match note text (e.g. `fill: currentColor; opacity: .85;`).

---

## 7. MusicXML export — `resources/js/tab-editor/utils/musicXmlWriter.js`

`serializeMeasure` (~495) iterates events and calls `serializeNote` per note. Grace notes must be
emitted **immediately before** their principal note's `<note>` element(s).

1. In the event loop (~557), before serializing the principal notes, emit grace notes:
   ```js
   if (!event.isRest && event.graceNotes?.length) {
       for (const g of event.graceNotes) {
           parts.push(serializeGraceNote(g, { voice, tuning }));
       }
   }
   ```
2. New `serializeGraceNote(g, opts)` — mostly a trimmed `serializeNote`:
   - First child element is `<grace${g.slash ? ' slash="yes"' : ''}/>` (immediately after `<note>`).
   - `<pitch>` from `g.pitch`/`g.octave` (reuse `parsePitch`), or derive via `pitchFromStringFret`
     when pitch is absent — same fallback `serializeNote` uses (lines 387–402).
   - **NO `<duration>`** (grace notes must not have one — that's the whole point).
   - `<voice>`, `<type>` (default `'eighth'` — grace notes need a type; store it or hardcode eighth
     for Phase 1), `<stem>up`.
   - `<notations>`: `<slur type="start"/>` if `g.slur`, plus `<technical><string>/<fret></technical>`
     from `g.string`/`g.fret`.
   - The matching `<slur type="stop"/>` ideally goes on the principal note. For Phase 1 a slur start
     with no explicit stop still round-trips through *our* parser (which only reads slur start on the
     grace). If you want clean XML, thread a flag so `serializeNote` adds the stop on the principal
     when it has grace notes — nice-to-have, not required.

The schema order inside `<note>` matters: `<grace/>` → `<pitch>` → (no duration) → `<voice>` →
`<type>` → `<stem>` → `<notations>`. Follow `serializeNote`'s existing ordering, just drop
`<duration>` and insert `<grace/>` first.

---

## 8. Audio — `resources/js/audio/adapters/tabMeasureToEvents.js`

Grace notes get a very short duration **stolen from the principal note**, played slightly before
the beat (acciaccatura = on-the-beat-or-just-before; for Phase 1, just-before is fine and simplest).

In the per-event loop (~73), before emitting the principal note's events (line 85 block), emit grace
events:

```js
const GRACE_BEATS = 0.0625; // a 64th-ish; tune by ear. Total grace run stolen from principal onset.
if (ev.graceNotes?.length) {
    const n = ev.graceNotes.length;
    const each = GRACE_BEATS;            // per grace note
    ev.graceNotes.forEach((g, gi) => {
        const midi = noteToMidi(g, tuning);
        if (midi == null) return;
        // stack them just before beatTime, in play order
        const offset = (n - gi) * each;  // earliest grace is furthest before the beat
        out.push({
            time:     beatTime - offset,
            voice,
            pitch:    midi,
            duration: each,
            velocity: 0.7,
            tieNext:  false,
            sourceId: ev.id || null,
        });
    });
}
```

Notes:
- `time` can go slightly negative for a grace on beat 0 of the whole piece — clamp to `>= 0` if the
  engine dislikes negative times (check `AudioEngine.load`/scheduler). A `Math.max(0, ...)` is safe.
- This does NOT change the principal note's `time`/`duration` (keeps the grid honest). The grace
  "steals" perceptually by sounding just before; we don't shorten the principal in Phase 1.
- `noteToMidi(g, tuning)` works on `{string, fret}` or `{pitch, octave}` — same as normal notes.
- **Verify** `chordVoicingsToEvents` doesn't also need to know about grace notes — it won't, grace
  notes live only on melody/tab events. The two adapters stay in sync because grace notes add events
  *within* an existing event's neighborhood, not new tick positions.

---

## 9. Files to touch (summary)

| Layer | File | Change |
|---|---|---|
| Fixture | `docs/GRACE - test fixture.musicxml` | new — §3 |
| Parse | `resources/views/admin/leadsheets/edit.blade.php` `parseNotes` ~840 | detect grace, don't advance tick, buffer→attach; guard 2nd reader ~1140 — §4 |
| Model | `resources/js/tab-editor/composables/useTabModel.js` ~108 | carry `graceNotes` onto event; verify undo round-trip — §5 |
| Render | `resources/js/tab-editor/components/TabMeasure.vue` `svgContent` ~689 | draw grace glyphs left of principal — §6 (**Checkpoint 2**) |
| CSS | tab-editor stylesheet (grep `sbn-tab-note-text`) | `.sbn-tab-grace-note` — §6 |
| Export | `resources/js/tab-editor/utils/musicXmlWriter.js` ~557 | `serializeGraceNote`, emit before principal — §7 |
| Audio | `resources/js/audio/adapters/tabMeasureToEvents.js` ~73 | emit short pre-beat grace events — §8 |

---

## 10. Definition of done (Phase 1)

1. Load the §3 fixture into the leadsheet tab editor → grace notes appear left of their principal
   notes, smaller, with a slur (Checkpoint 2).
2. Save → reload → the model is byte-identical in its `graceNotes` data (Checkpoint 1). Confirm by
   re-exporting and diffing the `<grace/>` notes, not by eye.
3. Play back → you hear the grace note(s) just before the principal note.
4. Structural undo/redo (insert/delete bar near a grace note) preserves the grace notes
   (`serializeModel`/`deserializeModel` test).
5. A bar with grace notes is NOT flagged overfilled (grace notes consume no tick capacity) — open
   such a bar and confirm no `sbn-tab-measure--overfill` tint.
6. No regression: a song with zero grace notes renders, exports, and plays exactly as before
   (grace code paths are all guarded by `graceNotes?.length`).

---

## 11. Explicitly OUT of scope (Phase 2 — separate spec)

- Adding/removing grace notes via keyboard or mouse in the editor.
- Stacked/chord grace notes (two grace notes sounding together).
- Grace-note beaming between multiple grace notes.
- Shortening the principal note's audio duration to "pay for" the grace (Phase 1 plays grace just
  before the beat without touching the principal).
- Reworking `useReflow.js` to reserve lead-in space (only if strategy-1 layout proves insufficient
  — escalate to Opus).

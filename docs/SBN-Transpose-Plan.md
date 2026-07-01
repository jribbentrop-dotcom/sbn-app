# Transpose Sheet — Implementation Plan

> **SHIPPED 2026-07-01 — this is the original plan, kept for history.** The feature
> was ultimately built **server-side** (not the client-only approach sketched below),
> because the two tab layers live as separate MusicXML strings on a `LeadsheetVersion`
> and only one loads into Vue at a time. For the as-built design (endpoint, both-layer
> transpose, json_data melody + chord-name gotchas, inverse-transpose undo) see
> **SBN-Admin-Chord-Tab-Editor-Reference.md → "Transpose sheet (backend…)"**. The
> `transpose.js` helpers below still drive the exercise (client-side) transpose path.

## Overview

Allows transposing an entire leadsheet (chord names + TAB fret numbers + note
pitch/octave) by a given interval, e.g. from A major to D major
(up a perfect 4th = +5 semitones).

---

## Data model reference

| Location | What it holds |
|---|---|
| `model.value.sections[].measures[].chordNames[]` | Chord name strings, e.g. `"C#m7"`, `"G13/B"` |
| `model.value.sections[].measures[].events[].notes[]` | Each note: `{ string, fret, pitch, octave, … }` |
| `model.value.chordVoicings` | **Model-global** object keyed `"Name@gi.ci"` (global measure index, chord slot) |
| `songKey` | Ref passed into `useTabModel` (`useAlpineBridge.js:40`); current key, e.g. `"A"` |
| `exportAlpineSections()` | Reads `chords[].name` — chord name changes flow through automatically |

Note structure: `string` = 1 (high e) … 6 (low E), `fret` = 0–24. `pitch`/`octave`
are `null` for hand-entered notes but carry real values on MusicXML-imported notes
(`useNoteInput.js:93`).

Existing helpers to **reuse** (don't hand-roll):
- `window.sbnSpellChordName(name, key)` — the single enharmonic spelling authority
  (see callout below). Guarded usage pattern at `useVoicingPickerStore.js:357`.
- `_renameVoicingKey(cv, oldName, newName, gi, ci)` — exported from `useChordGridOps.js:73`;
  the tested voicing-key remap. Use this instead of manually rebuilding keys.
- `structuralUndoOptions` in `TabEditor.vue:1225` (`{ serializeModel, deserializeModel, afterApply }`)
  — the whole-model undo wrapper every structural op (Add Bar, Split Section…) already uses.

---

## Step 1 — `transpose.js` utility (new file)

**`resources/js/tab-editor/utils/transpose.js`**

The utility only does raw semitone arithmetic. **Enharmonic spelling is NOT decided
here** — the transposed name is re-spelled against the target key by
`sbnSpellChordName` in Step 2. So `transposeChordName` shifts root/bass and returns
a *provisional* spelling (sharps table is fine); the final spelling comes from the
spelling core.

```js
const CHROMA = ['C','C#','D','D#','E','F','F#','G','G#','A','A#','B'];
const FLATS  = ['C','Db','D','Eb','E','F','Gb','G','Ab','A','Bb','B'];

function noteIndex(note) {
    let i = CHROMA.indexOf(note);
    if (i === -1) i = FLATS.indexOf(note);   // accept flat input (Db, Eb, …)
    return i;                                // -1 if unrecognised
}

// Provisional transpose — root/bass shifted; final spelling applied later by sbnSpellChordName.
export function transposeChordName(name, semitones) {
    if (!name) return name;                  // '' / null slots pass through
    // Regex mirrors _parseChordForPicker in useVoicingPickerStore.js:67
    const m = name.match(/^([A-G][#b]?)(.*?)(?:\/([A-G][#b]?))?$/);
    if (!m) return name;
    const [, root, body, bass] = m;
    const ri = noteIndex(root);
    if (ri === -1) return name;
    const shiftedRoot = CHROMA[((ri + semitones) % 12 + 12) % 12];
    let shiftedBass = '';
    if (bass) {
        const bi = noteIndex(bass);
        shiftedBass = bi === -1 ? bass : CHROMA[((bi + semitones) % 12 + 12) % 12];
    }
    return shiftedRoot + body + (shiftedBass ? '/' + shiftedBass : '');
}

export function transposeFret(fret, semitones) {
    const newFret = fret + semitones;
    return { fret: Math.max(0, Math.min(24, newFret)), overflow: newFret < 0 || newFret > 24 };
}

// pitch/octave transpose (MusicXML-imported notes carry these; null passes through).
// Spelling of pitch letter can stay chromatic-sharp — it is not shown to the user as a
// chord symbol; it feeds export/playback where pitch class is what matters.
export function transposePitch(pitch, octave, semitones) {
    if (pitch == null || octave == null) return { pitch, octave };
    const i = noteIndex(pitch);
    if (i === -1) return { pitch, octave };
    const abs = octave * 12 + i + semitones;
    return { pitch: CHROMA[((abs % 12) + 12) % 12], octave: Math.floor(abs / 12) };
}

// Shift a bare key name (e.g. 'A' → 'D') for the target-key argument to sbnSpellChordName.
export function transposeKey(keyName, semitones) {
    if (!keyName) return keyName;
    const [tonic, ...rest] = keyName.split(' ');       // "A minor" → ["A","minor"]
    const minor = tonic.endsWith('m') && tonic.length <= 3;
    const base = minor ? tonic.slice(0, -1) : tonic;
    const i = noteIndex(base);
    if (i === -1) return keyName;
    const shifted = CHROMA[((i + semitones) % 12 + 12) % 12];
    return (minor ? shifted + 'm' : shifted) + (rest.length ? ' ' + rest.join(' ') : '');
    // Final flat/sharp spelling of the tonic itself is handled downstream where songKey is consumed.
}
```

> ⚠️ **Superseded by the enharmonic spelling core (2026-06-30).** Do **not** hand-roll
> `keyUsesFlats` / `FLAT_KEYS` here — the app now has one authority. When implementing
> transpose, re-spell each transposed chord name with `window.sbnSpellChordName(name, newKey)`
> (JS) — it already encodes the house style: **flats by default**, only the genuine sharp keys
> (G D A E B F# C# + relative minors) spell sharp, and neutral C/Am spell flat. The sketch
> above predates that and would wrongly treat C major as sharp-side. See
> **SBN-Admin-Chord-Tab-Editor-Reference.md → "The enharmonic spelling core"** and
> [[project_enharmonic_core]].
>
> **How this shapes the plan:** `transpose.js` no longer takes a `useFlats` flag and no
> longer owns spelling. It shifts pitch classes only; `transposeSheet` (Step 2) then runs
> each shifted name through `sbnSpellChordName(name, newKey)` so the whole sheet is spelled
> consistently for the *destination* key. This also means we must rewrite `songKey.value`
> so the target key is what the spelling core reads.

---

## Step 2 — `transposeSheet()` in `useTabModel.js`

Add alongside `renameSection`, `splitSection`, etc. Corrections vs. the first draft:

1. **`overflowCount` lives outside the `fn` closure** — `wrapCommand` returns
   `undefined` (`useUndo.js:165`), so we can't read the count from its return value.
2. **Use the options form of `wrapCommand`** (full-model snapshot). A whole-sheet
   op mutates the **model-global** `chordVoicings` object, which the per-measure
   snapshot mode does **not** capture — undo would restore frets/names but leave
   voicing keys remapped. Pass `structuralUndoOptions` from `TabEditor.vue`.
3. **Rewrite `songKey.value` first**, then re-spell names against the new key via
   `sbnSpellChordName` (the spelling core reads `songKey`).

```js
function transposeSheet(semitones, undoOptions) {
    if (!model.value || semitones === 0) return 0;

    let overflowCount = 0;
    const cv = model.value.chordVoicings;
    const newKey = transposeKey(songKey?.value, semitones);
    const spell = (n) =>
        (typeof window !== 'undefined' && typeof window.sbnSpellChordName === 'function' && n)
            ? window.sbnSpellChordName(n, newKey) : n;

    wrapCommand(
        `Transpose ${semitones > 0 ? '+' : ''}${semitones} semitones`,
        [],                          // ignored in full-model (options) mode
        () => {
            if (songKey) songKey.value = newKey;   // spelling core reads this

            for (const section of model.value.sections) {
                for (const measure of section.measures) {
                    // 1. Chord names: shift → re-spell for target key → voicing-key remap
                    measure.chordNames = measure.chordNames.map((name, ci) => {
                        const shifted = transposeChordName(name, semitones);
                        const newName = spell(shifted);
                        if (cv && newName !== name) {
                            _renameVoicingKey(cv, name, newName, measure.index, ci);
                        }
                        return newName;
                    });

                    // 2. Fret + pitch/octave per note
                    for (const event of measure.events) {
                        if (event.isRest) continue;
                        for (const note of event.notes) {
                            const { fret, overflow } = transposeFret(note.fret, semitones);
                            note.fret = fret;
                            if (overflow) overflowCount++;
                            const p = transposePitch(note.pitch, note.octave, semitones);
                            note.pitch = p.pitch;
                            note.octave = p.octave;
                        }
                    }
                }
            }
        },
        undoOptions,                 // = structuralUndoOptions
    );

    return overflowCount;
}
```

`_renameVoicingKey` is returned from `useChordGridOps` — wire it in the same way
`useTabModel` already consumes other grid ops, or import directly. Export
`transposeSheet` from `useTabModel`'s return value.

---

## Step 3 — UI in `TabEditor.vue`

### Trigger
- Toolbar button: `⇅` or "Transpose" label
- Keyboard shortcut: `Shift+T` (guarded: only when no input focused)

### Modal fields
| Field | Type | Notes |
|---|---|---|
| From key | display-only | Current `songKey.value`, e.g. `"A"` |
| To key | dropdown | 12 keys (or "custom") |
| Semitones | number input | Auto-filled when To key selected; editable for custom |

**No spelling radio.** Spelling is derived from the target key by the enharmonic core
(flats by default; genuine sharp keys spell sharp). Removing the toggle keeps behaviour
consistent with the rest of the editor.

### On confirm
```js
const overflowCount = tabModel.transposeSheet(semitones, structuralUndoOptions);
syncTabSectionsToAlpine();
if (overflowCount > 0) showToast(`${overflowCount} note(s) were clamped to fret range`);
```

`structuralUndoOptions` already exists at `TabEditor.vue:1225` — pass it straight through.

---

## What's NOT in v1

- **String rebalancing** when transposing down causes negative frets or up causes >24.
  Notes are clamped and flagged. A v2 pass could attempt to move notes to adjacent
  strings using the `intervalBetween()` table already in `useNoteInput.js`.
- **Capo-aware transposition** (e.g. "sound pitch up 4th but keep capo 2 notation").
- **Partial selection transpose** (transpose only selected measures/notes).

---

## File touch list

| File | Change |
|---|---|
| `resources/js/tab-editor/utils/transpose.js` | **New** — chord + fret + pitch + key shift (arithmetic only, no spelling) |
| `resources/js/tab-editor/composables/useTabModel.js` | Add `transposeSheet(semitones, undoOptions)`; rewrite `songKey`, re-spell via `sbnSpellChordName`, remap voicings via `_renameVoicingKey`; export it |
| `resources/js/tab-editor/TabEditor.vue` | Modal UI, toolbar button, `Shift+T` shortcut; call with `structuralUndoOptions` |

## Effort estimate

2–3 focused Sonnet turns. Undo is already wired (`structuralUndoOptions`), the chord
parser and spelling core already exist, and the voicing-key remap helper
(`_renameVoicingKey`) is done. Net-new code is the semitone/pitch arithmetic in
`transpose.js` and the modal.

## Pre-flight checklist (verified against code 2026-07-01)

- [x] Chord regex matches `_parseChordForPicker` (`useVoicingPickerStore.js:67`)
- [x] Spelling routed through `window.sbnSpellChordName(name, key)` — not a local flat table (`useVoicingPickerStore.js:357`)
- [x] `_renameVoicingKey` exists + exported (`useChordGridOps.js:73`)
- [x] `wrapCommand` returns `undefined` → capture `overflowCount` outside the closure (`useUndo.js:165`)
- [x] `chordVoicings` is model-global → use full-model undo (`structuralUndoOptions`, `TabEditor.vue:1225`), not per-measure
- [x] `note` carries `pitch`/`octave` → transpose them too (`useNoteInput.js:93`)
- [x] `songKey` is a ref in `useTabModel` (`useAlpineBridge.js:40`) → rewrite it so spelling core reads the target key

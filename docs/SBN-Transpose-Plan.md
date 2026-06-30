# Transpose Sheet — Implementation Plan

## Overview

Allows transposing an entire leadsheet (chord names + TAB fret numbers) by a
given interval, e.g. from A major to D major (up a perfect 4th = +5 semitones).

---

## Data model reference

| Location | What it holds |
|---|---|
| `model.value.sections[].measures[].chordNames[]` | Chord name strings, e.g. `"C#m7"`, `"G13/B"` |
| `model.value.sections[].measures[].events[].notes[]` | Each note: `{ string, fret, pitch, octave, … }` |
| `model.value.chordVoicings` | Flat object keyed `"Name@gi.ci"` (global measure index, chord slot) |
| `exportAlpineSections()` | Reads `chords[].name` — chord name changes flow through automatically |

Note structure: `string` = 1 (high e) … 6 (low E), `fret` = 0–24.

Existing string-shift helpers in `useNoteInput.js` (`intervalBetween`,
`shiftNoteToString`) show the per-note mutation pattern.

---

## Step 1 — `transpose.js` utility (new file)

**`resources/js/tab-editor/utils/transpose.js`**

```js
const SHARPS = ['C','C#','D','D#','E','F','F#','G','G#','A','A#','B'];
const FLATS  = ['C','Db','D','Eb','E','F','Gb','G','Ab','A','Bb','B'];

export function transposeChordName(name, semitones, useFlats = false) {
    // Regex mirrors _parseChordForPicker in useVoicingPickerStore.js
    const m = name.match(/^([A-G][#b]?)(.*?)(?:\/([A-G][#b]?))?$/);
    if (!m) return name;
    const [, root, body, bass] = m;
    const shiftedRoot = shiftNote(root, semitones, useFlats);
    const shiftedBass = bass ? shiftNote(bass, semitones, useFlats) : '';
    return shiftedRoot + body + (shiftedBass ? '/' + shiftedBass : '');
}

function shiftNote(note, semitones, useFlats) {
    const table = useFlats ? FLATS : SHARPS;
    const idx = table.indexOf(note) !== -1
        ? table.indexOf(note)
        : SHARPS.indexOf(note);   // fallback: find in sharps if not in flats table
    if (idx === -1) return note;  // unrecognised — leave as-is
    return table[((idx + semitones) % 12 + 12) % 12];
}

export function transposeFret(fret, semitones) {
    const newFret = fret + semitones;
    return { fret: Math.max(0, Math.min(24, newFret)), overflow: newFret < 0 || newFret > 24 };
}

// Which keys prefer flats
const FLAT_KEYS = new Set(['F','Bb','Eb','Ab','Db','Gb','Dm','Gm','Cm','Fm','Bbm','Ebm']);
export function keyUsesFlats(keyName) {
    return FLAT_KEYS.has(keyName?.split(' ')[0] ?? '');
}
```

---

## Step 2 — `transposeSheet()` in `useTabModel.js`

Add alongside `renameSection`, `splitSection`, etc.

```js
function transposeSheet(semitones, useFlats = false) {
    if (!model.value) return;

    // Collect all measure global indices for undo snapshot
    const allIndices = model.value.sections
        .flatMap(s => s.measures.map(m => m.index));

    // Build chord rename map before mutating (needed for voicing key remap)
    const renameMap = new Map(); // "oldName@gi.ci" → newName

    wrapCommand(`Transpose ${semitones > 0 ? '+' : ''}${semitones} semitones`, allIndices, () => {
        let overflowCount = 0;

        for (const section of model.value.sections) {
            for (const measure of section.measures) {
                // 1. Transpose chord names
                measure.chordNames = measure.chordNames.map((name, ci) => {
                    const newName = transposeChordName(name, semitones, useFlats);
                    renameMap.set(`${name}@${measure.index}.${ci}`, newName);
                    return newName;
                });

                // 2. Transpose fret numbers
                for (const event of measure.events) {
                    if (event.isRest) continue;
                    for (const note of event.notes) {
                        const { fret, overflow } = transposeFret(note.fret, semitones);
                        note.fret = fret;
                        if (overflow) overflowCount++;
                    }
                }
            }
        }

        // 3. Remap voicing keys (same pattern as _renameVoicingKey in useChordGridOps.js)
        const cv = model.value.chordVoicings;
        if (cv) {
            for (const [oldKey, newName] of renameMap) {
                if (cv[oldKey] !== undefined) {
                    cv[newName.split('@')[0] + '@' + oldKey.split('@')[1]] = cv[oldKey];
                    delete cv[oldKey];
                }
            }
        }

        return overflowCount; // caller can surface warning
    });
}
```

Export from `useTabModel` return value; provide via `provide('transposeSheet', ...)` in `TabEditor.vue`.

---

## Step 3 — UI in `TabEditor.vue`

### Trigger
- Toolbar button: `⇅` or "Transpose" label
- Keyboard shortcut: `Shift+T` (guarded: only when no input focused)

### Modal fields
| Field | Type | Notes |
|---|---|---|
| From key | display-only | Current `songKey` from model, e.g. `"A major"` |
| To key | dropdown | 12 keys (or "custom") |
| Semitones | number input | Auto-filled when To key selected; editable for custom |
| Spelling | radio | Sharps / Flats / Auto (auto = `keyUsesFlats(toKey)`) |

### On confirm
```js
const overflowCount = tabModel.transposeSheet(semitones, useFlats);
syncTabSectionsToAlpine();
if (overflowCount > 0) showToast(`${overflowCount} note(s) were clamped to fret 24`);
```

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
| `resources/js/tab-editor/utils/transpose.js` | **New** — chord + fret transpose helpers |
| `resources/js/tab-editor/composables/useTabModel.js` | Add `transposeSheet()`, export it |
| `resources/js/tab-editor/TabEditor.vue` | Modal UI, toolbar button, `Shift+T` shortcut, `provide` wiring |

## Effort estimate

2–3 focused Sonnet turns. Undo is already wired, the chord parser is already
written, the mutation pattern is established. The only net-new code is the
semitone arithmetic in `transpose.js` and the modal.

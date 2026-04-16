/**
 * Pitch string + octave → MIDI note.
 * Accepts forms like "C", "F#", "Bb", "C♯", "E♭".
 * If pitch string is missing, falls back to {string, fret} in standard guitar tuning.
 */

const STEP_TO_SEMITONE = { C: 0, D: 2, E: 4, F: 5, G: 7, A: 9, B: 11 };

// SBN string numbering: 1 = high e, 6 = low E.
// Open-string MIDI values in standard tuning.
const OPEN_STRING_MIDI = {
    1: 64, // E4
    2: 59, // B3
    3: 55, // G3
    4: 50, // D3
    5: 45, // A2
    6: 40, // E2
};

export function pitchToMidi(pitchStr, octave) {
    if (!pitchStr || octave == null) return null;
    const step = pitchStr[0].toUpperCase();
    const semis = STEP_TO_SEMITONE[step];
    if (semis == null) return null;
    let alter = 0;
    const rest = pitchStr.slice(1);
    if (rest.includes('#') || rest.includes('♯')) alter = 1;
    else if (rest.includes('b') || rest.includes('♭')) alter = -1;
    // MIDI middle C (C4) = 60. MIDI = (octave + 1) * 12 + semis + alter.
    return (octave + 1) * 12 + semis + alter;
}

export function stringFretToMidi(string, fret) {
    const open = OPEN_STRING_MIDI[string];
    if (open == null) return null;
    return open + (fret || 0);
}

/**
 * Resolve a tab note to MIDI, preferring explicit pitch+octave, falling back to string+fret.
 * @param {{pitch?:string, octave?:number, string?:number, fret?:number}} note
 * @returns {number|null}
 */
export function noteToMidi(note) {
    if (!note) return null;
    const fromPitch = pitchToMidi(note.pitch, note.octave);
    if (fromPitch != null) return fromPitch;
    return stringFretToMidi(note.string, note.fret);
}

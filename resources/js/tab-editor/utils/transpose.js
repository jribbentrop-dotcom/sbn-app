const CHROMA = ['C','C#','D','D#','E','F','F#','G','G#','A','A#','B'];
const FLATS  = ['C','Db','D','Eb','E','F','Gb','G','Ab','A','Bb','B'];

function noteIndex(note) {
    let i = CHROMA.indexOf(note);
    if (i === -1) i = FLATS.indexOf(note);
    return i;
}

// Provisional transpose — root/bass shifted by semitones using sharps table.
// Final spelling is applied by sbnSpellChordName in transposeSheet.
export function transposeChordName(name, semitones) {
    if (!name) return name;
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

// pitch/octave transpose for MusicXML-imported notes (null passes through).
export function transposePitch(pitch, octave, semitones) {
    if (pitch == null || octave == null) return { pitch, octave };
    const i = noteIndex(pitch);
    if (i === -1) return { pitch, octave };
    const abs = octave * 12 + i + semitones;
    return { pitch: CHROMA[((abs % 12) + 12) % 12], octave: Math.floor(abs / 12) };
}

// Transpose all fretted strings in a 6-char diagram fret string by `semitones`.
// Octave-folds the WHOLE voicing (shift by ±12) to keep all frets in 0..24.
// 'x' muted strings pass through unchanged.
// Returns { frets: string, overflow: bool } where overflow is only true if a fret
// could not be placed in 0..24 even after folding (impossible for real shapes).
export function transposeVoicingFrets(frets, semitones) {
    if (!frets || typeof frets !== 'string') return { frets, overflow: false };
    const decoded = frets.split('').map(ch =>
        (ch === 'x' || ch === 'X') ? null : parseInt(ch, 16));
    const shifted = decoded.map(f => (f === null || isNaN(f)) ? null : f + semitones);

    const fretted = () => shifted.filter(f => f !== null);
    let guard = 0;
    while (fretted().length && guard++ < 4) {   // guard: real voicings span <=24 frets, prevents infinite loop on malformed input
        const hi = Math.max(...fretted());
        const lo = Math.min(...fretted());
        if (hi > 24)      shifted.forEach((f, i) => { if (f !== null) shifted[i] = f - 12; });
        else if (lo < 0)  shifted.forEach((f, i) => { if (f !== null) shifted[i] = f + 12; });
        else break;
    }

    let overflow = false;
    const out = shifted.map(f => {
        if (f === null) return 'x';
        if (f < 0 || f > 24) { overflow = true; f = Math.max(0, Math.min(24, f)); }
        return f.toString(16);
    });
    return { frets: out.join(''), overflow };
}

// Compute position from a fret string: the lowest non-zero fret, minimum 1.
// This matches the position convention used throughout chord voicing storage.
export function fretsToPosition(frets) {
    const nums = frets.split('')
        .map(c => c === 'x' ? null : parseInt(c, 16))
        .filter(f => f !== null && !isNaN(f) && f > 0);
    return nums.length ? Math.min(...nums) : 1;   // lowest non-zero fret, min 1
}

// Shift a bare key tonic (e.g. 'A' → 'D'); "A minor" → "D minor" also handled.
// Final flat/sharp spelling is applied downstream by sbnSpellChordName.
export function transposeKey(keyName, semitones) {
    if (!keyName) return keyName;
    const parts = keyName.split(' ');
    const tonic = parts[0];
    const minor = tonic.endsWith('m') && tonic.length <= 3;
    const base  = minor ? tonic.slice(0, -1) : tonic;
    const i = noteIndex(base);
    if (i === -1) return keyName;
    const shifted = CHROMA[((i + semitones) % 12 + 12) % 12];
    const newTonic = minor ? shifted + 'm' : shifted;
    return parts.length > 1 ? newTonic + ' ' + parts.slice(1).join(' ') : newTonic;
}

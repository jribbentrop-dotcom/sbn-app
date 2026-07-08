/**
 * fretboardTranspose — semitone-offset math for scale/positions-mode
 * fretboards. Chord/sequence mode is not transposable (see SBN-Fretboard-
 * Reference.md §6).
 */

const CHROMA = ['C', 'C#', 'D', 'D#', 'E', 'F', 'F#', 'G', 'G#', 'A', 'A#', 'B'];
const FLATS  = ['C', 'Db', 'D', 'Eb', 'E', 'F', 'Gb', 'G', 'Ab', 'A', 'Bb', 'B'];

function noteIndex(note: string): number {
    const n = note.trim();
    let i = CHROMA.indexOf(n);
    if (i === -1) i = FLATS.indexOf(n);
    return i;
}

/** Semitone offset from `fromNote` to `toNote` (both bare note names, e.g. "E"/"G"). Result is not itself octave-normalized — pass it through `foldShapeOffset` before applying with `transposeFret`. */
export function semitoneOffset(fromNote: string, toNote: string): number {
    const a = noteIndex(fromNote);
    const b = noteIndex(toNote);
    if (a === -1 || b === -1) return 0;
    return b - a;
}

const NECK_MIN_FRET = 0;
const NECK_MAX_FRET = 24;

/**
 * Given the lowest and highest fret across an entire shape (all dots and
 * window boundaries combined), return the octave-folded (±12) semitone
 * offset that keeps the *whole shape* within [NECK_MIN_FRET, NECK_MAX_FRET].
 *
 * Folding must happen once for the whole shape, not per-fret — a positions
 * scale spans many frets, and folding each fret independently would let one
 * dot/window boundary land an octave away from its neighbors, distorting the
 * shape (e.g. a window's `from` folding but its `to` not, inverting the span).
 */
export function foldShapeOffset(minFret: number, maxFret: number, semitones: number): number {
    let st = semitones;
    let guard = 0;
    while ((maxFret + st > NECK_MAX_FRET || minFret + st < NECK_MIN_FRET) && guard++ < 4) {
        if (maxFret + st > NECK_MAX_FRET) st -= 12;
        else st += 12;
    }
    return st;
}

/** Shift a fret by a (pre-folded) semitone offset — plain addition, no per-fret folding. */
export function transposeFret(fret: number, semitones: number): number {
    return fret + semitones;
}

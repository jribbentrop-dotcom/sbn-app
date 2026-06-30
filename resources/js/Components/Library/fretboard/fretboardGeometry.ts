// Pure fretboard geometry — extracted byte-for-byte from ChordProgressionViewer.vue
// (Phase 1 of PLAN-fretboard-svg-unification.md). No Vue reactivity in this file;
// everything here is plain math/constants computed once at module load, exactly
// as it was when inlined in the component's <script setup>.
//
// Phase 2 generalizes this: `makeGeometry(startFret, fretCount)` is a factory
// that computes the same shape for an arbitrary fret range, so <sbn-fretboard>
// (configurable start_fret/fret_count) can reuse it. The original module-level
// consts below are now just `makeGeometry(FRET_FROM, FRET_TO - FRET_FROM + 1)`
// — same values as before, so the prog viewer (which imports the consts
// directly) is byte-for-byte unchanged.

export const VB_W = 800;
export const VB_H = 130;
export const PAD_L = 20;
export const PAD_R = 10;
export const PAD_T = 14;
export const PAD_B = 20;
export const FRET_FROM = 1;
export const FRET_TO = 15;
export const FRET_WINDOW = 7; // always show exactly this many frets
export const stringH = (VB_H - PAD_T - PAD_B) / 5;

export const USABLE_W = VB_W - PAD_L - PAD_R;

// SBN convention: string 1 = Low E, 6 = High E. Render Low E at BOTTOM.
export function stringY(s: number) { return PAD_T + (6 - s) * stringH; }

export interface FretboardGeometry {
    startFret: number;
    fretCount: number;
    /** fretEdgeX[i] = x position of the left edge of fret (startFret + i); fretEdgeX[fretCount] = right edge of last fret. */
    fretEdgeX: number[];
    fretCenterX: (f: number) => number;
    NECK_L: number;
    NECK_R: number;
    fretLines: Array<{ x: number; isNut: boolean }>;
    stringLines: Array<{ y: number; s: number; x1: number; x2: number }>;
    singleInlays: Array<{ cx: number; cy: number }>;
    doubleInlays: Array<{ cx: number; cy: number }>;
    fretNumbers: Array<{ x: number; y: number; n: number }>;
}

/**
 * Build full neck geometry for the fret range [startFret, startFret+fretCount-1].
 * Real fretboard spacing: each fret's width = previous × 2^(-1/12), scaled to
 * fit USABLE_W. Same math as the original hardcoded 1..15 neck, parameterized.
 */
export function makeGeometry(startFret: number, fretCount: number): FretboardGeometry {
    const fretFrom = startFret;
    const fretTo = startFret + fretCount - 1;

    const rawWidths: number[] = [];
    for (let f = fretFrom; f <= fretTo; f++) rawWidths.push(Math.pow(2, -f / 12));
    const scale = USABLE_W / rawWidths.reduce((a, b) => a + b, 0);
    const edges = [PAD_L];
    for (const w of rawWidths) edges.push(edges[edges.length - 1] + w * scale);
    const fretEdgeX = edges;

    function fretCenterX(f: number) {
        return (fretEdgeX[f - fretFrom] + fretEdgeX[f - fretFrom + 1]) / 2;
    }

    const NECK_L = fretEdgeX[0];
    const NECK_R = fretEdgeX[fretTo - fretFrom + 1];

    const fretLines: Array<{ x: number; isNut: boolean }> = [];
    fretLines.push({ x: PAD_L, isNut: true });
    for (let f = fretFrom; f <= fretTo; f++) {
        fretLines.push({ x: fretEdgeX[f - fretFrom + 1], isNut: false });
    }

    const stringLines: Array<{ y: number; s: number; x1: number; x2: number }> = [];
    for (let s = 1; s <= 6; s++) stringLines.push({ y: stringY(s), s, x1: NECK_L, x2: NECK_R });

    const singleInlays = [3, 5, 7, 9, 15]
        .filter(f => f >= fretFrom && f <= fretTo)
        .map(f => ({ cx: fretCenterX(f), cy: PAD_T + stringH * 2.5 }));
    const doubleInlays = [12]
        .filter(f => f >= fretFrom && f <= fretTo)
        .flatMap(f => [
            { cx: fretCenterX(f), cy: PAD_T + stringH * 1.5 },
            { cx: fretCenterX(f), cy: PAD_T + stringH * 3.5 },
        ]);

    const labeled = new Set([3, 5, 7, 9, 12, 15]);
    const fretNumbers: Array<{ x: number; y: number; n: number }> = [];
    for (let f = fretFrom; f <= fretTo; f++) {
        if (!labeled.has(f)) continue;
        fretNumbers.push({ x: fretCenterX(f), y: VB_H - PAD_B + 18, n: f });
    }

    return {
        startFret: fretFrom,
        fretCount,
        fretEdgeX,
        fretCenterX,
        NECK_L,
        NECK_R,
        fretLines,
        stringLines,
        singleInlays,
        doubleInlays,
        fretNumbers,
    };
}

// ---------------------------------------------------------------------------
// Default geometry — the prog viewer's original 1..15 fret neck. All the
// module-level exports below are computed from makeGeometry so the values are
// identical to the pre-Phase-2 hardcoded versions byte-for-byte.
// ---------------------------------------------------------------------------
const defaultGeometry = makeGeometry(FRET_FROM, FRET_TO - FRET_FROM + 1);

export const fretEdgeX: number[] = defaultGeometry.fretEdgeX;
export function fretCenterX(f: number) { return defaultGeometry.fretCenterX(f); }

// Full-neck extents — must be declared before the IIFEs below that reference them.
export const NECK_L = defaultGeometry.NECK_L;
export const NECK_R = defaultGeometry.NECK_R;

// Full-neck static geometry — drawn once across whole neck; camera pan via excerptViewBox.
export const fretLines = defaultGeometry.fretLines;
export const stringLines = defaultGeometry.stringLines;
export const singleInlays = defaultGeometry.singleInlays;
export const doubleInlays = defaultGeometry.doubleInlays;
export const fretNumbers = defaultGeometry.fretNumbers;

// Fixed virtual width — never changes, only x-origin shifts.
export const EXCERPT_VW = fretEdgeX[FRET_WINDOW] - fretEdgeX[0];
// Max left-edge so the right side of the viewBox never goes past the last fret wire.
export const MAX_SMOOTH_X = fretEdgeX[FRET_TO - FRET_FROM + 1] - EXCERPT_VW;

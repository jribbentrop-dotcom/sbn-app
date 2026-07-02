/**
 * fretString — the single JS/TS authority for the SBN fret-string ↔ diagram_data
 * conversion. Mirrors `app/Services/ChordFretString.php` (keep the two in sync).
 *
 * A **fret string** is exactly 6 characters, one per diagram string 1→6 (low E
 * → high e in SBN's chord-diagram convention):
 *   - `x` (or `X`) → muted string
 *   - `0`          → open string
 *   - `1`–`9`, `a`–`f` → fret number in **hex** (so frets 10–15 stay single-char)
 *
 * `diagram_data` is the object form stored on chord diagrams:
 *   { positions: [{ string, fret }], barres: [], muted: number[], open: number[] }
 *
 * Historically this conversion was copy-pasted across five sites (see
 * SBN-Leadsheet-Reference §10). Everything below is the shared implementation.
 */

export type FretChar = number | 'x';

export interface DiagramData {
    positions: Array<{ string: number; fret: number; finger?: number | string }>;
    barres: Array<{ fret: number; from: number; to: number; finger?: number | string }>;
    muted: number[];
    open: number[];
}

/**
 * Decode a single fret-string character to a fret number.
 * Returns `null` for muted (`x`/`X`) or unparseable input. Open is `0`.
 */
export function parseFretChar(ch: string | undefined): number | null {
    if (!ch || ch === 'x' || ch === 'X') return null;
    const n = parseInt(ch, 16); // handles '0'-'9' and 'a'-'f'
    return Number.isFinite(n) && n >= 0 ? n : null;
}

/**
 * Encode a fret number (or `'x'`) to a single fret-string character.
 * Frets ≥ 10 become hex `a`–`f`.
 */
export function fretToChar(fret: FretChar): string {
    return fret === 'x' ? 'x' : (fret as number).toString(16);
}

/**
 * Convert `diagram_data` (object) → 6-char fret string.
 * Precedence per string: open → position → muted (muted wins if a string is
 * listed in more than one bucket, matching the historical order of overwrites).
 */
export function diagramDataToFretString(data: DiagramData | null | undefined): string {
    const frets: FretChar[] = ['x', 'x', 'x', 'x', 'x', 'x'];
    for (const s of data?.open ?? []) {
        if (s >= 1 && s <= 6) frets[s - 1] = 0;
    }
    for (const pos of data?.positions ?? []) {
        if (pos.string >= 1 && pos.string <= 6) frets[pos.string - 1] = pos.fret;
    }
    for (const s of data?.muted ?? []) {
        if (s >= 1 && s <= 6) frets[s - 1] = 'x';
    }
    return frets.map(fretToChar).join('');
}

/**
 * Convert a 6-char fret string → `diagram_data` (object).
 * `barres` is always empty — a flat fret string carries no barre grouping.
 */
export function fretStringToDiagramData(frets: string): DiagramData {
    const positions: DiagramData['positions'] = [];
    const open: number[] = [];
    const muted: number[] = [];

    for (let i = 0; i < 6; i++) {
        const stringNum = i + 1;
        const fret = parseFretChar(frets[i]);
        if (fret === null) {
            muted.push(stringNum);
        } else if (fret === 0) {
            open.push(stringNum);
        } else {
            positions.push({ string: stringNum, fret });
        }
    }

    return { positions, barres: [], muted, open };
}

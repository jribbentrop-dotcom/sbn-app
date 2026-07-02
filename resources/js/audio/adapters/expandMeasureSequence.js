/**
 * expandMeasureSequence — repeat + volta playback order
 *
 * Takes a flat array of measure objects (each with index, repeatStart,
 * repeatEnd, volta flags) and expands them into an ordered playback sequence
 * that honours standard repeat + volta notation.
 *
 * Standard repeat + first/second ending semantics:
 *
 *   [A] [B repeatStart] [C volta1,repeatEnd] [D volta2]
 *
 *   Playback order: A → B → C → (jump back to B) → B → D → continue
 *   (on the 2nd pass through the loop, the volta-1 bar C is skipped, and the
 *    repeatEnd barline on C is ignored because we never play C on pass 2)
 *
 * Model:
 *  - A "repeat block" opens at a repeatStart bar (or implicitly at bar 0 / the
 *    bar after the previous block) and is driven by a pass counter.
 *  - A bar carrying volta-N only sounds on pass N. On other passes it is
 *    skipped — and if it carried the repeatEnd barline, that barline is
 *    skipped with it (so we don't bounce back forever).
 *  - When we play (don't skip) a bar with repeatEnd: if a volta bracket for the
 *    NEXT pass exists in this block (including the trailing endings that sit
 *    after the repeatEnd barline), jump back to the block start for another
 *    pass. If the block has no voltas at all, repeat exactly once.
 *
 * @param {Array<{
 *   index?: number,
 *   globalIndex?: number,
 *   repeatStart?: boolean,
 *   repeatEnd?: boolean,
 *   volta?: { number: number } | null,
 * }>} measures  — flat array, one entry per bar (in order)
 *
 * @returns {number[]}  ordered list of globalIndex values for the audio engine
 */
export function expandMeasureSequence(measures) {
    return _expand(measures).sequence;
}

/**
 * Like expandMeasureSequence but also returns a parallel `passAtPosition`
 * array: passAtPosition[pos] = the repeat-block pass number active when that
 * play position was emitted. Non-repeated bars get pass 1.
 * Used by Cinema to know which volta ending to show at any play position.
 *
 * @returns {{ sequence: number[], passAtPosition: number[] }}
 */
export function expandMeasureSequenceWithPass(measures) {
    return _expand(measures);
}

/**
 * Flatten a nested `{ sections: [{ measures: [...] }] }` model into the flat
 * bar array that expandMeasureSequence expects, plus a gi → measure lookup.
 *
 * Every caller used to inline `model.sections.flatMap(s => s.measures ?? [])`
 * (and some also rebuilt the gi Map). Centralising it means the repeat/volta
 * flags on each bar are carried through one code path — a site can't silently
 * flatten in a way that drops them and produce a linear timeline.
 *
 * @param {{ sections?: Array<{ measures?: any[] }> }} model
 * @returns {{ flatMeasures: any[], measureByGi: Map<number, any> }}
 */
export function flattenModelMeasures(model) {
    const flatMeasures = [];
    const measureByGi = new Map();
    for (const section of model?.sections ?? []) {
        for (const measure of section.measures ?? []) {
            flatMeasures.push(measure);
            measureByGi.set(measure.index ?? flatMeasures.length - 1, measure);
        }
    }
    return { flatMeasures, measureByGi };
}

/**
 * Repeat + volta-aware playback sequence for a nested model — the flatten +
 * expand pair that view/adapter sites need. Use this instead of hand-rolling
 * the flatten before calling expandMeasureSequence.
 *
 * @param {{ sections?: Array<{ measures?: any[] }> }} model
 * @returns {number[]}
 */
export function expandModelSequence(model) {
    return expandMeasureSequence(flattenModelMeasures(model).flatMeasures);
}

function _expand(measures) {
    if (!measures?.length) return { sequence: [], passAtPosition: [] };

    const gi = (m, i) => m.globalIndex ?? m.index ?? i;
    const sequence = [];
    const passAtPosition = [];

    let i = 0;
    let blockStart = 0;
    let pass = 1;

    const MAX_POSITIONS = measures.length * 64 + 256;

    while (i < measures.length) {
        if (sequence.length > MAX_POSITIONS) break;

        const m = measures[i];

        if (m.repeatStart && i !== blockStart) {
            blockStart = i;
            pass = 1;
        }

        const v = m.volta?.number;
        const skip = v != null && v !== pass;

        if (!skip) {
            sequence.push(gi(m, i));
            passAtPosition.push(pass);

            if (m.repeatEnd) {
                const hasNextVolta  = _blockHasVoltaForPass(measures, blockStart, i, pass + 1);
                const noVoltasAtAll = !_blockHasAnyVolta(measures, blockStart, i);
                if (hasNextVolta || (noVoltasAtAll && pass < 2)) {
                    pass++;
                    i = blockStart;
                    continue;
                }
                blockStart = i + 1;
                pass = 1;
            }
        }

        i++;
    }

    return { sequence, passAtPosition };
}

/**
 * Does this repeat block contain a volta bracket numbered `n`?
 * Scans from `start` through the repeatEnd bar `end`, then keeps scanning
 * across the trailing run of volta bars that sit immediately after `end`
 * (the 2nd / 3rd endings), stopping at the first non-volta bar or a new
 * repeatStart.
 */
function _blockHasVoltaForPass(measures, start, end, n) {
    for (let k = start; k <= end; k++) {
        if (measures[k]?.volta?.number === n) return true;
    }
    for (let k = end + 1; k < measures.length; k++) {
        const mm = measures[k];
        if (!mm?.volta) break;
        if (k !== end + 1 && mm.repeatStart) break;
        if (mm.volta.number === n) return true;
    }
    return false;
}

/** True if any measure in [start, end] carries a volta bracket. */
function _blockHasAnyVolta(measures, start, end) {
    for (let k = start; k <= end; k++) {
        if (measures[k]?.volta) return true;
    }
    return false;
}

/**
 * Build a beat-time lookup table from the expanded sequence.
 * Returns an array where index i = { globalIndex, beatStart }.
 * beatStart is in quarter-note beats from the start of playback.
 *
 * @param {number[]} sequence   — output of expandMeasureSequence
 * @param {number}   beatsPerMeasure
 * @returns {Array<{ globalIndex: number, beatStart: number }>}
 */
export function sequenceToBeatMap(sequence, beatsPerMeasure) {
    return sequence.map((globalIndex, position) => ({
        globalIndex,
        beatStart: position * beatsPerMeasure,
    }));
}

/**
 * Coordinate-system note: the audio engine clock counts **play positions**
 * (the linear, repeat-expanded index — sequence[pos] === the bar's gi at that
 * point), while the score, video-sync mappings, and most UI work in **gi**
 * (global bar index). These helpers convert between the two.
 */

/**
 * Play position → gi. Out-of-range positions clamp to the last bar.
 * @param {number[]} sequence
 * @param {number}   pos  — may be fractional; floored before lookup
 * @returns {number}
 */
export function giAtPosition(sequence, pos) {
    if (!sequence?.length) return Math.max(0, Math.floor(pos || 0));
    const i = Math.max(0, Math.min(sequence.length - 1, Math.floor(pos || 0)));
    return sequence[i];
}

/**
 * gi → the FIRST play position at which that bar sounds. Falls back to `gi`
 * itself when the bar isn't in the sequence (e.g. empty sequence). When a bar
 * repeats, this points at its first occurrence — the start of the phrase
 * containing it, which is the sane target for "jump to this bar".
 * @param {number[]} sequence
 * @param {number}   gi
 * @returns {number}
 */
export function firstPositionForGi(sequence, gi) {
    if (!sequence?.length) return gi;
    const i = sequence.indexOf(gi);
    return i === -1 ? gi : i;
}

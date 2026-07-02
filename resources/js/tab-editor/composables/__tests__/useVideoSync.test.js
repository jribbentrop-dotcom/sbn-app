import { describe, it, expect } from 'vitest';
import { useVideoSync } from '../useVideoSync.js';

/**
 * Focused tests for the pos-pairing logic in `mappingsByPosition` — the heart of
 * AABA repeat support. mappings are keyed by gi (what the author taps); the
 * computed projects them onto play positions in the repeat-expanded sequence,
 * pairing a gi's marks (in videoTime order) with that gi's successive positions.
 *
 * Previously only verified by manual taps (see SBN-Audio-Reference §9 followups).
 */

// wrapCommand stub: just run the mutation (no undo stack in these tests).
const runNow = (_label, _affected, fn) => fn();

/**
 * Mount the composable with a fixed expanded sequence and set of gi-keyed marks.
 * @param {number[]} sequence  play position → gi (the expanded timeline)
 * @param {Array<{measureIndex:number, videoTime:number}>} marks
 */
function mount(sequence, marks) {
    const vs = useVideoSync({}, {
        wrapCommand: runNow,
        getSequence: () => sequence,
    });
    vs.mappings.value = marks.map(m => ({ ...m }));
    return vs;
}

describe('useVideoSync mappingsByPosition', () => {
    it('maps marks straight through when every gi appears once', () => {
        // Through-composed: sequence is identity, one mark per bar.
        const vs = mount([0, 1, 2, 3], [
            { measureIndex: 0, videoTime: 0 },
            { measureIndex: 2, videoTime: 10 },
            { measureIndex: 3, videoTime: 15 },
        ]);
        expect(vs.mappingsByPosition.value).toEqual([
            { pos: 0, videoTime: 0 },
            { pos: 2, videoTime: 10 },
            { pos: 3, videoTime: 15 },
        ]);
    });

    it('assigns AABA per-pass marks to successive play positions of the same gi', () => {
        // A1 = bars 0,1 (positions 0,1); A2 = bars 0,1 again (positions 2,3).
        // gi 0 has two marks (one per pass); they must land on pos 0 and pos 2
        // in videoTime order — NOT both on pos 0.
        const sequence = [0, 1, 0, 1, 2, 3];
        const vs = mount(sequence, [
            { measureIndex: 0, videoTime: 0 },   // pass 1 of bar 0
            { measureIndex: 0, videoTime: 20 },  // pass 2 of bar 0
            { measureIndex: 1, videoTime: 5 },
            { measureIndex: 1, videoTime: 25 },
        ]);
        expect(vs.mappingsByPosition.value).toEqual([
            { pos: 0, videoTime: 0 },
            { pos: 1, videoTime: 5 },
            { pos: 2, videoTime: 20 },
            { pos: 3, videoTime: 25 },
        ]);
    });

    it('pairs marks in videoTime order regardless of insertion order', () => {
        // Marks added out of order — the later videoTime must still map to the
        // later play position.
        const vs = mount([0, 1, 0, 1], [
            { measureIndex: 0, videoTime: 20 },  // inserted first, but pass 2
            { measureIndex: 0, videoTime: 0 },   // inserted second, but pass 1
        ]);
        expect(vs.mappingsByPosition.value).toEqual([
            { pos: 0, videoTime: 0 },
            { pos: 2, videoTime: 20 },
        ]);
    });

    it('collapses surplus marks onto the last play position (more marks than passes)', () => {
        // gi 0 occurs twice (pos 0, 2) but has three marks — the pathological
        // "more taps than passes" case. The surplus mark clamps to pos 2.
        const vs = mount([0, 1, 0, 1], [
            { measureIndex: 0, videoTime: 0 },
            { measureIndex: 0, videoTime: 20 },
            { measureIndex: 0, videoTime: 40 },
        ]);
        expect(vs.mappingsByPosition.value).toEqual([
            { pos: 0, videoTime: 0 },
            { pos: 2, videoTime: 20 },
            { pos: 2, videoTime: 40 },
        ]);
    });

    it('sorts the output by pos, then by videoTime for ties', () => {
        // Two marks share the last position (from the surplus case) — the tie
        // breaks on videoTime so interpolation sees a monotonic list.
        const vs = mount([0, 0], [
            { measureIndex: 0, videoTime: 40 },
            { measureIndex: 0, videoTime: 10 },
        ]);
        expect(vs.mappingsByPosition.value).toEqual([
            { pos: 0, videoTime: 10 },
            { pos: 1, videoTime: 40 },
        ]);
    });

    it('falls back to gi-as-pos for a mark whose gi is absent from the sequence', () => {
        // gi 9 never plays in the sequence — firstPositionForGi returns the gi
        // itself, so it lands at pos 9 rather than being dropped.
        const vs = mount([0, 1, 2], [
            { measureIndex: 1, videoTime: 5 },
            { measureIndex: 9, videoTime: 99 },
        ]);
        expect(vs.mappingsByPosition.value).toEqual([
            { pos: 1, videoTime: 5 },
            { pos: 9, videoTime: 99 },
        ]);
    });

    it('falls back to gi-as-pos when there is no sequence at all', () => {
        // Empty sequence → early return branch: pos = measureIndex, sorted.
        const vs = mount([], [
            { measureIndex: 3, videoTime: 15 },
            { measureIndex: 0, videoTime: 0 },
        ]);
        expect(vs.mappingsByPosition.value).toEqual([
            { pos: 0, videoTime: 0 },
            { pos: 3, videoTime: 15 },
        ]);
    });

    it('returns an empty list when there are no marks', () => {
        const vs = mount([0, 1, 2], []);
        expect(vs.mappingsByPosition.value).toEqual([]);
    });
});

import { describe, it, expect } from 'vitest';
import { expandMeasureSequence, sequenceToBeatMap } from '../expandMeasureSequence.js';

const M = (i, f = {}) => ({ index: i, ...f });

describe('expandMeasureSequence', () => {
    it('returns measures in order when there are no markers', () => {
        expect(expandMeasureSequence([M(0), M(1), M(2)])).toEqual([0, 1, 2]);
    });

    it('handles a simple repeat block', () => {
        // A [B .. C] D  →  A B C B C D
        expect(expandMeasureSequence([
            M(0), M(1, { repeatStart: true }), M(2, { repeatEnd: true }), M(3),
        ])).toEqual([0, 1, 2, 1, 2, 3]);
    });

    it('treats a bare repeatEnd as an implicit repeat from bar 0', () => {
        expect(expandMeasureSequence([
            M(0), M(1, { repeatEnd: true }), M(2),
        ])).toEqual([0, 1, 0, 1, 2]);
    });

    it('does not re-enter the block when repeatStart is bar 0', () => {
        expect(expandMeasureSequence([
            M(0, { repeatStart: true }), M(1, { repeatEnd: true }), M(2),
        ])).toEqual([0, 1, 0, 1, 2]);
    });

    it('plays a 1st ending on pass 1 and a 2nd ending on pass 2', () => {
        // A [B [C v1, rE] [D v2]]  →  A B C B D
        expect(expandMeasureSequence([
            M(0),
            M(1, { repeatStart: true }),
            M(2, { volta: { number: 1 }, repeatEnd: true }),
            M(3, { volta: { number: 2 } }),
        ])).toEqual([0, 1, 2, 1, 3]);
    });

    it('handles multi-bar voltas', () => {
        // A [B C [D v1, rE] [E v2 F v2]]  →  A B C D B C E F
        expect(expandMeasureSequence([
            M(0),
            M(1, { repeatStart: true }),
            M(2),
            M(3, { volta: { number: 1 }, repeatEnd: true }),
            M(4, { volta: { number: 2 } }),
            M(5, { volta: { number: 2 } }),
        ])).toEqual([0, 1, 2, 3, 1, 2, 4, 5]);
    });

    it('handles two consecutive repeat blocks', () => {
        expect(expandMeasureSequence([
            M(0, { repeatStart: true }), M(1, { repeatEnd: true }),
            M(2, { repeatStart: true }), M(3, { repeatEnd: true }),
        ])).toEqual([0, 1, 0, 1, 2, 3, 2, 3]);
    });

    it('falls back to array position when index/globalIndex is absent', () => {
        expect(expandMeasureSequence([{}, {}, { repeatEnd: true }])).toEqual([0, 1, 2, 0, 1, 2]);
    });

    it('builds a beat map at the right offsets', () => {
        expect(sequenceToBeatMap([0, 1, 0, 1, 2], 4)).toEqual([
            { globalIndex: 0, beatStart: 0 },
            { globalIndex: 1, beatStart: 4 },
            { globalIndex: 0, beatStart: 8 },
            { globalIndex: 1, beatStart: 12 },
            { globalIndex: 2, beatStart: 16 },
        ]);
    });
});

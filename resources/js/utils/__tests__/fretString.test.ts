import { describe, it, expect } from 'vitest';
import {
    parseFretChar,
    fretToChar,
    diagramDataToFretString,
    fretStringToDiagramData,
} from '../fretString.ts';

describe('parseFretChar', () => {
    it('returns null for muted', () => {
        expect(parseFretChar('x')).toBeNull();
        expect(parseFretChar('X')).toBeNull();
    });
    it('returns null for empty/undefined', () => {
        expect(parseFretChar('')).toBeNull();
        expect(parseFretChar(undefined)).toBeNull();
    });
    it('parses open and single-digit frets', () => {
        expect(parseFretChar('0')).toBe(0);
        expect(parseFretChar('5')).toBe(5);
        expect(parseFretChar('9')).toBe(9);
    });
    it('parses hex frets 10–15', () => {
        expect(parseFretChar('a')).toBe(10);
        expect(parseFretChar('c')).toBe(12);
        expect(parseFretChar('f')).toBe(15);
    });
});

describe('fretToChar', () => {
    it('encodes muted', () => {
        expect(fretToChar('x')).toBe('x');
    });
    it('encodes single-digit frets', () => {
        expect(fretToChar(0)).toBe('0');
        expect(fretToChar(7)).toBe('7');
    });
    it('encodes frets ≥ 10 as hex', () => {
        expect(fretToChar(10)).toBe('a');
        expect(fretToChar(15)).toBe('f');
    });
});

describe('fretStringToDiagramData', () => {
    it('splits a mixed string into open / positions / muted', () => {
        // x32010 → E-string muted, A=3, D=2, G open, B=1, e open
        expect(fretStringToDiagramData('x32010')).toEqual({
            positions: [
                { string: 2, fret: 3 },
                { string: 3, fret: 2 },
                { string: 5, fret: 1 },
            ],
            barres: [],
            muted: [1],
            open: [4, 6],
        });
    });
    it('decodes hex frets', () => {
        expect(fretStringToDiagramData('acfx00').positions).toEqual([
            { string: 1, fret: 10 },
            { string: 2, fret: 12 },
            { string: 3, fret: 15 },
        ]);
    });
    it('treats a short/undefined char as muted', () => {
        expect(fretStringToDiagramData('0').muted).toEqual([2, 3, 4, 5, 6]);
    });
});

describe('diagramDataToFretString', () => {
    it('round-trips with fretStringToDiagramData', () => {
        for (const s of ['x32010', 'acfx00', 'xx0232', '000000', 'xxxxxx']) {
            expect(diagramDataToFretString(fretStringToDiagramData(s))).toBe(s);
        }
    });
    it('handles null/empty data', () => {
        expect(diagramDataToFretString(null)).toBe('xxxxxx');
        expect(diagramDataToFretString({ positions: [], barres: [], muted: [], open: [] }))
            .toBe('xxxxxx');
    });
    it('muted wins when a string appears in multiple buckets', () => {
        // string 1 listed as both open and muted → muted overwrites (last)
        expect(diagramDataToFretString({
            positions: [], barres: [], open: [1], muted: [1],
        })).toBe('xxxxxx');
    });
});

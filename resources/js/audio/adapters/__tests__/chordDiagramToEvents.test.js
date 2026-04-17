import { describe, it, expect } from 'vitest';
import { chordDiagramToEvents } from '../chordDiagramToEvents.js';
import model from './chordDiagramToEvents/model.json' assert { type: 'json' };
import context from './chordDiagramToEvents/context.json' assert { type: 'json' };
import expected from './chordDiagramToEvents/events.json' assert { type: 'json' };

describe('chordDiagramToEvents', () => {
    it('produces the golden fixture output', () => {
        const result = chordDiagramToEvents(model, context);
        // Strip _comment fields before comparing
        const clean = result.map(e => { const c = { ...e }; delete c._comment; return c; });
        const exp = expected.map(e => { const c = { ...e }; delete c._comment; return c; });
        expect(clean).toEqual(exp);
    });

    it('returns empty array for fully muted chord', () => {
        const m = {
            ...model,
            diagram_data: {
                ...model.diagram_data,
                positions: [],
                open: [],
                muted: [1, 2, 3, 4, 5, 6]
            }
        };
        expect(chordDiagramToEvents(m, context)).toEqual([]);
    });

    it('respects barre definitions', () => {
        const m = {
            id: 2,
            diagram_data: {
                positions: [],
                open: [],
                muted: [],
                barres: [{ fret: 3, fromString: 1, toString: 6 }]
            }
        };
        const result = chordDiagramToEvents(m, { startBeat: 0, durationBeats: 2 });
        // All 6 strings should be fretted at 3
        expect(result).toHaveLength(6);
        // String 1 (Low E) at fret 3 = 40 + 3 = 43
        expect(result[0].pitch).toBe(43);
        // String 6 (Hi E) at fret 3 = 64 + 3 = 67
        expect(result[5].pitch).toBe(67);
    });
});

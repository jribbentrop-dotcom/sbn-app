import { describe, it, expect } from 'vitest';
import { chordProgressionToEvents } from '../chordProgressionToEvents.js';
import model from './chordProgressionToEvents/model.json' assert { type: 'json' };
import context from './chordProgressionToEvents/context.json' assert { type: 'json' };
import expected from './chordProgressionToEvents/events.json' assert { type: 'json' };

describe('chordProgressionToEvents', () => {
    it('produces the golden fixture output', () => {
        const result = chordProgressionToEvents(model, context);
        // Strip _comment fields before comparing
        const clean = result.map(e => { const c = { ...e }; delete c._comment; return c; });
        const exp = expected.map(e => { const c = { ...e }; delete c._comment; return c; });
        expect(clean).toEqual(exp);
    });

    it('returns empty array for unknown key', () => {
        const m = { ...model, key: 'Z#' };
        expect(chordProgressionToEvents(m, context)).toEqual([]);
    });

    it('ctx.startBeat offsets all event times correctly', () => {
        const offset = 8;
        const result = chordProgressionToEvents(model, { ...context, startBeat: offset });
        // First chord should start at offset
        const firstChordEvents = result.filter(e => e.sourceId === 'chord-0');
        expect(firstChordEvents[0].time).toBe(offset);
        // Second chord starts at offset + 4 (first chord duration)
        const secondChordEvents = result.filter(e => e.sourceId === 'chord-1');
        expect(secondChordEvents[0].time).toBe(offset + 4);
    });
});

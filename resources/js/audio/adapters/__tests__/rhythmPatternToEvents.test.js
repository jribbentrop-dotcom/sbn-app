import { describe, it, expect } from 'vitest';
import { rhythmPatternToEvents } from '../rhythmPatternToEvents.js';
import model from './rhythmPatternToEvents/model.json' assert { type: 'json' };
import context from './rhythmPatternToEvents/context.json' assert { type: 'json' };
import expected from './rhythmPatternToEvents/events.json' assert { type: 'json' };

describe('rhythmPatternToEvents', () => {
    it('produces the golden fixture output', () => {
        const result = rhythmPatternToEvents(model, context);
        // Strip _comment fields before comparing
        const clean = result.map(e => { const c = { ...e }; delete c._comment; return c; });
        const exp = expected.map(e => { const c = { ...e }; delete c._comment; return c; });
        expect(clean).toEqual(exp);
    });

    it('returns empty array for all-rest pattern', () => {
        const m = {
            ...model,
            thumb: '................',
            fingers: '................',
            percTop: 'shaker',
            percBass: 'kick'
        };
        expect(rhythmPatternToEvents(m, context)).toEqual([]);
    });

    it('skips percussion events when percTop is none', () => {
        const m = { ...model, percTop: 'none' };
        const result = rhythmPatternToEvents(m, context);
        expect(result.every(e => e.sample !== model.percTop)).toBe(true);
    });
});

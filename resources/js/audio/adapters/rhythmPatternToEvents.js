/**
 * RhythmPattern model → EngineEvent[] adapter.
 * Pure function: no side effects, no Tone.js imports, no DOM access.
 *
 * Converts a RhythmPattern (from Laravel backend) into EngineEvents for the audio scheduler.
 *
 * @typedef {import('../types.js').EngineEvent} EngineEvent
 * @typedef {import('../types.js').Beats} Beats
 */

/** @type {Record<string, number>} */
const GRID_STEP_BEATS = {
    eighth: 0.5,
    sixteenth: 0.25,
    triplet: 1 / 3,
};

/**
 * Convert a RhythmPattern model to engine events.
 *
 * @param {Object} model — RhythmPattern from backend
 * @param {string} model.thumb — pattern string (x=soft, X=accent, .=rest)
 * @param {string} model.fingers — pattern string
 * @param {string} model.percTop — instrument for fingers (e.g. 'shaker') or 'none'
 * @param {string} model.percBass — instrument for thumb (e.g. 'kick') or 'none'
 * @param {number} model.beats — total steps in pattern
 * @param {string} model.gridType — 'eighth' | 'sixteenth' | 'triplet'
 * @param {Object} [ctx] — AdapterContext
 * @param {Beats} [ctx.startBeat=0] — offset for concatenation
 * @returns {EngineEvent[]}
 */
export function rhythmPatternToEvents(model, ctx = {}) {
    /** @type {EngineEvent[]} */
    const out = [];

    const stepBeats = GRID_STEP_BEATS[model.gridType] ?? 0.25;
    const startBeat = ctx.startBeat ?? 0;

    const hasThumb = model.percBass && model.percBass !== 'none';
    const hasFingers = model.percTop && model.percTop !== 'none';

    // Parse thumb (bass) pattern
    if (hasThumb && model.thumb) {
        for (let i = 0; i < model.thumb.length; i++) {
            const char = model.thumb[i];
            if (char === '.') continue;

            const isAccent = char === 'X';
            out.push({
                time: startBeat + i * stepBeats,
                voice: 'percussion',
                sample: model.percBass,
                variant: isAccent ? 'accent' : 'soft',
                velocity: isAccent ? 1.0 : 0.85,
                duration: stepBeats,
                sourceId: `step-${i}`,
            });
        }
    }

    // Parse fingers (treble) pattern
    if (hasFingers && model.fingers) {
        for (let i = 0; i < model.fingers.length; i++) {
            const char = model.fingers[i];
            if (char === '.') continue;

            const isAccent = char === 'X';
            out.push({
                time: startBeat + i * stepBeats,
                voice: 'percussion',
                sample: model.percTop,
                variant: isAccent ? 'accent' : 'soft',
                velocity: isAccent ? 1.0 : 0.78,
                duration: stepBeats,
                sourceId: `step-${i}`,
            });
        }
    }

    // Sort by time ascending
    out.sort((a, b) => a.time - b.time);

    return out;
}

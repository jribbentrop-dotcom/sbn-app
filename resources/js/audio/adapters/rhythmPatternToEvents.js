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
 * Standard open-string MIDI for classical right-hand finger assignment.
 * Tab-editor convention (pitchToMidi.js): string 1 = high e, string 6 = low E.
 * p (thumb) → D string (string 4, D3=50) — canonical bass string for p-i-m-a in position
 * i → G string (string 3, G3=55)
 * m → B string (string 2, B3=59)
 * a → high-e string (string 1, E4=64)
 * Callers can override via ctx.chordMidi to play actual chord tones.
 */
const FINGER_DEFAULT_MIDI = {
    thumb:        50, // D3 — string 4 (p, bass)
    fingerIndex:  55, // G3 — string 3 (i)
    fingerMiddle: 59, // B3 — string 2 (m)
    fingerRing:   64, // E4 — string 1 / high e (a)
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
 * @param {boolean} [model.pickingMode] — when true, use fingerIndex/Middle/Ring + nylon voice
 * @param {string|null} [model.fingerIndex]  — picking pattern for index finger
 * @param {string|null} [model.fingerMiddle] — picking pattern for middle finger
 * @param {string|null} [model.fingerRing]   — picking pattern for ring finger
 * @param {Object} [ctx] — AdapterContext
 * @param {Beats} [ctx.startBeat=0] — offset for concatenation
 * @param {Object} [ctx.chordMidi] — per-finger MIDI overrides: { fingerIndex, fingerMiddle, fingerRing }
 *   Pass the actual fretted MIDI from the active chord diagram so picks play real chord tones.
 *   Falls back to open-string defaults when absent.
 * @returns {EngineEvent[]}
 */
export function rhythmPatternToEvents(model, ctx = {}) {
    /** @type {EngineEvent[]} */
    const out = [];

    const stepBeats = GRID_STEP_BEATS[model.gridType] ?? 0.25;
    const startBeat = ctx.startBeat ?? 0;
    // Notes ring slightly beyond the grid step so adjacent picks overlap naturally.
    const noteDuration = stepBeats * 2;

    const hasThumb = model.thumb && model.thumb.replace(/\./g, '').length > 0;

    // ── Thumb (bass) ─────────────────────────────────────────────────────────
    if (hasThumb) {
        for (let i = 0; i < model.thumb.length; i++) {
            const char = model.thumb[i];
            if (char === '.') continue;
            const isAccent = char === 'X';

            if (model.pickingMode) {
                // Picking mode: thumb plays a pitched bass note via NylonSampler
                const chordMidi = ctx.chordMidi ?? {};
                const midi = chordMidi.thumb ?? FINGER_DEFAULT_MIDI.thumb;
                out.push({
                    time: startBeat + i * stepBeats,
                    voice: 'nylon',
                    pitch: midi,
                    velocity: isAccent ? 0.9 : 0.75,
                    duration: noteDuration,
                    sourceId: `step-${i}`,
                });
            } else if (model.percBass && model.percBass !== 'none') {
                // Standard mode: percussion sample
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
    }

    if (model.pickingMode) {
        // ── Picking mode: i / m / a → pitched nylon events ──────────────────
        const chordMidi = ctx.chordMidi ?? {};
        const fingers = [
            { pattern: model.fingerIndex,  field: 'fingerIndex'  },
            { pattern: model.fingerMiddle, field: 'fingerMiddle' },
            { pattern: model.fingerRing,   field: 'fingerRing'   },
        ];
        for (const { pattern, field } of fingers) {
            if (!pattern) continue;
            const midi = chordMidi[field] ?? FINGER_DEFAULT_MIDI[field];
            for (let i = 0; i < pattern.length; i++) {
                const char = pattern[i];
                if (char === '.') continue;
                const isAccent = char === 'X';
                out.push({
                    time: startBeat + i * stepBeats,
                    voice: 'nylon',
                    pitch: midi,
                    velocity: isAccent ? 0.85 : 0.65,
                    duration: noteDuration,
                    sourceId: `step-${i}`,
                });
            }
        }
    } else {
        // ── Standard mode: fingers row → percussion ──────────────────────────
        const hasFingers = model.percTop && model.percTop !== 'none';
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
    }

    // Sort by time ascending
    out.sort((a, b) => a.time - b.time);

    return out;
}

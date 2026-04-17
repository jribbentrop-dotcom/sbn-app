/**
 * ChordProgression model → EngineEvent[] adapter.
 * Pure function: no side effects, no Tone.js imports, no DOM access.
 *
 * Converts a resolved chord progression (Roman numerals + key + quality + duration)
 * into EngineEvents. The model is assembled by the Vue composable.
 *
 * @typedef {import('../types.js').EngineEvent} EngineEvent
 * @typedef {import('../types.js').Beats} Beats
 */

/** @type {Record<string, number>} — Scale degrees in semitones */
const NUMERAL_TO_DEGREE = {
    'I': 0, 'i': 0,
    'II': 2, 'ii': 2,
    'III': 4, 'iii': 4,
    'IV': 5, 'iv': 5,
    'V': 7, 'v': 7,
    'VI': 9, 'vi': 9,
    'VII': 11, 'vii': 11,
    'bII': 1, 'bIII': 3, 'bVI': 8, 'bVII': 10,
    '#IV': 6, '#I': 1,
};

/** @type {Record<string, number[]>} — Intervals in semitones above root */
const QUALITY_INTERVALS = {
    maj: [0, 4, 7],
    min: [0, 3, 7],
    maj7: [0, 4, 7, 11],
    m7: [0, 3, 7, 10],
    dom7: [0, 4, 7, 10],
    m7b5: [0, 3, 6, 10],
    o7: [0, 3, 6, 9],
    maj6: [0, 4, 7, 9],
    m6: [0, 3, 7, 9],
    sus4: [0, 5, 7],
    sus2: [0, 2, 7],
    add9: [0, 2, 4, 7],
    aug: [0, 4, 8],
    dim: [0, 3, 6],
    5: [0, 7],
};

/** @type {Record<string, number>} — Root MIDI for each key (octave 3) */
const KEY_ROOT_MIDI = {
    'C': 48, 'C#': 49, 'Db': 49, 'D': 50, 'D#': 51, 'Eb': 51,
    'E': 52, 'F': 53, 'F#': 54, 'Gb': 54, 'G': 55, 'G#': 56, 'Ab': 56,
    'A': 57, 'A#': 58, 'Bb': 58, 'B': 59,
};

/**
 * Parse a Roman numeral to scale degree (semitones from tonic).
 * @param {string} numeral — e.g. 'ii', 'V', 'bVII', '#IV'
 * @returns {number|null}
 */
function parseNumeral(numeral) {
    return NUMERAL_TO_DEGREE[numeral] ?? null;
}

/**
 * Convert a ChordProgression model to engine events.
 *
 * @param {Object} model — assembled progression model
 * @param {string} model.numerals — comma-separated, e.g. "ii,V,I"
 * @param {string} model.key — e.g. "C"
 * @param {string[]} model.qualityPerChord — e.g. ["m7", "dom7", "maj7"]
 * @param {number[]} model.beatsPerChord — e.g. [4, 4, 8]
 * @param {Object} [ctx] — AdapterContext
 * @param {Beats} [ctx.startBeat=0] — offset for the entire progression
 * @returns {EngineEvent[]}
 */
export function chordProgressionToEvents(model, ctx = {}) {
    /** @type {EngineEvent[]} */
    const out = [];

    const startBeat = ctx.startBeat ?? 0;
    const rootMidi = KEY_ROOT_MIDI[model.key];

    if (rootMidi == null) {
        console.warn(`[chordProgressionToEvents] Unknown key: ${model.key}`);
        return out;
    }

    const numerals = model.numerals.split(',');

    let currentBeat = startBeat;

    for (let i = 0; i < numerals.length; i++) {
        const numeral = numerals[i].trim();
        const degree = parseNumeral(numeral);

        if (degree == null) {
            console.warn(`[chordProgressionToEvents] Unknown numeral: ${numeral}`);
            continue;
        }

        const quality = model.qualityPerChord[i] ?? 'maj';
        const intervals = QUALITY_INTERVALS[quality] ?? QUALITY_INTERVALS.maj;
        const duration = model.beatsPerChord[i] ?? 4;

        const chordRoot = rootMidi + degree;
        const sourceId = `chord-${i}`;

        // Emit one event per chord tone
        for (const interval of intervals) {
            out.push({
                time: currentBeat,
                voice: 'pitched',
                pitch: chordRoot + interval,
                duration,
                velocity: 0.8,
                sourceId,
            });
        }

        // Advance beat cursor for next chord
        currentBeat += duration;
    }

    // Within each chord, events are already sorted by interval (ascending).
    // Overall sort by time then pitch for determinism.
    out.sort((a, b) => {
        if (a.time !== b.time) return a.time - b.time;
        return a.pitch - b.pitch;
    });

    return out;
}

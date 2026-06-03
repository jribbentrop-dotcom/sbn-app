/**
 * Chord voicings → EngineEvent[] adapter.
 * Pure function: no side effects, no Tone.js imports, no DOM access.
 *
 * Converts the tab model's chord voicings into pitched EngineEvents.
 * Multiple chords per measure are distributed evenly across the measure beats.
 * Used for chord-view playback in TabEditor.
 *
 * Repeat + volta: the measure sequence is expanded via expandMeasureSequence
 * before building events, so repeats with 1st/2nd endings are reflected in
 * the event timeline. A measure that appears twice in the sequence gets two
 * sets of events at different beat offsets.
 *
 * Voicing lookup key: "{chordName}@{globalMeasureIndex}.{chordSlot}"
 * Frets format: 6-char string, index 0 = string 1 (Low E) … index 5 = string 6 (Hi E).
 *   'x' = muted/not played.  '0' = open.  '1'-'9' = fret.  'a'-'f' = fret 10-15 (hex).
 *   Matches the format produced by _diagramDataToFrets() in useVoicingPickerStore.js
 *   and the WP sbn-audio.js getNotesFromFrets() parser.
 *
 * Standard tuning (string 1–6):
 *   1 Low E = MIDI 40,  2 A = 45,  3 D = 50,
 *   4 G = 55,           5 B = 59,  6 Hi E = 64
 *
 * @typedef {import('../types.js').EngineEvent} EngineEvent
 * @typedef {import('../types.js').Beats} Beats
 */

import { expandMeasureSequence } from './expandMeasureSequence.js';

const OPEN_STRING_MIDI = [null, 40, 45, 50, 55, 59, 64]; // 1-indexed
const TICKS_PER_BEAT = 480;

/**
 * Parse one character from a fret string.
 * Returns the fret number, or null if the string is muted/invalid.
 * Mirrors WP sbn-audio.js fretToNote() + sbn-chord-card.js parseFretString().
 * @param {string} ch
 * @returns {number|null}
 */
function parseFretChar(ch) {
    if (!ch || ch === 'x' || ch === 'X') return null;
    const n = parseInt(ch, 16); // handles '0'-'9' and 'a'-'f'
    return Number.isFinite(n) && n >= 0 ? n : null;
}

/**
 * @param {Object} model     The reactive tab model from useTabModel.
 * @param {Object} [ctx]     AdapterContext overrides.
 * @param {Beats}  [ctx.startBeat=0]
 * @returns {EngineEvent[]}
 */
export function chordVoicingsToEvents(model, ctx = {}) {
    if (!model?.sections?.length) return [];

    const startBeat     = ctx.startBeat ?? 0;
    const voicings      = model.chordVoicings ?? {};
    const beatsPerMeasure = (model.ticksPerMeasure ?? 1920) / TICKS_PER_BEAT;

    // Build flat measure list and a gi → measure lookup
    const flatMeasures = [];
    const measureByGi  = new Map();
    for (const section of model.sections) {
        for (const measure of section.measures) {
            flatMeasures.push(measure);
            measureByGi.set(measure.index ?? flatMeasures.length - 1, measure);
        }
    }

    // Expand the sequence respecting repeats and voltas
    const sequence = expandMeasureSequence(flatMeasures);

    // Build a per-play-position beat offset table so pickup bars (which have
    // fewer beats than the global time signature) don't leave a gap in the timeline.
    const positionBeatStart = [];
    let beatCursor = startBeat;
    for (const gi of sequence) {
        positionBeatStart.push(beatCursor);
        const m = measureByGi.get(gi);
        beatCursor += m?.pickupBeats ?? beatsPerMeasure;
    }

    /** @type {EngineEvent[]} */
    const out = [];

    sequence.forEach((globalIndex, playPosition) => {
        const measure = measureByGi.get(globalIndex);
        if (!measure) return;

        const chordNames = measure.chordNames ?? [];
        const chordCount = chordNames.length;
        if (chordCount === 0) return;

        const measureStartBeat = positionBeatStart[playPosition];

        const chordOffsets    = measure.chordOffsets;
        const chordBeats      = measure.chordBeats;
        const hasOffsets      = Array.isArray(chordOffsets) && chordOffsets.length === chordCount;
        const hasBeats        = Array.isArray(chordBeats)   && chordBeats.length   === chordCount;
        const effectiveBeats  = measure.pickupBeats ?? beatsPerMeasure;
        const evenBeats       = effectiveBeats / chordCount;

        for (let slotIndex = 0; slotIndex < chordCount; slotIndex++) {
            const chordName = chordNames[slotIndex];
            if (!chordName) continue;

            const slotKey  = `${chordName}@${globalIndex}.${slotIndex}`;
            const slot0Key = `${chordName}@${globalIndex}.0`;
            const voicing  = voicings[slotKey] ?? voicings[slot0Key] ?? voicings[chordName];

            if (voicing?.frets?.length) {
                const offsetBeats = hasOffsets ? chordOffsets[slotIndex] : slotIndex * evenBeats;
                const duration    = hasBeats   ? chordBeats[slotIndex]   : evenBeats;
                const chordBeat   = measureStartBeat + offsetBeats;

                for (let i = 0; i < 6; i++) {
                    const fret = parseFretChar(voicing.frets[i]);
                    if (fret === null) continue;

                    const stringNum = i + 1;
                    const midi = OPEN_STRING_MIDI[stringNum] + fret;

                    out.push({
                        time:     chordBeat,
                        voice:    'pitched',
                        pitch:    midi,
                        duration,
                        velocity: 0.8,
                        sourceId: `measure-${globalIndex}-pos-${playPosition}-slot-${slotIndex}`,
                    });
                }
            }
        }
    });

    out.sort((a, b) => a.time - b.time);
    return out;
}

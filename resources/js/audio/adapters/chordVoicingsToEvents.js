/**
 * Chord voicings → EngineEvent[] adapter.
 * Pure function: no side effects, no Tone.js imports, no DOM access.
 *
 * Converts the tab model's chord voicings (one per measure) into pitched
 * EngineEvents — one strum per measure that has a voicing assigned.
 * Used for chord-view playback in TabEditor.
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

    /** @type {EngineEvent[]} */
    const out = [];

    let globalMeasureIndex = 0;

    for (const section of model.sections) {
        for (const measure of section.measures) {
            const chordName = measure.chordNames?.[0];

            if (chordName) {
                // Try voicing key for chord slot 0 at this measure
                const key = `${chordName}@${globalMeasureIndex}.0`;
                // Fall back to global key ("Am") if no per-measure key ("Am@3.0") exists.
                // Global voicings are assigned from VoicingOverview (one for all occurrences).
                const voicing = voicings[key] ?? voicings[chordName];

                if (voicing?.frets?.length) {
                    const measureBeat = startBeat + globalMeasureIndex * beatsPerMeasure;

                    for (let i = 0; i < 6; i++) {
                        const fret = parseFretChar(voicing.frets[i]);
                        if (fret === null) continue;

                        const stringNum = i + 1; // frets[0] = string 1 (Low E)
                        const midi = OPEN_STRING_MIDI[stringNum] + fret;

                        out.push({
                            time:     measureBeat,
                            voice:    'pitched',
                            pitch:    midi,
                            duration: beatsPerMeasure,
                            velocity: 0.8,
                            sourceId: `measure-${globalMeasureIndex}`,
                        });
                    }
                }
            }

            globalMeasureIndex++;
        }
    }

    out.sort((a, b) => a.time - b.time);
    return out;
}

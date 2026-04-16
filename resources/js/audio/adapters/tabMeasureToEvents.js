/**
 * Tab model → EngineEvent[] adapter.
 * Pure function: no side effects, no Tone.js imports, no DOM access.
 *
 * Input shape (from useTabModel.js):
 *   model.sections[].measures[].events[] → each event has:
 *     { tick, ticks, voice, isRest, notes: [{ string, fret, pitch, octave, tieStart, tieStop }],
 *       tieStart, tieStop, id }
 *   model.timeSignature — e.g. "4/4"
 *
 * Output: EngineEvent[] sorted by time, per docs/audio-engine-contract.md §3.
 *
 * @typedef {import('../types.js').EngineEvent} EngineEvent
 * @typedef {import('../types.js').Beats} Beats
 */

import { noteToMidi } from './pitchToMidi.js';

const TICKS_PER_BEAT = 480; // matches tab-editor/utils/constants.js TICKS.perBeat

/**
 * Convert a full tab model to engine events.
 *
 * @param {Object} model  The reactive tab model from useTabModel.
 * @param {Object} [ctx]  AdapterContext overrides.
 * @param {Beats}  [ctx.startBeat=0]
 * @returns {EngineEvent[]}
 */
export function tabModelToEvents(model, ctx = {}) {
    if (!model?.sections?.length) return [];
    const startBeat = ctx.startBeat ?? 0;
    /** @type {EngineEvent[]} */
    const out = [];

    for (const section of model.sections) {
        for (const measure of section.measures) {
            for (const ev of measure.events) {
                if (ev.isRest) continue;
                if (!ev.notes?.length) continue;

                const beatTime = startBeat + ev.tick / TICKS_PER_BEAT;
                const beatDur  = ev.ticks / TICKS_PER_BEAT;

                for (const note of ev.notes) {
                    // Skip notes that are tie continuations — the original onset
                    // already covers the full duration via tieNext chaining.
                    if (note.tieStop && !note.tieStart) continue;

                    const midi = noteToMidi(note);
                    if (midi == null) continue;

                    // If this note ties forward, we need the total tied duration.
                    // For the Phase 7C spike we emit the single-event duration;
                    // full tie-chain resolution can land iteratively.
                    const duration = beatDur;

                    out.push({
                        time:     beatTime,
                        voice:    'pitched',
                        pitch:    midi,
                        duration,
                        velocity: 0.8,
                        tieNext:  note.tieStart || false,
                        sourceId: ev.id || null,
                    });
                }
            }
        }
    }

    out.sort((a, b) => a.time - b.time);
    return out;
}

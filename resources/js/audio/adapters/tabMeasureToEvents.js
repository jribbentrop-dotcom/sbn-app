/**
 * Tab model → EngineEvent[] adapter.
 * Pure function: no side effects, no Tone.js imports, no DOM access.
 *
 * Input shape (from useTabModel.js):
 *   model.sections[].measures[].events[] → each event has:
 *     { tick, tickInMeasure, ticks, voice, isRest, notes: [{ string, fret, pitch, octave, tieStart, tieStop }],
 *       tieStart, tieStop, id }
 *   model.timeSignature — e.g. "4/4"
 *
 * Repeat + volta: the measure sequence is expanded via expandMeasureSequence
 * before building events, so repeats with 1st/2nd endings are reflected in the
 * event timeline (a bar that plays twice gets two sets of events). This MUST
 * match what chordVoicingsToEvents does — otherwise the two adapters disagree
 * on the timeline length and you hear a doubled playhead.
 *
 * @typedef {import('../types.js').EngineEvent} EngineEvent
 * @typedef {import('../types.js').Beats} Beats
 */

import { noteToMidi } from './pitchToMidi.js';
import { expandMeasureSequence, flattenModelMeasures } from './expandMeasureSequence.js';

const TICKS_PER_BEAT = 480; // matches tab-editor/utils/constants.js TICKS.perBeat

/**
 * Convert a full tab model to engine events.
 *
 * @param {Object} model  The reactive tab model from useTabModel.
 * @param {Object} [ctx]  AdapterContext overrides.
 * @param {Beats}  [ctx.startBeat=0]
 * @param {string} [ctx.voice='pitched']  Voice name passed to the engine ('pitched' or 'nylon').
 * @returns {EngineEvent[]}
 */
export function tabModelToEvents(model, ctx = {}) {
    if (!model?.sections?.length) return [];
    const startBeat = ctx.startBeat ?? 0;
    const voice = ctx.voice ?? 'pitched';
    const tuning = ctx.tuning ?? 'standard';
    const beatsPerMeasure = (model.ticksPerMeasure ?? 1920) / TICKS_PER_BEAT;

    // Flat measure list + gi → measure lookup.
    const { flatMeasures, measureByGi } = flattenModelMeasures(model);

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

        const measureStartBeat = positionBeatStart[playPosition];

        for (const ev of measure.events) {
            if (ev.isRest) continue;
            if (!ev.notes?.length) continue;

            // Use the in-measure tick so the event re-times correctly when the
            // measure appears at a new play position. Fall back to the absolute
            // tick modulo a measure if tickInMeasure isn't populated.
            const tpm = model.ticksPerMeasure ?? 1920;
            const tickInMeasure = ev.tickInMeasure ?? ((ev.tick ?? 0) % tpm);
            const beatTime = measureStartBeat + tickInMeasure / TICKS_PER_BEAT;
            const beatDur  = (ev.ticks ?? 0) / TICKS_PER_BEAT;

            // Grace notes: emit as very short events just before the principal beat.
            if (ev.graceNotes?.length) {
                const GRACE_BEATS = 0.0625; // ~64th note; steal perceptually before the beat
                const n = ev.graceNotes.length;
                ev.graceNotes.forEach((g, gi) => {
                    const midi = noteToMidi(g, tuning);
                    if (midi == null) return;
                    const offset = (n - gi) * GRACE_BEATS; // earliest grace furthest before beat
                    out.push({
                        time:     Math.max(0, beatTime - offset),
                        voice,
                        pitch:    midi,
                        duration: GRACE_BEATS,
                        velocity: 0.7,
                        tieNext:  false,
                        sourceId: ev.id || null,
                    });
                });
            }

            for (const note of ev.notes) {
                // Skip tie-continuation notes — the original onset covers the
                // full tied duration via tieNext chaining.
                if (note.tieStop && !note.tieStart) continue;

                const midi = noteToMidi(note, tuning);
                if (midi == null) continue;

                out.push({
                    time:     beatTime,
                    voice,
                    pitch:    midi,
                    duration: beatDur,
                    velocity: 0.8,
                    tieNext:  note.tieStart || false,
                    sourceId: ev.id || null,
                });
            }
        }
    });

    out.sort((a, b) => a.time - b.time);
    return out;
}

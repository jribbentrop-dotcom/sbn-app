/**
 * SBN Tab Editor — Reflow Engine (v2, Soundslice model)
 *
 * Phase 7d: Duration changes within a single measure.
 * Phase 7-polish/Bug #12: Tuplet editing — dissolve and create triplets.
 *
 * ── Design ─────────────────────────────────────────────────────────
 *
 * Soundslice approach: edits stay measure-local.
 *   - Changing a duration repositions events within THAT measure only.
 *   - If total ticks exceed ticksPerMeasure, the measure is flagged
 *     as "overfilled" (rendered with a red indicator).
 *   - The user decides how to fix it (shorten notes, delete, etc.).
 *   - No cross-bar cascade, no event splitting, no auto-tie creation.
 *
 * ── Tuplet editing ─────────────────────────────────────────────────
 *
 *   +/- on a tuplet member → dissolves the group. Each member gets
 *   converted to its normal-duration equivalent (q-triplet → q).
 *   The measure will likely overfill — user fixes with further edits.
 *
 *   Ctrl+3 on a normal note → creates a triplet group. Splits the
 *   note into 3 tuplet events of the next-shorter duration.
 *   First event inherits the note data, 2nd and 3rd are rests.
 *
 *   Ctrl+3 on a tuplet member → dissolves the group (toggle).
 *
 * ── What this composable does ──────────────────────────────────────
 *
 *   changeDuration(event, newDur)   — set duration, reposition measure
 *   toggleDotted(event)             — flip 'q' ↔ 'qd'
 *   increaseDuration(event)         — step longer  (e→q→h→w), dissolves tuplet
 *   decreaseDuration(event)         — step shorter  (h→q→e→s), dissolves tuplet
 *   toggleTriplet(event)            — Ctrl+3: create or dissolve triplet group
 *   dissolveTupletGroup(event)      — strip tuplet, convert to normal durations
 *   toggleTie(event, stringIndex)   — per-note tie to next event
 *   measureFill(measure)            — { totalTicks, tpm, overfill }
 *   handleDurationKey(e, ev, mode)  — keyboard shortcut dispatcher
 */

import {
    durationToTicks, ticksToDuration, flagCount,
    TICKS, isTripletTicks, generateId,
} from '../utils/constants.js';


// ── Helpers ──────────────────────────────────────────────────

/**
 * Is this event part of a tuplet group?
 */
function isTupletEvent(event) {
    return event && event.tupletActual != null && event.tupletActual > 0;
}

/**
 * Return voice-1 events from a measure, sorted by tick.
 */
function voice1Sorted(measure) {
    return measure.events
        .filter(e => (e.voice || 1) === 1)
        .sort((a, b) => a.tick - b.tick);
}

/**
 * Reposition all voice-1 events sequentially within a measure.
 * Recalculates tick, tickInMeasure, xPos for each event.
 *
 * If the measure is overfilled (totalTicks > tpm), the measure stretches:
 * xPos is computed relative to totalTicks so events spread across the
 * wider space. measure.actualTicks is set so TabMeasure can compute
 * the visual width.
 *
 * Does NOT touch other measures. Does NOT delete events.
 */
function repositionMeasure(measure, tpm) {
    const v1 = voice1Sorted(measure);
    const baseTick = measure.index * tpm;
    let currentTick = baseTick;

    v1.forEach(ev => {
        ev.tick = currentTick;
        ev.tickInMeasure = currentTick - baseTick;
        ev.measureIdx = measure.index;
        currentTick += ev.ticks;
    });

    // Total ticks consumed by voice-1 events
    const totalTicks = currentTick - baseTick;
    const effectiveTicks = Math.max(totalTicks, tpm);

    // Compute xPos relative to the effective (possibly stretched) width
    v1.forEach(ev => {
        ev.xPos = ev.tickInMeasure / effectiveTicks;
    });

    // Store actualTicks on the measure so TabMeasure can stretch
    measure.actualTicks = totalTicks;

    // Re-sort the full events array (including voice 2)
    measure.events.sort((a, b) => a.tick - b.tick || a.voice - b.voice);

    // Recompute beams on all events
    recomputeBeams(measure, tpm);
}

/**
 * Beat-based beam heuristic for a single measure.
 * Preserves tuplet group links (beamWith, noBeamBar) for tuplet events.
 */
function recomputeBeams(measure, tpm) {
    const events = voice1Sorted(measure);

    // ── Save tuplet groups before clearing ──
    // Tuplet events use beamWith to link group members for rendering.
    // We must re-link them after clearing beam data.
    const tupletGroups = [];
    const tupletSeen = new Set();
    events.forEach(e => {
        if (isTupletEvent(e) && !tupletSeen.has(e.id)) {
            // Collect the group from beamWith or by scanning consecutive tuplet events
            let group;
            if (e.beamWith && e.beamWith.length >= 2) {
                group = e.beamWith;
            } else {
                // Fallback: scan from this event's tupletType='start' to 'stop',
                // accumulating all tuplet events regardless of individual duration.
                // This handles mixed-duration groups (e.g. half+quarter in a 3:2 bracket).
                const idx = events.indexOf(e);
                group = [e];
                if (e.tupletType === 'start') {
                    for (let j = idx + 1; j < events.length; j++) {
                        if (isTupletEvent(events[j])) {
                            group.push(events[j]);
                            if (events[j].tupletType === 'stop') break;
                        } else break;
                    }
                } else {
                    // No start tag — fall back to same-ticks scan
                    for (let j = idx + 1; j < events.length; j++) {
                        if (isTupletEvent(events[j]) && events[j].ticks === e.ticks) {
                            group.push(events[j]);
                        } else break;
                    }
                }
            }
            if (group.length >= 2) {
                tupletGroups.push(group);
                group.forEach(m => tupletSeen.add(m.id));
            }
        }
    });

    // Clear beam data
    events.forEach(e => {
        e.beamStart = false;
        e.beamEnd = false;
        e.beamContinue = false;
        e.beamWith = null;
        e.beam1 = null;
        e.beam2 = null;
    });

    // ── Re-link tuplet groups ──
    tupletGroups.forEach(group => {
        // noBeamBar = true for half and quarter triplets (ticks >= 320) — bracket only.
        // Eighth triplets (160) and shorter get real beam bars.
        // Note: flagCount(320) returns 1 due to baseDuration() triplet mapping, so we
        // cannot rely on flagCount here — compare ticks directly instead.
        const needsBeamBar = group[0].ticks < 320;
        group.forEach(m => {
            m.beamWith = group;
            m.noBeamBar = !needsBeamBar;
        });
    });

    // ── Standard beam grouping for non-tuplet beamable events ──
    const beamable = events.filter(e => !e.isRest && e.flagCount > 0 && !isTupletEvent(e));
    if (beamable.length < 2) return;

    // Group by beat (assumes 4/x time — ticksPerBeat = tpm / beatsPerMeasure)
    // For simplicity, use quarter-note beats (480 ticks)
    const ticksPerBeat = TICKS.perBeat;

    const groups = {};
    beamable.forEach(e => {
        const beat = Math.floor(e.tickInMeasure / ticksPerBeat);
        if (!groups[beat]) groups[beat] = [];
        groups[beat].push(e);
    });

    for (const group of Object.values(groups)) {
        if (group.length < 2) continue;
        group.sort((a, b) => a.tick - b.tick);
        group[0].beamStart = true;
        group[group.length - 1].beamEnd = true;
        for (let i = 1; i < group.length - 1; i++) {
            group[i].beamContinue = true;
        }
        group.forEach(m => { m.beamWith = group; });
    }
}

/**
 * Find the measure object that contains a given event.
 */
function findMeasure(model, event) {
    for (const section of model.sections) {
        for (const measure of section.measures) {
            if (measure.events.some(e => e.id === event.id)) {
                return measure;
            }
        }
    }
    return null;
}

/**
 * Get a flat list of all measures across all sections.
 */
function allMeasures(model) {
    return model.sections.flatMap(s => s.measures);
}


// ── Main export ─────────────────────────────────────────────

export function useReflow(model) {

    // ── Measure fill query ──────────────────────────────────

    /**
     * Compute the fill state of a measure.
     * @param {Object} measure — MeasureModel
     * @returns {{ totalTicks: number, tpm: number, overfill: boolean }}
     */
    function measureFill(measure) {
        if (!model.value) return { totalTicks: 0, tpm: 1920, overfill: false };

        const tpm = model.value.ticksPerMeasure;
        const v1 = voice1Sorted(measure);

        // For tuplet events, ev.ticks may be either:
        //   (a) the real triplet tick value (320 for q-triplet) — created by toggleTriplet
        //   (b) the nominal full duration (480 for q-triplet) — from some XML parsers
        // Case (a) is identified by isTripletTicks(). Case (b) needs the 2/3 ratio applied.
        const totalTicks = v1.reduce((sum, ev) => {
            let t = ev.ticks;
            if (ev.tupletActual && ev.tupletNormal && ev.tupletActual !== ev.tupletNormal
                && !isTripletTicks(t)) {
                // Nominal ticks (e.g. 480) → convert to real triplet ticks (e.g. 320)
                t = Math.round(t * ev.tupletNormal / ev.tupletActual);
            }
            return sum + t;
        }, 0);

        return {
            totalTicks,
            tpm,
            overfill: totalTicks > tpm,
        };
    }

    // ── Duration operations ─────────────────────────────────

    /**
     * Change the duration of an event. Repositions events within the
     * same measure. Never touches other measures.
     *
     * @param {Object} event   — The TabEvent to modify.
     * @param {string} newDur  — Duration code: 'w','h','q','e','s','t'
     *                           (optionally with 'd' suffix for dotted).
     */
    function changeDuration(event, newDur) {
        if (!model.value) return;

        const newTicks = durationToTicks(newDur);
        if (newTicks === event.ticks) return;

        const tpm = model.value.ticksPerMeasure;

        // Update the event
        event.duration = newDur;
        event.ticks = newTicks;
        event.flagCount = event.isRest ? 0 : flagCount(newTicks);

        // Reposition all events in this measure
        const measure = findMeasure(model.value, event);
        if (measure) {
            repositionMeasure(measure, tpm);
        }
    }

    /**
     * Toggle the dotted state of an event's duration.
     * e.g. 'q' (480) ↔ 'qd' (720).
     */
    function toggleDotted(event) {
        if (!event) return;
        const dur = event.duration;
        if (dur.endsWith('d')) {
            changeDuration(event, dur.slice(0, -1));
        } else {
            changeDuration(event, dur + 'd');
        }
    }

    /**
     * Increase event duration by one step: t→s→e→q→h→w.
     * If the event is a tuplet member, dissolves the group first.
     */
    function increaseDuration(event) {
        if (!event) return;
        if (isTupletEvent(event)) {
            dissolveTupletGroup(event);
            return;
        }
        const steps = ['t', 's', 'e', 'q', 'h', 'w'];
        const base = event.duration.replace('d', '');
        const dotted = event.duration.endsWith('d');
        const idx = steps.indexOf(base);
        if (idx === -1 || idx >= steps.length - 1) return;
        changeDuration(event, steps[idx + 1] + (dotted ? 'd' : ''));
    }

    /**
     * Decrease event duration by one step: w→h→q→e→s→t.
     * If the event is a tuplet member, dissolves the group first.
     */
    function decreaseDuration(event) {
        if (!event) return;
        if (isTupletEvent(event)) {
            dissolveTupletGroup(event);
            return;
        }
        const steps = ['t', 's', 'e', 'q', 'h', 'w'];
        const base = event.duration.replace('d', '');
        const dotted = event.duration.endsWith('d');
        const idx = steps.indexOf(base);
        if (idx <= 0) return;
        changeDuration(event, steps[idx - 1] + (dotted ? 'd' : ''));
    }

    // ── Tuplet operations ───────────────────────────────────

    /**
     * Find the tuplet group that an event belongs to.
     * Returns array of events in the group, or null.
     */
    function findTupletGroup(event) {
        if (!isTupletEvent(event)) return null;

        // beamWith holds the group reference for linked tuplets
        if (event.beamWith && event.beamWith.length >= 2) {
            return event.beamWith;
        }

        // Fallback: scan the measure for consecutive tuplet events with same tick duration
        const measure = findMeasure(model.value, event);
        if (!measure) return null;

        const v1 = voice1Sorted(measure);
        const evIdx = v1.findIndex(e => e.id === event.id);
        if (evIdx === -1) return null;

        // Walk backward to find group start
        let start = evIdx;
        while (start > 0 && isTupletEvent(v1[start - 1]) && v1[start - 1].ticks === event.ticks) {
            start--;
        }
        // Walk forward to find group end
        let end = evIdx;
        while (end < v1.length - 1 && isTupletEvent(v1[end + 1]) && v1[end + 1].ticks === event.ticks) {
            end++;
        }

        const group = v1.slice(start, end + 1);
        return group.length >= 2 ? group : null;
    }

    /**
     * Dissolve a tuplet group: strip tuplet metadata from all members
     * and convert each to its normal (non-tuplet) duration equivalent.
     *
     * Triplet quarter (320 ticks, 3:2) → normal quarter (480 ticks).
     * Triplet eighth  (160 ticks, 3:2) → normal eighth  (240 ticks).
     *
     * This will overfill the measure — that's expected and the user
     * deals with the overflow via further edits.
     */
    function dissolveTupletGroup(event) {
        if (!model.value) return;

        const group = findTupletGroup(event);
        if (!group) {
            // Lone tuplet event (orphaned) — just strip its metadata
            stripTupletMetadata(event);
            const measure = findMeasure(model.value, event);
            if (measure) repositionMeasure(measure, model.value.ticksPerMeasure);
            return;
        }

        // Map tuplet ticks → normal ticks:
        // tuplet actual occupies (ticks * normal/actual) of real time,
        // but the note's written duration is the base. E.g. a quarter-note
        // triplet has ticks=480 (quarter) but occupies 320 of real time.
        // After dissolving, it should become a plain quarter note (480 ticks).
        // However, if ticks is already the tuplet-adjusted value (320),
        // we need to figure out the normal base.
        group.forEach(member => {
            const normalTicks = tupletToNormalTicks(member);
            const normalDur = ticksToDuration(normalTicks);

            member.duration = normalDur;
            member.ticks = normalTicks;
            member.flagCount = member.isRest ? 0 : flagCount(normalTicks);

            stripTupletMetadata(member);
        });

        // Reposition the measure
        const measure = findMeasure(model.value, event);
        if (measure) {
            repositionMeasure(measure, model.value.ticksPerMeasure);
        }
    }

    /**
     * Convert tuplet tick value to the equivalent normal duration ticks.
     * A triplet quarter (320) → quarter (480). Triplet eighth (160) → eighth (240).
     */
    function tupletToNormalTicks(event) {
        const ticks = event.ticks;
        // Known triplet tick values → their normal equivalents
        if (ticks === 640) return TICKS.half;        // triplet half → half
        if (ticks === 320) return TICKS.quarter;     // triplet quarter → quarter
        if (ticks === 160) return TICKS.eighth;      // triplet eighth → eighth
        if (ticks === 80)  return TICKS.sixteenth;   // triplet 16th → 16th

        // If ticks is already a normal value (e.g. 480 with tuplet metadata from XML),
        // the event's duration field is the written duration — keep it
        if (!isTripletTicks(ticks)) {
            return durationToTicks(event.duration || 'q');
        }

        // Fallback: scale by actual/normal ratio
        const actual = event.tupletActual || 3;
        const normal = event.tupletNormal || 2;
        return Math.round(ticks * actual / normal);
    }

    /**
     * Strip all tuplet-related metadata from an event.
     */
    function stripTupletMetadata(event) {
        event.tupletActual  = null;
        event.tupletNormal  = null;
        event.tupletType    = null;
        event.tupletBracket = false;
        event.noBeamBar     = false;
        // Don't clear beamWith here — recomputeBeams will handle it
        event.beamWith      = null;
        event.beamStart     = false;
        event.beamEnd       = false;
        event.beamContinue  = false;
    }

    /**
     * Create a triplet group from a normal note at the cursor.
     * Splits the current event into 3 tuplet events of the next-shorter duration.
     *
     * Quarter (480) → 3 × eighth-triplet (160 each, total 480).
     * Half (960)    → 3 × quarter-triplet (320 each, total 960).
     * Eighth (240)  → 3 × 16th-triplet (80 each, total 240).
     *
     * The first event inherits the original note data.
     * Events 2 and 3 are rests.
     *
     * If the event is already a tuplet member, dissolves the group instead (toggle).
     *
     * @param {Object} event — The TabEvent at cursor.
     * @returns {boolean} — true if the operation was performed.
     */
    function toggleTriplet(event) {
        if (!event || !model.value) return false;

        // If already a tuplet → dissolve
        if (isTupletEvent(event)) {
            dissolveTupletGroup(event);
            return true;
        }

        const measure = findMeasure(model.value, event);
        if (!measure) return false;

        // Determine triplet duration
        const parentTicks = event.ticks;
        // Triplet subdivides the parent into 3 notes that fit in the same space
        // Each triplet note = parentTicks / 3  (but must be a clean triplet tick value)
        const tripletTicks = Math.round(parentTicks / 3);

        // Validate: we need a recognizable triplet tick value
        const validTripletTicks = [640, 320, 160, 80];
        if (!validTripletTicks.includes(tripletTicks)) {
            // Can't create triplet from this duration (e.g. 32nd note = 60 ticks → 20 per triplet)
            return false;
        }

        // Determine the written duration name for the triplet notes
        const tripletDurMap = { 640: 'h', 320: 'q', 160: 'e', 80: 's' };
        const tripletDur = tripletDurMap[tripletTicks];

        const tpm = model.value.ticksPerMeasure;
        const baseTick = event.tick;

        // Find the event in the measure's events array
        const evIdx = measure.events.findIndex(e => e.id === event.id);
        if (evIdx === -1) return false;

        // Build 3 tuplet events
        const newEvents = [];

        for (let i = 0; i < 3; i++) {
            const isFirst = i === 0;
            const isLast  = i === 2;

            const ev = {
                id:            generateId(),
                tick:          baseTick + i * tripletTicks,
                tickInMeasure: (baseTick % tpm) + i * tripletTicks,
                measureIdx:    measure.index,
                duration:      tripletDur,
                ticks:         tripletTicks,
                voice:         event.voice || 1,
                isRest:        event.isRest || !isFirst,  // rests if source was rest, or non-first
                notes:         (isFirst && !event.isRest) ? [...event.notes.map(n => ({...n}))] : [],
                tieStart:      false,
                tieStop:       false,
                stemDir:       event.stemDir || 'down',
                flagCount:     flagCount(tripletTicks),
                beam1:         null,
                beam2:         null,
                beamStart:     false,
                beamEnd:       false,
                beamContinue:  false,
                beamWith:      null,
                tupletActual:  3,
                tupletNormal:  2,
                tupletType:    isFirst ? 'start' : (isLast ? 'stop' : null),
                tupletBracket: isFirst,
                noBeamBar:     false,
                xPos:          0,   // repositionMeasure will fix this
                originalIdx:   null,
            };

            newEvents.push(ev);
        }

        // noBeamBar = true for quarter/half triplets (ticks >= 320) — bracket only, no beam bars.
        // Eighth triplets (160) and shorter get real beam bars.
        const needsBeamBar = tripletTicks < 320;
        newEvents.forEach(ev => {
            ev.beamWith = newEvents;
            ev.noBeamBar = !needsBeamBar;
        });

        // Replace the original event with the 3 new ones
        measure.events.splice(evIdx, 1, ...newEvents);

        // Reposition
        repositionMeasure(measure, tpm);

        return true;
    }

    // ── Tie toggle ──────────────────────────────────────────

    /**
     * Toggle tie on a specific note (identified by string number) within an event.
     * Links to the same string on the next voice-1 non-rest event.
     *
     * @param {Object} event       — The TabEvent containing the note.
     * @param {number} stringIndex — Guitar string 1-6 to toggle tie on.
     */
    function toggleTie(event, stringIndex) {
        if (!event || event.isRest) return;

        const note = event.notes.find(n => n.string === stringIndex);
        if (!note) return;

        if (note.tieStart) {
            // Remove tie from this note
            if (note.tieEndNote) {
                note.tieEndNote.tieStop = false;
                note.tieEndNote.tieStartEvent = null;
                note.tieEndNote.tieStartNote = null;
            }
            note.tieStart = false;
            note.tieEndEvent = null;
            note.tieEndNote = null;
        } else {
            // Add tie: find the next event that has a note on the same string
            const measures = allMeasures(model.value);
            const allEvts = measures.flatMap(m =>
                m.events.filter(e => (e.voice || 1) === (event.voice || 1) && !e.isRest)
            ).sort((a, b) => a.tick - b.tick);

            const idx = allEvts.findIndex(e => e.id === event.id);
            if (idx === -1) return;

            // Search forward for an event with a note on this string
            for (let j = idx + 1; j < allEvts.length; j++) {
                const targetNote = allEvts[j].notes.find(n => n.string === stringIndex);
                if (targetNote) {
                    note.tieStart = true;
                    note.tieEndEvent = allEvts[j];
                    note.tieEndNote = targetNote;
                    targetNote.tieStop = true;
                    targetNote.tieStartEvent = event;
                    targetNote.tieStartNote = note;
                    break;
                }
            }
        }

        // Update event-level derived flags
        event.tieStart = event.notes.some(n => n.tieStart);
        event.tieStop  = event.notes.some(n => n.tieStop);
    }

    // ── Duration keyboard handler ───────────────────────────

    /**
     * Duration shortcut keys — only active when cursor is in navigate mode.
     *
     *   + / =    → shorter duration (e.g. quarter → eighth); dissolves tuplet
     *   -        → longer duration  (e.g. quarter → half); dissolves tuplet
     *   .        → toggle dotted
     *   T        → toggle tie
     *   Ctrl+1   → whole
     *   Ctrl+2   → half
     *   Ctrl+3   → toggle triplet (create or dissolve)
     *   Ctrl+4   → eighth
     *   Ctrl+5   → 16th
     *   Ctrl+6   → 32nd
     *
     * @param {KeyboardEvent} e           — keydown event.
     * @param {Object}        event       — current TabEvent at cursor.
     * @param {string}        mode        — cursor mode.
     * @param {number}        stringIndex — cursor string (1-6), for per-note tie.
     * @returns {boolean}                 — true if the key was consumed.
     */
    function handleDurationKey(e, event, mode, stringIndex) {
        if (!event || mode === 'input') return false;

        const key = e.key;

        // Ctrl+1 through Ctrl+6: absolute duration (Ctrl+3 = triplet toggle)
        if (e.ctrlKey || e.metaKey) {
            // Ctrl+3: triplet toggle (overrides quarter-note shortcut)
            if (key === '3') {
                e.preventDefault();
                toggleTriplet(event);
                return true;
            }
            const ctrlMap = { '1': 'w', '2': 'h', '4': 'e', '5': 's', '6': 't' };
            if (ctrlMap[key]) {
                e.preventDefault();
                const base = ctrlMap[key];
                const dotted = event.duration.endsWith('d');
                changeDuration(event, base + (dotted ? 'd' : ''));
                return true;
            }
            return false;
        }

        // + / = : decrease duration (shorter note)
        if (key === '+' || key === '=') {
            e.preventDefault();
            decreaseDuration(event);
            return true;
        }

        // - : increase duration (longer note)
        if (key === '-') {
            e.preventDefault();
            increaseDuration(event);
            return true;
        }

        // . : toggle dotted
        if (key === '.') {
            e.preventDefault();
            toggleDotted(event);
            return true;
        }

        // T : toggle tie (navigate mode only)
        if ((key === 't' || key === 'T') && mode === 'navigate') {
            if (e.altKey) return false;
            e.preventDefault();
            toggleTie(event, stringIndex);
            return true;
        }

        return false;
    }


    // ── Public API ──────────────────────────────────────────

    return {
        // Query
        measureFill,

        // Duration operations
        changeDuration,
        toggleDotted,
        toggleTie,
        increaseDuration,
        decreaseDuration,

        // Tuplet operations
        toggleTriplet,
        dissolveTupletGroup,

        // Keyboard handler
        handleDurationKey,

        // Measure layout (used by TabEditor for mid-measure insert)
        repositionMeasure: (measure) => repositionMeasure(measure, model.value?.ticksPerMeasure || 1920),
    };
}

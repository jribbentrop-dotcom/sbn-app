/**
 * SBN Tab Editor — Selection + Copy/Paste (Phase 7g)
 *
 * CLIPBOARD MODES
 * ───────────────
 *
 *  'note'    — single note on one string (default, no Shift selection)
 *    { mode: 'note', fret, string, duration, ticks }
 *    Paste: sets that fret on cursor string in the cursor event.
 *    Same granularity as Delete.
 *
 *  'events'  — one or more full events (Shift+← range selection active)
 *    { mode: 'events', events[], totalTicks }
 *    Paste: inserts at cursor tick, replacing overlapping events.
 *
 * SELECTION
 * ─────────
 * selectedEvents: Set<eventId> — populated by Shift+Arrow in TabEditor.
 * Empty = cursor note only. Visual: orange columns in TabCursor.
 */

import { ref, computed, readonly } from 'vue';
import { generateId } from '../utils/constants.js';

// ── Helpers ──────────────────────────────────────────────────────────────────

function cloneEvent(ev, newId = true) {
    return {
        id:            newId ? generateId() : ev.id,
        tick:          ev.tick,
        tickInMeasure: ev.tickInMeasure,
        measureIdx:    ev.measureIdx,
        duration:      ev.duration,
        ticks:         ev.ticks,
        voice:         ev.voice ?? 1,
        isRest:        ev.isRest,
        notes:         ev.notes.map(n => ({ ...n, tieStartEvent: null, tieEndEvent: null, tieStartNote: null, tieEndNote: null })),
        tieStart:      ev.tieStart,  tieStop: ev.tieStop,
        tieStartEvent: null,         tieEndEvent: null,
        stemDir:       ev.stemDir,   flagCount:     ev.flagCount,
        beam1:         ev.beam1,     beam2:         ev.beam2,
        beamStart:     ev.beamStart, beamEnd:       ev.beamEnd, beamContinue: ev.beamContinue,
        beamWith:      null,         noBeamBar:     ev.noBeamBar,
        tupletActual:  ev.tupletActual, tupletNormal: ev.tupletNormal,
        tupletType:    ev.tupletType,   tupletBracket: ev.tupletBracket,
        xPos:          ev.xPos,
        originalIdx:   ev.originalIdx ?? null,
    };
}

function makeWholeRest(measureIndex, tpm) {
    return {
        id: generateId(),
        tick: measureIndex * tpm, tickInMeasure: 0,
        measureIdx: measureIndex, duration: 'w', ticks: tpm,
        voice: 1, isRest: true, notes: [],
        tieStart: false, tieStop: false, tieStartEvent: null, tieEndEvent: null,
        stemDir: null, flagCount: 0,
        beam1: null, beam2: null, beamStart: false, beamEnd: false, beamContinue: false,
        beamWith: null, noBeamBar: false,
        tupletActual: null, tupletNormal: null, tupletType: null, tupletBracket: false,
        xPos: 0, originalIdx: null,
    };
}

// ── Composable ───────────────────────────────────────────────────────────────

export function useSelection(model) {

    // ── Note-level selection (Shift+Arrow range) ───────────────────────────

    const selectedEvents = ref(new Set());   // Set<eventId>
    const hasNoteSelection = computed(() => selectedEvents.value.size > 0);

    function extendNoteSelection(v1Events, anchorEventIdx, targetEventIdx) {
        const lo = Math.min(anchorEventIdx, targetEventIdx);
        const hi = Math.max(anchorEventIdx, targetEventIdx);
        const next = new Set();
        for (let i = lo; i <= hi; i++) {
            if (v1Events[i]) next.add(v1Events[i].id);
        }
        selectedEvents.value = next;
    }

    function clearNoteSelection() {
        selectedEvents.value = new Set();
    }

    function setSelectedEvents(iterable) {
        selectedEvents.value = new Set(iterable);
    }

    // ── Drag Selection (Phase 7h) ──────────────────────────────────────────

    const isDragging = ref(false);
    const dragMode = ref(null); // 'note' | 'measure'
    const dragAnchorEventId = ref(null);
    const dragAnchorMeasureIndex = ref(null);

    function startNoteDrag(measureIndex, eventId) {
        isDragging.value = true;
        dragMode.value = 'note';
        dragAnchorEventId.value = eventId;
        dragAnchorMeasureIndex.value = measureIndex;
        setSelectedEvents([eventId]);
    }

    function updateNoteDrag(eventId) {
        if (!isDragging.value || dragMode.value !== 'note' || !dragAnchorEventId.value || !model.value) return;
        
        const allV1 = model.value.sections.flatMap(s => s.measures).flatMap(m => m.events).filter(e => (e.voice ?? 1) === 1);
        const idx1 = allV1.findIndex(e => e.id === dragAnchorEventId.value);
        const idx2 = allV1.findIndex(e => e.id === eventId);
        
        if (idx1 === -1 || idx2 === -1) return;
        
        const lo = Math.min(idx1, idx2);
        const hi = Math.max(idx1, idx2);
        const next = new Set();
        for (let i = lo; i <= hi; i++) next.add(allV1[i].id);
        selectedEvents.value = next;
    }

    function startMeasureDrag(measureIndex, v1Ids) {
        isDragging.value = true;
        dragMode.value = 'measure';
        dragAnchorMeasureIndex.value = measureIndex;
        dragAnchorEventId.value = null;
        setSelectedEvents(v1Ids);
    }

    function updateMeasureDrag(measureIndex) {
        if (!isDragging.value || dragMode.value !== 'measure' || dragAnchorMeasureIndex.value === null || !model.value) return;
        
        const allM = model.value.sections.flatMap(s => s.measures);
        const lo = Math.min(dragAnchorMeasureIndex.value, measureIndex);
        const hi = Math.max(dragAnchorMeasureIndex.value, measureIndex);
        
        const next = new Set();
        for (let i = lo; i <= hi; i++) {
            const m = allM[i];
            if (m) {
                m.events.filter(e => (e.voice ?? 1) === 1).forEach(e => next.add(e.id));
            }
        }
        selectedEvents.value = next;
    }

    function stopDrag() {
        isDragging.value = false;
        dragMode.value = null;
    }

    // ── Clipboard ──────────────────────────────────────────────────────────

    const clipboard = ref(null);

    /**
     * Copy into clipboard.
     *
     * cursorEvent   — the TabEvent the cursor is on (from useCursor.currentEvent)
     * stringIndex   — cursor.stringIndex (1-6)
     * selectedEventIds — Set of IDs from Shift+Arrow; if non-empty, copies full events
     *
     * Default (no Shift selection): copies only the single note at
     * (cursorEvent, stringIndex) — same granularity as Delete.
     */
    function copy(cursorEvent, stringIndex, selectedEventIds) {
        if (!model.value || !cursorEvent) return false;
        const frozenIds = selectedEventIds ? new Set(selectedEventIds) : new Set();

        if (frozenIds.size > 0) {
            // ── Multi-event copy (Shift+Arrow range) ────────────────────
            const allMeasures = model.value.sections.flatMap(s => s.measures);
            const selected = [];
            allMeasures.forEach(m => {
                m.events.filter(ev => (ev.voice ?? 1) === 1).forEach(ev => {
                    if (frozenIds.has(ev.id)) {
                        selected.push(ev);
                    }
                });
            });
            selected.sort((a, b) => a.tick - b.tick);
            if (!selected.length) return false;

            const firstTick = selected[0].tick;
            const events = selected.map(ev => {
                const c = cloneEvent(ev, false);
                c.relTick = ev.tick - firstTick;
                return c;
            });
            const last = selected[selected.length - 1];
            const totalTicks = last.tick + last.ticks - firstTick;
            clipboard.value = { mode: 'events', events, totalTicks };

        } else if (cursorEvent.isRest) {
            // ── Rest copy — copy the whole rest event ───────────────────
            const c = cloneEvent(cursorEvent, false);
            c.relTick = 0;
            clipboard.value = { mode: 'events', events: [c], totalTicks: c.ticks };

        } else {
            // ── Single note copy (default) ───────────────────────────────
            // Copy only the note on cursor string within this event.
            const note = cursorEvent.notes.find(n => n.string === stringIndex);
            if (!note) return false;
            clipboard.value = {
                mode:     'note',
                fret:     note.fret,
                string:   stringIndex,
                duration: cursorEvent.duration,
                ticks:    cursorEvent.ticks,
            };
        }
        return true;
    }

    /**
     * Cut: copy then clear.
     *
     * Note mode: removes only the note on cursor string (same as Delete on that string).
     *   If it was the last note on the event → event becomes a rest.
     * Events mode: replaces selected events with a rest.
     */
    function prepareCut(cursorEvent, stringIndex, selectedEventIds) {
        const frozenIds = selectedEventIds ? new Set(selectedEventIds) : new Set();
        const copied = copy(cursorEvent, stringIndex, frozenIds);
        if (!copied) return null;

        const m = model.value.sections.flatMap(s => s.measures)
            .find(m => m.index === cursorEvent.measureIdx);
        if (!m) return null;
        const tpm = model.value.ticksPerMeasure;
        const isNoteMode = clipboard.value.mode === 'note';

        return {
            affectedIndices: [cursorEvent.measureIdx],
            mutate() {
                if (isNoteMode) {
                    // Remove just this string's note — identical to deleteNoteAtCursor
                    const noteIdx = cursorEvent.notes.findIndex(n => n.string === stringIndex);
                    if (noteIdx !== -1) {
                        cursorEvent.notes.splice(noteIdx, 1);
                        if (cursorEvent.notes.length === 0) {
                            cursorEvent.isRest  = true;
                            cursorEvent.stemDir = null;
                            cursorEvent.flagCount = 0;
                        }
                    }
                } else {
                    // Remove selected events, insert rest in the gap
                    const removeIds = frozenIds.size > 0 ? frozenIds : new Set([cursorEvent.id]);
                    const v2   = m.events.filter(ev => (ev.voice ?? 1) > 1);
                    const kept = m.events.filter(ev => (ev.voice ?? 1) === 1 && !removeIds.has(ev.id));
                    const removed = m.events
                        .filter(ev => (ev.voice ?? 1) === 1 && removeIds.has(ev.id))
                        .sort((a, b) => a.tick - b.tick);

                    if (removed.length) {
                        const gapTick      = removed[0].tick;
                        const gapInMeasure = gapTick - cursorEvent.measureIdx * tpm;
                        const gapTicks     = removed.reduce((s, e) => s + e.ticks, 0);
                        const STDS = [
                            {ticks:1920,dur:'w'},{ticks:960,dur:'h'},{ticks:720,dur:'hd'},
                            {ticks:480,dur:'q'},{ticks:360,dur:'ed'},{ticks:240,dur:'e'},
                            {ticks:180,dur:'sd'},{ticks:120,dur:'s'},{ticks:60,dur:'t'},
                        ];
                        const fit = STDS.find(s => s.ticks <= gapTicks) || STDS[STDS.length-1];
                        const gapRest = {
                            ...makeWholeRest(cursorEvent.measureIdx, tpm),
                            tick: gapTick, tickInMeasure: gapInMeasure,
                            duration: fit.dur, ticks: fit.ticks,
                            xPos: gapInMeasure / tpm,
                        };
                        m.events = [...v2, ...kept, gapRest].sort((a,b) => a.tick-b.tick || a.voice-b.voice);
                    } else {
                        m.events = [...v2, ...kept].sort((a,b) => a.tick-b.tick || a.voice-b.voice);
                    }
                    const allV1 = m.events.filter(ev => (ev.voice ?? 1) === 1).sort((a,b) => a.tick-b.tick);
                    const last = allV1[allV1.length-1];
                    m.actualTicks = last ? last.tickInMeasure + last.ticks : tpm;
                }
            },
        };
    }

    /**
     * Paste clipboard at cursor position.
     *
     * Note mode: sets the copied fret on cursor string of the cursor event.
     *   If cursor event is a rest → converts it to a note first.
     * Events mode: inserts events at cursor tick, replacing overlapping events.
     */
    function preparePaste(cursorEvent, stringIndex) {
        if (!clipboard.value || !model.value || !cursorEvent) return null;
        const m = model.value.sections.flatMap(s => s.measures)
            .find(m => m.index === cursorEvent.measureIdx);
        if (!m) return null;
        const tpm = model.value.ticksPerMeasure;

        if (clipboard.value.mode === 'note') {
            // ── Note-level paste: set fret on cursor string ──────────────
            const { fret } = clipboard.value;
            return {
                affectedIndices: [cursorEvent.measureIdx],
                mutate() {
                    if (cursorEvent.isRest) {
                        // Convert rest to note
                        cursorEvent.isRest = false;
                    }
                    // Remove existing note on target string, add pasted note
                    cursorEvent.notes = cursorEvent.notes.filter(n => n.string !== stringIndex);
                    cursorEvent.notes.push({ string: stringIndex, fret, tieStart: false, tieStop: false });
                },
            };
        } else {
            // ── Events-level paste: insert at cursor tick ────────────────
            const clip = clipboard.value;
            const v1 = m.events.filter(ev => (ev.voice ?? 1) === 1).sort((a,b) => a.tick-b.tick);
            const insertTick  = cursorEvent.tick;
            const baseTick    = cursorEvent.measureIdx * tpm;
            const pastedTicks = clip.totalTicks;
            const pasteEnd    = insertTick + pastedTicks;

            return {
                affectedIndices: [cursorEvent.measureIdx],
                mutate() {
                    const v2 = m.events.filter(ev => (ev.voice ?? 1) > 1);
                    const pasted = clip.events.map(clipEv => {
                        const ev = cloneEvent(clipEv, true);
                        ev.tick          = insertTick + clipEv.relTick;
                        ev.tickInMeasure = ev.tick - baseTick;
                        ev.measureIdx    = cursorEvent.measureIdx;
                        ev.xPos          = ev.tickInMeasure / tpm;
                        return ev;
                    });
                    const kept = v1.filter(ev => ev.tick < insertTick || ev.tick >= pasteEnd);
                    m.events = [...v2, ...kept, ...pasted].sort((a,b) => a.tick-b.tick || a.voice-b.voice);
                    const allV1 = m.events.filter(ev => (ev.voice ?? 1) === 1).sort((a,b) => a.tick-b.tick);
                    const last = allV1[allV1.length-1];
                    m.actualTicks = last ? last.tickInMeasure + last.ticks : tpm;
                },
            };
        }
    }

    /**
     * Copy N full measures into the clipboard as mode: 'measures'.
     *
     * @param {Array} measureObjects — live measure objects from allMeasures
     */
    function copyMeasures(measureObjects) {
        if (!measureObjects?.length) return false;
        const tpm = model.value?.ticksPerMeasure ?? 1920;
        const measures = measureObjects.map(m => {
            const events = m.events.map(ev => {
                const c = cloneEvent(ev, false);
                // store tick relative to measure start so paste re-bases correctly
                c.relTick = ev.tickInMeasure;
                return c;
            });
            return { events, chordNames: [...(m.chordNames || [])] };
        });
        clipboard.value = { mode: 'measures', measures, tpm };
        return true;
    }

    /**
     * Prepare a paste of mode:'measures' clipboard into consecutive measures
     * starting at `startMeasureIdx`.
     *
     * @param {Array} allMeasureList — allMeasures.value
     * @param {number} startMeasureIdx — global index of first destination measure
     */
    function preparePasteMeasures(allMeasureList, startMeasureIdx) {
        if (!clipboard.value || clipboard.value.mode !== 'measures') return null;
        const { measures: clipMeasures, tpm: clipTpm } = clipboard.value;
        const tpm = model.value?.ticksPerMeasure ?? clipTpm;

        const startIdx = allMeasureList.findIndex(m => m.index === startMeasureIdx);
        if (startIdx === -1) return null;

        const affectedIndices = [];
        for (let i = 0; i < clipMeasures.length && startIdx + i < allMeasureList.length; i++) {
            affectedIndices.push(allMeasureList[startIdx + i].index);
        }
        if (!affectedIndices.length) return null;

        return {
            affectedIndices,
            mutate() {
                for (let i = 0; i < clipMeasures.length && startIdx + i < allMeasureList.length; i++) {
                    const destM  = allMeasureList[startIdx + i];
                    const destGi = destM.index;
                    const { events: clipEvents, chordNames } = clipMeasures[i];

                    const pasted = clipEvents.map(clipEv => {
                        const ev = cloneEvent(clipEv, true);
                        ev.tickInMeasure = clipEv.relTick;
                        ev.tick          = destGi * tpm + clipEv.relTick;
                        ev.measureIdx    = destGi;
                        ev.xPos          = clipEv.relTick / tpm;
                        return ev;
                    });
                    pasted.sort((a, b) => a.tick - b.tick || (a.voice ?? 1) - (b.voice ?? 1));
                    destM.events = pasted;

                    const allV1 = pasted.filter(ev => (ev.voice ?? 1) === 1).sort((a, b) => a.tick - b.tick);
                    const last = allV1[allV1.length - 1];
                    destM.actualTicks = last ? last.tickInMeasure + last.ticks : tpm;

                    // restore chord names
                    if (chordNames.length) {
                        destM.chordNames.splice(0, destM.chordNames.length, ...chordNames);
                    }
                }
            },
        };
    }

    // ── Public API ─────────────────────────────────────────────────────────

    return {
        selectedEvents:     readonly(selectedEvents),
        hasNoteSelection,
        extendNoteSelection,
        clearNoteSelection,
        setSelectedEvents,
        isDragging: readonly(isDragging),
        startNoteDrag, updateNoteDrag,
        startMeasureDrag, updateMeasureDrag,
        stopDrag,
        clipboard:          readonly(clipboard),
        copy,
        copyMeasures,
        prepareCut,
        preparePaste,
        preparePasteMeasures,
        makeWholeRest,
    };
}

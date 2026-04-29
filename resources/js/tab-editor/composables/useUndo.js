/**
 * SBN Tab Editor — Undo/Redo
 *
 * Command pattern with before/after measure snapshots.
 *
 * Strategy: snapshot the minimal set of measures affected by each operation.
 * A "snapshot" is a deep clone of measure.events + measure.actualTicks.
 * On undo/redo, the snapshot is restored onto the live measure object in-place
 * so Vue's reactivity picks it up.
 *
 * All mutation entry points call `wrapCommand()`.
 * For note-level edits it snapshots only the affected measures.
 * For structural edits it can snapshot the full model when passed
 * serialize/deserialize hooks.
 *
 * Max stack depth: 100 commands (older entries are dropped).
 */

import { ref, computed } from 'vue';

const MAX_STACK = 100;

export function useUndo(model) {

    const stack   = ref([]);   // Array<{ label, undo: fn, redo: fn }>
    const pointer = ref(-1);   // index of last applied command

    const canUndo = computed(() => pointer.value >= 0);
    const canRedo = computed(() => pointer.value < stack.value.length - 1);

    // ── Snapshot helpers ───────────────────────────────────

    /**
     * Deep-clone a measure's mutable state.
     * We clone events (and their notes arrays) plus actualTicks.
     * xPos, tick, tickInMeasure, beamWith etc. are all on the events themselves.
     */
    function snapshotMeasure(measure) {
        return {
            index:        measure.index,
            actualTicks:  measure.actualTicks,
            chordNames:   measure.chordNames  ? [...measure.chordNames]  : [],
            chordOffsets: measure.chordOffsets ? [...measure.chordOffsets] : [],
            chordBeats:   measure.chordBeats   ? [...measure.chordBeats]   : [],
            events:      measure.events.map(ev => ({
                ...ev,
                notes:    ev.notes.map(n => {
                    const clonedNote = { ...n };
                    delete clonedNote.tieStartEvent;
                    delete clonedNote.tieEndEvent;
                    delete clonedNote.tieStartNote;
                    delete clonedNote.tieEndNote;
                    return clonedNote;
                }),
                // beamWith is an array of refs to sibling events in the same measure.
                // After restore we re-link beamWith by id, so don't clone it here.
                beamWith: null,
            })),
        };
    }

    /**
     * Restore a snapshot onto a live measure object in-place.
     * Vue reactivity fires because we mutate the existing reactive ref.
     */
    function restoreSnapshot(measure, snap) {
        measure.actualTicks = snap.actualTicks;
        if (snap.chordNames) {
            measure.chordNames.splice(0, measure.chordNames.length, ...snap.chordNames);
        }
        if (snap.chordOffsets) {
            measure.chordOffsets = [...snap.chordOffsets];
        }
        if (snap.chordBeats) {
            measure.chordBeats = [...snap.chordBeats];
        }
        // Replace events array contents in-place (keeps the same array reference
        // so TabMeasure's v-for doesn't fully remount)
        measure.events.splice(0, measure.events.length, ...snap.events.map(ev => ({ ...ev, notes: ev.notes.map(n => ({ ...n })) })));
        // Re-link beamWith within the restored events (by id)
        relinkBeamWith(measure.events);
    }

    /**
     * After restoring from a snapshot, beamWith arrays need to point to the
     * newly-restored event objects (the old object references are stale).
     */
    function relinkBeamWith(events) {
        const byId = new Map(events.map(e => [e.id, e]));
        events.forEach(ev => {
            // beamWith was nulled during snapshot; re-derive from beamStart/End/Continue flags.
            // The simplest approach: rebuild by scanning for matching ids stored in a
            // separate field. But we didn't store them. Instead we use the flags directly.
            ev.beamWith = null;
        });
        // Re-run the beam linking pass (same logic as computeBeams but lighter:
        // we only need to restore beamWith, not recompute ticks/xPos).
        const groups = {};
        events.forEach(ev => {
            if (ev.isRest || !ev.beam1) return;
            const key = `${ev.measureIdx}-${ev.voice}`;
            if (!groups[key]) groups[key] = [];
            groups[key].push(ev);
        });
        for (const group of Object.values(groups)) {
            group.sort((a, b) => a.tick - b.tick);
            let beamGroup = [];
            group.forEach(ev => {
                if (ev.beam1 === 'begin')    { beamGroup = [ev]; }
                else if (ev.beam1 === 'continue' && beamGroup.length) { beamGroup.push(ev); }
                else if (ev.beam1 === 'end'  && beamGroup.length) {
                    beamGroup.push(ev);
                    beamGroup.forEach(m => { m.beamWith = beamGroup; });
                    beamGroup = [];
                }
            });
        }
        // Heuristic beamWith for events without beam1 tags (noBeamBar tuplets etc.)
        // These will be re-derived on next render; set beamWith to null is safe.
    }

    // ── Find a measure by index in the live model ──────────

    function findMeasure(measureIdx) {
        if (!model.value) return null;
        for (const section of model.value.sections) {
            const m = section.measures.find(m => m.index === measureIdx);
            if (m) return m;
        }
        return null;
    }

    // ── Core API ────────────────────────────────────────────

    function pushCommand(command) {
        stack.value.splice(pointer.value + 1);
        stack.value.push(command);

        if (stack.value.length > MAX_STACK) {
            stack.value.shift();
        } else {
            pointer.value++;
        }
    }

    /**
     * Wrap a mutation in a command with before/after snapshots.
     *
     * @param {string}   label          — human-readable label for debugging
     * @param {number[]} measureIndices — indices of measures that may be mutated
     * @param {Function} fn             — the mutation to perform
     * @param {Object}   options        — optional full-model snapshot hooks
     */
    function wrapCommand(label, measureIndices, fn, options = null) {
        const serializeModel = options?.serializeModel;
        const deserializeModel = options?.deserializeModel;
        const afterApply = options?.afterApply;

        if (typeof serializeModel === 'function' && typeof deserializeModel === 'function') {
            if (!model.value) {
                fn?.();
                return;
            }

            const before = serializeModel();
            if (!before) {
                fn?.();
                afterApply?.();
                return;
            }

            fn?.();

            const after = serializeModel();
            if (JSON.stringify(before) === JSON.stringify(after)) return;

            pushCommand({
                label,
                undo: () => {
                    deserializeModel(before);
                    afterApply?.();
                },
                redo: () => {
                    deserializeModel(after);
                    afterApply?.();
                },
            });
            return;
        }

        const measures = measureIndices.map(idx => findMeasure(idx)).filter(Boolean);
        if (!measures.length) { fn(); return; }

        // Before snapshots
        const before = measures.map(snapshotMeasure);

        // Run the mutation
        fn();

        // After snapshots
        const after = measures.map(snapshotMeasure);

        // Check if anything actually changed (events, ticks, or chord names)
        const changed = measures.some((m, i) => {
            return JSON.stringify(before[i].events)       !== JSON.stringify(after[i].events)       ||
                   before[i].actualTicks                  !== after[i].actualTicks                  ||
                   JSON.stringify(before[i].chordNames)   !== JSON.stringify(after[i].chordNames)   ||
                   JSON.stringify(before[i].chordOffsets) !== JSON.stringify(after[i].chordOffsets) ||
                   JSON.stringify(before[i].chordBeats)   !== JSON.stringify(after[i].chordBeats);
        });
        if (!changed) return;

        pushCommand({
            label,
            undo: () => {
                measures.forEach((m, i) => restoreSnapshot(m, before[i]));
            },
            redo: () => {
                measures.forEach((m, i) => restoreSnapshot(m, after[i]));
            },
        });
    }

    function undo() {
        if (!canUndo.value) return;
        stack.value[pointer.value].undo();
        pointer.value--;
        relinkTiesGlobally(model.value);
    }

    function redo() {
        if (!canRedo.value) return;
        pointer.value++;
        stack.value[pointer.value].redo();
        relinkTiesGlobally(model.value);
    }

    function relinkTiesGlobally(modelData) {
        if (!modelData) return;
        const allEvents = [];
        modelData.sections.forEach(s => s.measures.forEach(m => allEvents.push(...m.events)));
        const byVoice = {};
        allEvents.forEach(e => {
            if (e.isRest) return;
            const v = e.voice || 1;
            if (!byVoice[v]) byVoice[v] = [];
            byVoice[v].push(e);
        });
        Object.values(byVoice).forEach(events => {
            events.sort((a, b) => {
                if (a.measureIdx !== b.measureIdx) return a.measureIdx - b.measureIdx;
                return a.tick - b.tick;
            });
            events.forEach(e => {
                e.notes.forEach(n => { n.tieStartEvent = null; n.tieEndEvent = null; n.tieStartNote = null; n.tieEndNote = null; });
            });
            for (let i = 0; i < events.length - 1; i++) {
                const ev1 = events[i];
                ev1.notes.forEach(n1 => {
                    if (n1.tieStart) {
                        for (let j = i + 1; j < events.length; j++) {
                            const ev2 = events[j];
                            const n2 = ev2.notes.find(n => n.string === n1.string);
                            if (n2 && n2.tieStop) {
                                n1.tieEndEvent = ev2;
                                n1.tieEndNote = n2;
                                n2.tieStartEvent = ev1;
                                n2.tieStartNote = n1;
                                break;
                            }
                        }
                    }
                });
            }
        });
    }

    /** Clear stack — call after a full model rebuild (e.g. new leadsheet loaded). */
    function reset() {
        stack.value = [];
        pointer.value = -1;
    }

    return {
        canUndo,
        canRedo,
        wrapCommand,
        undo,
        redo,
        reset,
    };
}

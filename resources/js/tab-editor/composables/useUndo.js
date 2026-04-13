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
            index:       measure.index,
            actualTicks: measure.actualTicks,
            events:      measure.events.map(ev => {
                // Destructure to strip circular reference properties
                const { tieStartEvent, tieEndEvent, beamWith, ...rest } = ev;
                return {
                    ...rest,
                    notes: ev.notes.map(n => {
                        const { tieStartEvent, tieEndEvent, tieStartNote, tieEndNote, ...nRest } = n;
                        return {
                            ...nRest,
                            tieStartEvent: null,
                            tieEndEvent: null,
                            tieStartNote: null,
                            tieEndNote: null,
                        };
                    }),
                    // These are object references that create circular references
                    // They will be re-linked after restore
                    tieStartEvent: null,
                    tieEndEvent: null,
                    beamWith: null,
                };
            }),
        };
    }

    /**
     * Restore a snapshot onto a live measure object in-place.
     * Vue reactivity fires because we mutate the existing reactive ref.
     */
    function restoreSnapshot(measure, snap) {
        measure.actualTicks = snap.actualTicks;
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
        events.forEach(ev => {
            ev.beamWith = null;
        });

        // 1. Standard Beams (XML & Heuristic)
        const groups = {};
        events.forEach(ev => {
            if (ev.isRest || (!ev.beamStart && !ev.beamContinue && !ev.beamEnd)) return;
            const key = `${ev.measureIdx}-${ev.voice}`;
            if (!groups[key]) groups[key] = [];
            groups[key].push(ev);
        });
        for (const group of Object.values(groups)) {
            group.sort((a, b) => a.tick - b.tick);
            let beamGroup = [];
            group.forEach(ev => {
                if (ev.beamStart)    { beamGroup = [ev]; }
                else if (ev.beamContinue && beamGroup.length) { beamGroup.push(ev); }
                else if (ev.beamEnd  && beamGroup.length) {
                    beamGroup.push(ev);
                    beamGroup.forEach(m => { m.beamWith = beamGroup; });
                    beamGroup = [];
                }
            });
        }

        // 2. Unbeamed Tuplets (quarter-triplets, etc.)
        const tupGroups = {};
        events.forEach(e => {
            if (e.beamWith) return; 
            const key = `${e.measureIdx}-${e.voice}`;
            if (!tupGroups[key]) tupGroups[key] = [];
            tupGroups[key].push(e);
        });
        for (const group of Object.values(tupGroups)) {
            group.sort((a, b) => a.tick - b.tick);
            let tupGroup = [];
            const closeGroup = () => {
                if (tupGroup.length >= 2) {
                    tupGroup.forEach(m => { m.beamWith = tupGroup; });
                }
                tupGroup = [];
            };
            group.forEach(e => {
                if (e.tupletType === 'start') {
                    if (tupGroup.length) closeGroup();
                    tupGroup = [e];
                } else if (tupGroup.length) {
                    tupGroup.push(e);
                    if (e.tupletType === 'stop') closeGroup();
                }
            });
            closeGroup();
        }
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

        // Check if anything actually changed
        const changed = measures.some((m, i) => {
            return JSON.stringify(before[i].events) !== JSON.stringify(after[i].events) ||
                   before[i].actualTicks !== after[i].actualTicks;
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
    }

    function redo() {
        if (!canRedo.value) return;
        pointer.value++;
        stack.value[pointer.value].redo();
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

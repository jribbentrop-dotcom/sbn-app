/**
 * SBN Tab Editor — Chord Grid Operations
 *
 * Single mutation surface for all chord-grid actions.
 * Every function wraps in useUndo.wrapCommand so all ops are undoable.
 *
 * Pattern A — chord name / voicing ops (fast path, per-measure snapshot):
 *   Snapshot cost: one (or a few) measures. Use when only chordNames or
 *   chordVoicings change in known measures.
 *
 * Pattern B — structural ops (slow path, full-model serialize/deserialize):
 *   Snapshot cost: full XML round-trip. Use for insert/delete bar, move bar,
 *   duplicate section. After apply, dispatches sbn-tab-sections-sync.
 */

/**
 * @param {import('vue').Ref} model          — model.value from useTabModel
 * @param {object}            undo            — useUndo instance
 * @param {object}            tabModel        — full useTabModel return (for structural ops)
 */
export function useChordGridOps(model, undo, tabModel) {

    // ── Helpers ─────────────────────────────────────────────

    /**
     * Find a measure in the live model by its global index.
     */
    function _findMeasureByGi(gi) {
        if (!model.value) return null;
        for (const sec of model.value.sections) {
            for (const m of sec.measures) {
                if (m.index === gi) return m;
            }
        }
        return null;
    }

    /**
     * Convert global index → { si, mi } (section index + local measure index).
     */
    function _globalToLocal(gi) {
        if (!model.value) return null;
        let g = 0;
        for (let si = 0; si < model.value.sections.length; si++) {
            const sec = model.value.sections[si];
            for (let mi = 0; mi < sec.measures.length; mi++) {
                if (g === gi) return { si, mi };
                g++;
            }
        }
        return null;
    }

    /** Dispatch sbn-tab-sections-sync so Alpine analysis panel is invalidated. */
    function _dispatchSync() {
        window.dispatchEvent(new CustomEvent('sbn-tab-sections-sync'));
    }

    // ── Private: voicing key helpers ─────────────────────────

    /**
     * Rename voicing key after a chord name change.
     * Old key: `oldName@gi.ci`  →  new key: `newName@gi.ci`
     * Also updates the global-less key `oldName` → `newName` only if oldName
     * is not used anywhere else in the model.
     *
     * @param {object} cv      — model.value.chordVoicings (mutated in place)
     * @param {string} oldName
     * @param {string} newName
     * @param {number} gi      — global measure index
     * @param {number} ci      — chord slot index
     */
    function _renameVoicingKey(cv, oldName, newName, gi, ci) {
        if (!cv || !oldName || !newName || oldName === newName) return;

        // Rename specific positional key
        const oldKey = `${oldName}@${gi}.${ci}`;
        const newKey = `${newName}@${gi}.${ci}`;
        if (cv[oldKey] !== undefined) {
            cv[newKey] = cv[oldKey];
            delete cv[oldKey];
        }

        // Rename global key only if oldName is no longer used anywhere in the model
        if (cv[oldName] !== undefined) {
            const stillUsed = model.value.sections.some(sec =>
                sec.measures.some(m =>
                    (m.chordNames || []).includes(oldName)
                )
            );
            if (!stillUsed) {
                cv[newName] = cv[oldName];
                delete cv[oldName];
            }
        }
    }

    /**
     * After removing a chord slot at ci in measure gi, renumber all
     * `name@gi.X` keys where X > ci by decrementing X by 1.
     *
     * @param {object} cv — model.value.chordVoicings (mutated in place)
     * @param {number} gi — global measure index
     * @param {number} ci — removed chord index
     */
    function _compactChordIndicesInMeasure(cv, gi, ci) {
        if (!cv) return;
        const re = /^(.+)@(\d+)\.(\d+)$/;
        const toRename = [];
        for (const key of Object.keys(cv)) {
            const m = re.exec(key);
            if (!m) continue;
            const keyGi = parseInt(m[2], 10);
            const keyCi = parseInt(m[3], 10);
            if (keyGi === gi && keyCi > ci) {
                toRename.push({ key, name: m[1], ci: keyCi });
            }
        }
        // Sort ascending so renames don't collide
        toRename.sort((a, b) => a.ci - b.ci);
        for (const { key, name, ci: oldCi } of toRename) {
            const newKey = `${name}@${gi}.${oldCi - 1}`;
            if (newKey !== key) {
                cv[newKey] = cv[key];
                delete cv[key];
            }
        }
    }

    // ── Pattern A: chord name operations ────────────────────

    /**
     * Rename a single chord slot.
     *
     * @param {number} gi   — global measure index
     * @param {number} ci   — chord index within measure
     * @param {string} name — new chord name
     */
    function setChordName(gi, ci, name) {
        const m = _findMeasureByGi(gi);
        if (!m) return;
        const oldName = m.chordNames[ci] || '';

        undo.wrapCommand('Rename chord', [gi], () => {
            // Ensure array is long enough
            while (m.chordNames.length <= ci) m.chordNames.push('');
            m.chordNames[ci] = name;
            _renameVoicingKey(model.value.chordVoicings, oldName, name, gi, ci);
        });
        _dispatchSync();
    }

    /**
     * Clear chord name(s) identified by a selection array [{gi, ci}].
     * Clears the name to '' (keeps the slot) — use deleteChordSlot to fully remove.
     * Grouped by measure so each affected measure is one undo entry.
     *
     * @param {Array<{gi:number, ci:number}>} selection
     */
    function deleteChords(selection) {
        if (!selection?.length) return;

        // Group by gi so we snapshot each measure once
        const byGi = new Map();
        for (const { gi, ci } of selection) {
            if (!byGi.has(gi)) byGi.set(gi, []);
            byGi.get(gi).push(ci);
        }

        for (const [gi, indices] of byGi) {
            const m = _findMeasureByGi(gi);
            if (!m) continue;

            undo.wrapCommand('Delete chord(s)', [gi], () => {
                // Sort descending so splice indices don't shift
                const sorted = [...indices].sort((a, b) => b - a);
                for (const ci of sorted) {
                    const oldName = m.chordNames[ci] || '';
                    // Remove the slot entirely
                    m.chordNames.splice(ci, 1);
                    // Delete positional voicing key for this slot
                    const key = `${oldName}@${gi}.${ci}`;
                    if (model.value.chordVoicings && model.value.chordVoicings[key]) {
                        delete model.value.chordVoicings[key];
                    }
                    // Compact remaining chord indices in this measure
                    _compactChordIndicesInMeasure(model.value.chordVoicings, gi, ci);
                }
                // Redistribute positions evenly across remaining slots
                _recomputeEvenOffsets(m);
            });
        }
    }

    /**
     * Recompute chordOffsets / chordBeats for a measure using even distribution.
     * Called after any structural change to chordNames (add/remove slot).
     */
    function _recomputeEvenOffsets(m) {
        const bpm = (model.value?.ticksPerMeasure ?? 1920) / 480;
        const count = m.chordNames.length;
        const newOffsets = count === 0 ? [] : m.chordNames.map((_, i) => i * (bpm / count));
        const newBeats   = count === 0 ? [] : m.chordNames.map(() => bpm / count);
        if (!m.chordOffsets) m.chordOffsets = [];
        if (!m.chordBeats)   m.chordBeats   = [];
        m.chordOffsets.splice(0, m.chordOffsets.length, ...newOffsets);
        m.chordBeats.splice(0, m.chordBeats.length, ...newBeats);
    }

    /**
     * Add a new chord slot to a measure (appends empty string).
     *
     * @param {number} gi — global measure index
     */
    function addChordToMeasure(gi) {
        const m = _findMeasureByGi(gi);
        if (!m) return;

        undo.wrapCommand('Add chord', [gi], () => {
            m.chordNames.push('');
            _recomputeEvenOffsets(m);
        });
    }

    /**
     * Add a single chord slot at a specific beat offset, snapped to 0.5-beat grid.
     * The slot spans from beatOffset to the next existing slot (or measure end).
     * Existing slots are not moved — the new slot is inserted in sorted order.
     *
     * @param {number} gi
     * @param {number} beatOffset — beat position (quarter beats from measure start)
     */
    function addChordAtBeat(gi, beatOffset) {
        const m = _findMeasureByGi(gi);
        if (!m) return;
        const bpm = (model.value?.ticksPerMeasure ?? 1920) / 480;

        undo.wrapCommand('Add chord at beat', [gi], () => {
            // Snap to 0.5-beat grid, clamp to measure
            const snapped = Math.max(0, Math.min(bpm - 0.5, Math.round(beatOffset / 0.5) * 0.5));

            if (!m.chordNames) m.chordNames = [];
            if (!m.chordOffsets) m.chordOffsets = [];
            if (!m.chordBeats) m.chordBeats = [];

            // Find insertion index (sorted by offset)
            const offsets = [...m.chordOffsets];
            let insertAt = offsets.findIndex(o => o > snapped);
            if (insertAt === -1) insertAt = offsets.length;

            // Duration: from snapped to next slot (or measure end)
            const nextOffset = insertAt < offsets.length ? offsets[insertAt] : bpm;
            const duration = Math.max(0.5, nextOffset - snapped);

            // Trim the preceding slot's duration if it overlaps
            if (insertAt > 0) {
                const prevEnd = offsets[insertAt - 1] + m.chordBeats[insertAt - 1];
                if (prevEnd > snapped) {
                    m.chordBeats[insertAt - 1] = snapped - offsets[insertAt - 1];
                }
            }

            m.chordNames.splice(insertAt, 0, '');
            m.chordOffsets.splice(insertAt, 0, snapped);
            m.chordBeats.splice(insertAt, 0, duration);
        });

        return (m.chordOffsets || []).findIndex(o => Math.abs(o - Math.round(beatOffset / 0.5) * 0.5) < 0.01);
    }

    /**
     * Move a chord slot to a new beat offset (8th-note grid drag-and-drop).
     *
     * Rules:
     *  - newOffset is snapped to 0.5-beat grid by the caller before this runs.
     *  - The dragged chord keeps its original duration unless a neighbor forces a trim.
     *  - If the dragged chord's end overlaps the START of another chord → that chord's
     *    start is pushed right (trimmed) to where the dragged chord ends. If the trim
     *    would reduce it below 0.5 beats it is removed entirely (fully covered).
     *  - If newOffset lands fully inside another chord AND the dragged chord's end also
     *    stays inside that chord → replace mode: remove the target slot entirely.
     *  - Gap is left where the dragged chord came from; neighbors don't expand.
     *
     * @param {number} gi        — global measure index
     * @param {number} ci        — chord slot index being dragged
     * @param {number} newOffset — snapped beat offset (quarter beats from measure start)
     */
    function setChordOffset(gi, ci, newOffset) {
        const m = _findMeasureByGi(gi);
        if (!m) return;

        const bpm = (model.value?.ticksPerMeasure ?? 1920) / 480;

        undo.wrapCommand('Move chord', [gi], () => {
            // Work on local copies — write back all three arrays atomically at the end
            const names   = [...m.chordNames];
            const offsets = [...(m.chordOffsets || names.map((_, i) => i * (bpm / names.length)))];
            const beats   = [...(m.chordBeats   || names.map(() => bpm / names.length))];

            const dragBeats = beats[ci];
            const newEnd    = newOffset + dragBeats;

            offsets[ci] = newOffset;

            // Resolve collisions with every other slot
            const toRemove = [];
            for (let i = 0; i < names.length; i++) {
                if (i === ci) continue;
                const slotStart = offsets[i];
                const slotEnd   = slotStart + beats[i];

                // No overlap at all — skip
                if (newEnd <= slotStart || newOffset >= slotEnd) continue;

                // Dragged chord fully covers the other slot → remove it
                if (newOffset <= slotStart && newEnd >= slotEnd) {
                    toRemove.push(i);
                    continue;
                }

                // Dragged chord overlaps the RIGHT end of a preceding slot
                // (dragged start lands inside it) → trim that slot's end
                if (newOffset > slotStart && newOffset < slotEnd) {
                    const remaining = newOffset - slotStart;
                    if (remaining < 0.5) {
                        toRemove.push(i);
                    } else {
                        beats[i] = remaining;
                    }
                    continue;
                }

                // Dragged chord overlaps the LEFT start of a following slot
                // (dragged end lands inside it) → trim that slot's start
                if (newEnd > slotStart && newEnd < slotEnd) {
                    const remaining = slotEnd - newEnd;
                    if (remaining < 0.5) {
                        toRemove.push(i);
                    } else {
                        offsets[i] = newEnd;
                        beats[i]   = remaining;
                    }
                }
            }

            // Remove fully-covered slots descending so indices stay valid
            toRemove.sort((a, b) => b - a);
            for (const i of toRemove) {
                const removedName = names[i];
                names.splice(i, 1);
                offsets.splice(i, 1);
                beats.splice(i, 1);
                const key = `${removedName}@${gi}.${i}`;
                if (model.value.chordVoicings?.[key]) delete model.value.chordVoicings[key];
                _compactChordIndicesInMeasure(model.value.chordVoicings, gi, i);
            }

            // Write back in-place so Vue's reactivity on the existing array refs fires
            m.chordNames.splice(0, m.chordNames.length, ...names);
            if (!m.chordOffsets) m.chordOffsets = [];
            m.chordOffsets.splice(0, m.chordOffsets.length, ...offsets);
            if (!m.chordBeats) m.chordBeats = [];
            m.chordBeats.splice(0, m.chordBeats.length, ...beats);
        });
    }

    /**
     * Resize a chord slot by dragging its right edge to a new beat end position.
     * Start is fixed. Same collision rules as setChordOffset:
     *  - newEnd trims the start of any following chord it overlaps
     *  - newEnd fully covering a following chord removes it
     *  - minimum duration 0.5 beats
     *
     * @param {number} gi     — global measure index
     * @param {number} ci     — chord slot index being resized
     * @param {number} newEnd — snapped beat end position (quarter beats from measure start)
     */
    function setChordEnd(gi, ci, newEnd) {
        const m = _findMeasureByGi(gi);
        if (!m) return;

        const bpm = (model.value?.ticksPerMeasure ?? 1920) / 480;

        undo.wrapCommand('Resize chord', [gi], () => {
            const names   = [...m.chordNames];
            const offsets = [...(m.chordOffsets || names.map((_, i) => i * (bpm / names.length)))];
            const beats   = [...(m.chordBeats   || names.map(() => bpm / names.length))];

            const fixedStart = offsets[ci];
            const clampedEnd = Math.max(fixedStart + 0.5, Math.min(newEnd, bpm));
            beats[ci] = clampedEnd - fixedStart;

            const toRemove = [];
            for (let i = 0; i < names.length; i++) {
                if (i === ci) continue;
                const slotStart = offsets[i];
                const slotEnd   = slotStart + beats[i];

                if (clampedEnd <= slotStart || fixedStart >= slotEnd) continue;

                // Fully covered → remove
                if (fixedStart <= slotStart && clampedEnd >= slotEnd) {
                    toRemove.push(i);
                    continue;
                }

                // Partial overlap on left of following slot → trim its start
                if (clampedEnd > slotStart && clampedEnd < slotEnd) {
                    const remaining = slotEnd - clampedEnd;
                    if (remaining < 0.5) {
                        toRemove.push(i);
                    } else {
                        offsets[i] = clampedEnd;
                        beats[i]   = remaining;
                    }
                }
            }

            toRemove.sort((a, b) => b - a);
            for (const i of toRemove) {
                const removedName = names[i];
                names.splice(i, 1);
                offsets.splice(i, 1);
                beats.splice(i, 1);
                const key = `${removedName}@${gi}.${i}`;
                if (model.value.chordVoicings?.[key]) delete model.value.chordVoicings[key];
                _compactChordIndicesInMeasure(model.value.chordVoicings, gi, i);
            }

            m.chordNames.splice(0, m.chordNames.length, ...names);
            if (!m.chordOffsets) m.chordOffsets = [];
            m.chordOffsets.splice(0, m.chordOffsets.length, ...offsets);
            if (!m.chordBeats) m.chordBeats = [];
            m.chordBeats.splice(0, m.chordBeats.length, ...beats);
        });
    }

    /**
     * Resize a chord slot by dragging its left edge to a new beat start position.
     * End is fixed. Same collision rules: trims/removes preceding chords that overlap.
     *
     * @param {number} gi       — global measure index
     * @param {number} ci       — chord slot index being resized
     * @param {number} newStart — snapped beat start position
     */
    function setChordStart(gi, ci, newStart) {
        const m = _findMeasureByGi(gi);
        if (!m) return;

        const bpm = (model.value?.ticksPerMeasure ?? 1920) / 480;

        undo.wrapCommand('Resize chord', [gi], () => {
            const names   = [...m.chordNames];
            const offsets = [...(m.chordOffsets || names.map((_, i) => i * (bpm / names.length)))];
            const beats   = [...(m.chordBeats   || names.map(() => bpm / names.length))];

            const fixedEnd    = offsets[ci] + beats[ci];
            const clampedStart = Math.min(Math.max(0, newStart), fixedEnd - 0.5);
            offsets[ci] = clampedStart;
            beats[ci]   = fixedEnd - clampedStart;

            const toRemove = [];
            for (let i = 0; i < names.length; i++) {
                if (i === ci) continue;
                const slotStart = offsets[i];
                const slotEnd   = slotStart + beats[i];

                if (clampedStart >= slotEnd || fixedEnd <= slotStart) continue;

                // Fully covered → remove
                if (clampedStart <= slotStart && fixedEnd >= slotEnd) {
                    toRemove.push(i);
                    continue;
                }

                // New start cuts into the right end of a preceding slot → trim its end
                if (clampedStart > slotStart && clampedStart < slotEnd) {
                    const remaining = clampedStart - slotStart;
                    if (remaining < 0.5) {
                        toRemove.push(i);
                    } else {
                        beats[i] = remaining;
                    }
                }
            }

            toRemove.sort((a, b) => b - a);
            for (const i of toRemove) {
                const removedName = names[i];
                names.splice(i, 1);
                offsets.splice(i, 1);
                beats.splice(i, 1);
                const key = `${removedName}@${gi}.${i}`;
                if (model.value.chordVoicings?.[key]) delete model.value.chordVoicings[key];
                _compactChordIndicesInMeasure(model.value.chordVoicings, gi, i);
            }

            m.chordNames.splice(0, m.chordNames.length, ...names);
            if (!m.chordOffsets) m.chordOffsets = [];
            m.chordOffsets.splice(0, m.chordOffsets.length, ...offsets);
            if (!m.chordBeats) m.chordBeats = [];
            m.chordBeats.splice(0, m.chordBeats.length, ...beats);
        });
    }

    // ── Pattern B: structural operations ────────────────────

    /**
     * Insert an empty bar after the measure at (si, mi).
     *
     * @param {number} si — section index
     * @param {number} mi — local measure index within section
     */
    function insertBarAfter(si, mi) {
        if (!model.value) return;
        undo.wrapCommand('Insert bar', [], () => {
            tabModel.insertMeasureAfter(si, mi);
        }, {
            serializeModel: tabModel.serializeModel,
            deserializeModel: tabModel.deserializeModel,
            afterApply: _dispatchSync,
        });
    }

    /**
     * Insert an empty bar before the measure at (si, mi).
     *
     * @param {number} si — section index
     * @param {number} mi — local measure index within section
     */
    function insertBarBefore(si, mi) {
        if (!model.value) return;
        undo.wrapCommand('Insert bar before', [], () => {
            tabModel.insertMeasureBefore(si, mi);
        }, {
            serializeModel: tabModel.serializeModel,
            deserializeModel: tabModel.deserializeModel,
            afterApply: _dispatchSync,
        });
    }

    /**
     * Insert `count` empty bars after global index gi (all in one undo step).
     */
    function insertBarsAfterGi(gi, count) {
        if (!model.value || count < 1) return;
        undo.wrapCommand(`Insert ${count} bars`, [], () => {
            for (let i = 0; i < count; i++) {
                // Re-resolve each time since indices shift after each insert
                const coord = _globalToLocal(gi + i);
                if (coord) tabModel.insertMeasureAfter(coord.si, coord.mi);
            }
        }, {
            serializeModel: tabModel.serializeModel,
            deserializeModel: tabModel.deserializeModel,
            afterApply: _dispatchSync,
        });
    }

    /**
     * Insert `count` empty bars before global index gi (all in one undo step).
     */
    function insertBarsBeforeGi(gi, count) {
        if (!model.value || count < 1) return;
        undo.wrapCommand(`Insert ${count} bars`, [], () => {
            for (let i = 0; i < count; i++) {
                // Always insert at same gi — each insert pushes previous ones forward
                const coord = _globalToLocal(gi);
                if (coord) tabModel.insertMeasureBefore(coord.si, coord.mi);
            }
        }, {
            serializeModel: tabModel.serializeModel,
            deserializeModel: tabModel.deserializeModel,
            afterApply: _dispatchSync,
        });
    }

    /**
     * Delete the bar at global index gi.
     *
     * @param {number} gi — global measure index
     */
    function deleteBar(gi) {
        if (!model.value) return;
        const coord = _globalToLocal(gi);
        if (!coord) return;

        undo.wrapCommand('Delete bar', [], () => {
            tabModel.deleteMeasure(coord.si, coord.mi);
        }, {
            serializeModel: tabModel.serializeModel,
            deserializeModel: tabModel.deserializeModel,
            afterApply: _dispatchSync,
        });
    }

    /**
     * Delete multiple bars by global indices.
     *
     * @param {number[]} globalIndices
     */
    function deleteBars(globalIndices) {
        if (!model.value || !globalIndices?.length) return;
        undo.wrapCommand('Delete bars', [], () => {
            tabModel.deleteMeasuresByGlobalIndices(globalIndices);
        }, {
            serializeModel: tabModel.serializeModel,
            deserializeModel: tabModel.deserializeModel,
            afterApply: _dispatchSync,
        });
    }

    /**
     * Move a bar within a section from fromMi → toMi (local indices).
     *
     * @param {number} si     — section index
     * @param {number} fromMi — source local measure index
     * @param {number} toMi   — target local measure index
     */
    function moveBar(si, fromMi, toMi) {
        if (!model.value) return;
        undo.wrapCommand('Move bar', [], () => {
            tabModel.moveMeasure(si, fromMi, toMi);
        }, {
            serializeModel: tabModel.serializeModel,
            deserializeModel: tabModel.deserializeModel,
            afterApply: _dispatchSync,
        });
    }

    /**
     * Duplicate the section at si — creates a new section immediately after
     * with copies of all measures and chord names (voicings shared by reference).
     *
     * @param {number} si — section index to duplicate
     */
    function duplicateSection(si) {
        if (!model.value) return;
        undo.wrapCommand('Duplicate section', [], () => {
            const src = model.value.sections[si];
            if (!src) return;

            const cloneMeasure = (m) => ({
                ...m,
                index: 0,               // will be re-indexed below
                chordNames: [...(m.chordNames || [])],
                events: m.events.map(ev => ({
                    ...ev,
                    beamWith: null,
                    notes: ev.notes.map(n => ({ ...n })),
                })),
            });

            const letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
            const existingIds = model.value.sections.map(s => s.id).filter(id => id?.length === 1);
            const lastId = existingIds[existingIds.length - 1] || 'A';
            const lastIdx = letters.indexOf(lastId);
            const nextId = lastIdx >= 0 && lastIdx < letters.length - 1
                ? letters[lastIdx + 1]
                : (src.id || '') + '2';

            const newSec = {
                id: nextId,
                name: src.name || '',
                lineBreaks: src.lineBreaks ? [...src.lineBreaks] : null,
                measures: src.measures.map(cloneMeasure),
            };

            model.value.sections.splice(si + 1, 0, newSec);

            // Re-index all measures globally
            let gi = 0;
            const tpm = model.value.ticksPerMeasure;
            model.value.sections.forEach(sec => {
                sec.measures.forEach(m => {
                    m.index = gi;
                    m.events.forEach(ev => {
                        ev.measureIdx = gi;
                        ev.tick = gi * tpm + ev.tickInMeasure;
                    });
                    gi++;
                });
            });
        }, {
            serializeModel: tabModel.serializeModel,
            deserializeModel: tabModel.deserializeModel,
            afterApply: _dispatchSync,
        });
    }

    // ── Volta / repeat toggle ops ─────────────────────────────

    /**
     * Toggle a repeat-start barline on a measure.
     * @param {number} gi
     */
    function toggleRepeatStart(gi) {
        const m = _findMeasureByGi(gi);
        if (!m) return;
        undo.wrapCommand('Toggle Repeat Start', [gi], () => {
            m.repeatStart = !m.repeatStart;
        });
        _dispatchSync();
    }

    /**
     * Toggle a repeat-end barline on a measure.
     * @param {number} gi
     */
    function toggleRepeatEnd(gi) {
        const m = _findMeasureByGi(gi);
        if (!m) return;
        undo.wrapCommand('Toggle Repeat End', [gi], () => {
            m.repeatEnd = !m.repeatEnd;
        });
        _dispatchSync();
    }

    /**
     * Set a volta bracket start on a measure.
     * Clears any existing volta on that measure first.
     *
     * @param {number} gi     — global measure index (the first bar of the bracket)
     * @param {number} number — volta number (1, 2, …)
     */
    function setVoltaStart(gi, number) {
        const m = _findMeasureByGi(gi);
        if (!m) return;
        undo.wrapCommand(`Set Volta ${number}`, [gi], () => {
            m.volta      = { number, text: `${number}.` };
            m.voltaStart = true;
            // Don't touch voltaEnd here — caller sets the end separately,
            // or setVoltaEnd is called on the last bar of the bracket.
        });
        _dispatchSync();
    }

    /**
     * Mark a measure as the end of the current volta bracket.
     * The measure must already carry a volta object (set by setVoltaStart or
     * by being in the middle of a multi-bar bracket).
     *
     * @param {number} gi
     */
    function setVoltaEnd(gi) {
        const m = _findMeasureByGi(gi);
        if (!m) return;
        undo.wrapCommand('Set Volta End', [gi], () => {
            m.voltaEnd = true;
            // Clear any trailing bars that were part of this bracket
            let i = gi + 1;
            while (true) {
                const next = _findMeasureByGi(i);
                if (!next || !next.volta || next.voltaStart) break;
                next.volta     = null;
                next.voltaEnd  = false;
                i++;
            }
        });
        _dispatchSync();
    }

    /**
     * Extend a volta bracket from its current end to a new end measure.
     * Walks from the volta-start backwards to find the bracket's number,
     * then stamps volta data onto all intermediate measures up to newEndGi.
     *
     * @param {number} gi       — current last bar of the bracket (volta.voltaEnd === true)
     * @param {number} newEndGi — new last bar (must be > gi)
     */
    function extendVoltaEnd(gi, newEndGi) {
        if (!model.value || newEndGi <= gi) return;

        // Find the volta number by scanning back to the voltaStart
        let voltaNum = null;
        for (let i = gi; i >= 0; i--) {
            const candidate = _findMeasureByGi(i);
            if (!candidate) break;
            if (candidate.volta) { voltaNum = candidate.volta.number; }
            if (candidate.voltaStart) break;
        }
        if (voltaNum === null) return;

        undo.wrapCommand('Extend Volta', [], () => {
            // Un-end the old last bar
            const oldEnd = _findMeasureByGi(gi);
            if (oldEnd) oldEnd.voltaEnd = false;

            // Stamp intermediate bars
            for (let i = gi + 1; i <= newEndGi; i++) {
                const im = _findMeasureByGi(i);
                if (!im) continue;
                im.volta     = { number: voltaNum, text: `${voltaNum}.` };
                im.voltaEnd  = (i === newEndGi);
            }
        });
        _dispatchSync();
    }

    /**
     * Clear volta data from a measure (and propagate clear to the whole bracket
     * if this is the voltaStart bar).
     *
     * @param {number} gi
     */
    function clearVolta(gi) {
        if (!model.value) return;
        const m = _findMeasureByGi(gi);
        if (!m) return;

        undo.wrapCommand('Clear Volta', [], () => {
            if (m.voltaStart) {
                // Clear the whole bracket forward
                let clearing = true;
                let i = gi;
                while (clearing) {
                    const bar = _findMeasureByGi(i);
                    if (!bar) break;
                    const wasEnd = bar.voltaEnd;
                    bar.volta      = null;
                    bar.voltaStart = false;
                    bar.voltaEnd   = false;
                    if (wasEnd) clearing = false;
                    i++;
                }
            } else {
                // Just clear this single bar (middle or end of a bracket)
                m.volta      = null;
                m.voltaStart = false;
                m.voltaEnd   = false;
            }
        });
        _dispatchSync();
    }

    return {
        // Pattern A
        setChordName,
        deleteChords,
        addChordToMeasure,
        addChordAtBeat,
        setChordOffset,
        setChordEnd,
        setChordStart,
        // Repeat / volta
        toggleRepeatStart,
        toggleRepeatEnd,
        setVoltaStart,
        setVoltaEnd,
        extendVoltaEnd,
        clearVolta,
        // Pattern B
        insertBarAfter,
        insertBarBefore,
        insertBarsAfterGi,
        insertBarsBeforeGi,
        deleteBar,
        deleteBars,
        moveBar,
        duplicateSection,
        // Exposed helpers (used by voicing ops in Step 5)
        _renameVoicingKey,
        _compactChordIndicesInMeasure,
        _findMeasureByGi,
    };
}

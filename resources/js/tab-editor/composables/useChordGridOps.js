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
        if (count === 0) {
            m.chordOffsets = [];
            m.chordBeats   = [];
        } else {
            const slotBeats = bpm / count;
            m.chordOffsets  = m.chordNames.map((_, i) => i * slotBeats);
            m.chordBeats    = m.chordNames.map(() => slotBeats);
        }
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

    return {
        // Pattern A
        setChordName,
        deleteChords,
        addChordToMeasure,
        // Pattern B
        insertBarAfter,
        insertBarBefore,
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

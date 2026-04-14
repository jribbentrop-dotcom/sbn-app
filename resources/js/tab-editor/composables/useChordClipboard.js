/**
 * SBN Tab Editor — Chord Clipboard
 *
 * Copy / cut / paste of chord arrays between measures.
 *
 * Clipboard entry shape:
 *   {
 *     chordNames: string[],        // e.g. ['Am7', 'D7']
 *     voicings:   object,          // subset of chordVoicings for these slots (keyed by ci)
 *   }
 *
 * On paste, chord names are written into the target measure and voicings are
 * cloned into model.value.chordVoicings under the target gi/ci keys.
 */

import { ref } from 'vue';

export function useChordClipboard(model, undo) {

    /**
     * @type {import('vue').Ref<{chordNames: string[], voicings: object} | null>}
     */
    const clipboard = ref(null);

    const hasClipboard = ref(false);

    // ── Helpers ─────────────────────────────────────────────

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
     * Extract the voicing subset for a single measure's chord slots.
     * Returns a plain object keyed by ci: { 0: voicingObj, 1: voicingObj, ... }
     *
     * @param {string[]} chordNames
     * @param {number}   gi
     * @returns {object}
     */
    function _extractVoicings(chordNames, gi) {
        const cv = model.value?.chordVoicings || {};
        const out = {};
        chordNames.forEach((name, ci) => {
            if (!name) return;
            const posKey = `${name}@${gi}.${ci}`;
            if (cv[posKey]) {
                out[ci] = cv[posKey];
            } else if (cv[name]) {
                out[ci] = cv[name];
            }
        });
        return out;
    }

    // ── Public API ───────────────────────────────────────────

    /**
     * Copy chord names + voicings from a measure.
     *
     * @param {number} gi — global measure index
     */
    function copyMeasure(gi) {
        const m = _findMeasureByGi(gi);
        if (!m) return;
        const chordNames = [...(m.chordNames || [])];
        const voicings = _extractVoicings(chordNames, gi);
        clipboard.value = { chordNames, voicings };
        hasClipboard.value = true;
    }

    /**
     * Copy only the selected chord slots (not the whole measure).
     *
     * @param {Array<{gi:number, ci:number}>} selection
     */
    function copySelection(selection) {
        if (!selection?.length || !model.value) return;

        // For simplicity, copy from the first selected measure
        // (multi-measure copy pastes as a single sequence)
        const sorted = [...selection].sort((a, b) => a.gi !== b.gi ? a.gi - b.gi : a.ci - b.ci);
        const chordNames = [];
        const voicings = {};
        const cv = model.value.chordVoicings || {};

        sorted.forEach(({ gi, ci }, idx) => {
            const m = _findMeasureByGi(gi);
            if (!m) return;
            const name = m.chordNames?.[ci] || '';
            chordNames.push(name);
            if (name) {
                const posKey = `${name}@${gi}.${ci}`;
                if (cv[posKey]) voicings[idx] = cv[posKey];
                else if (cv[name]) voicings[idx] = cv[name];
            }
        });

        clipboard.value = { chordNames, voicings };
        hasClipboard.value = chordNames.length > 0;
    }

    /**
     * Cut: copy then clear the source chords.
     * Uses the ops-level wrapCommand so the cut is undoable.
     *
     * @param {number} gi — global measure index (cut whole measure)
     */
    function cutMeasure(gi) {
        copyMeasure(gi);
        const m = _findMeasureByGi(gi);
        if (!m) return;

        undo.wrapCommand('Cut chords', [gi], () => {
            m.chordNames.splice(0, m.chordNames.length);
        });
    }

    /**
     * Cut selected chord slots.
     *
     * @param {Array<{gi:number, ci:number}>} selection
     * @param {Function}                      deleteChords — from useChordGridOps
     */
    function cutSelection(selection, deleteChords) {
        copySelection(selection);
        deleteChords(selection);
    }

    /**
     * Paste clipboard contents into a target measure.
     * Replaces the target measure's chordNames and writes voicings under the new gi keys.
     *
     * @param {number} gi — target global measure index
     */
    function pasteMeasure(gi) {
        if (!clipboard.value || !model.value) return;
        const m = _findMeasureByGi(gi);
        if (!m) return;

        const { chordNames, voicings } = clipboard.value;

        undo.wrapCommand('Paste chords', [gi], () => {
            // Replace chord names in-place
            m.chordNames.splice(0, m.chordNames.length, ...chordNames);

            // Write voicings under target keys
            const cv = model.value.chordVoicings;
            chordNames.forEach((name, ci) => {
                if (!name) return;
                const srcVoicing = voicings[ci];
                if (srcVoicing) {
                    cv[`${name}@${gi}.${ci}`] = { ...srcVoicing };
                }
            });
        });
    }

    /**
     * Clear the clipboard.
     */
    function clearClipboard() {
        clipboard.value = null;
        hasClipboard.value = false;
    }

    return {
        clipboard,
        hasClipboard,
        copyMeasure,
        copySelection,
        cutMeasure,
        cutSelection,
        pasteMeasure,
        clearClipboard,
    };
}

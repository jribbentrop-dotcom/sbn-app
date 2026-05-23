/**
 * SBN Tab Editor — Chord Clipboard
 *
 * Copy / cut / paste of chord arrays between measures.
 *
 * Clipboard entry shape:
 *   {
 *     measures: Array<{ chordNames: string[], voicings: object }>,
 *       // one entry per copied measure, voicings keyed by ci
 *   }
 *
 * Single-measure copy produces measures.length === 1.
 * Multi-measure copy preserves bar boundaries and pastes across
 * consecutive measures starting from the target gi.
 */

import { ref } from 'vue';

export function useChordClipboard(model, undo) {

    /**
     * @type {import('vue').Ref<{measures: Array<{chordNames:string[],voicings:object}>}|null>}
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

    function _allMeasuresFlat() {
        const out = [];
        if (!model.value) return out;
        for (const sec of model.value.sections)
            for (const m of sec.measures) out.push(m);
        return out;
    }

    /**
     * Extract voicings for a single measure's chord slots.
     * Returns plain object keyed by ci index.
     */
    function _extractVoicings(chordNames, gi) {
        const cv = model.value?.chordVoicings || {};
        const out = {};
        chordNames.forEach((name, ci) => {
            if (!name) return;
            const posKey = `${name}@${gi}.${ci}`;
            if (cv[posKey])  out[ci] = cv[posKey];
            else if (cv[name]) out[ci] = cv[name];
        });
        return out;
    }

    // ── Public API ───────────────────────────────────────────

    /**
     * Copy chord names + voicings from a single measure.
     */
    function copyMeasure(gi) {
        const m = _findMeasureByGi(gi);
        if (!m) return;
        const chordNames = [...(m.chordNames || [])];
        clipboard.value = {
            measures: [{ chordNames, voicings: _extractVoicings(chordNames, gi) }],
        };
        hasClipboard.value = true;
    }

    /**
     * Copy selected chord slots, preserving measure boundaries.
     * Each distinct gi becomes its own entry in clipboard.measures.
     *
     * @param {Array<{gi:number, ci:number}>} selection
     */
    function copySelection(selection) {
        if (!selection?.length || !model.value) return;

        const sorted = [...selection].sort((a, b) => a.gi !== b.gi ? a.gi - b.gi : a.ci - b.ci);
        const cv = model.value.chordVoicings || {};

        // Group by gi, preserving order of first appearance
        const byGi = new Map();
        for (const { gi, ci } of sorted) {
            if (!byGi.has(gi)) byGi.set(gi, []);
            byGi.get(gi).push(ci);
        }

        const measures = [];
        for (const [gi, ciList] of byGi) {
            const m = _findMeasureByGi(gi);
            if (!m) continue;
            const chordNames = ciList.map(ci => m.chordNames?.[ci] || '');
            const voicings = {};
            ciList.forEach((ci, idx) => {
                const name = chordNames[idx];
                if (!name) return;
                const posKey = `${name}@${gi}.${ci}`;
                if (cv[posKey])    voicings[idx] = cv[posKey];
                else if (cv[name]) voicings[idx] = cv[name];
            });
            measures.push({ chordNames, voicings });
        }

        clipboard.value = { measures };
        hasClipboard.value = measures.length > 0;
    }

    /**
     * Cut: copy then clear the source chords.
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
     */
    function cutSelection(selection, deleteChords) {
        copySelection(selection);
        deleteChords(selection);
    }

    /**
     * Paste clipboard into consecutive measures starting at `gi`.
     * - If clipboard has 1 measure, pastes into the single target measure (original behaviour).
     * - If clipboard has N measures, pastes into measures gi, gi+1, … gi+N-1.
     *
     * @param {number} gi — target global measure index (first destination)
     */
    function pasteMeasure(gi) {
        if (!clipboard.value || !model.value) return;

        const { measures: clipMeasures } = clipboard.value;
        if (!clipMeasures?.length) return;

        const flat = _allMeasuresFlat();
        const startIdx = flat.findIndex(m => m.index === gi);
        if (startIdx === -1) return;

        // Collect affected gi values for undo tracking
        const affectedGis = [];
        for (let i = 0; i < clipMeasures.length && startIdx + i < flat.length; i++) {
            affectedGis.push(flat[startIdx + i].index);
        }

        undo.wrapCommand('Paste chords', affectedGis, () => {
            const cv = model.value.chordVoicings;
            for (let i = 0; i < clipMeasures.length && startIdx + i < flat.length; i++) {
                const destM  = flat[startIdx + i];
                const destGi = destM.index;
                const { chordNames, voicings } = clipMeasures[i];

                destM.chordNames.splice(0, destM.chordNames.length, ...chordNames);

                chordNames.forEach((name, ci) => {
                    if (!name) return;
                    const srcVoicing = voicings[ci];
                    if (srcVoicing) cv[`${name}@${destGi}.${ci}`] = { ...srcVoicing };
                });
            }
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

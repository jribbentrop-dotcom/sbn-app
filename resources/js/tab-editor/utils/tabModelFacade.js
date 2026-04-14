/**
 * window.__sbnTabModel facade  (Phase B Step 7)
 *
 * A thin singleton that lets Alpine (and any other non-Vue code) read live
 * Vue model data without owning or duplicating it.
 *
 * TabEditor.vue calls initTabModelFacade() once after useTabModel is set up.
 * Every subsequent call to window.__sbnTabModel.getSections() etc. reads
 * directly from the live Vue reactive model — no snapshot staleness.
 *
 * Consumers
 * ---------
 *  - Alpine blade: reads on sbn-tab-sections-sync (Step 7), save() (Step 8),
 *    loadAnalysis() (Step 9)
 *  - DevTools: window.__sbnTabModel.getSections() for debugging
 */

let _fns = null;

const facade = {
    /** True once TabEditor has registered its getter functions. */
    _ready: false,

    /** @private — called once by TabEditor.vue via initTabModelFacade() */
    _init(fns) {
        _fns   = fns;
        this._ready = true;
    },

    /**
     * Returns sections in Alpine shape: sections[].measures[].chords[{name, beats}]
     * Uses exportAlpineSections() so the shape is identical to what Alpine expects.
     */
    getSections()      { return _fns?.getSections()      ?? []; },

    /** Returns a plain-object clone of chordVoicings keyed by voicing key. */
    getChordVoicings() { return _fns?.getChordVoicings() ?? {}; },

    /** Returns repeatMarkers or null. */
    getRepeatMarkers() { return _fns?.getRepeatMarkers() ?? null; },

    /** Returns voltaEndings or null. */
    getVoltaEndings()  { return _fns?.getVoltaEndings()  ?? null; },

    /**
     * Returns song metadata: { title, composer, key, tempo, timeSignature }.
     * Useful for save() Step 8 so Alpine doesn't have to keep parsed.title etc.
     */
    getMeta()          { return _fns?.getMeta()          ?? {}; },
};

if (typeof window !== 'undefined' && !window.__sbnTabModel) {
    window.__sbnTabModel = facade;
}

/**
 * Register Vue model getters with the facade.
 * Call this once from TabEditor.vue after useTabModel() is destructured.
 *
 * @param {{ getSections, getChordVoicings, getRepeatMarkers, getVoltaEndings, getMeta }} fns
 */
export function initTabModelFacade({ getSections, getChordVoicings, getRepeatMarkers, getVoltaEndings, getMeta }) {
    if (typeof window !== 'undefined') {
        window.__sbnTabModel._init({ getSections, getChordVoicings, getRepeatMarkers, getVoltaEndings, getMeta });
    }
}

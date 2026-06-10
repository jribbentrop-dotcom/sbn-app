/**
 * Shared JSDoc types for the SBN audio engine.
 * See docs/audio-engine-contract.md for the authoritative design.
 *
 * @typedef {number} Beats   Musical time in beats from transport 0 (not seconds).
 * @typedef {number} MIDINote  0..127
 *
 * @typedef {'pitched'|'nylon'|'percussion'|'muted'|'clave'|'noise'} Voice
 * @typedef {'soft'|'accent'} Variant
 *
 * @typedef {Object} EngineEvent
 * @property {Beats}    time                When (in beats) the event fires.
 * @property {Voice}    voice               Which voice renders it.
 * @property {MIDINote} [pitch]             Required for pitched/muted/clave voices.
 * @property {Beats}    duration            Note length, in beats.
 * @property {number}   velocity            0..1
 * @property {Variant}  [variant]           Percussion accent/soft.
 * @property {string}   [sample]            Percussion sample bucket id.
 * @property {{fromSemitones:number,toSemitones:number,atBeat:Beats}} [bend]
 * @property {boolean}  [tieNext]           Glue to following note (legato).
 * @property {'palm-mute'|'ghost'|'staccato'} [articulation]
 * @property {string}   [sourceId]          Stable id from the source model, for UI highlight.
 *
 * @typedef {Object} AdapterContext
 * @property {number}  tempoBpm
 * @property {Beats}   [startBeat]
 * @property {number}  [repeat]
 *
 * @typedef {() => void} Unsubscribe
 */
export {};

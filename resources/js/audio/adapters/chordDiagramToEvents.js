/**
 * ChordDiagram model → EngineEvent[] adapter.
 * Pure function: no side effects, no Tone.js imports, no DOM access.
 *
 * Converts a ChordDiagram into EngineEvents representing a single chord strum.
 * Derives MIDI from fret positions + standard tuning (NOT from notes column).
 *
 * Standard tuning (string 1 = Low E):
 *   String 1 (Low E) = MIDI 40 (E2)
 *   String 2 (A)     = MIDI 45 (A2)
 *   String 3 (D)     = MIDI 50 (D3)
 *   String 4 (G)     = MIDI 55 (G3)
 *   String 5 (B)     = MIDI 59 (B3)
 *   String 6 (Hi E)  = MIDI 64 (E4)
 *
 * @typedef {import('../types.js').EngineEvent} EngineEvent
 * @typedef {import('../types.js').Beats} Beats
 */

/** @type {number[]} — MIDI note numbers for open strings (1-indexed, so index 0 is unused) */
const OPEN_STRING_MIDI = [null, 40, 45, 50, 55, 59, 64];

/**
 * Convert a ChordDiagram model to engine events.
 *
 * @param {Object} model — ChordDiagram from backend
 * @param {number} model.id — diagram ID
 * @param {Object} model.diagram_data
 * @param {Array<{string:number, fret:number}>} model.diagram_data.positions — explicit fretted positions
 * @param {number[]} model.diagram_data.open — string numbers played open (fret 0)
 * @param {number[]} model.diagram_data.muted — string numbers to skip
 * @param {Array<{fret:number, fromString:number, toString:number}>} model.diagram_data.barres — barre definitions
 * @param {Object} [ctx] — AdapterContext
 * @param {Beats} [ctx.startBeat=0] — offset
 * @param {Beats} [ctx.durationBeats=2] — chord hold duration
 * @returns {EngineEvent[]}
 */
export function chordDiagramToEvents(model, ctx = {}) {
    /** @type {EngineEvent[]} */
    const out = [];

    const startBeat = ctx.startBeat ?? 0;
    const duration = ctx.durationBeats ?? 2;
    const sourceId = `diagram-${model.id}`;

    // Build a map of string -> fret from explicit positions
    /** @type {Map<number, number>} */
    const stringFret = new Map();

    // Apply barres first (barre applies to all strings in range unless overridden)
    // DB stores from/to (not fromString/toString)
    const barres = model.diagram_data?.barres ?? [];
    for (const barre of barres) {
        const lo = barre.from ?? barre.fromString;
        const hi = barre.to   ?? barre.toString;
        for (let s = lo; s <= hi; s++) {
            stringFret.set(s, barre.fret);
        }
    }

    // Explicit positions override barres
    const positions = model.diagram_data?.positions ?? [];
    for (const pos of positions) {
        stringFret.set(pos.string, pos.fret);
    }

    // Add open strings (fret 0) unless already set
    const openStrings = model.diagram_data?.open ?? [];
    for (const s of openStrings) {
        if (!stringFret.has(s)) {
            stringFret.set(s, 0);
        }
    }

    // Determine muted strings to skip
    const mutedSet = new Set(model.diagram_data?.muted ?? []);

    // Collect sounding strings first (low→high order, string 1=low E)
    for (let s = 1; s <= 6; s++) {
        if (mutedSet.has(s)) continue;
        if (!stringFret.has(s)) continue;

        const fret = stringFret.get(s);
        const midi = OPEN_STRING_MIDI[s] + fret;

        out.push({
            time: startBeat,
            voice: 'pitched',
            pitch: midi,
            duration,
            velocity: 0.8,
            sourceId,
            stringNum: s, // carry string number for dot animation
        });
    }

    // Sort low→high (string 1 first)
    out.sort((a, b) => a.stringNum - b.stringNum);

    // Stagger: 120 ms per string. At 120 BPM, 1 beat = 500ms → 0.24 beats per string.
    // Can be overridden via ctx.staggerBeats for faster playback (e.g., progression viewer).
    const STAGGER_BEATS = ctx.staggerBeats ?? 0.36;
    out.forEach((ev, i) => { ev.time = startBeat + i * STAGGER_BEATS; });

    return out;
}

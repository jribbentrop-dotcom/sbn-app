/**
 * SBN Tab Editor — Chord Sync
 *
 * Utilities for bridging between the chord grid (Alpine) and the tab editor (Vue).
 *
 * Phase 7-int Step 5: Click chord name → open voicing picker with current voicing highlighted.
 * Phase 7-int Step 3: After fret edits, user-initiated chord identification from tab frets.
 *
 * String numbering conventions (TWO coexist — be careful):
 *   Tab model (note.string): 1 = high E, 6 = low E  (standard tab)
 *   Diagram / fret string:   0 = low E, 5 = high E  (SBN plugin convention)
 *
 * Conversion:
 *   diagIdx  = 6 - tabString   (tab 6 → diag 0, tab 1 → diag 5)
 *   tabString = 6 - diagIdx    (diag 0 → tab 6, diag 5 → tab 1)
 */

/**
 * Extract a fret string from the first chordal event (≥3 notes) under a chord position.
 *
 * @param {Object} measure        - TabModel measure { events, chordNames, index }
 * @param {number} chordIndex     - Index into measure.chordNames[]
 * @param {number} ticksPerMeasure
 * @returns {{ frets: string, position: number } | null}
 *          frets: 6-char string, diagram convention (position 0 = low E)
 *          position: lowest non-zero fret (min 1)
 *          null if no chordal event found in region
 */
export function extractFretsAtChord(measure, chordIndex, ticksPerMeasure) {
    const numChords = Math.max((measure.chordNames || []).length, 1);
    // Use parser-derived offsets when available, otherwise fall back to even division
    const bpm       = ticksPerMeasure / 480;  // beats per measure
    const hasOffsets = Array.isArray(measure.chordOffsets) && measure.chordOffsets.length === numChords;
    const hasBeats   = Array.isArray(measure.chordBeats)   && measure.chordBeats.length   === numChords;
    const evenBeats  = bpm / numChords;
    const startBeat  = hasOffsets ? measure.chordOffsets[chordIndex] : chordIndex * evenBeats;
    const durBeats   = hasBeats   ? measure.chordBeats[chordIndex]   : evenBeats;
    const startTick  = startBeat * 480;
    const endTick    = (startBeat + durBeats) * 480;

    // Find the event with the most notes in this slot (prefer ≥3, accept any)
    const candidates = (measure.events || []).filter(ev =>
        !ev.isRest &&
        ev.notes.length >= 1 &&
        ev.tickInMeasure >= startTick &&
        ev.tickInMeasure < endTick
    );
    if (!candidates.length) return null;
    const chordEvent = candidates.reduce((best, ev) => ev.notes.length > best.notes.length ? ev : best);

    // Build fret string: position 0 = SBN diagram string 1 = low E
    // Tab string → diagram index: diagIdx = 6 - tabString
    const result = ['x', 'x', 'x', 'x', 'x', 'x'];
    const nonZeroFrets = [];
    for (const note of chordEvent.notes) {
        const diagIdx = 6 - note.string;
        if (diagIdx < 0 || diagIdx > 5) continue;
        result[diagIdx] = note.fret <= 9 ? String(note.fret) : note.fret.toString(16);
        if (note.fret > 0) nonZeroFrets.push(note.fret);
    }

    return {
        frets:    result.join(''),
        position: nonZeroFrets.length ? Math.min(...nonZeroFrets) : 1,
    };
}

/**
 * Apply a voicing's frets to all chordal events under a chord position.
 * Preserves rhythm (duration, ticks). Clears ties. Nulls pitch/octave
 * (musicXmlWriter recalculates from string+fret on save).
 *
 * If no chordal events exist in the slot, generates a single new quarter-note event.
 *
 * @param {Object} measure        - TabModel measure
 * @param {number} chordIndex     - Index into measure.chordNames[]
 * @param {number} ticksPerMeasure
 * @param {string} frets          - 6-char diagram fret string (position 0 = low E)
 */
export function applyVoicingToChord(measure, chordIndex, ticksPerMeasure, frets) {
    const numChords  = Math.max((measure.chordNames || []).length, 1);
    const bpm        = ticksPerMeasure / 480;
    const hasOffsets = Array.isArray(measure.chordOffsets) && measure.chordOffsets.length === numChords;
    const hasBeats   = Array.isArray(measure.chordBeats)   && measure.chordBeats.length   === numChords;
    const evenBeats  = bpm / numChords;
    const startBeat  = hasOffsets ? measure.chordOffsets[chordIndex] : chordIndex * evenBeats;
    const durBeats   = hasBeats   ? measure.chordBeats[chordIndex]   : evenBeats;
    const startTick  = startBeat * 480;
    const endTick    = (startBeat + durBeats) * 480;
    const slotTicks  = endTick - startTick;

    // Parse diagram frets → tab note objects
    // Diagram index → tab string: tabString = 6 - diagIdx
    const voicingNotes = [];
    frets.split('').forEach((ch, diagIdx) => {
        if (ch === 'x') return;
        const fret = parseInt(ch, 16);
        if (isNaN(fret)) return;
        const tabString = 6 - diagIdx;
        voicingNotes.push({ string: tabString, fret, pitch: null, octave: null });
    });

    if (!voicingNotes.length) return;

    // Find all chordal events in this chord's time slot
    const chordalEvents = (measure.events || []).filter(ev =>
        !ev.isRest &&
        ev.notes.length >= 3 &&
        ev.tickInMeasure >= startTick &&
        ev.tickInMeasure < endTick
    );

    if (chordalEvents.length > 0) {
        // Replace notes[] on each chordal event, preserving rhythm
        for (const ev of chordalEvents) {
            ev.notes = voicingNotes.map(n => ({ ...n }));
            // Clear ties — chord shape change invalidates existing ties
            ev.tieStart      = false;
            ev.tieStop       = false;
            ev.tieStartEvent = null;
            ev.tieEndEvent   = null;
        }
    } else {
        // No chordal events — remove any rests covering this slot, then insert a quarter note
        const restIndices = [];
        measure.events.forEach((ev, i) => {
            if (ev.isRest && ev.tickInMeasure >= startTick && ev.tickInMeasure < endTick) {
                restIndices.push(i);
            }
        });
        for (let i = restIndices.length - 1; i >= 0; i--) {
            measure.events.splice(restIndices[i], 1);
        }

        const { generateId } = _importGenerateId();
        const tickInMeasure  = startTick;
        const baseTick       = measure.index * ticksPerMeasure;
        const ticks          = Math.min(480, slotTicks); // quarter or smaller slot
        const effectiveTicks = Math.max(tickInMeasure + ticks, ticksPerMeasure);

        const newEvent = {
            id:            generateId(),
            tick:          baseTick + tickInMeasure,
            tickInMeasure,
            measureIdx:    measure.index,
            duration:      'q',
            ticks,
            voice:         1,
            isRest:        false,
            notes:         voicingNotes.map(n => ({ ...n })),
            tieStart:      false,
            tieStop:       false,
            tieStartEvent: null,
            tieEndEvent:   null,
            stemDir:       'down',
            flagCount:     0,
            beam1:         null,
            beam2:         null,
            beamStart:     false,
            beamEnd:       false,
            beamContinue:  false,
            beamWith:      null,
            tupletActual:  null,
            tupletNormal:  null,
            tupletType:    null,
            xPos:          tickInMeasure / effectiveTicks,
            originalIdx:   null,
        };

        measure.events.push(newEvent);
        measure.events.sort((a, b) => a.tick - b.tick || a.voice - b.voice);
    }
}

// Lazy import shim — avoids circular deps at module parse time
function _importGenerateId() {
    // generateId is a simple util; inline a compatible implementation here
    // to avoid dynamic import complexity in a sync context.
    return {
        generateId() {
            return Math.random().toString(36).slice(2, 10);
        },
    };
}

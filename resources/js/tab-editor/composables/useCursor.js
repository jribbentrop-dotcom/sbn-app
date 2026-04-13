/**
 * SBN Tab Editor — Cursor State Machine
 *
 * Phase 7b: Cursor navigation across events and strings.
 *
 * Cursor modes:
 *   'navigate' — arrow keys move cursor, default mode
 *   'input'    — a digit was pressed, fret entry active (Phase 7c)
 *   'select'   — Shift+click / Shift+arrow, range selection (Phase 7g)
 *
 * Cursor address:
 *   measureIndex   — global measure index (across all sections)
 *   eventIndex     — index within measure.events (voice 1 only for now)
 *   stringIndex    — guitar string 1-6 (1 = high e, 6 = low E)
 *
 * The cursor always sits on a specific (event, string) cell.
 * If the event has no note on that string, the string is "empty" —
 * the cursor is still valid, used for fret entry in Phase 7c.
 */

import { ref, computed, readonly } from 'vue';

// ── Default cursor ─────────────────────────────────────────

const NULL_CURSOR = {
    measureIndex: 0,
    eventIndex:   0,
    stringIndex:  1,
    mode:         'navigate',
};

export function useCursor(model) {

    // ── State ──────────────────────────────────────────────

    const cursor  = ref({ ...NULL_CURSOR });
    const active  = ref(false);   // true once user has clicked or tab is focused

    // ── Derived: flat measure list ─────────────────────────
    // Flatten all sections → measures for cross-section navigation

    const allMeasures = computed(() => {
        if (!model.value) return [];
        return model.value.sections.flatMap(s => s.measures);
    });

    // ── Helpers ────────────────────────────────────────────

    /**
     * Return only voice-1 events from a measure (sorted by tick).
     */
    function voice1Events(measure) {
        if (!measure) return [];
        return measure.events.filter(e => (e.voice || 1) === 1);
    }

    /**
     * Clamp a value between min and max (inclusive).
     */
    function clamp(val, min, max) {
        return Math.max(min, Math.min(max, val));
    }

    /**
     * Return the event at the current cursor position, or null.
     */
    const currentEvent = computed(() => {
        const measures = allMeasures.value;
        if (!measures.length) return null;
        const m = measures[cursor.value.measureIndex];
        if (!m) return null;
        const evs = voice1Events(m);
        return evs[cursor.value.eventIndex] || null;
    });

    /**
     * Return the note at cursor.stringIndex within currentEvent, or null.
     */
    const currentNote = computed(() => {
        const ev = currentEvent.value;
        if (!ev || ev.isRest) return null;
        return ev.notes.find(n => n.string === cursor.value.stringIndex) || null;
    });

    /**
     * Is the cursor position valid for the current model?
     */
    const isValid = computed(() => {
        const measures = allMeasures.value;
        if (!measures.length || !active.value) return false;
        const m = measures[cursor.value.measureIndex];
        if (!m) return false;
        const evs = voice1Events(m);
        return cursor.value.eventIndex < evs.length;
    });

    // ── Placement helpers ──────────────────────────────────

    /**
     * Move cursor to (measureIndex, eventIndex, stringIndex).
     * Clamps to valid range. Does not change mode.
     */
    function moveTo(measureIndex, eventIndex, stringIndex) {
        const measures = allMeasures.value;
        if (!measures.length) return;

        const mi  = clamp(measureIndex, 0, measures.length - 1);
        const m   = measures[mi];
        const evs = voice1Events(m);

        if (!evs.length) {
            // Empty measure — place cursor at start
            cursor.value = { ...cursor.value, measureIndex: mi, eventIndex: 0, stringIndex: clamp(stringIndex, 1, 6) };
            return;
        }

        const ei = clamp(eventIndex, 0, evs.length - 1);
        const si = clamp(stringIndex, 1, 6);

        cursor.value = { ...cursor.value, measureIndex: mi, eventIndex: ei, stringIndex: si };
        active.value = true;
    }

    /**
     * Place cursor on a specific event object (by id), on the given string.
     * Scans all measures to locate the event.
     */
    function moveToEvent(eventId, stringIndex) {
        const measures = allMeasures.value;
        for (let mi = 0; mi < measures.length; mi++) {
            const evs = voice1Events(measures[mi]);
            const ei = evs.findIndex(e => e.id === eventId);
            if (ei !== -1) {
                moveTo(mi, ei, stringIndex || cursor.value.stringIndex);
                return;
            }
        }
    }

    // ── Navigation actions ─────────────────────────────────

    /**
     * ← Previous event. If at start of measure, go to last event of previous measure.
     */
    function moveLeft() {
        const measures = allMeasures.value;
        if (!measures.length) return;
        let { measureIndex, eventIndex, stringIndex } = cursor.value;
        const evs = voice1Events(measures[measureIndex]);

        if (eventIndex > 0) {
            moveTo(measureIndex, eventIndex - 1, stringIndex);
        } else if (measureIndex > 0) {
            const prevMeasure = measures[measureIndex - 1];
            const prevEvs = voice1Events(prevMeasure);
            moveTo(measureIndex - 1, Math.max(0, prevEvs.length - 1), stringIndex);
        }
    }

    /**
     * → Next event. If at end of measure, go to first event of next measure.
     */
    function moveRight() {
        const measures = allMeasures.value;
        if (!measures.length) return;
        let { measureIndex, eventIndex, stringIndex } = cursor.value;
        const evs = voice1Events(measures[measureIndex]);

        if (eventIndex < evs.length - 1) {
            moveTo(measureIndex, eventIndex + 1, stringIndex);
        } else if (measureIndex < measures.length - 1) {
            moveTo(measureIndex + 1, 0, stringIndex);
        }
    }

    /**
     * ↑ Move cursor to lower string number (higher pitch).
     * String 1 = high e (top of tab), string 6 = low E (bottom).
     * ↑ visually means UP on screen = lower string number.
     */
    function moveUp() {
        const { measureIndex, eventIndex, stringIndex } = cursor.value;
        if (stringIndex > 1) {
            moveTo(measureIndex, eventIndex, stringIndex - 1);
        }
    }

    /**
     * ↓ Move cursor to higher string number (lower pitch).
     */
    function moveDown() {
        const { measureIndex, eventIndex, stringIndex } = cursor.value;
        if (stringIndex < 6) {
            moveTo(measureIndex, eventIndex, stringIndex + 1);
        }
    }

    /**
     * Tab → first event of next measure.
     */
    function moveNextMeasure() {
        const measures = allMeasures.value;
        const { measureIndex, stringIndex } = cursor.value;
        if (measureIndex < measures.length - 1) {
            moveTo(measureIndex + 1, 0, stringIndex);
        }
    }

    /**
     * Shift+Tab → first event of previous measure.
     */
    function movePrevMeasure() {
        const { measureIndex, stringIndex } = cursor.value;
        if (measureIndex > 0) {
            moveTo(measureIndex - 1, 0, stringIndex);
        }
    }

    /**
     * Home → first event in current measure.
     */
    function moveHome() {
        const { measureIndex, stringIndex } = cursor.value;
        moveTo(measureIndex, 0, stringIndex);
    }

    /**
     * End → last event in current measure.
     */
    function moveEnd() {
        const measures = allMeasures.value;
        const { measureIndex, stringIndex } = cursor.value;
        const evs = voice1Events(measures[measureIndex]);
        moveTo(measureIndex, Math.max(0, evs.length - 1), stringIndex);
    }

    // ── Mode transitions ───────────────────────────────────

    function setMode(mode) {
        cursor.value = { ...cursor.value, mode };
    }

    function enterNavigate() { setMode('navigate'); }
    function enterInput()    { setMode('input'); }
    function enterSelect()   { setMode('select'); }

    // ── Click-to-select ────────────────────────────────────

    /**
     * Called when user clicks on a note or rest in TabMeasure.
     * @param {number} measureIndex — global measure index
     * @param {string} eventId      — TabEvent.id
     * @param {number} stringIndex  — string clicked (1-6), or best-guess for rests
     */
    function clickEvent(measureIndex, eventId, stringIndex) {
        const measures = allMeasures.value;
        if (!measures.length) return;
        const m = measures[measureIndex];
        if (!m) return;

        const evs = voice1Events(m);
        const ei = evs.findIndex(e => e.id === eventId);
        if (ei === -1) return;

        const si = stringIndex || cursor.value.stringIndex || 1;
        cursor.value = {
            measureIndex,
            eventIndex: ei,
            stringIndex: clamp(si, 1, 6),
            mode: 'navigate',
        };
        active.value = true;
    }

    /**
     * Called when user clicks on a rest.
     */
    function clickRest(measureIndex, eventId) {
        clickEvent(measureIndex, eventId, cursor.value.stringIndex || 1);
    }

    // ── Keyboard dispatcher ────────────────────────────────

    /**
     * Handle a keydown event. Returns true if the key was consumed.
     * Only processes navigation keys in navigate mode (fret entry handled by useNoteInput).
     */
    function handleKeydown(e) {
        if (!active.value) return false;
        const mode = cursor.value.mode;

        // Escape always returns to navigate
        if (e.key === 'Escape') {
            if (mode !== 'navigate') {
                enterNavigate();
                e.preventDefault();
                return true;
            }
            return false;
        }

        // Navigation keys — only in navigate (and select) mode
        if (mode === 'navigate' || mode === 'select') {
            switch (e.key) {
                case 'ArrowLeft':
                    e.preventDefault();
                    moveLeft();
                    return true;

                case 'ArrowRight':
                    e.preventDefault();
                    moveRight();
                    return true;

                case 'ArrowUp':
                    e.preventDefault();
                    moveUp();
                    return true;

                case 'ArrowDown':
                    e.preventDefault();
                    moveDown();
                    return true;

                case 'Tab':
                    e.preventDefault();
                    if (e.shiftKey) {
                        movePrevMeasure();
                    } else {
                        moveNextMeasure();
                    }
                    return true;

                case 'Home':
                    e.preventDefault();
                    moveHome();
                    return true;

                case 'End':
                    e.preventDefault();
                    moveEnd();
                    return true;
            }
        }

        return false;
    }

    // ── Reset ──────────────────────────────────────────────

    function reset() {
        cursor.value = { ...NULL_CURSOR };
        active.value = false;
    }

    // ── Public API ─────────────────────────────────────────

    return {
        // State (read-only refs for external consumers)
        cursor: readonly(cursor),
        active: readonly(active),

        // Derived
        allMeasures,
        currentEvent,
        currentNote,
        isValid,

        // Navigation
        moveTo,
        moveToEvent,
        moveLeft,
        moveRight,
        moveUp,
        moveDown,
        moveNextMeasure,
        movePrevMeasure,
        moveHome,
        moveEnd,

        // Click handlers
        clickEvent,
        clickRest,

        // Mode
        setMode,
        enterNavigate,
        enterInput,
        enterSelect,

        // Keyboard
        handleKeydown,

        // Lifecycle
        reset,
    };
}

/**
 * SBN Tab Editor — Note Input Composable
 *
 * Phase 7c: Fret number entry, note deletion, rest↔note conversion.
 *
 * Behaviour (Soundslice convention):
 *  - Cursor on any string → type 0-9 → sets fret on that string immediately.
 *  - Two-digit frets: type '1' → pending fret shown; type another digit within
 *    600ms → commits the combined fret (e.g. 12). After 600ms, '1' commits alone.
 *  - No auto-advance after entry — cursor stays on same (event, string).
 *  - Delete / Backspace → remove note on cursor string.
 *    If last note in event → event becomes a rest.
 *  - Typing on a rest → rest is converted to a note event on cursor string.
 */

import { ref } from 'vue';

// Sticky grace-entry mode — exported so TabCursor can show a visual hint.
import { flagCount } from '../utils/constants.js';

// ── Constants ──────────────────────────────────────────────

const TWO_DIGIT_TIMEOUT_MS = 600;
const MIN_FRET = 0;
const MAX_FRET = 24;

export function useNoteInput(cursor, model, wrapCommand = null, repositionMeasure = null) {

    // ── Two-digit fret input state ─────────────────────────

    const pendingDigit = ref(null);   // string '0'-'9' or null
    const pendingTimer = ref(null);   // setTimeout handle

    // ── Grace mode ─────────────────────────────────────────

    const graceMode = ref(false);     // when true, fret digits attach grace notes

    // ── Model access helpers ───────────────────────────────

    function getCursorEvent() {
        if (!model.value) return null;
        const measures = model.value.sections.flatMap(s => s.measures);
        const m = measures[cursor.value.measureIndex];
        if (!m) return null;
        const evs = m.events.filter(e => (e.voice || 1) === 1);
        return evs[cursor.value.eventIndex] || null;
    }

    function getCursorMeasure() {
        if (!model.value) return null;
        const measures = model.value.sections.flatMap(s => s.measures);
        return measures[cursor.value.measureIndex] || null;
    }

    // ── Pending digit helpers ──────────────────────────────

    function clearPending() {
        if (pendingTimer.value !== null) {
            clearTimeout(pendingTimer.value);
            pendingTimer.value = null;
        }
        pendingDigit.value = null;
    }

    function startPendingDigit(digit) {
        pendingDigit.value = digit;
        pendingTimer.value = setTimeout(() => {
            // Timeout expired — commit this single digit as-is
            const fret = parseInt(digit, 10);
            pendingDigit.value = null;
            pendingTimer.value = null;
            commitPendingFret(fret);
        }, TWO_DIGIT_TIMEOUT_MS);
    }

    // Routes a committed fret to either grace or normal entry.
    function commitPendingFret(fret) {
        if (graceMode.value) commitGraceFret(fret);
        else commitFret(fret);
    }

    // ── Fret commit ────────────────────────────────────────

    function commitFret(fret) {
        const ev = getCursorEvent();
        if (!ev) return;
        if (isNaN(fret) || fret < MIN_FRET || fret > MAX_FRET) return;

        const doCommit = () => {
            const stringIdx = cursor.value.stringIndex;
            if (ev.isRest) {
                ev.isRest    = false;
                ev.notes     = [{ string: stringIdx, fret, pitch: null, octave: null, tieStart: false, tieStop: false }];
                ev.flagCount = flagCount(ev.ticks);
                ev.stemDir   = 'down';
            } else {
                const existing = ev.notes.findIndex(n => n.string === stringIdx);
                if (existing !== -1) {
                    ev.notes[existing] = { ...ev.notes[existing], fret };
                } else {
                    ev.notes.push({ string: stringIdx, fret, pitch: null, octave: null, tieStart: false, tieStop: false });
                    ev.notes.sort((a, b) => a.string - b.string);
                }
            }
        };

        if (wrapCommand) {
            wrapCommand('fret', [ev.measureIdx], doCommit);
        } else {
            doCommit();
        }

    }

    // ── Note deletion ──────────────────────────────────────

    function deleteNoteAtCursor() {
        const ev = getCursorEvent();
        if (!ev) return;

        const doDelete = () => {
            if (ev.isRest) {
                deleteEventFromMeasure(ev);
                return;
            }
            const stringIdx = cursor.value.stringIndex;
            const noteIdx   = ev.notes.findIndex(n => n.string === stringIdx);
            if (noteIdx === -1) return;
            ev.notes.splice(noteIdx, 1);
            if (ev.notes.length === 0) {
                ev.isRest    = true;
                ev.stemDir   = null;
                ev.flagCount = 0;
            }
        };

        if (wrapCommand) {
            wrapCommand('delete', [ev.measureIdx], doDelete);
        } else {
            doDelete();
        }

    }

    /**
     * Remove an event entirely from its measure.
     * Moves cursor to the previous event (or next, or stays on measure start).
     */
    function deleteEventFromMeasure(ev) {
        if (!model.value) return;
        const measures = model.value.sections.flatMap(s => s.measures);
        const m = measures[ev.measureIdx];
        if (!m) return;

        const evIdx = m.events.findIndex(e => e.id === ev.id);
        if (evIdx === -1) return;

        m.events.splice(evIdx, 1);

        // Reposition: recalculates ticks, xPos, actualTicks — clears stale overfill.
        if (repositionMeasure) repositionMeasure(m);

        // Adjust cursor: prefer previous event, then next, then stay at 0
        const v1 = m.events.filter(e => (e.voice || 1) === 1);
        const ci = cursor.value.eventIndex;
        if (v1.length === 0) {
            // Measure is now empty — cursor stays at index 0
        } else if (ci >= v1.length) {
            // Was on the last event — step back
            cursor.value = { ...cursor.value, eventIndex: v1.length - 1 };
        }
        // else cursor index still valid — it now points to the next event
    }

    // ── Two-digit fret processing ──────────────────────────

    /**
     * Process a digit key. Returns the fret to commit, or null if still pending.
     *
     * FIX (vs original): save pendingDigit.value BEFORE calling clearPending(),
     * which nulls it. The original code read pendingDigit.value after clearPending()
     * and got NaN, causing digits 2-9 after '1' to silently fail.
     */
    function processDigit(digit) {
        if (pendingDigit.value !== null) {
            // Capture the saved first digit BEFORE clearing
            const firstDigit = pendingDigit.value;
            clearPending();

            const combined = parseInt(firstDigit + digit, 10);

            if (combined >= MIN_FRET && combined <= MAX_FRET) {
                // e.g. '1'+'2' → fret 12
                return combined;
            } else {
                // Combined out of range (e.g. '1'+'9' if MAX_FRET=18) —
                // commit the first digit, then start fresh with this digit
                commitPendingFret(parseInt(firstDigit, 10));

                if (digit === '1') {
                    startPendingDigit(digit);
                    return null;
                }
                return parseInt(digit, 10);
            }
        }

        // No pending digit — start fresh
        if (digit === '0') {
            return 0;
        } else if (digit === '1') {
            // Could be start of 10-19 — wait for second digit
            startPendingDigit(digit);
            return null;
        } else {
            // 2-9: commit immediately
            return parseInt(digit, 10);
        }
    }

    // ── String shift (Ctrl+Up / Ctrl+Down) ────────────────
    // Cursor string numbering: 1 = high e (top of tab), 6 = low E (bottom).
    // Tuning intervals between adjacent strings (semitones), ordered by string number:
    //   1(e)↔2(B)  = major 3rd  (4 st)  ← the e→B exception
    //   2(B)↔3(G)  = perfect 4th (5 st)
    //   3(G)↔4(D)  = perfect 4th (5 st)
    //   4(D)↔5(A)  = perfect 4th (5 st)
    //   5(A)↔6(E)  = perfect 4th (5 st)
    //
    // Ctrl+ArrowUp   → lower string number (higher pitch, toward high e), fret -= interval
    // Ctrl+ArrowDown → higher string number (lower pitch, toward low E),  fret += interval

    function intervalBetween(fromStr, toStr) {
        const lo = Math.min(fromStr, toStr);
        const hi = Math.max(fromStr, toStr);
        // B(2)↔G(3) crossing is a major 3rd (4 st); all others are perfect 4ths (5 st).
        return (lo === 2 && hi === 3) ? 4 : 5;
    }

    function shiftNoteToString(direction) {
        // direction: -1 = ArrowUp (lower str#, higher pitch), +1 = ArrowDown (higher str#, lower pitch)
        const ev = getCursorEvent();
        if (!ev || ev.isRest) return false;

        const currentStr = cursor.value.stringIndex;
        const targetStr  = currentStr + direction;

        if (targetStr < 1 || targetStr > 6) return false; // boundary → no-op

        const noteIdx = ev.notes.findIndex(n => n.string === currentStr);
        if (noteIdx === -1) return false; // no note on cursor string → no-op

        const interval  = intervalBetween(currentStr, targetStr);
        // Moving up (lower str#, higher-pitched open string) → same pitch needs lower fret.
        // Moving down (higher str#, lower-pitched open string) → same pitch needs higher fret.
        const fretDelta = direction * interval;
        const newFret   = ev.notes[noteIdx].fret + fretDelta;

        if (newFret < MIN_FRET || newFret > MAX_FRET) return false; // clamp → no-op

        const doShift = () => {
            const existingIdx = ev.notes.findIndex(n => n.string === targetStr);
            if (existingIdx !== -1) ev.notes.splice(existingIdx, 1);
            const idx = ev.notes.findIndex(n => n.string === currentStr);
            if (idx === -1) return;
            ev.notes[idx].string = targetStr;
            ev.notes[idx].fret   = newFret;
            ev.notes.sort((a, b) => a.string - b.string);
        };

        if (wrapCommand) {
            wrapCommand('shift-string', [ev.measureIdx], doShift);
        } else {
            doShift();
        }

        return targetStr; // caller must move cursor to this string
    }

    // ── Grace note mutators ────────────────────────────────

    function commitGraceFret(fret) {
        const ev = getCursorEvent();
        if (!ev || ev.isRest) return;
        if (isNaN(fret) || fret < MIN_FRET || fret > MAX_FRET) return;
        const stringIdx = cursor.value.stringIndex;

        const doCommit = () => {
            if (!ev.graceNotes) ev.graceNotes = [];
            ev.graceNotes.push({ string: stringIdx, fret, pitch: null, octave: null, slash: true, slur: true });
        };

        if (wrapCommand) wrapCommand('grace', [ev.measureIdx], doCommit);
        else doCommit();

        graceMode.value = false;
    }

    function deleteGraceAtCursor() {
        const ev = getCursorEvent();
        if (!ev || !ev.graceNotes?.length) return false;
        const stringIdx = cursor.value.stringIndex;

        const doDelete = () => {
            let idx = ev.graceNotes.findIndex(g => g.string === stringIdx);
            if (idx === -1) idx = ev.graceNotes.length - 1;
            ev.graceNotes.splice(idx, 1);
            if (ev.graceNotes.length === 0) delete ev.graceNotes;
        };

        if (wrapCommand) wrapCommand('grace-delete', [ev.measureIdx], doDelete);
        else doDelete();
        return true;
    }

    // ── Keyboard handler ───────────────────────────────────

    function handleKeydown(e) {
        if (!cursor.value || !model.value) return false;

        const key = e.key;

        // 'g': toggle grace-entry mode
        if (key === 'g' && !e.ctrlKey && !e.metaKey && !e.altKey) {
            e.preventDefault();
            const ev = getCursorEvent();
            if (ev && !ev.isRest) graceMode.value = !graceMode.value;
            clearPending();
            return true;
        }

        // Digits 0-9: fret entry (skip if modifier held — Ctrl+num = future duration keys)
        if (/^[0-9]$/.test(key) && !e.ctrlKey && !e.metaKey) {
            e.preventDefault();
            const fret = processDigit(key);
            if (fret !== null) {
                commitPendingFret(fret);
            }
            return true;
        }

        // Delete / Backspace: remove note, or cancel pending digit
        if (key === 'Delete' || key === 'Backspace') {
            if (pendingDigit.value !== null) {
                clearPending();
                e.preventDefault();
                return true;
            }
            e.preventDefault();
            if (graceMode.value) {
                deleteGraceAtCursor();
            } else {
                deleteNoteAtCursor();
            }
            return true;
        }

        // Escape: cancel grace mode or pending digit (navigation escape handled by useCursor)
        if (key === 'Escape') {
            if (graceMode.value) {
                graceMode.value = false;
                e.preventDefault();
                return true;
            }
            if (pendingDigit.value !== null) {
                clearPending();
                e.preventDefault();
                return true;
            }
        }

        return false;
    }

    // ── Cleanup ────────────────────────────────────────────

    function dispose() {
        clearPending();
    }

    return {
        pendingDigit,
        graceMode,
        handleKeydown,
        deleteNoteAtCursor,
        commitFret,
        commitGraceFret,
        deleteGraceAtCursor,
        shiftNoteToString,
        dispose,
    };
}

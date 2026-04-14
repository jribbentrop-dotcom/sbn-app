/**
 * SBN Tab Editor — Chord Grid Selection
 *
 * Owns the chord selection state for the chord view.
 * Each entry is { gi, ci } where gi = global measure index, ci = chord slot index.
 *
 * Supports:
 *   - Single click        → select one chord, clear rest
 *   - Ctrl+Click          → toggle one chord in/out of selection
 *   - Shift+Click         → range select from last anchor to clicked chord
 *   - Ctrl+A              → select all chords in the model
 *   - Escape              → clear selection
 *   - Delete / Backspace  → inform caller (does NOT mutate — caller passes deleteChords)
 */

import { ref, computed } from 'vue';

export function useGridSelection(model) {

    /** @type {import('vue').Ref<Array<{gi:number,ci:number}>>} */
    const selection = ref([]);

    /** Last single-clicked cell — used as the anchor for Shift+Click range. */
    const _anchor = ref(null);    // { gi, ci }

    // ── Computed helpers ─────────────────────────────────────

    const hasSelection = computed(() => selection.value.length > 0);

    function isSelected(gi, ci) {
        return selection.value.some(s => s.gi === gi && s.ci === ci);
    }

    // ── Flat index helpers for range selection ───────────────

    /**
     * Return an ordered flat list of all { gi, ci } pairs in the model,
     * in document order (section order, measure order, chord slot order).
     */
    function _flatList() {
        if (!model.value) return [];
        const list = [];
        for (const sec of model.value.sections) {
            for (const m of sec.measures) {
                for (let ci = 0; ci < (m.chordNames || []).length; ci++) {
                    list.push({ gi: m.index, ci });
                }
            }
        }
        return list;
    }

    function _flatIndex(gi, ci, flat) {
        return flat.findIndex(f => f.gi === gi && f.ci === ci);
    }

    // ── Public API ───────────────────────────────────────────

    /**
     * Handle a click event on a chord card.
     * Respects Ctrl and Shift modifiers via the native MouseEvent.
     *
     * @param {number}     gi    — global measure index
     * @param {number}     ci    — chord slot index
     * @param {MouseEvent} event — the DOM event
     */
    function handleClick(gi, ci, event) {
        if (event.shiftKey && _anchor.value) {
            // Shift+Click: range from anchor to here
            const flat = _flatList();
            const aIdx = _flatIndex(_anchor.value.gi, _anchor.value.ci, flat);
            const bIdx = _flatIndex(gi, ci, flat);
            if (aIdx < 0 || bIdx < 0) {
                selection.value = [{ gi, ci }];
                _anchor.value = { gi, ci };
                return;
            }
            const lo = Math.min(aIdx, bIdx);
            const hi = Math.max(aIdx, bIdx);
            // Extend existing selection with the range (don't clear anchor-less entries)
            const rangeSet = new Set(
                flat.slice(lo, hi + 1).map(f => `${f.gi}:${f.ci}`)
            );
            selection.value = flat.slice(lo, hi + 1);
            // Don't update anchor on shift+click
            return;
        }

        if (event.ctrlKey || event.metaKey) {
            // Ctrl+Click: toggle
            const idx = selection.value.findIndex(s => s.gi === gi && s.ci === ci);
            if (idx >= 0) {
                selection.value.splice(idx, 1);
            } else {
                selection.value.push({ gi, ci });
            }
            _anchor.value = { gi, ci };
            return;
        }

        // Plain click: select only this chord
        selection.value = [{ gi, ci }];
        _anchor.value = { gi, ci };
    }

    /**
     * Select all chord slots in the model.
     */
    function selectAll() {
        selection.value = _flatList();
        _anchor.value = selection.value[0] ?? null;
    }

    /**
     * Clear the selection.
     */
    function clearSelection() {
        selection.value = [];
        _anchor.value = null;
    }

    /**
     * Wire keyboard shortcuts.
     * Call this once from the parent component that owns keyboard focus.
     * Returns a cleanup function to remove the listener.
     *
     * @param {Function} onDelete — callback when Delete/Backspace is pressed with selection
     */
    function setupKeyboardHandlers(onDelete) {
        function handler(e) {
            // Only active when no input/textarea is focused
            const tag = document.activeElement?.tagName?.toLowerCase();
            if (tag === 'input' || tag === 'textarea' || tag === 'select') return;
            if (document.activeElement?.isContentEditable) return;

            if (e.key === 'Escape') {
                clearSelection();
                return;
            }
            if ((e.key === 'Delete' || e.key === 'Backspace') && hasSelection.value) {
                e.preventDefault();
                onDelete?.(selection.value);
                return;
            }
            if ((e.ctrlKey || e.metaKey) && e.key === 'a') {
                e.preventDefault();
                selectAll();
                return;
            }
        }
        window.addEventListener('keydown', handler);
        return () => window.removeEventListener('keydown', handler);
    }

    return {
        selection,
        hasSelection,
        isSelected,
        handleClick,
        selectAll,
        clearSelection,
        setupKeyboardHandlers,
    };
}

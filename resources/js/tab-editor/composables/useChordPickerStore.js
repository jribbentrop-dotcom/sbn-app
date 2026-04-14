/**
 * SBN Tab Editor — Chord Picker Store (minimal, Step 4)
 *
 * Owns open/closed state and position for the inline chord name editor popup.
 * Step 5 (VoicingPicker migration) will add useVoicingPickerStore.js alongside this.
 *
 * Usage:
 *   const chordPicker = useChordPickerStore();
 *   chordPicker.openAt(targetEl, gi, ci, currentName);
 *   // ChordPicker.vue reads chordPicker.open, .top, .left, .gi, .ci, .value
 *   // and emits 'apply' which the parent routes to ops.setChordName(gi, ci, name)
 */

import { ref, computed } from 'vue';

// Module-level singleton — only one chord picker in the app at a time.
const _open  = ref(false);
const _gi    = ref(0);
const _ci    = ref(0);
const _value = ref('');
const _top   = ref(0);
const _left  = ref(0);

export function useChordPickerStore() {

    /**
     * Open the chord picker positioned near targetEl.
     *
     * @param {HTMLElement|DOMRect} targetOrRect — element or bounding rect to anchor to
     * @param {number}  gi           — global measure index
     * @param {number}  ci           — chord slot index
     * @param {string}  currentName  — current chord name (pre-fills the input)
     */
    function openAt(targetOrRect, gi, ci, currentName = '') {
        const rect = targetOrRect instanceof Element
            ? targetOrRect.getBoundingClientRect()
            : targetOrRect;

        _gi.value    = gi;
        _ci.value    = ci;
        _value.value = currentName;
        _top.value   = (rect?.bottom ?? 0) + window.scrollY + 4;
        _left.value  = (rect?.left   ?? 0) + window.scrollX;
        _open.value  = true;
    }

    function close() {
        _open.value = false;
    }

    return {
        open:  _open,
        gi:    _gi,
        ci:    _ci,
        value: _value,
        top:   _top,
        left:  _left,
        openAt,
        close,
    };
}

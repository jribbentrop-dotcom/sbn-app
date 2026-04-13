/**
 * sbn-grid-ops.js — shared operation identifiers + context menu builder
 *
 * Loaded as a plain <script> (same pattern as chords.js).
 * Exposes OPS and buildMenuItems on window so Alpine x-data can call them.
 * When the Vue tab editor imports these it can use proper ES imports from
 * this same file — the window assignments don't interfere.
 *
 * Usage (from Alpine):
 *   var items = buildMenuItems('leadsheet', state);
 *   var opId  = OPS.DELETE_BAR;
 */

(function () {

    // ── Operation IDs ─────────────────────────────────────────────────────
    // Single source of truth. Use these constants in handleContextAction()
    // switch statements — never raw strings.

    var OPS = {
        // Chord-level (within a single measure)
        RENAME_CHORD:      'renameChord',
        CHANGE_VOICING:    'changeVoicing',
        ADD_CHORD:         'addChord',
        REMOVE_CHORD:      'removeChord',

        // Measure-level (structural)
        INSERT_BAR_BEFORE: 'insertBarBefore',
        INSERT_BAR_AFTER:  'insertBarAfter',
        DELETE_BAR:        'deleteBar',
        TOGGLE_REPEAT:     'toggleRepeat',

        // Batch (multi-measure selection)
        COPY:              'copy',
        CUT:               'cut',
        PASTE:             'paste',
        CLEAR_CHORDS:      'clearChords',
        SELECT_ALL:        'selectAll',
        DELETE_SELECTION:  'deleteSelection',
        INSERT_N_BEFORE:   'insertNBefore',
    };

    /**
     * Build the items array for a context menu.
     *
     * @param {'leadsheet'|'builder'|'tab'} context
     * @param {Object}  state
     * @param {'chord'|'measure'} state.selectionLevel
     * @param {number}  state.selectionCount   — chords or measures depending on level
     * @param {boolean} state.hasClipboard
     * @param {number}  state.chordCount       — chords in the right-clicked measure
     * @param {boolean} state.canAddChord      — chordCount < 4
     * @param {boolean} state.canRemoveChord   — chordCount > 1
     * @param {number}  state.clickedChord     — ci of the right-clicked chord card
     * @returns {Array<Object>}  MenuItem[]
     */
    function buildMenuItems(context, state) {
        var items = [];

        if (context === 'leadsheet') {

            // ── Chord-level: individual chord(s) within one measure ───────
            if (state.selectionLevel === 'chord') {
                items.push(
                    { id: OPS.RENAME_CHORD,   label: 'Rename chord',   icon: '✏️',  group: 'chord' },
                    { id: OPS.CHANGE_VOICING, label: 'Change voicing', icon: '🎸', group: 'chord' }
                );
                if (state.canAddChord) {
                    items.push({ id: OPS.ADD_CHORD,    label: 'Add chord to bar',  icon: '➕', group: 'chord-edit' });
                }
                if (state.canRemoveChord) {
                    items.push({ id: OPS.REMOVE_CHORD, label: 'Remove last chord', icon: '➖', group: 'chord-edit' });
                }
                items.push(
                    { id: OPS.COPY, label: 'Copy bar', shortcut: 'Ctrl+C', group: 'clipboard' },
                    { id: OPS.CUT,  label: 'Cut bar',  shortcut: 'Ctrl+X', group: 'clipboard' }
                );
                if (state.hasClipboard) {
                    items.push({ id: OPS.PASTE, label: 'Paste after', shortcut: 'Ctrl+V', group: 'clipboard' });
                }
                items.push(
                    { id: OPS.INSERT_BAR_AFTER,  label: 'Insert bar after',  group: 'structure' },
                    { id: OPS.INSERT_BAR_BEFORE, label: 'Insert bar before', group: 'structure' },
                    { id: OPS.TOGGLE_REPEAT,     label: 'Toggle repeat', icon: '𝄆𝄇', group: 'structure' },
                    { id: OPS.DELETE_BAR,        label: 'Delete bar', danger: true, group: 'danger' }
                );
            }

            // ── Measure-level: single measure fully selected ──────────────
            if (state.selectionLevel === 'measure' && state.selectionCount === 1) {
                items.push(
                    { id: OPS.RENAME_CHORD,   label: 'Rename chord',   icon: '✏️',  group: 'chord' },
                    { id: OPS.CHANGE_VOICING, label: 'Change voicing', icon: '🎸', group: 'chord' }
                );
                if (state.canAddChord) {
                    items.push({ id: OPS.ADD_CHORD,    label: 'Add chord to bar',  icon: '➕', group: 'chord-edit' });
                }
                if (state.canRemoveChord) {
                    items.push({ id: OPS.REMOVE_CHORD, label: 'Remove last chord', icon: '➖', group: 'chord-edit' });
                }
                items.push(
                    { id: OPS.COPY, label: 'Copy bar', shortcut: 'Ctrl+C', group: 'clipboard' },
                    { id: OPS.CUT,  label: 'Cut bar',  shortcut: 'Ctrl+X', group: 'clipboard' }
                );
                if (state.hasClipboard) {
                    items.push({ id: OPS.PASTE, label: 'Paste after', shortcut: 'Ctrl+V', group: 'clipboard' });
                }
                items.push(
                    { id: OPS.INSERT_BAR_AFTER,  label: 'Insert bar after',  group: 'structure' },
                    { id: OPS.INSERT_BAR_BEFORE, label: 'Insert bar before', group: 'structure' },
                    { id: OPS.TOGGLE_REPEAT,     label: 'Toggle repeat', icon: '𝄆𝄇', group: 'structure' },
                    { id: OPS.DELETE_BAR,        label: 'Delete bar', danger: true, group: 'danger' }
                );
            }

            // ── Measure-level: multiple measures selected ─────────────────
            if (state.selectionLevel === 'measure' && state.selectionCount > 1) {
                items.push(
                    { id: OPS.COPY, label: 'Copy ' + state.selectionCount + ' bars', shortcut: 'Ctrl+C', group: 'clipboard' },
                    { id: OPS.CUT,  label: 'Cut '  + state.selectionCount + ' bars', shortcut: 'Ctrl+X', group: 'clipboard' }
                );
                if (state.hasClipboard) {
                    items.push({ id: OPS.PASTE, label: 'Paste after selection', shortcut: 'Ctrl+V', group: 'clipboard' });
                }
                items.push(
                    { id: OPS.CLEAR_CHORDS,     label: 'Clear chords',                                       group: 'batch'     },
                    { id: OPS.INSERT_N_BEFORE,  label: 'Insert ' + state.selectionCount + ' bars before',    group: 'structure' },
                    { id: OPS.DELETE_SELECTION, label: 'Delete ' + state.selectionCount + ' bars', danger: true, group: 'danger' }
                );
            }

        } else if (context === 'builder') {
            items.push(
                { id: OPS.CHANGE_VOICING, label: 'Change voicing', icon: '🎸', group: 'chord' },
                { id: OPS.CLEAR_CHORDS,   label: 'Clear slot',                 group: 'edit'  }
            );

        } else if (context === 'tab') {
            // Reserved for Phase 5 tab editor context menu.
            // Structural ops will dispatch sbn-tab-structure-request to Alpine.
            // Tab-only ops (copy/paste tab notes) stay in Vue.
        }

        return items;
    }

    // ── Expose globally (Alpine x-data calls these directly) ─────────────
    window.OPS            = OPS;
    window.buildMenuItems = buildMenuItems;

}());

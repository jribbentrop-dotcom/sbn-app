/**
 * SBN Tab Editor — Sidebar Store
 *
 * A module-level reactive store shared between the two Vue app instances:
 *   - TabEditor app  (mounted on #sbn-tab-editor — notation area)
 *   - TabSidebar app (mounted on #sbn-tab-sidebar — Blade right panel)
 */

import { reactive } from 'vue';

export const sidebarStore = reactive({
    // Cursor state
    active:          false,
    measureIndex:    0,
    eventIndex:      0,
    stringIndex:     1,
    mode:            'navigate',

    // Selected event (plain object copy — not a reactive TabEvent reference)
    currentEvent:    null,

    // Selected note on cursor string (plain object copy)
    currentNote:     null,

    // Pending fret digit from useNoteInput
    pendingDigit:    null,

    // Model context
    ticksPerMeasure: 1920,

    // Phase 7d: measure fill state
    measureOverfill:    false,
    measureTotalTicks:  0,

    // Phase 7e: undo/redo availability
    canUndo: false,
    canRedo: false,

    // Phase 7g: clipboard
    hasClipboard:   false,
    clipboardCount: 0,
    clipboardMode:  '',   // 'measure' | 'events' | ''
});

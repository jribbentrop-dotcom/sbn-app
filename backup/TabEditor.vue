<template>
    <div
        class="sbn-tab-editor-root"
        tabindex="0"
        @keydown="onKeydown"
        @focus="onEditorFocus"
        @click="onEditorClick"
        ref="editorRoot"
    >

        <!-- No data state -->
        <div v-if="!hasData" class="sbn-tab-no-data">
            <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2z"/>
            </svg>
            <p class="sbn-tab-no-data-title">No tablature yet</p>
            <p class="sbn-tab-no-data-hint">
                Import a MusicXML file with TAB notation using the button above,
                <span v-if="hasChordsData"> or generate a blank tab skeleton from the chord chart:</span>
                <span v-else> or add chords in the Chords view first.</span>
            </p>
            <button
                v-if="hasChordsData"
                class="sbn-btn sbn-btn-primary sbn-tab-generate-btn"
                @click="onGenerateFromChords"
            >
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                </svg>
                Generate blank tab from chords
            </button>
        </div>

        <!-- Tab content -->
        <template v-else-if="model">
            <div class="sbn-tab-editor-notation">
                    <div v-for="(section, si) in model.sections" :key="section.id || si" class="sbn-ve-section">

                        <!-- Section header -->
                        <div class="sbn-ve-section-header sbn-ve-section-header--readonly">
                            <div v-if="section.id" class="sbn-ve-section-id" style="pointer-events:none">
                                {{ section.id }}
                            </div>
                            <span v-if="section.name && section.name !== section.id"
                                  style="font-size:13px;font-weight:600;color:var(--clr-text);flex:1">
                                {{ section.name }}
                            </span>
                            <span v-else style="flex:1"></span>
                            <span style="font-size:11px;color:var(--clr-text-muted)">
                                {{ section.measures.length }} bars
                            </span>
                        </div>

                        <!-- Section body -->
                        <div class="sbn-ve-section-body" style="padding:8px 4px 4px;">
                            <div v-for="(row, ri) in measureRows(section)" :key="ri" class="sbn-tab-row">

                                <div class="sbn-tab-measures">
                                    <TabMeasure
                                        v-for="(measure, li) in row"
                                        :key="measure.index"
                                        :measure="measure"
                                        :is-first-of-section="ri === 0 && li === 0"
                                        :ticks-per-measure="model.ticksPerMeasure"
                                        :next-measure="getNextMeasure(measure.index)"
                                        :is-next-first-of-section="isNextMeasureFirstOfSection(measure.index)"
                                        :chord-names="measure.chordNames || []"
                                        :cursor="cursorState"
                                        :pending-digit="pendingDigit"
                                        :selected-events="selectedEvents"
                                        :is-measure-selected="isMeasureSelected(measure.index)"
                                        @cursor-click-event="onCursorClickEvent"
                                        @cursor-click-rest="onCursorClickRest"
                                        @chord-click="onChordClick"
                                        @chord-identify="onChordIdentify"
                                        @chord-context-menu="onChordContextMenu"
                                        @chord-name-needed="onChordNameNeeded"
                                        @measure-select="onMeasureSelect"
                                        @measure-context-menu="onMeasureContextMenu"
                                    />
                                    <!-- Fill empty slots to hold row width -->
                                    <div v-for="f in emptySlots(row)" :key="'empty-' + f"
                                         class="sbn-tab-measure" style="visibility:hidden">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
            </div>
        </template>
        <!-- Keyboard shortcut overlay (? key) -->
        <transition name="sbn-tab-overlay-fade">
            <div v-if="showShortcuts" class="sbn-tab-shortcut-overlay" @click.self="showShortcuts = false">
                <div class="sbn-tab-shortcut-panel">
                    <div class="sbn-tab-shortcut-header">
                        <span>Keyboard Shortcuts</span>
                        <button class="sbn-tab-shortcut-close" @click="showShortcuts = false">✕</button>
                    </div>
                    <div class="sbn-tab-shortcut-cols">
                        <div class="sbn-tab-shortcut-group">
                            <div class="sbn-tab-shortcut-group-title">Navigation</div>
                            <div class="sbn-tab-shortcut-row"><kbd>← →</kbd><span>Previous / next event</span></div>
                            <div class="sbn-tab-shortcut-row"><kbd>↑ ↓</kbd><span>Move between strings</span></div>
                            <div class="sbn-tab-shortcut-row"><kbd>Tab</kbd><span>Next measure</span></div>
                            <div class="sbn-tab-shortcut-row"><kbd>Shift+Tab</kbd><span>Previous measure</span></div>
                            <div class="sbn-tab-shortcut-row"><kbd>Home / End</kbd><span>First / last event</span></div>
                            <div class="sbn-tab-shortcut-row"><kbd>Esc</kbd><span>Cancel / back to navigate</span></div>
                        </div>
                        <div class="sbn-tab-shortcut-group">
                            <div class="sbn-tab-shortcut-group-title">Note Entry</div>
                            <div class="sbn-tab-shortcut-row"><kbd>0 – 9</kbd><span>Enter fret number</span></div>
                            <div class="sbn-tab-shortcut-row"><kbd>Del / ⌫</kbd><span>Remove note on string</span></div>
                            <div class="sbn-tab-shortcut-row"><kbd>A</kbd><span>Append rest at end</span></div>
                            <div class="sbn-tab-shortcut-row"><kbd>→ (at end)</kbd><span>Fill measure with rest</span></div>
                        </div>
                        <div class="sbn-tab-shortcut-group">
                            <div class="sbn-tab-shortcut-group-title">Duration</div>
                            <div class="sbn-tab-shortcut-row"><kbd>Ctrl+1 – 2, 4 – 6</kbd><span>Whole, half, 8th, 16th, 32nd</span></div>
                            <div class="sbn-tab-shortcut-row"><kbd>Ctrl+3</kbd><span>Toggle triplet (create / dissolve)</span></div>
                            <div class="sbn-tab-shortcut-row"><kbd>+ / =</kbd><span>Shorter duration (dissolves triplet)</span></div>
                            <div class="sbn-tab-shortcut-row"><kbd>−</kbd><span>Longer duration (dissolves triplet)</span></div>
                            <div class="sbn-tab-shortcut-row"><kbd>.</kbd><span>Toggle dotted</span></div>
                            <div class="sbn-tab-shortcut-row"><kbd>T</kbd><span>Toggle tie</span></div>
                        </div>
                        <div class="sbn-tab-shortcut-group">
                            <div class="sbn-tab-shortcut-group-title">Selection &amp; Clipboard</div>
                            <div class="sbn-tab-shortcut-row"><kbd>Shift+← →</kbd><span>Extend note selection</span></div>
                            <div class="sbn-tab-shortcut-row"><kbd>Ctrl+C</kbd><span>Copy note / selection</span></div>
                            <div class="sbn-tab-shortcut-row"><kbd>Ctrl+X</kbd><span>Cut note / selection</span></div>
                            <div class="sbn-tab-shortcut-row"><kbd>Ctrl+V</kbd><span>Paste at cursor</span></div>
                        </div>
                        <div class="sbn-tab-shortcut-group">
                            <div class="sbn-tab-shortcut-group-title">History</div>
                            <div class="sbn-tab-shortcut-row"><kbd>Ctrl+Z</kbd><span>Undo</span></div>
                            <div class="sbn-tab-shortcut-row"><kbd>Ctrl+Shift+Z</kbd><span>Redo</span></div>
                            <div class="sbn-tab-shortcut-row"><kbd>?</kbd><span>This help panel</span></div>
                        </div>
                    </div>
                </div>
            </div>
        </transition>
    </div>
</template>

<script setup>
/**
 * TabEditor — Root component for the SBN tab editor.
 *
 * Phase 7a: Read-only rendering.
 * Phase 7b: Cursor navigation, click-to-select, keyboard shortcuts, sidebar.
 * Phase 7c: Fret entry, note deletion, rest↔note conversion, chord ID emit.
 * Phase 7d: Duration changes, reflow, tie toggle, dotted toggle.
 */

import { computed, defineExpose, ref, onMounted, onUnmounted, watch } from 'vue';
import { LAYOUT, generateId } from './utils/constants.js';
import { useAlpineBridge } from './composables/useAlpineBridge.js';
import { useTabModel } from './composables/useTabModel.js';
import { useCursor } from './composables/useCursor.js';
import { useNoteInput } from './composables/useNoteInput.js';
import { useReflow } from './composables/useReflow.js';
import { useUndo } from './composables/useUndo.js';
import { useSelection } from './composables/useSelection.js';
import { useMeasureSelection } from './composables/useMeasureSelection.js';
import { sidebarStore } from './composables/useSidebarStore.js';
import { modelToMusicXml } from './utils/musicXmlWriter.js';
import { extractFretsAtChord, applyVoicingToChord } from './composables/useChordSync.js';
import TabMeasure from './components/TabMeasure.vue';

// ── Alpine Bridge ──────────────────────────────────────────

const {
    melody, sections, timeSignature, songKey,
    title, composer,
    tabXml, repeatMarkers, voltaEndings,
    initialized, emitTabEdited, setSaveHandler, setVoicingAppliedHandler, emitChordUpdate,
    setSnapshotHandler, setRestoreHandler,
    emitStructureRequest,
    pendingStructureHint,
} = useAlpineBridge();

// ── Working Model ──────────────────────────────────────────

const { model, hasData, buildModel, serializeModel, deserializeModel } = useTabModel(
    melody, sections, timeSignature, repeatMarkers, voltaEndings, pendingStructureHint
);

// ── Cursor ─────────────────────────────────────────────────

const {
    cursor,
    active: cursorActive,
    currentEvent,
    currentNote,
    allMeasures,
    handleKeydown: cursorKeydown,
    clickEvent,
    clickRest,
    moveTo,
    moveRight: cursorMoveRight,
} = useCursor(model);

const cursorState = computed(() => cursor.value);

// ── Undo / Redo (Phase 7e) ─────────────────────────────────

const { canUndo, canRedo, wrapCommand, undo, redo, reset: resetUndo } = useUndo(model);

// Reset undo stack when a new leadsheet loads
watch(model, (newVal, oldVal) => {
    if (newVal && !oldVal) resetUndo();
});

// ── Step 4: Structural sync — clamp cursor after grid changes ──
// When Alpine adds/removes measures, buildModel() re-slices from the
// updated sections array. If the cursor's measureIndex now exceeds the
// new measure count, clamp it to the last valid measure so navigation
// doesn't break.
watch(allMeasures, (newMeasures) => {
    if (!newMeasures.length) return;
    const max = newMeasures.length - 1;
    if (cursor.value.measureIndex > max) {
        moveTo(max, 0, cursor.value.stringIndex);
    }
}, { flush: 'post' });

const {
    selectedEvents,
    hasNoteSelection,
    extendNoteSelection,
    clearNoteSelection,
    clipboard,
    copy:        selectionCopy,
    prepareCut,
    preparePaste,
} = useSelection(model);

// Note-selection anchor: event index where Shift+Arrow started.
// Reset on any plain cursor move.
const _noteSelAnchorIdx = ref(null);

// ── Measure-level selection (Phase 2b) ────────────────────────
// Mutually exclusive with note-level selection.

const {
    selectionAnchor:   measureAnchor,
    hasSelection:      hasMeasureSelection,
    selectionCount:    measureSelectionCount,
    isSelected:        isMeasureSelected,
    selectSingle:      selectMeasure,
    toggleSelect:      toggleMeasureSelect,
    selectRange:       selectMeasureRange,
    clearSelection:    clearMeasureSelection,
    getSelectedIndices,
} = useMeasureSelection();

/**
 * Return the measure index for a given TabEvent.
 */
function measureIdxOf(event) {
    return event?.measureIdx ?? cursor.value?.measureIndex ?? 0;
}

// Wrapped mutation helpers — all editor actions go through these.

function cmdChangeDuration(ev, newDur) {
    wrapCommand('duration', [measureIdxOf(ev)], () => changeDuration(ev, newDur));
}

function cmdToggleDotted(ev) {
    wrapCommand('dotted', [measureIdxOf(ev)], () => toggleDotted(ev));
}

function cmdToggleTie(ev, stringIndex) {
    wrapCommand('tie', [measureIdxOf(ev)], () => toggleTie(ev, stringIndex));
}

function cmdHandleDurationKey(e, ev, mode, stringIndex) {
    // handleDurationKey returns true if it consumed the event.
    // We need to know which operation fires to snapshot correctly.
    // Simplest: snapshot the current measure, run, check if changed.
    if (!ev) return false;
    const idx = measureIdxOf(ev);
    let consumed = false;
    wrapCommand('duration-key', [idx], () => {
        consumed = handleDurationKey(e, ev, mode, stringIndex);
    });
    return consumed;
}
// ── Reflow (Phase 7d) ──────────────────────────────────────

const {
    changeDuration,
    toggleDotted,
    toggleTie,
    measureFill,
    handleDurationKey,
    toggleTriplet,
    dissolveTupletGroup,
    repositionMeasure,
} = useReflow(model);

// ── Note Input ─────────────────────────────────────────────

const {
    pendingDigit,
    handleKeydown: noteInputKeydown,
    dispose: disposeNoteInput,
} = useNoteInput(cursor, model, emitTabEdited, wrapCommand, repositionMeasure);


// ── Event insertion helpers (Phase 7d / 7-polish) ──────────────

const STD_DURS = [
    { dur: 'w', ticks: 1920 }, { dur: 'hd', ticks: 1440 },
    { dur: 'h', ticks: 960 },  { dur: 'qd', ticks: 720 },
    { dur: 'q', ticks: 480 },  { dur: 'ed', ticks: 360 },
    { dur: 'e', ticks: 240 },  { dur: 'sd', ticks: 180 },
    { dur: 's', ticks: 120 },  { dur: 't', ticks: 60 },
];

/**
 * Insert a rest event immediately after the cursor event in the current measure.
 *
 * Duration = cursor event's duration (same subdivision).
 * The measure is allowed to go overfull — repositionMeasure handles the visual
 * stretch exactly as when a duration is lengthened beyond the bar capacity.
 *
 * If the cursor is on the LAST event, the behaviour is the same (append after last).
 * Works mid-measure too: events after the insertion point are pushed forward.
 *
 * Returns the new event, or null if preconditions aren't met.
 */
function insertRestAfterCursor() {
    if (!model.value) return null;

    const measures = allMeasures.value;
    const m = measures[cursor.value.measureIndex];
    if (!m) return null;

    const tpm = model.value.ticksPerMeasure;
    const v1  = m.events.filter(ev => (ev.voice || 1) === 1).sort((a, b) => a.tick - b.tick);
    if (!v1.length) return null;

    const cursorEv    = v1[cursor.value.eventIndex] || v1[v1.length - 1];
    const cursorEvIdx = v1.indexOf(cursorEv);
    const dur         = cursorEv.duration || 'q';
    const ticks       = STD_DURS.find(d => d.dur === dur)?.ticks || 480;

    const rest = {
        id:            generateId(),
        // Temporary tick: just after cursorEv so the initial sort lands it in the right slot.
        // repositionMeasure will overwrite all ticks sequentially anyway.
        tick:          cursorEv.tick + cursorEv.ticks - 0.5,
        tickInMeasure: 0,
        measureIdx:    m.index,
        duration:      dur,
        ticks,
        voice:         1,
        isRest:        true,
        notes:         [],
        tieStart:      false,
        tieStop:       false,
        tieStartEvent: null,
        tieEndEvent:   null,
        stemDir:       null,
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
        xPos:          0,
        originalIdx:   null,
    };

    // Splice into m.events directly after the cursor event's position in v1.
    // Find the cursor event in the full events array and insert after it.
    const fullIdx = m.events.indexOf(cursorEv);
    if (fullIdx !== -1) {
        m.events.splice(fullIdx + 1, 0, rest);
    } else {
        m.events.push(rest);
    }

    // repositionMeasure sorts v1 by tick, then reassigns all ticks sequentially.
    // The -0.5 on the new rest's tick ensures it sorts immediately after cursorEv
    // and before the next event (which retains its original tick value).
    repositionMeasure(m);

    // Move cursor to the newly inserted event
    const newV1  = m.events.filter(ev => (ev.voice || 1) === 1).sort((a, b) => a.tick - b.tick);
    const newIdx = newV1.findIndex(ev => ev.id === rest.id);
    if (newIdx !== -1) {
        moveTo(cursor.value.measureIndex, newIdx, cursor.value.stringIndex);
    }

    return rest;
}

/**
 * ArrowRight at end of incomplete measure → fill with a rest using insertRestAfterCursor.
 */
function handleArrowRight(e) {
    if (!cursorActive.value || !model.value) return false;
    if (cursor.value.mode !== 'navigate') return false;

    const measures = allMeasures.value;
    const m = measures[cursor.value.measureIndex];
    if (!m) return false;

    const v1 = m.events.filter(ev => (ev.voice || 1) === 1);

    // Only intercept when we're on the LAST event in the measure
    if (cursor.value.eventIndex < v1.length - 1) return false;

    // Only fill if the measure has remaining space (ArrowRight fill, not overfill)
    const fill = measureFill(m);
    if (fill.totalTicks >= fill.tpm) return false;

    const result = insertRestAfterCursor();
    if (!result) return false;

    e.preventDefault();
    return true;
}

/**
 * "A" key: insert a new rest event immediately after the cursor event.
 * Always allowed — measure may go overfull (intentional, same as duration lengthening).
 */
function handleInsertEvent(e) {
    if (!cursorActive.value || !model.value) return false;
    if (cursor.value.mode !== 'navigate') return false;

    const measures = allMeasures.value;
    const m = measures[cursor.value.measureIndex];
    if (!m) return false;

    let result = null;
    wrapCommand('insert', [m.index], () => {
        result = insertRestAfterCursor();
    });

    if (!result) return false;
    e.preventDefault();
    return true;
}

// ── Sidebar event listeners (Phase 7d) ─────────────────────
// TabSidebarApp dispatches CustomEvents for duration/tie/dotted buttons.

function onSidebarSetDuration(e) {
    const ev = currentEvent.value;
    if (!ev) return;
    const { durCode } = e.detail;
    if (durCode) cmdChangeDuration(ev, durCode);
}

function onSidebarToggleTie() {
    const ev = currentEvent.value;
    if (!ev) return;
    cmdToggleTie(ev, cursor.value.stringIndex);
}

function onSidebarToggleDotted() {
    const ev = currentEvent.value;
    if (!ev) return;
    cmdToggleDotted(ev);
}

onMounted(() => {
    document.addEventListener('sbn-sidebar-set-duration', onSidebarSetDuration);
    document.addEventListener('sbn-sidebar-toggle-tie', onSidebarToggleTie);
    document.addEventListener('sbn-sidebar-toggle-dotted', onSidebarToggleDotted);
    document.addEventListener('sbn-sidebar-undo', undo);
    document.addEventListener('sbn-sidebar-redo', redo);
    document.addEventListener('sbn-sidebar-copy',  handleCopy);
    document.addEventListener('sbn-sidebar-cut',   handleCut);
    document.addEventListener('sbn-sidebar-paste', handlePaste);

    // Register the XML serializer so the bridge can respond to sbn-tab-save-request
    setSaveHandler(() => {
        if (!model.value) return null;
        return modelToMusicXml(model.value, {
            title:    title.value    || undefined,
            composer: composer.value || undefined,
        });
    });

    // Register voicing-applied handler so bridge can call back into Vue
    setVoicingAppliedHandler(onVoicingApplied);

    // Register snapshot handlers for cross-domain structural undo.
    // sbn-tab-request-snapshot: Alpine requests a serialized tab model (synchronous).
    // sbn-tab-restore-snapshot: Alpine restores a serialized tab model (undo/redo).
    setSnapshotHandler(() => serializeModel());
    setRestoreHandler((snapshot) => deserializeModel(snapshot));
});

onUnmounted(() => {
    disposeNoteInput();
    document.removeEventListener('sbn-sidebar-set-duration', onSidebarSetDuration);
    document.removeEventListener('sbn-sidebar-toggle-tie', onSidebarToggleTie);
    document.removeEventListener('sbn-sidebar-toggle-dotted', onSidebarToggleDotted);
    document.removeEventListener('sbn-sidebar-undo', undo);
    document.removeEventListener('sbn-sidebar-redo', redo);
    document.removeEventListener('sbn-sidebar-copy',  handleCopy);
    document.removeEventListener('sbn-sidebar-cut',   handleCut);
    document.removeEventListener('sbn-sidebar-paste', handlePaste);
});

// ── Sync cursor + note state into shared sidebar store ─────
// TabSidebarApp (separate Vue mount in the Blade right panel) reads this.

watch(
    [cursor, cursorActive, currentEvent, currentNote, pendingDigit, model],
    () => {
        sidebarStore.active           = cursorActive.value;
        sidebarStore.measureIndex     = cursor.value.measureIndex;
        sidebarStore.eventIndex       = cursor.value.eventIndex;
        sidebarStore.stringIndex      = cursor.value.stringIndex;
        sidebarStore.mode             = cursor.value.mode;
        sidebarStore.hasClipboard   = !!clipboard.value;
        sidebarStore.clipboardMode  = clipboard.value?.mode ?? '';
        sidebarStore.clipboardCount = clipboard.value?.mode === 'events'
            ? (clipboard.value?.events?.length ?? 0)
            : clipboard.value?.mode === 'note' ? 1 : 0;
        sidebarStore.currentEvent   = currentEvent.value
            ? { ...currentEvent.value, notes: currentEvent.value.notes ? [...currentEvent.value.notes] : [] }
            : null;
        sidebarStore.currentNote    = currentNote.value ? { ...currentNote.value } : null;
        sidebarStore.pendingDigit   = pendingDigit.value;
        sidebarStore.canUndo        = canUndo.value;
        sidebarStore.canRedo        = canRedo.value;
        sidebarStore.ticksPerMeasure = model.value?.ticksPerMeasure ?? 1920;

        // Phase 7d: overfill indicator for current measure
        if (model.value && cursorActive.value) {
            const measures = model.value.sections.flatMap(s => s.measures);
            const m = measures[cursor.value.measureIndex];
            if (m) {
                const fill = measureFill(m);
                sidebarStore.measureOverfill = fill.overfill;
                sidebarStore.measureTotalTicks = fill.totalTicks;
            } else {
                sidebarStore.measureOverfill = false;
                sidebarStore.measureTotalTicks = 0;
            }
        } else {
            sidebarStore.measureOverfill = false;
            sidebarStore.measureTotalTicks = 0;
        }
    },
    { flush: 'post' }
);

// ── Editor root ref ────────────────────────────────────────

const editorRoot = ref(null);

// ── Keyboard shortcut overlay (Phase 7g) ───────────────────

const showShortcuts = ref(false);

// ── Empty state helpers (Phase 7g) ─────────────────────────

const hasChordsData = computed(() => {
    if (!sections.value?.length) return false;
    return sections.value.some(s =>
        s.measures?.some(m => m.chords?.length || m.chord_names?.length)
    );
});

function onGenerateFromChords() {
    // Dispatch to Alpine — tells it to build a blank tab skeleton
    // matching the chord chart structure (one whole-rest per measure).
    document.dispatchEvent(new CustomEvent('sbn-tab-generate-from-chords'));
}

// ── Keyboard handling ──────────────────────────────────────

function onKeydown(e) {
    // Undo / Redo (Phase 7e)
    if ((e.ctrlKey || e.metaKey) && !e.shiftKey && e.key === 'z') {
        e.preventDefault();
        if (canUndo.value) {
            undo();
        } else {
            // Vue stack empty — delegate to Alpine (handles structural ops: insert/delete bar)
            document.dispatchEvent(new CustomEvent('sbn-tab-delegate-undo'));
        }
        return;
    }
    if ((e.ctrlKey || e.metaKey) && (e.key === 'y' || (e.shiftKey && e.key === 'z') || (e.shiftKey && e.key === 'Z'))) {
        e.preventDefault();
        if (canRedo.value) {
            redo();
        } else {
            document.dispatchEvent(new CustomEvent('sbn-tab-delegate-redo'));
        }
        return;
    }

    // Shortcut reference overlay (Phase 7g)
    if (e.key === '?' && !e.ctrlKey && !e.metaKey && !e.altKey && cursor.value.mode !== 'input') {
        e.preventDefault();
        showShortcuts.value = !showShortcuts.value;
        return;
    }

    // Copy / Cut / Paste (Phase 7g)
    if ((e.ctrlKey || e.metaKey) && e.key === 'c') {
        e.preventDefault();
        handleCopy();
        return;
    }
    if ((e.ctrlKey || e.metaKey) && e.key === 'x') {
        e.preventDefault();
        handleCut();
        return;
    }
    if ((e.ctrlKey || e.metaKey) && e.key === 'v') {
        e.preventDefault();
        handlePaste();
        return;
    }

    // Shift+Tab / Shift+Arrow: extend measure selection (Phase 7g)
    // Note: Shift+ArrowLeft/Right extend selection measure by measure.
    // ── Shift+←/→: note-level selection ───────────────────────
    if (e.shiftKey && cursor.value.mode === 'navigate'
        && (e.key === 'ArrowLeft' || e.key === 'ArrowRight')) {
        e.preventDefault();
        const mi = cursor.value.measureIndex;
        const ei = cursor.value.eventIndex;
        const si = cursor.value.stringIndex;
        const m  = allMeasures.value[mi];
        if (!m) return;
        const v1 = m.events.filter(ev => (ev.voice ?? 1) === 1).sort((a, b) => a.tick - b.tick);

        // Entering note selection clears measure selection (mutual exclusion).
        if (hasMeasureSelection.value) clearMeasureSelection();

        // Lock anchor on first Shift press
        if (_noteSelAnchorIdx.value === null) _noteSelAnchorIdx.value = ei;

        let newEi = ei;
        if (e.key === 'ArrowLeft'  && ei > 0)             newEi = ei - 1;
        if (e.key === 'ArrowRight' && ei < v1.length - 1) newEi = ei + 1;

        moveTo(mi, newEi, si);
        extendNoteSelection(v1, _noteSelAnchorIdx.value, newEi);
        return;
    }

    // Escape: clear measure selection (Phase 2b)
    if (e.key === 'Escape' && hasMeasureSelection.value) {
        clearMeasureSelection();
        e.preventDefault();
        return;
    }

    // Escape: clear note selection
    if (e.key === 'Escape' && hasNoteSelection.value) {
        clearNoteSelection();
        _noteSelAnchorIdx.value = null;
        e.preventDefault();
        return;
    }

    // 0. ArrowRight: fill with rests when at end of incomplete measure (Phase 7d)
    if (e.key === 'ArrowRight' && !e.shiftKey && handleArrowRight(e)) return;

    // 1. Cursor navigation — plain move always clears note selection
    const cursorConsumed = cursorKeydown(e);
    if (cursorConsumed) {
        clearNoteSelection();
        _noteSelAnchorIdx.value = null;
        return;
    }

    // 2. Duration / tie shortcuts (Phase 7d)
    if (cmdHandleDurationKey(e, currentEvent.value, cursor.value.mode, cursor.value.stringIndex)) return;

    // 3. "A" key: insert new event at end of measure (Phase 7d)
    if ((e.key === 'a' || e.key === 'A') && !e.ctrlKey && !e.metaKey && !e.altKey) {
        if (cursor.value.mode === 'navigate' && handleInsertEvent(e)) return;
    }

    // 3b. Delete selected measures (Phase 2b)
    if ((e.key === 'Delete' || e.key === 'Backspace') && hasMeasureSelection.value) {
        e.preventDefault();
        const indices = getSelectedIndices();
        emitStructureRequest('deleteSelection', indices[0], { selectedIndices: indices });
        clearMeasureSelection();
        return;
    }

    // 4. Delete selected events (Shift+Arrow range active)
    if ((e.key === 'Delete' || e.key === 'Backspace') && hasNoteSelection.value) {
        e.preventDefault();
        handleDeleteSelected();
        return;
    }

    // 5. Note input (digits 0-9, Delete, Backspace)
    noteInputKeydown(e);
}

// ── Chord name click → open voicing picker (Phase 7-int Step 5) ──

/**
 * Called when a chord name is clicked in TabMeasure.
 * Extracts current frets at that chord position (if any chordal event exists)
 * and dispatches sbn-tab-open-picker to Alpine.
 */
function onChordClick({ measureIndex, chordIndex, chordName }) {
    if (!model.value) return;

    const measures = allMeasures.value;
    const measure  = measures.find(m => m.index === measureIndex);
    if (!measure) return;

    const tpm     = model.value.ticksPerMeasure;
    const tabData = extractFretsAtChord(measure, chordIndex, tpm);

    // Build voicingKey matching Alpine's format: "ChordName@globalIdx.chordIdx"
    const voicingKey = chordName + '@' + measureIndex + '.' + chordIndex;

    document.dispatchEvent(new CustomEvent('sbn-tab-open-picker', {
        detail: {
            chordName,
            voicingKey,
            currentFrets:    tabData ? tabData.frets    : null,
            currentPosition: tabData ? tabData.position : 1,
            globalMeasureIndex: measureIndex,
            chordIndex,
        },
    }));
}

/**
 * Called by the bridge when Alpine dispatches sbn-tab-voicing-applied.
 * Applies the selected voicing frets into the tab model.
 */
function onVoicingApplied({ globalMeasureIndex, chordIndex, chordName, frets, position }) {
    console.log('[SBN] onVoicingApplied called', { globalMeasureIndex, chordIndex, frets });
    if (!model.value || !frets) {
        console.warn('[SBN] onVoicingApplied: early return — model:', !!model.value, 'frets:', frets);
        return;
    }

    const measures = allMeasures.value;
    const measure  = measures.find(m => m.index === globalMeasureIndex);
    if (!measure) {
        console.warn('[SBN] onVoicingApplied: measure not found for index', globalMeasureIndex, 'available:', measures.map(m => m.index));
        return;
    }

    console.log('[SBN] onVoicingApplied: applying to measure', globalMeasureIndex, 'chordIndex', chordIndex, 'frets', frets, 'events count:', measure.events.length);
    const tpm = model.value.ticksPerMeasure;
    wrapCommand('voicing-apply', [globalMeasureIndex], () => {
        applyVoicingToChord(measure, chordIndex, tpm, frets);
    });
    console.log('[SBN] onVoicingApplied: done, measure events now:', measure.events.map(e => e.notes));
}

/**
 * Called when user clicks 🔍 next to a chord name.
 * Extracts frets from the tab, POSTs to identifySingle, shows a confirmation.
 */
async function onChordIdentify({ measureIndex, chordIndex, chordName }) {
    console.log('[SBN identify] clicked', { measureIndex, chordIndex, chordName });
    if (!model.value) { console.warn('[SBN identify] no model'); return; }

    const measures = allMeasures.value;
    const measure  = measures.find(m => m.index === measureIndex);
    if (!measure) { console.warn('[SBN identify] measure not found', measureIndex); return; }

    const tpm     = model.value.ticksPerMeasure;
    const tabData = extractFretsAtChord(measure, chordIndex, tpm);
    console.log('[SBN identify] tabData', tabData);

    if (!tabData) {
        _showIdentifyToast('No chord found at this position', 'warn');
        return;
    }

    // Build harmonic context from neighbor chord names + song key
    const allMeasureList = allMeasures.value;
    const measureFlatIdx = allMeasureList.findIndex(m => m.index === measureIndex);
    const prevChords = [];
    const nextChords = [];
    if (measureFlatIdx !== -1) {
        // Collect up to 2 chord names before and after this chord slot
        // Walking backwards through chordNames of preceding measures
        for (let mi = measureFlatIdx; mi >= 0 && prevChords.length < 2; mi--) {
            const m = allMeasureList[mi];
            const names = m.chordNames || [];
            const startCi = mi === measureFlatIdx ? chordIndex - 1 : names.length - 1;
            for (let ci = startCi; ci >= 0 && prevChords.length < 2; ci--) {
                if (names[ci]) prevChords.unshift(names[ci]);
            }
        }
        // Walking forwards
        for (let mi = measureFlatIdx; mi < allMeasureList.length && nextChords.length < 2; mi++) {
            const m = allMeasureList[mi];
            const names = m.chordNames || [];
            const startCi = mi === measureFlatIdx ? chordIndex + 1 : 0;
            for (let ci = startCi; ci < names.length && nextChords.length < 2; ci++) {
                if (names[ci]) nextChords.push(names[ci]);
            }
        }
    }

    try {
        console.log('[SBN identify] fetching for frets', tabData.frets);
        const csrf = document.querySelector('meta[name=csrf-token]')?.content;
        const resp = await fetch('/api/admin/leadsheets/identify-single', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
            },
            body: JSON.stringify({
                frets:       tabData.frets,
                position:    tabData.position,
                song_key:    songKey.value || null,
                prev_chords: prevChords,
                next_chords: nextChords,
            }),
        });

        if (!resp.ok) {
            console.warn('[SBN] identifySingle HTTP error', resp.status);
            _showIdentifyToast('Identification failed', 'error');
            return;
        }

        const data = await resp.json();
        console.log('[SBN identify] server response', data);
        if (!data.success || !data.name || data.confidence === 'none') {
            _showIdentifyToast('Could not identify chord', 'warn');
            return;
        }

        const identifiedName = data.name;

        // Always show confirm — even if same name, user may want to verify
        _showIdentifyConfirm(chordName, identifiedName, measureIndex, chordIndex);

    } catch (err) {
        console.error('[SBN] onChordIdentify fetch error:', err);
        _showIdentifyToast('Identification request failed', 'error');
    }
}

function _showIdentifyToast(msg, type = 'info') {
    // Use global sbnToast if available (defined in edit_blade.php)
    if (typeof window !== 'undefined' && typeof window.sbnToast === 'function') {
        window.sbnToast(msg, type);
    } else {
        console.log('[SBN identify]', msg);
    }
}

function _showIdentifyConfirm(oldName, newName, measureIndex, chordIndex) {
    // Dispatch a custom event that Alpine can handle to show a confirm toast
    document.dispatchEvent(new CustomEvent('sbn-tab-identify-result', {
        detail: { oldName, newName, measureIndex, chordIndex },
    }));
}

// ── Copy / Cut / Paste handlers (Phase 7g) ─────────────────
// If a note-level selection (Shift+←/→) is active, copy/cut only
// those events. Otherwise copy/cut the whole measure.

function handleCopy() {
    // Pass the actual event object + string index — same as delete uses
    selectionCopy(currentEvent.value, cursor.value.stringIndex, selectedEvents.value);
}

function handleCut() {
    const op = prepareCut(currentEvent.value, cursor.value.stringIndex, selectedEvents.value);
    if (!op) return;
    wrapCommand('cut', op.affectedIndices, op.mutate);
    clearNoteSelection();
    _noteSelAnchorIdx.value = null;
}

function handlePaste() {
    const op = preparePaste(currentEvent.value, cursor.value.stringIndex);
    if (!op) return;
    wrapCommand('paste', op.affectedIndices, op.mutate);
    clearNoteSelection();
    _noteSelAnchorIdx.value = null;
}

/**
 * Delete all selected events (Shift+Arrow range).
 * Each selected event is removed from its measure; repositionMeasure
 * recalculates ticks/xPos/actualTicks afterward.
 * Cursor lands on the event immediately before the first deleted one,
 * or event 0 if deleting from the start.
 */
function handleDeleteSelected() {
    if (!hasNoteSelection.value || !model.value) return false;

    const frozenIds = new Set(selectedEvents.value);
    const measures = allMeasures.value;
    const m = measures[cursor.value.measureIndex];
    if (!m) return false;

    const v1Before = m.events.filter(e => (e.voice || 1) === 1).sort((a, b) => a.tick - b.tick);
    const firstSelectedIdx = v1Before.findIndex(e => frozenIds.has(e.id));

    wrapCommand('delete-selected', [m.index], () => {
        m.events = m.events.filter(e => !frozenIds.has(e.id));
        // If measure is now empty, insert a whole rest so it remains accessible
        const v1 = m.events.filter(e => (e.voice || 1) === 1);
        if (v1.length === 0) {
            const tpm = model.value.ticksPerMeasure;
            m.events.push({
                id: generateId(),
                tick: m.index * tpm, tickInMeasure: 0,
                measureIdx: m.index, duration: 'w', ticks: tpm,
                voice: 1, isRest: true, notes: [],
                tieStart: false, tieStop: false, tieStartEvent: null, tieEndEvent: null,
                stemDir: null, flagCount: 0,
                beam1: null, beam2: null, beamStart: false, beamEnd: false, beamContinue: false,
                beamWith: null, noBeamBar: false,
                tupletActual: null, tupletNormal: null, tupletType: null, tupletBracket: false,
                xPos: 0, originalIdx: null,
            });
        }
        repositionMeasure(m);
    });

    // Move cursor to event just before the deleted range, or 0
    const v1After = m.events.filter(e => (e.voice || 1) === 1).sort((a, b) => a.tick - b.tick);
    const newIdx = v1After.length === 0 ? 0 : Math.max(0, firstSelectedIdx - 1);
    moveTo(cursor.value.measureIndex, newIdx, cursor.value.stringIndex);

    clearNoteSelection();
    _noteSelAnchorIdx.value = null;
    return true;
}



function onEditorFocus() {
    // Intentionally left minimal — cursor activates on click or first keypress
}

function onEditorClick() {
    editorRoot.value?.focus({ preventScroll: true });
}

// ── Cursor click handlers ──────────────────────────────────

function onCursorClickEvent({ measureIndex, eventId, stringIndex }) {
    clickEvent(measureIndex, eventId, stringIndex);
    clearNoteSelection();
    _noteSelAnchorIdx.value = null;
    clearMeasureSelection();
    editorRoot.value?.focus({ preventScroll: true });
}

function onCursorClickRest({ measureIndex, eventId }) {
    clickRest(measureIndex, eventId);
    clearNoteSelection();
    _noteSelAnchorIdx.value = null;
    clearMeasureSelection();
    editorRoot.value?.focus({ preventScroll: true });
}

// ── Measure selection handler (Phase 2b) ──────────────────────

/**
 * Fired by TabMeasure when the user Shift/Ctrl/plain-clicks the measure
 * background (or any non-note area within the measure).
 * Clears note selection and updates measure selection accordingly.
 */
function onMeasureSelect({ measureIndex, event }) {
    // Mutual exclusion: entering measure selection clears note selection.
    clearNoteSelection();
    _noteSelAnchorIdx.value = null;

    if (event.shiftKey && measureAnchor.value !== null) {
        selectMeasureRange(measureAnchor.value, measureIndex);
    } else if (event.ctrlKey || event.metaKey) {
        toggleMeasureSelect(measureIndex);
    } else {
        selectMeasure(measureIndex);
    }

    editorRoot.value?.focus({ preventScroll: true });
}

// ── Measure context menu (Phase 2b Step 3) ────────────────────

/**
 * Fired by TabMeasure on right-click anywhere in the measure.
 * Ensures the right-clicked measure is selected, then shows the
 * context menu via the global showContextMenu singleton.
 */
function onMeasureContextMenu({ measureIndex, event }) {
    // Right-clicking a measure that isn't in the current selection
    // resets to just that measure (matches standard UX convention).
    if (!isMeasureSelected(measureIndex)) {
        clearNoteSelection();
        _noteSelAnchorIdx.value = null;
        selectMeasure(measureIndex);
    }

    const indices = getSelectedIndices();
    const single  = indices.length === 1;
    const m       = allMeasures.value[measureIndex];
    const chordName = m?.chordNames?.[0];
    const items   = [];

    if (single) {
        if (chordName) {
            items.push(
                { id: 'openVoicingPicker', label: 'Open voicing picker', icon: '🎸', group: 'chord' },
                { id: 'identifyChord',     label: 'Identify chord from tab', icon: '🔍', group: 'chord' },
            );
        }
        items.push(
            { id: 'copy', label: 'Copy bar',   shortcut: 'Ctrl+C', group: 'clipboard' },
            { id: 'cut',  label: 'Cut bar',    shortcut: 'Ctrl+X', group: 'clipboard' },
        );
        items.push(
            { id: 'insertBarAfter',  label: 'Insert bar after',  group: 'structure' },
            { id: 'insertBarBefore', label: 'Insert bar before', group: 'structure' },
            { id: 'deleteBar',       label: 'Delete bar', danger: true, group: 'danger' },
        );
    } else {
        items.push(
            { id: 'copy', label: `Copy ${indices.length} bars`,   shortcut: 'Ctrl+C', group: 'clipboard' },
            { id: 'cut',  label: `Cut ${indices.length} bars`,    shortcut: 'Ctrl+X', group: 'clipboard' },
            { id: 'deleteSelection', label: `Delete ${indices.length} bars`, danger: true, group: 'danger' },
        );
    }

    if (typeof window.showContextMenu === 'function') {
        window.showContextMenu(event, items, (actionId) => {
            handleTabContextAction(actionId, measureIndex);
        });
    }

    editorRoot.value?.focus({ preventScroll: true });
}

/**
 * Dispatches the chosen context menu action.
 * Structural ops go through Alpine via emitStructureRequest.
 * Clipboard ops are stubs pending measure-level tab copy/paste.
 */
function handleTabContextAction(actionId, measureIndex) {
    switch (actionId) {
        case 'openVoicingPicker': {
            const m = allMeasures.value[measureIndex];
            if (m?.chordNames?.[0]) {
                onChordClick({ measureIndex, chordIndex: 0, chordName: m.chordNames[0] });
            }
            break;
        }
        case 'identifyChord': {
            const m = allMeasures.value[measureIndex];
            if (m?.chordNames?.[0]) {
                onChordIdentify({ measureIndex, chordIndex: 0, chordName: m.chordNames[0] });
            }
            break;
        }
        case 'insertBarAfter':
        case 'insertBarBefore':
        case 'deleteBar':
            emitStructureRequest(actionId, measureIndex);
            clearMeasureSelection();
            break;
        case 'deleteSelection': {
            const indices = getSelectedIndices();
            emitStructureRequest('deleteSelection', indices[0], { selectedIndices: indices });
            clearMeasureSelection();
            break;
        }
        case 'copy':
        case 'cut':
            // TODO: measure-level tab clipboard (deferred)
            break;
    }
}

/**
 * Click on the "?" placeholder in an empty bar.
 * Tells Alpine to open the chord name text-input picker for that measure.
 */
function onChordNameNeeded({ measureIndex, chordIndex, clientX, clientY }) {
    document.dispatchEvent(new CustomEvent('sbn-tab-open-chord-picker', {
        detail: { globalMeasureIndex: measureIndex, chordIndex, clientX, clientY },
    }));
}
function onChordContextMenu({ measureIndex, chordIndex, chordName, event }) {
    const items = [
        { id: 'openVoicingPicker', label: 'Rename chord (open picker)', icon: '✏️', group: 'chord' },
        { id: 'changeVoicing',     label: 'Change voicing',             icon: '🎸', group: 'chord' },
        { id: 'identifyChord',     label: 'Identify chord from tab',    icon: '🔍', group: 'chord' },
    ];
    if (typeof window.showContextMenu === 'function') {
        window.showContextMenu(event, items, (actionId) => {
            // Reuse the existing action handlers — all three are already wired
            // in handleTabContextAction and operate on measureIndex.
            handleTabContextAction(actionId, measureIndex);
        });
    }
}

function measureRows(section) {
    // Follow chord grid lineBreaks when available.
    // The chord grid owns lineBreaks — the tab editor reads them
    // for visual synchronization between the two views.
    //
    // Bug 4 fix: match by index position rather than id/name.
    // id can be '' or null for the single-section fallback from buildModel,
    // causing (s.id || s.name) === (section.id || section.name) to always
    // match the first Alpine section.
    const sectionIndex = model.value?.sections.indexOf(section) ?? -1;
    const alpineSec = sectionIndex >= 0
        ? (sections.value || [])[sectionIndex]
        : null;
    const lineBreaks = alpineSec?.lineBreaks;

    if (lineBreaks && lineBreaks.length) {
        const rows = [];
        let idx = 0;
        lineBreaks.forEach(count => {
            if (idx < section.measures.length) {
                // Bug 5 fix: mark rows from lineBreaks so emptySlots skips padding.
                const row = section.measures.slice(idx, idx + count);
                row._fromLineBreaks = true;
                rows.push(row);
                idx += count;
            }
        });
        // Any remaining measures beyond lineBreaks sum go into a final row
        if (idx < section.measures.length) {
            const row = section.measures.slice(idx);
            row._fromLineBreaks = true;
            rows.push(row);
        }
        return rows;
    }

    // Fallback: uniform rows of LAYOUT.measuresPerRow
    const rows = [];
    for (let i = 0; i < section.measures.length; i += LAYOUT.measuresPerRow) {
        rows.push(section.measures.slice(i, i + LAYOUT.measuresPerRow));
    }
    return rows;
}

function emptySlots(row) {
    // lineBreaks rows are marked by measureRows() — they never need padding
    // because each row's width is intentional (measures stretch to fill via flex).
    // Only pad fallback rows (uniform LAYOUT.measuresPerRow) so all rows in a
    // section have the same number of visual slots.
    if (row._fromLineBreaks) return [];
    if (row.length >= LAYOUT.measuresPerRow) return [];
    const count = LAYOUT.measuresPerRow - row.length;
    return count > 0 ? Array.from({ length: count }, (_, i) => i) : [];
}

/**
 * Get the next measure after the given global index (for cross-measure ties).
 */
function getNextMeasure(globalIndex) {
    const measures = allMeasures.value;
    const idx = measures.findIndex(m => m.index === globalIndex);
    if (idx === -1 || idx >= measures.length - 1) return null;
    return measures[idx + 1];
}

/**
 * Is the measure after globalIndex the first in its section?
 */
function isNextMeasureFirstOfSection(globalIndex) {
    if (!model.value) return false;
    const nextMeasure = getNextMeasure(globalIndex);
    if (!nextMeasure) return false;
    for (const section of model.value.sections) {
        if (section.measures.length && section.measures[0].index === nextMeasure.index) {
            return true;
        }
    }
    return false;
}

// ── Expose for Alpine integration ──────────────────────────

defineExpose({
    getTabModelJson() {
        return model.value ? JSON.stringify(model.value) : null;
    },
    rebuild() {
        buildModel();
    },
});
</script>

<style scoped>
.sbn-tab-editor-root {
    outline: none;
    position: relative;
    user-select: none;
    -webkit-user-select: none;
}

.sbn-tab-editor-notation {
    width: 100%;
    overflow-x: auto;
}

/* ── Empty state ─────────────────────────────────────────── */
.sbn-tab-no-data {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 12px;
    padding: 48px 24px;
    text-align: center;
    color: var(--clr-text-muted);
}

.sbn-tab-no-data svg {
    opacity: 0.3;
}

.sbn-tab-no-data-title {
    font-size: 15px;
    font-weight: 600;
    color: var(--clr-text);
    margin: 0;
}

.sbn-tab-no-data-hint {
    font-size: 13px;
    color: var(--clr-text-muted);
    max-width: 340px;
    line-height: 1.5;
    margin: 0;
}

.sbn-tab-generate-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin-top: 4px;
}

/* ── Shortcut overlay ────────────────────────────────────── */
.sbn-tab-shortcut-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.45);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1000;
}

.sbn-tab-shortcut-panel {
    background: var(--clr-surface, #fff);
    border: 1px solid var(--clr-border);
    border-radius: 10px;
    box-shadow: 0 8px 32px rgba(0,0,0,0.18);
    width: min(720px, 92vw);
    max-height: 80vh;
    overflow-y: auto;
    padding: 0;
}

.sbn-tab-shortcut-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 18px 12px;
    border-bottom: 1px solid var(--clr-border);
    font-weight: 600;
    font-size: 14px;
    color: var(--clr-text);
    position: sticky;
    top: 0;
    background: var(--clr-surface, #fff);
}

.sbn-tab-shortcut-close {
    background: none;
    border: none;
    font-size: 16px;
    cursor: pointer;
    color: var(--clr-text-muted);
    padding: 2px 6px;
    border-radius: 4px;
    line-height: 1;
}
.sbn-tab-shortcut-close:hover { background: var(--clr-surface-2); }

.sbn-tab-shortcut-cols {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 0;
    padding: 4px 8px 16px;
}

.sbn-tab-shortcut-group {
    padding: 12px 10px 4px;
}

.sbn-tab-shortcut-group-title {
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.07em;
    text-transform: uppercase;
    color: var(--clr-text-muted);
    margin-bottom: 8px;
}

.sbn-tab-shortcut-row {
    display: flex;
    align-items: baseline;
    gap: 8px;
    margin-bottom: 5px;
    font-size: 12px;
    color: var(--clr-text);
}

.sbn-tab-shortcut-row kbd {
    display: inline-block;
    padding: 1px 5px;
    border: 1px solid var(--clr-border);
    border-radius: 3px;
    background: var(--clr-surface-3, #f3f4f6);
    font-size: 11px;
    font-family: var(--font-mono, monospace);
    white-space: nowrap;
    flex-shrink: 0;
    min-width: 60px;
    text-align: center;
}

.sbn-tab-shortcut-row span {
    color: var(--clr-text-muted);
}

/* Transition */
.sbn-tab-overlay-fade-enter-active,
.sbn-tab-overlay-fade-leave-active {
    transition: opacity 0.15s ease;
}
.sbn-tab-overlay-fade-enter-from,
.sbn-tab-overlay-fade-leave-to {
    opacity: 0;
}
</style>

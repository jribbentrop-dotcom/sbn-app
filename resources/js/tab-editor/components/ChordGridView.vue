<template>
  <div class="sbn-ve-grid" @click="onGridClick" @contextmenu.prevent>

    <!-- Sections -->
    <ChordSection
      v-for="(section, index) in sections"
      :key="section.id || index"
      :section="section"
      :section-index="index"
      :read-only="readOnly"
      :density="density"
      @contextmenu="openContextMenu"
    />

    <!-- Context menu (Teleported to body inside the component) - hidden in readOnly mode -->
    <ChordContextMenu
      v-if="!readOnly"
      v-model:open="ctxMenuOpen"
      :top="ctxMenuTop"
      :left="ctxMenuLeft"
      :context-data="ctxMenuData"
      :has-clipboard="clipboard ? clipboard.hasClipboard.value : false"
      :selection-bar-count="selectionBarCount"
      :near-volta-end="nearVoltaEnd"
      @action="onContextMenuAction"
    />
  </div>
</template>

<script setup>
import { inject, ref, computed, onMounted, onUnmounted } from 'vue';
import ChordSection     from './ChordSection.vue';
import ChordContextMenu from './ChordContextMenu.vue';

const props = defineProps({
  sections: {
    type: Array,
    default: () => [],
  },
  readOnly: {
    type: Boolean,
    default: false,
  },
  density: {
    type: String,
    default: 'full',
    validator: (value) => ['full', 'compact'].includes(value),
  },
});

// ── Injected from TabEditor ───────────────────────────────────────────────────

const ops                = inject('chordGridOps', null);
const clipboard          = inject('chordClipboard', null);
const gridSelection      = inject('gridSelection', null);
const chordPicker        = inject('chordPicker', null);
const voicingPicker      = inject('voicingPicker', null);
const triggerInlineRename = inject('triggerInlineRename', null);
const beatsPerMeasureRef  = inject('beatsPerMeasureRef', null);

// ── Context menu state (flat refs — v-model in template binds directly) ─────

const ctxMenuOpen = ref(false);
const ctxMenuTop  = ref(0);
const ctxMenuLeft = ref(0);
const ctxMenuData = ref({});
// gi of the measure whose voltaEnd === true immediately before ctxMenuData.gi
const ctxVoltaEndGi = ref(null);

function openContextMenu({ event, gi, ci, chordName, voicing, si, mi, measure }) {
  if (props.readOnly === true) return;
  ctxMenuTop.value  = event.clientY;
  ctxMenuLeft.value = event.clientX;
  ctxMenuData.value = {
    gi, ci, chordName, voicing, si, mi,
    repeatStart: measure?.repeatStart ?? false,
    repeatEnd:   measure?.repeatEnd   ?? false,
    pickup:          measure?.pickup || measure?.isPickup || measure?.pickupBar || false,
    pickupBeats:     measure?.pickupBeats ?? null,
    beatsPerMeasure: beatsPerMeasureRef?.value ?? 4,
    volta:       measure?.volta       ?? null,
    voltaStart:  measure?.voltaStart  ?? false,
    voltaEnd:    measure?.voltaEnd    ?? false,
  };
  // Check if the bar immediately before this one is the end of a volta bracket
  ctxVoltaEndGi.value = _voltaEndBefore(gi);
  ctxMenuOpen.value = true;
}

// Returns the gi of the voltaEnd bar immediately preceding gi, or null.
function _voltaEndBefore(gi) {
  if (gi <= 0) return null;
  const prevGi = gi - 1;
  for (const sec of props.sections) {
    for (const m of sec.measures) {
      if (m.index === prevGi && m.voltaEnd) return prevGi;
    }
  }
  return null;
}

const nearVoltaEnd = computed(() => ctxVoltaEndGi.value !== null)

const selectionBarCount = computed(() => {
  const sel = gridSelection?.selection?.value ?? [];
  return new Set(sel.map(s => s.gi)).size || 1;
});

// ── Grid click — close pickers on bare-grid click ────────────────────────────

function onGridClick(event) {
  // Clicking the grid background (not a card) clears selection
  if (event.target.classList.contains('sbn-ve-grid') ||
      event.target.classList.contains('sbn-ve-row') ||
      event.target.classList.contains('sbn-ve-section-body')) {
    gridSelection?.clearSelection();
    chordPicker?.close();
  }
}

// ── Chord picker apply ────────────────────────────────────────────────────────

// ── Context menu action dispatch ──────────────────────────────────────────────

function onContextMenuAction(actionId, data, payload) {
  const { gi, ci, chordName, si, mi } = data;

  switch (actionId) {

    // Chord slot ops
    case 'rename-chord':
      triggerInlineRename?.(gi, ci);
      break;

    case 'change-voicing':
      voicingPicker?.openForChord?.(chordName, gi, ci);
      break;

    case 'add-chord':
      ops?.addChordToMeasure(gi);
      break;

    case 'delete-chord':
      ops?.deleteChords([{ gi, ci }]);
      gridSelection?.clearSelection();
      break;

    // Clipboard
    case 'copy-measure': {
      const sel = gridSelection?.selection?.value ?? [];
      const selGis = [...new Set(sel.map(s => s.gi))];
      if (selGis.length > 1) {
        clipboard?.copySelection(sel);
      } else {
        clipboard?.copyMeasure(gi);
      }
      break;
    }

    case 'cut-measure': {
      const sel = gridSelection?.selection?.value ?? [];
      const selGis = [...new Set(sel.map(s => s.gi))];
      if (selGis.length > 1) {
        clipboard?.cutSelection(sel, (s) => ops?.deleteChords(s));
      } else {
        clipboard?.cutMeasure(gi);
      }
      gridSelection?.clearSelection();
      break;
    }

    case 'paste-measure':
      clipboard?.pasteMeasure(gi);
      break;

    // Structural
    case 'insert-bar-before': {
      const sel = gridSelection?.selection?.value ?? [];
      const selGis = [...new Set(sel.map(s => s.gi))];
      if (selGis.length > 1) {
        ops?.insertBarsBeforeGi(Math.min(...selGis), selGis.length);
      } else {
        ops?.insertBarBefore(si, mi);
      }
      break;
    }

    case 'insert-bar-after': {
      const sel = gridSelection?.selection?.value ?? [];
      const selGis = [...new Set(sel.map(s => s.gi))];
      if (selGis.length > 1) {
        ops?.insertBarsAfterGi(Math.max(...selGis), selGis.length);
      } else {
        ops?.insertBarAfter(si, mi);
      }
      break;
    }

    case 'delete-bar': {
      const sel = gridSelection?.selection?.value ?? [];
      const selGis = [...new Set(sel.map(s => s.gi))];
      if (selGis.length > 1) {
        ops?.deleteBars(selGis);
      } else {
        ops?.deleteBar(gi);
      }
      gridSelection?.clearSelection();
      break;
    }

    // Section
    case 'duplicate-section':
      ops?.duplicateSection(si);
      break;

    // Repeat / volta
    case 'toggle-pickup':
      ops?.togglePickup(gi);
      break;

    case 'set-pickup-beats':
      ops?.setPickupBeats(gi, payload ?? null);
      break;

    case 'toggle-repeat-start':
      ops?.toggleRepeatStart(gi);
      break;

    case 'toggle-repeat-end':
      ops?.toggleRepeatEnd(gi);
      break;

    case 'set-volta-1':
      if (data.volta?.number === 1) {
        ops?.clearVolta(gi);
      } else {
        ops?.setVoltaStart(gi, 1);
        ops?.setVoltaEnd(gi); // always close as single-bar by default; user extends via "Extend bracket to here"
      }
      break;

    case 'set-volta-2':
      if (data.volta?.number === 2) {
        ops?.clearVolta(gi);
      } else {
        ops?.setVoltaStart(gi, 2);
        ops?.setVoltaEnd(gi);
      }
      break;

    case 'set-volta-end':
      ops?.setVoltaEnd(gi);
      break;

    case 'extend-volta-here':
      if (ctxVoltaEndGi.value !== null) {
        ops?.extendVoltaEnd(ctxVoltaEndGi.value, gi);
      }
      break;

    case 'clear-volta':
      ops?.clearVolta(gi);
      break;
  }
}

// ── Keyboard delete handler (Delete/Backspace on selected chords) ─────────────

let _cleanupKeys = null;
onMounted(() => {
  if (gridSelection?.setupKeyboardHandlers) {
    _cleanupKeys = gridSelection.setupKeyboardHandlers((sel) => {
      const selGis = [...new Set(sel.map(s => s.gi))];
      if (selGis.length > 1) {
        ops?.deleteBars(selGis);
      } else {
        ops?.deleteChords(sel);
      }
      gridSelection.clearSelection();
    });
  }
});
onUnmounted(() => {
  _cleanupKeys?.();
});
</script>

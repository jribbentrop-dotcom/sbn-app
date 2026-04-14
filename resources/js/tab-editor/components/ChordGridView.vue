<template>
  <div class="sbn-ve-grid" @click="onGridClick" @contextmenu.prevent>

    <!-- Sections -->
    <ChordSection
      v-for="(section, index) in sections"
      :key="section.id || index"
      :section="section"
      :section-index="index"
      @contextmenu="openContextMenu"
    />

    <!-- Chord name inline picker (positioned popup) -->
    <ChordPicker
      v-if="chordPicker"
      :open="chordPicker.open.value"
      :top="chordPicker.top.value"
      :left="chordPicker.left.value"
      :value="chordPicker.value.value"
      :si="0"
      :mi="chordPicker.gi.value"
      :ci="chordPicker.ci.value"
      @update:open="chordPicker.close()"
      @apply="onChordPickerApply"
    />

    <!-- Context menu (Teleported to body inside the component) -->
    <ChordContextMenu
      v-model:open="ctxMenuOpen"
      :top="ctxMenuTop"
      :left="ctxMenuLeft"
      :context-data="ctxMenuData"
      :has-clipboard="clipboard ? clipboard.hasClipboard.value : false"
      @action="onContextMenuAction"
    />
  </div>
</template>

<script setup>
import { inject, ref, onMounted, onUnmounted } from 'vue';
import ChordSection     from './ChordSection.vue';
import ChordPicker      from './ChordPicker.vue';
import ChordContextMenu from './ChordContextMenu.vue';

const props = defineProps({
  sections: {
    type: Array,
    default: () => [],
  },
});

// ── Injected from TabEditor ───────────────────────────────────────────────────

const ops          = inject('chordGridOps');    // useChordGridOps
const clipboard    = inject('chordClipboard');  // useChordClipboard
const gridSelection = inject('gridSelection'); // useGridSelection
const chordPicker  = inject('chordPicker');     // useChordPickerStore
const voicingPicker = inject('voicingPicker'); // useVoicingPickerStore (stub til Step 5)

// ── Context menu state (flat refs — v-model in template binds directly) ─────

const ctxMenuOpen = ref(false);
const ctxMenuTop  = ref(0);
const ctxMenuLeft = ref(0);
const ctxMenuData = ref({});

function openContextMenu({ event, gi, ci, chordName, voicing, si, mi }) {
  ctxMenuTop.value  = event.clientY;
  ctxMenuLeft.value = event.clientX;
  ctxMenuData.value = { gi, ci, chordName, voicing, si, mi };
  ctxMenuOpen.value = true;
}

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

function onChordPickerApply({ name, mi: gi, ci }) {
  ops?.setChordName(gi, ci, name);
  chordPicker?.close();
}

// ── Context menu action dispatch ──────────────────────────────────────────────

function onContextMenuAction(actionId, data) {
  const { gi, ci, chordName, si, mi } = data;

  switch (actionId) {

    // Chord slot ops
    case 'rename-chord':
      chordPicker?.openAt(
        { bottom: ctxMenuTop.value, left: ctxMenuLeft.value },
        gi, ci, chordName
      );
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
    case 'copy-measure':
      clipboard?.copyMeasure(gi);
      break;

    case 'cut-measure':
      clipboard?.cutMeasure(gi);
      gridSelection?.clearSelection();
      break;

    case 'paste-measure':
      clipboard?.pasteMeasure(gi);
      break;

    // Structural
    case 'insert-bar-before':
      ops?.insertBarBefore(si, mi);
      break;

    case 'insert-bar-after':
      ops?.insertBarAfter(si, mi);
      break;

    case 'delete-bar':
      ops?.deleteBar(gi);
      gridSelection?.clearSelection();
      break;

    // Section
    case 'duplicate-section':
      ops?.duplicateSection(si);
      break;
  }
}

// ── Keyboard delete handler (Delete/Backspace on selected chords) ─────────────

let _cleanupKeys = null;
onMounted(() => {
  if (gridSelection?.setupKeyboardHandlers) {
    _cleanupKeys = gridSelection.setupKeyboardHandlers((sel) => {
      ops?.deleteChords(sel);
      gridSelection.clearSelection();
    });
  }
});
onUnmounted(() => {
  _cleanupKeys?.();
});
</script>

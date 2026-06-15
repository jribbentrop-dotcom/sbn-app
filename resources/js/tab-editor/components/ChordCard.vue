<template>
  <div
    class="sbn-ve-chord"
    :class="[densityClass, { 'is-selected': selected, 'is-active': isPlayingCard, 'is-being-dragged': isBeingDragged }]"
    @click.stop="onBodyClick"
    @contextmenu.prevent="onContextMenu"
  >

    <!-- Chord name — click activates inline rename input -->
    <div class="sbn-ve-chord-name" @click.stop="onNameClick">
      <input
        v-if="editing"
        ref="nameInput"
        class="sbn-ve-chord-name-input"
        :value="editValue"
        @input="editValue = $event.target.value"
        @keydown.enter.prevent="commitEdit"
        @keydown.escape.prevent="cancelEdit"
        @blur="commitEdit"
        @click.stop
        @pointerdown.stop
      />
      <span v-else v-html="formattedChordName || (readOnly ? '' : '<span class=\'sbn-ve-chord-name-empty\'>?</span>')"></span>
    </div>

    <!-- Diagram area — drag to move, click opens voicing picker -->
    <div
      class="sbn-ve-chord-diagram"
      :class="{ empty: !voicing }"
      @click.stop="onDiagramClick"
      @pointerdown.stop="onCardPointerDown"
    >
      <div v-if="voicing" class="sbn-diagram-card" v-html="renderedDiagram"></div>
      <span v-else class="sbn-ve-chord-no-voicing">🎸</span>
    </div>

    <!-- Left-edge resize handle -->
    <div
      v-if="chordPicker"
      class="sbn-ve-chord-resize-handle sbn-ve-chord-resize-handle--left"
      title="Drag to resize"
      @pointerdown.stop="onLeftResizeHandlePointerDown"
    ></div>

    <!-- Right-edge resize handle -->
    <div
      v-if="chordPicker"
      class="sbn-ve-chord-resize-handle sbn-ve-chord-resize-handle--right"
      title="Drag to resize"
      @pointerdown.stop="onResizeHandlePointerDown"
    ></div>

  </div>
</template>

<script setup>
import { inject, computed, ref, nextTick, watch } from 'vue';

import { formatChordHtml, renderDiagramSVG } from '../utils/chordFormat';

const props = defineProps({
  chord: {
    type: Object,
    required: true,
  },
  sectionIndex: {
    type: Number,
    required: true,
  },
  measureIndex: {
    // Global measure index (gi) — passed through from ChordMeasure
    type: Number,
    required: true,
  },
  chordIndex: {
    type: Number,
    required: true,
  },
  totalChords: {
    type: Number,
    required: false,
    default: 1,
  },
  // Parser-derived beat offset and duration (quarter beats from measure start).
  // When present, used for playhead highlighting instead of even division.
  chordOffset: {
    type: Number,
    default: null,
  },
  chordDuration: {
    type: Number,
    default: null,
  },
  isBeingDragged: {
    type: Boolean,
    default: false,
  },
  readOnly: {
    type: Boolean,
    default: false,
  },
});

const emit = defineEmits(['contextmenu', 'chord-drag-start', 'chord-resize-start', 'chord-resize-start-left']);

// ── Injected from TabEditor (provided once at root) ───────────────────────────

const model               = inject('model', null);
const globalIndexOf       = inject('globalIndexOf', null);
const gridSelection       = inject('gridSelection', null);   // useGridSelection instance
const chordPicker         = inject('chordPicker', null);     // useChordPickerStore (not provided in viewer mode)
const voicingPicker       = inject('voicingPicker', null);   // useVoicingPickerStore (not provided in viewer mode)
const playingMeasureIndex = inject('playingMeasureIndex', null);
const transportBeat       = inject('transportBeat', null);
const beatsPerMeasureRef  = inject('beatsPerMeasureRef', null);
const seekToMeasure       = inject('seekToMeasure', null);  // seek + play from TabEditor
const setChordName         = inject('setChordName', null);
const inlineRenameTarget   = inject('inlineRenameTarget', null);

// ── Inline editing state ──────────────────────────────────────────────────────

const nameInput = ref(null);
const editing   = ref(false);
const editValue = ref('');

watch(inlineRenameTarget, (target) => {
  if (target && target.source !== 'tab' && target.gi === gi.value && target.ci === ci.value) {
    editValue.value = props.chord.name || '';
    editing.value = true;
    nextTick(() => { nameInput.value?.focus(); nameInput.value?.select(); });
  }
});

// ── Derived ───────────────────────────────────────────────────────────────────

// gi is the global measure index (passed as measureIndex prop from ChordMeasure)
const gi = computed(() => props.measureIndex);
const ci = computed(() => props.chordIndex);

const isPlayingCard = computed(() => {
  if (playingMeasureIndex?.value !== props.measureIndex) return false;
  const total = props.totalChords || 1;
  if (total <= 1) return true; // single chord covers the full measure

  const bpm           = beatsPerMeasureRef?.value ?? 4;
  const beat          = transportBeat?.value ?? 0;
  const beatInMeasure = ((beat % bpm) + bpm) % bpm; // clamp to [0, bpm)

  // Use parser-derived window when available; otherwise fall back to even division.
  const slotStart = props.chordOffset  != null ? props.chordOffset   : props.chordIndex * (bpm / total);
  const slotEnd   = props.chordDuration != null ? slotStart + props.chordDuration : slotStart + (bpm / total);

  return beatInMeasure >= slotStart && beatInMeasure < slotEnd;
});

const densityClass = computed(() => {
  const len = props.totalChords;
  if (len === 2) return 'double';
  if (len >= 3 && len <= 4) return 'multi';
  if (len >= 5) return 'dense';
  return '';
});

const selected = computed(() =>
  gridSelection?.isSelected(gi.value, ci.value) ?? false
);

const formattedChordName = computed(() =>
  formatChordHtml(props.chord.name)
);

const voicing = computed(() => {
  if (!model || !model.value?.chordVoicings || !props.chord.name) return null;
  const cv = model.value.chordVoicings;
  return cv[`${props.chord.name}@${gi.value}.${ci.value}`] || cv[props.chord.name] || null;
});

const renderedDiagram = computed(() => {
  if (!voicing.value) return '';
  return renderDiagramSVG(voicing.value);
});

// ── Interaction handlers ──────────────────────────────────────────────────────

/** Click anywhere on the card body → selection + seek (no auto-play when stopped) */
function onBodyClick(event) {
  gridSelection?.handleClick(gi.value, ci.value, event);
  seekToMeasure?.(gi.value, ci.value);
  // Empty name — open inline edit immediately so the slot is reachable
  if (!props.readOnly && setChordName && !props.chord.name && !editing.value) {
    editValue.value = '';
    editing.value = true;
    nextTick(() => { nameInput.value?.focus(); });
  }
}

/** Click on the chord name text → select + seek, then activate inline edit in editor mode */
function onNameClick(event) {
  event.stopPropagation();
  gridSelection?.handleClick(gi.value, ci.value, event);
  seekToMeasure?.(gi.value, ci.value);

  if (!props.readOnly && setChordName && !editing.value) {
    editValue.value = props.chord.name || '';
    editing.value = true;
    nextTick(() => {
      nameInput.value?.focus();
      nameInput.value?.select();
    });
  }
}

function commitEdit() {
  if (!editing.value) return;
  editing.value = false;
  const newName = editValue.value.trim();
  if (newName !== (props.chord.name || '')) {
    setChordName?.(gi.value, ci.value, newName);
  }
}

function cancelEdit() {
  editing.value = false;
}

/** Click on diagram area → select + seek (viewer mode) or open voicing picker (editor mode) */
function onDiagramClick(event) {
  event.stopPropagation();
  gridSelection?.handleClick(gi.value, ci.value, event);
  seekToMeasure?.(gi.value, ci.value);

  if (!props.readOnly && voicingPicker) {
    voicingPicker.openForChord?.(props.chord.name || '', gi.value, ci.value);
  }
}

/** Pointerdown on card body — emit drag-start only after a small movement threshold
 *  so plain clicks still fire normally. */
function onCardPointerDown(event) {
  if (!chordPicker) return;  // viewer mode — no drag
  const startX = event.clientX;
  const startY = event.clientY;

  function onMove(e) {
    const dx = e.clientX - startX;
    const dy = e.clientY - startY;
    if (Math.sqrt(dx * dx + dy * dy) > 4) {
      window.removeEventListener('pointermove', onMove);
      window.removeEventListener('pointerup', onUp);
      emit('chord-drag-start', e);
    }
  }

  function onUp() {
    window.removeEventListener('pointermove', onMove);
    window.removeEventListener('pointerup', onUp);
  }

  window.addEventListener('pointermove', onMove);
  window.addEventListener('pointerup', onUp);
}

function onResizeHandlePointerDown(event) {
  emit('chord-resize-start', event);
}

function onLeftResizeHandlePointerDown(event) {
  emit('chord-resize-start-left', event);
}

/** Right-click → bubble contextmenu up to ChordMeasure */
function onContextMenu(event) {
  emit('contextmenu', {
    event,
    gi: gi.value,
    ci: ci.value,
    chordName: props.chord.name || '',
    voicing: voicing.value,
  });
}
</script>

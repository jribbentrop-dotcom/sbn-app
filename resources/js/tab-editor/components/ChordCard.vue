<template>
  <div
    class="sbn-ve-chord"
    :class="[densityClass, { 'is-selected': selected, 'is-active': isPlayingCard }]"
    @click.stop="onBodyClick"
    @contextmenu.prevent="onContextMenu"
  >
    <!-- Chord name — click opens inline name editor -->
    <div
      class="sbn-ve-chord-name"
      v-html="formattedChordName"
      @click.stop="onNameClick"
    ></div>

    <!-- Diagram area — click opens voicing picker -->
    <div
      class="sbn-ve-chord-diagram"
      :class="{ empty: !voicing }"
      @click.stop="onDiagramClick"
    >
      <div v-if="voicing" class="sbn-diagram-card" v-html="renderedDiagram"></div>
      <span v-else class="sbn-ve-chord-no-voicing">🎸</span>
    </div>

  </div>
</template>

<script setup>
import { inject, computed } from 'vue';

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
});

const emit = defineEmits(['contextmenu']);

// ── Injected from TabEditor (provided once at root) ───────────────────────────

const model               = inject('model');
const globalIndexOf       = inject('globalIndexOf');
const gridSelection       = inject('gridSelection');        // useGridSelection instance
const chordPicker         = inject('chordPicker');          // useChordPickerStore instance
const voicingPicker       = inject('voicingPicker');        // useVoicingPickerStore (stub until Step 5)
const playingMeasureIndex = inject('playingMeasureIndex', null);
const transportBeat       = inject('transportBeat', null);
const beatsPerMeasureRef  = inject('beatsPerMeasureRef', null);
const seekToMeasure       = inject('seekToMeasure', null);  // seek + play from TabEditor

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

/** Click anywhere on the card body → selection + seek/play from this measure */
function onBodyClick(event) {
  gridSelection?.handleClick(gi.value, ci.value, event);
  seekToMeasure?.(gi.value);
}

/** Click on the chord name text → open chord picker */
function onNameClick(event) {
  event.stopPropagation();
  // Also update selection to this card
  gridSelection?.handleClick(gi.value, ci.value, event);

  if (chordPicker) {
    chordPicker.openAt(event.currentTarget, gi.value, ci.value, props.chord.name || '');
  }
}

/** Click on diagram area → open voicing picker */
function onDiagramClick(event) {
  event.stopPropagation();
  // Also select this card
  gridSelection?.handleClick(gi.value, ci.value, event);

  if (voicingPicker) {
    // openForChord is the Step 5 API — stub accepts this call gracefully
    voicingPicker.openForChord?.(props.chord.name || '', gi.value, ci.value);
  }
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

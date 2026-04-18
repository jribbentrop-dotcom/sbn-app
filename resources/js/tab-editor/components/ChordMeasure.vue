<template>
  <div
    class="sbn-ve-measure"
    :class="measureClasses"
    :data-si="sectionIndex"
    :data-mi="measureIndex"
    :data-gi="globalIdx"
  >
    <div class="sbn-ve-volta" v-if="volta">{{ volta.number }}.</div>
    <div class="sbn-ve-measure-num">{{ globalIdx + 1 }}</div>
    <div class="sbn-ve-tab-badge" v-if="measure._fromTab">TAB</div>
    <div class="sbn-ve-rep-sign rep-start" v-if="hasRepStart">𝄆</div>
    <div class="sbn-ve-rep-sign rep-end"   v-if="hasRepEnd">𝄇</div>

    <div class="sbn-ve-measure-content">
      <!-- Beat-grid tick marks — one per quarter-note beat across the measure -->
      <div class="sbn-ve-beat-grid">
        <div
          v-for="b in beatsPerMeasure"
          :key="b"
          class="sbn-ve-beat-tick"
          :class="{ 'beat-one': b === 1, 'beat-active': activeBeat === b }"
          :style="{ left: ((b - 0.5) / beatsPerMeasure * 100) + '%' }"
        ></div>
      </div>

      <!-- Real chord cards — absolutely positioned by beat offset -->
      <ChordCard
        v-for="(name, chordIndex) in chordNamesArray"
        :key="chordIndex"
        :chord="{ name, beats: chordBeats(chordIndex) }"
        :section-index="sectionIndex"
        :measure-index="globalIdx"
        :chord-index="chordIndex"
        :total-chords="chordNamesArray.length"
        :chord-offset="measure.chordOffsets?.[chordIndex]"
        :chord-duration="measure.chordBeats?.[chordIndex]"
        :style="chordPositionStyle(chordIndex)"
        @contextmenu="onCardContextMenu"
      />
      <!-- Ghost slot — shown when bar is empty so it stays clickable/right-clickable -->
      <ChordCard
        v-if="chordNamesArray.length === 0"
        :chord="{ name: '', beats: 1 }"
        :section-index="sectionIndex"
        :measure-index="globalIdx"
        :chord-index="0"
        :total-chords="0"
        :style="{ position: 'absolute', left: '0', width: '100%' }"
        @contextmenu="onCardContextMenu"
      />
    </div>
  </div>
</template>

<script setup>
import { inject, computed } from 'vue';
import ChordCard from './ChordCard.vue';

const props = defineProps({
  measure: {
    type: Object,
    required: true,
  },
  sectionIndex: {
    type: Number,
    required: true,
  },
  measureIndex: {
    // This is the LOCAL measure index within the section (for data attrs / Alpine compat).
    // The global index is computed from globalIndexOf below.
    type: Number,
    required: true,
  },
});

const emit = defineEmits(['contextmenu']);

// ── Injected from TabEditor ───────────────────────────────────────────────────

const globalIndexOf       = inject('globalIndexOf');
const playingMeasureIndex = inject('playingMeasureIndex', null);
const transportBeat       = inject('transportBeat', null);

// ── Derived ───────────────────────────────────────────────────────────────────

// The model stores measure.index as the global index.
// globalIndexOf is the canonical source; fall back to measure.index.
const globalIdx = computed(() =>
  globalIndexOf
    ? globalIndexOf(props.sectionIndex, props.measureIndex)
    : props.measure.index ?? props.measureIndex
);

// Normalise chord names: measure.chordNames is the canonical array (string[])
const chordNamesArray = computed(() => {
  const names = props.measure.chordNames || props.measure.chords || [];
  return Array.isArray(names)
    ? names.map(c => (typeof c === 'string' ? c : (c?.name || c?.chordName || '')))
    : [];
});

// Compute quarter-note beats per chord slot: evenly divide the measure.
// beatsPerMeasureRef is injected from TabEditor (provides 'beatsPerMeasureRef').
const beatsPerMeasureRef = inject('beatsPerMeasureRef', null);

function chordBeats(ci) {
  const total = chordNamesArray.value.length || 1;
  const bpm   = beatsPerMeasureRef?.value ?? 4;
  // If the model has explicit per-chord beat data, honour it; otherwise divide evenly.
  return props.measure.chordBeats?.[ci] ?? (bpm / total);
}

// Which beat (1-based) is currently playing in this measure, or 0 if none.
const activeBeat = computed(() => {
  if (playingMeasureIndex?.value !== globalIdx.value) return 0;
  const bpm  = beatsPerMeasureRef?.value ?? 4;
  const beat = transportBeat?.value ?? 0;
  // beatInMeasure is 0-based; floor gives us the current quarter-beat slot (0..bpm-1)
  return Math.floor(((beat % bpm) + bpm) % bpm) + 1; // 1-based
});

// Total beats in the measure — the denominator for the beat grid.
const beatsPerMeasure = computed(() => beatsPerMeasureRef?.value ?? 4);

// Absolute position style for each chord card.
// left  = beat offset as % of measure width.
// width = beat duration as % of measure width.
// Falls back to even division when parser-derived offsets are absent.
function chordPositionStyle(ci) {
  const total  = chordNamesArray.value.length || 1;
  const bpm    = beatsPerMeasure.value;
  const offset = props.measure.chordOffsets?.[ci] ?? (ci * (bpm / total));
  const dur    = props.measure.chordBeats?.[ci]   ?? (bpm / total);
  return {
    left:  (offset / bpm * 100) + '%',
    width: (dur    / bpm * 100) + '%',
  };
}

// Repeat signs and volta are serialized directly onto the measure object.
const volta      = computed(() => props.measure.volta);
const hasRepStart = computed(() => props.measure.repeatStart);
const hasRepEnd   = computed(() => props.measure.repeatEnd);

const measureClasses = computed(() => ({
  'has-volta':      !!volta.value,
  'rep-start-bar':  hasRepStart.value,
  'rep-end-bar':    hasRepEnd.value,
  'is-empty':       chordNamesArray.value.length === 0,
}));

// ── Context menu ─────────────────────────────────────────────────────────────

/**
 * A ChordCard emitted a contextmenu event — augment with measure coords and re-emit
 * so ChordSection (and ultimately ChordGridView) can position the context menu.
 */
function onCardContextMenu(payload) {
  emit('contextmenu', {
    ...payload,
    si: props.sectionIndex,
    mi: props.measureIndex,
  });
}
</script>

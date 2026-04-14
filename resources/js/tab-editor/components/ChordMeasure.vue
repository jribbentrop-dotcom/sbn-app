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
      <ChordCard
        v-for="(name, chordIndex) in chordNamesArray"
        :key="chordIndex"
        :chord="{ name, beats: chordBeats(chordIndex) }"
        :section-index="sectionIndex"
        :measure-index="globalIdx"
        :chord-index="chordIndex"
        :total-chords="chordNamesArray.length"
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

const globalIndexOf = inject('globalIndexOf');

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

function chordBeats(ci) {
  return props.measure.chordBeats?.[ci] ?? 1;
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

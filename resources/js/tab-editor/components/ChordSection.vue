<template>
  <div class="sbn-ve-section">
    <div class="sbn-ve-section-header">
      <div v-if="section.id" class="sbn-ve-section-id">{{ section.id }}</div>
      <input class="sbn-ve-section-name"
             :value="section.name"
             placeholder="Section name…"
             @blur="renameSection(sectionIndex, $event.target.value)"
             @keydown.enter="$event.target.blur()" />
      <span class="sbn-ve-section-bar-count">{{ section.measures ? section.measures.length : 0 }} bars</span>
    </div>

    <div class="sbn-ve-section-body">
      <div v-for="(row, ri) in rows" :key="ri" class="sbn-ve-row">
        <ChordMeasure
          v-for="measure in row"
          :key="measure.index"
          :measure="measure"
          :section-index="sectionIndex"
          :measure-index="localIndexOf(measure)"
          @contextmenu="emit('contextmenu', $event)"
        />
        <!-- Row resize controls — wired in a later step -->
        <div class="sbn-ve-row-resize"></div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, inject } from 'vue';
import ChordMeasure from './ChordMeasure.vue';

const props = defineProps({
  section: {
    type: Object,
    required: true,
  },
  sectionIndex: {
    type: Number,
    required: true,
  },
});

const emit = defineEmits(['contextmenu']);

const renameSection = inject('renameSection');

// ── Row layout (respects lineBreaks from model) ───────────────────────────────

const DEFAULT_BARS_PER_ROW = 4;

const rows = computed(() => {
  const lineBreaks = props.section.lineBreaks;
  const measures   = props.section.measures || [];

  if (lineBreaks?.length) {
    const out = [];
    let idx = 0;
    for (const count of lineBreaks) {
      if (idx >= measures.length) break;
      out.push(measures.slice(idx, idx + count));
      idx += count;
    }
    if (idx < measures.length) {
      out.push(measures.slice(idx));
    }
    return out;
  }

  // Fallback: uniform rows
  const out = [];
  for (let i = 0; i < measures.length; i += DEFAULT_BARS_PER_ROW) {
    out.push(measures.slice(i, i + DEFAULT_BARS_PER_ROW));
  }
  return out;
});

/**
 * Return the LOCAL (within-section) index of a measure object.
 * ChordMeasure needs it for data attrs and legacy Alpine compat.
 */
function localIndexOf(measure) {
  return props.section.measures.indexOf(measure);
}
</script>

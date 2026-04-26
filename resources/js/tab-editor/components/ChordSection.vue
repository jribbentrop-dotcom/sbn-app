<template>
  <div class="sbn-ve-section">
    <div class="sbn-ve-section-header" :class="{ 'is-collapsed': collapsed }">
      <!-- Collapse button - hidden in readOnly -->
      <button v-if="!props.readOnly"
              class="sbn-ve-section-collapse"
              :class="{ 'is-collapsed': collapsed }"
              @click.stop="collapsed = !collapsed"
              title="Collapse section">▼</button>
      <div v-if="section.id" class="sbn-ve-section-id">{{ section.id }}</div>
      <!-- Editable input in edit mode, span in readOnly -->
      <input v-if="!readOnly"
             class="sbn-ve-section-name"
             :value="section.name"
             placeholder="Section name…"
             @blur="renameSection(sectionIndex, $event.target.value)"
             @keydown.enter="$event.target.blur()" />
      <span v-else class="sbn-ve-section-name">{{ section.name }}</span>
      <span class="sbn-ve-section-bar-count">{{ section.measures ? section.measures.length : 0 }} bars</span>
      <!-- Action buttons - hidden in readOnly -->
      <div v-if="!props.readOnly" class="sbn-ve-section-actions">
        <button class="sbn-ve-section-btn" @click="addMeasureToSection(sectionIndex)" title="Add bar">+</button>
        <button v-if="sectionCount > 1" class="sbn-ve-section-delete" @click="deleteSection(sectionIndex)" title="Remove section">×</button>
      </div>
    </div>

    <div class="sbn-ve-section-body" v-show="!collapsed">
      <div v-for="(row, ri) in rows" :key="ri" class="sbn-ve-row">
        <ChordMeasure
          v-for="measure in row"
          :key="measure.index"
          :measure="measure"
          :section-index="sectionIndex"
          :measure-index="localIndexOf(measure)"
          :read-only="readOnly"
          :density="density"
          @contextmenu="emit('contextmenu', $event)"
        />
        <!-- Row resize controls - hidden in readOnly -->
        <div v-if="!readOnly" class="sbn-ve-row-resize">
          <button class="sbn-ve-row-btn"
                  :disabled="row.length <= 1"
                  title="Move last bar to next row"
                  @click.stop="rowShrink(sectionIndex, ri)">−</button>
          <button class="sbn-ve-row-btn"
                  :disabled="ri >= rows.length - 1"
                  title="Pull next bar into this row"
                  @click.stop="rowGrow(sectionIndex, ri)">+</button>
          <button class="sbn-ve-row-btn sbn-ve-row-btn-section"
                  title="New section after this row"
                  @click.stop="rowSplit(sectionIndex, ri)">§</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, inject, ref } from 'vue';
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

const emit = defineEmits(['contextmenu']);

const collapsed            = ref(false);
// All injects below are editor-only; provide null defaults so viewer mode doesn't warn.
const renameSection        = inject('renameSection', null);
const addMeasureToSection  = inject('addMeasureToSection', null);
const deleteSection        = inject('deleteSection', null);
const sectionCount         = inject('sectionCount', null);
const rowShrink            = inject('rowShrink', null);
const rowGrow              = inject('rowGrow', null);
const rowSplit             = inject('rowSplit', null);

// ── Row layout (respects lineBreaks from model) ───────────────────────────────

const DEFAULT_BARS_PER_ROW = 4;

// Density affects default bars per row - compact mode fits more measures
const barsPerRow = computed(() => {
  if (props.density === 'compact') {
    return 6; // More measures fit when diagrams are hidden
  }
  return DEFAULT_BARS_PER_ROW;
});

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

  // Fallback: uniform rows based on density
  const out = [];
  const currentBarsPerRow = barsPerRow.value;
  for (let i = 0; i < measures.length; i += currentBarsPerRow) {
    out.push(measures.slice(i, i + currentBarsPerRow));
  }
  return out;
});

function localIndexOf(measure) {
  return props.section.measures.indexOf(measure);
}
</script>

<template>
  <div class="classic-chord-card">
    <div class="classic-chord-name" v-html="formatChordHtml(chordName)"></div>
    <div v-if="svgHtml" class="classic-chord-diagram" v-html="svgHtml"></div>
    <NeonChordDiagram
      v-else-if="voicing"
      :frets="voicing.frets"
      :position="voicing.position ?? voicing.pos ?? 1"
      width="72"
    />
    <div v-else class="classic-chord-empty"></div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import NeonChordDiagram from '@/Components/ChordDiagram/NeonChordDiagram.vue';
import { formatChordHtml } from '@/tab-editor/utils/chordFormat.js';

const props = defineProps({
  chordName: { type: String, default: '' },
  voicing:   { type: Object, default: null },
  dotColor:  { type: String, default: 'var(--stage-accent, var(--clr-red))' },
});

const svgHtml = computed(() => {
  if (!props.voicing?.frets || typeof window.sbnRenderDiagramSVG !== 'function') return '';
  return window.sbnRenderDiagramSVG(props.voicing, {
    dotColor: props.dotColor,
    showFingers: true,
  });
});
</script>

<style scoped>
.classic-chord-card {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  flex-shrink: 0;
  width: 72px;
}

.classic-chord-name {
  font-family: var(--stage-font-chord);
  font-size: 14px;
  font-weight: 600;
  color: #000000;
  --sbn-chord-color: #000000;
  text-align: center;
  line-height: 1;
  white-space: nowrap;
}

.classic-chord-name :deep(.sbn-chord-accidental) { font-size: 0.75em; vertical-align: 0.15em; }
.classic-chord-name :deep(.sbn-chord-quality)    { font-size: 0.7em; font-style: italic; font-weight: 400; }
.classic-chord-name :deep(.sbn-chord-ext)        { font-size: 0.6em; vertical-align: 0.5em; font-weight: 600; color: #000000; }

.classic-chord-diagram {
  width: 100%;
}

.classic-chord-diagram :deep(svg) {
  width: 100%;
  height: auto;
  display: block;
}

.classic-chord-empty {
  width: 100%;
  height: 80px;
}
</style>

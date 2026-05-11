<template>
  <div>
    <!-- Section tabs -->
    <div class="stage-sec-tabs">
      <button
        v-for="(sec, si) in sections" :key="si"
        class="stage-sec-tab"
        :class="{ 'stage-sec-tab--active': si === activeSectionIndex }"
        :data-sec="si % 4"
        @click="activeSectionIndex = si"
      >
        <span class="stage-sec-tab-letter">{{ sec.id || String.fromCharCode(65 + si) }}</span>
        <span class="stage-sec-tab-name">{{ sec.name || '' }}</span>
      </button>
    </div>

    <!-- Single active section — horizontal scroll row, one frame per measure -->
    <div v-if="activeSection" class="stage-sec-panel" :data-sec="activeSectionIndex % 4">
      <div class="stage-sec-scroll">
        <div
          v-for="(measure, mi) in (activeSection.measures || [])" :key="measure.globalIndex ?? mi"
          class="stage-sec-measure"
          :class="{
            'stage-sec-measure--active': measure.globalIndex === currentBarIndex,
            'stage-sec-measure--past':   playing && measure.globalIndex < currentBarIndex,
          }"
          @click="$emit('seek-measure', measure.globalIndex ?? mi)"
        >
          <div class="stage-sec-bar-num">{{ (measure.globalIndex ?? mi) + 1 }}</div>
          <div class="stage-sec-measure-chords">
            <ClassicChordCard
              v-for="(name, ci) in (measure.chordNames || [])" :key="ci"
              :chord-name="name"
              :voicing="getVoicingAt(measure, ci)"
            />
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import ClassicChordCard from '@/Components/ChordDiagram/ClassicChordCard.vue';

const props = defineProps({
  sections:        { type: Array, default: () => [] },
  currentBarIndex: { type: Number, default: 0 },
  playing:         { type: Boolean, default: false },
  chordVoicings:   { type: Object, default: () => ({}) },
});

defineEmits(['seek-measure']);

const activeSectionIndex = ref(0);
const activeSection = computed(() => props.sections[activeSectionIndex.value] ?? null);

// Auto-advance section tab when the playing bar enters a new section
watch(() => props.currentBarIndex, (barIndex) => {
  const si = props.sections.findIndex(sec =>
    (sec.measures ?? []).some(m => (m.globalIndex ?? -1) === barIndex)
  );
  if (si !== -1 && si !== activeSectionIndex.value) {
    activeSectionIndex.value = si;
  }
});

function getVoicingAt(measure, ci) {
  const name = measure.chordNames?.[ci];
  if (!name) return null;
  const gi = measure.globalIndex ?? 0;
  return props.chordVoicings?.[`${name}@${gi}.${ci}`] ?? props.chordVoicings?.[name] ?? null;
}
</script>

<style scoped>
/* ── Section tabs ── */
.stage-sec-tabs {
  display: flex;
  gap: 6px;
  margin-bottom: 14px;
  flex-wrap: wrap;
}

.stage-sec-tab {
  display: flex;
  align-items: center;
  gap: 7px;
  padding: 6px 14px 6px 10px;
  border-radius: 8px;
  border: 1px solid var(--stage-line-2);
  background: transparent;
  cursor: pointer;
  transition: all 0.15s ease;
  color: var(--stage-text-dim);
}

.stage-sec-tab:hover {
  background: var(--stage-bg-2);
  color: var(--stage-text);
}

.stage-sec-tab--active {
  background: var(--stage-bg-2);
  border-color: rgba(var(--stage-accent-rgb), 0.4);
  color: var(--stage-text);
}

.stage-sec-tab-letter {
  font-family: var(--stage-font-mono);
  font-size: 11px;
  font-weight: 700;
  width: 20px;
  height: 20px;
  border-radius: 4px;
  display: grid;
  place-items: center;
  background: var(--stage-bg-3);
  color: var(--stage-text-dim);
  flex-shrink: 0;
}

.stage-sec-tab--active .stage-sec-tab-letter,
.stage-sec-tab[data-sec="0"] .stage-sec-tab-letter,
.stage-sec-tab[data-sec="1"] .stage-sec-tab-letter {
  background: rgba(var(--stage-accent-rgb), 0.15);
  color: var(--stage-accent-2);
}

.stage-sec-tab[data-sec="2"] .stage-sec-tab-letter { background: rgba(107,70,246,0.15); color: #a88bff; }
.stage-sec-tab[data-sec="3"] .stage-sec-tab-letter { background: rgba(74,222,128,0.15); color: var(--stage-good); }

.stage-sec-tab-name {
  font-family: var(--stage-font-chord);
  font-style: italic;
  font-size: 13px;
}

/* ── Full-width section panel ── */
.stage-sec-panel {
  background: var(--stage-bg-1);
  border: 1px solid var(--stage-line);
  border-radius: 10px;
  padding: 14px 16px;
}

.stage-sec-scroll {
  display: flex;
  gap: 8px;
  overflow-x: auto;
  padding-bottom: 6px;
  scrollbar-width: thin;
  scrollbar-color: var(--stage-line-2) transparent;
}

.stage-sec-scroll::-webkit-scrollbar { height: 4px; }
.stage-sec-scroll::-webkit-scrollbar-track { background: transparent; }
.stage-sec-scroll::-webkit-scrollbar-thumb { background: var(--stage-line-2); border-radius: 2px; }

.stage-sec-measure {
  display: flex;
  flex-direction: column;
  gap: 6px;
  flex-shrink: 0;
  cursor: pointer;
  padding: 8px 10px 10px;
  border-radius: 8px;
  border: 1px solid var(--stage-line);
  background: var(--stage-bg-2);
  transition: border-color 0.15s ease, opacity 0.15s ease;
  min-width: 80px;
}

.stage-sec-measure:hover {
  border-color: var(--stage-line-2);
}

.stage-sec-measure--active {
  border-color: var(--stage-accent);
  background: rgba(var(--stage-accent-rgb), 0.04);
}

.stage-sec-measure--past {
  opacity: 0.35;
}

.stage-sec-bar-num {
  font-family: var(--stage-font-mono);
  font-size: 9px;
  color: var(--stage-text-mute);
  font-weight: 500;
}

.stage-sec-measure--active .stage-sec-bar-num {
  color: var(--stage-accent);
}

.stage-sec-measure-chords {
  display: flex;
  gap: 8px;
}
</style>

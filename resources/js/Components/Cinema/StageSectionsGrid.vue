<template>
  <div>
    <!-- Top bar: section tabs left, view toggle right -->
    <div class="stage-sec-topbar">
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

      <!-- View toggle — only show Tab button when song has tab data -->
      <div class="stage-view-toggle">
        <button
          class="stage-view-btn"
          :class="{ 'stage-view-btn--active': view === 'chords' }"
          @click="view = 'chords'"
        >Chords</button>
        <button
          v-if="tabHasData"
          class="stage-view-btn"
          :class="{ 'stage-view-btn--active': view === 'tab' }"
          @click="view = 'tab'"
        >Tab</button>
      </div>
    </div>

    <!-- ── Chords view ── -->
    <div v-if="view === 'chords' && activeSection" class="stage-sec-panel" :data-sec="activeSectionIndex % 4">
      <div ref="chordsScrollEl" class="stage-sec-scroll">
        <div
          v-for="(measure, mi) in (activeSection.measures || [])" :key="measure.globalIndex ?? mi"
          class="stage-sec-measure"
          :class="{
            'stage-sec-measure--active': measure.globalIndex === currentBarIndex,
            'stage-sec-measure--past':   playing && measure.globalIndex < currentBarIndex,
            'stage-sec-measure--hidden': !isMeasureVisible(measure),
          }"
          @click="isMeasureVisible(measure) && $emit('seek-measure', measure.globalIndex ?? mi)"
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

    <!-- ── Tab view ── -->
    <div v-if="view === 'tab' && tabModel" class="stage-tab-panel">
      <div ref="tabScrollEl" class="stage-tab-scroll">
        <template v-for="(section, si) in tabSections" :key="section.id || si">
          <div
            v-for="measure in section.measures"
            :key="measure.index"
            class="stage-tab-measure-wrap"
            :class="{ 'stage-tab-measure-wrap--active': measure.index === currentBarIndex }"
            @click="emit('seek-measure', measure.index)"
          >
            <TabMeasure
              :measure="measure"
              :is-first-of-section="si === 0 && measure === section.measures[0]"
              :ticks-per-measure="tabModel.ticksPerMeasure"
              :next-measure="getNextTabMeasure(measure.index)"
              :is-next-first-of-section="isNextTabMeasureFirstOfSection(measure.index)"
              :chord-names="measure.chordNames || []"
              :bars-per-row="4"
              :read-only="true"
              :allow-chord-click="false"
              :cursor="null"
              :pending-digit="null"
              :selected-events="new Set()"
            />
          </div>
        </template>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, nextTick } from 'vue';
import ClassicChordCard from '@/Components/ChordDiagram/ClassicChordCard.vue';
import TabMeasure from '@/tab-editor/components/TabMeasure.vue';

const props = defineProps({
  sections:        { type: Array,   default: () => [] },
  currentBarIndex: { type: Number,  default: 0 },
  playing:         { type: Boolean, default: false },
  chordVoicings:   { type: Object,  default: () => ({}) },
  activeVoltaPass: { type: Number,  default: 1 },
  tabModel:        { type: Object,  default: null },
  tabHasData:      { type: Boolean, default: false },
});

const emit = defineEmits(['seek-measure']);

const view = ref('chords');
const activeSectionIndex = ref(0);
const activeSection = computed(() => props.sections[activeSectionIndex.value] ?? null);

const chordsScrollEl = ref(null);
const tabScrollEl    = ref(null);

// Auto-advance section tab + scroll active measure into view
watch(() => props.currentBarIndex, async (barIndex) => {
  const si = props.sections.findIndex(sec =>
    (sec.measures ?? []).some(m => (m.globalIndex ?? -1) === barIndex)
  );
  if (si !== -1 && si !== activeSectionIndex.value) {
    activeSectionIndex.value = si;
    await nextTick();
  }
  scrollToActive(barIndex);
});

function scrollToActive(barIndex) {
  // Chords view — find the active .stage-sec-measure card
  if (view.value === 'chords' && chordsScrollEl.value) {
    const active = chordsScrollEl.value.querySelector('.stage-sec-measure--active');
    if (active) active.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
    return;
  }
  // Tab view — find the TabMeasure wrapper by data-measure attribute
  if (view.value === 'tab' && tabScrollEl.value) {
    const active = tabScrollEl.value.querySelector(`[data-measure="${barIndex}"]`);
    if (active) active.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
  }
}

// A volta measure is visible only when its number matches the active pass.
function isMeasureVisible(measure) {
  if (!measure.volta) return true;
  return measure.volta.number === props.activeVoltaPass;
}

function getVoicingAt(measure, ci) {
  const name = measure.chordNames?.[ci];
  if (!name) return null;
  const gi = measure.globalIndex ?? 0;
  return props.chordVoicings?.[`${name}@${gi}.${ci}`] ?? props.chordVoicings?.[name] ?? null;
}

// ── Tab helpers ──────────────────────────────────────────────────────────────

// Only the active section's measures in the tab view
const tabSections = computed(() => {
  if (!props.tabModel) return [];
  const sec = props.tabModel.sections[activeSectionIndex.value];
  return sec ? [sec] : [];
});

function getNextTabMeasure(index) {
  if (!props.tabModel) return null;
  const all = props.tabModel.sections.flatMap(s => s.measures ?? []);
  const idx = all.findIndex(m => m.index === index);
  return idx >= 0 && idx < all.length - 1 ? all[idx + 1] : null;
}

function isNextTabMeasureFirstOfSection(index) {
  if (!props.tabModel) return false;
  const next = getNextTabMeasure(index);
  if (!next) return false;
  for (const section of props.tabModel.sections) {
    if (section.measures?.[0]?.index === next.index) return true;
  }
  return false;
}
</script>

<style scoped>
/* ── Top bar: section tabs + view toggle ── */
.stage-sec-topbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 14px;
  flex-wrap: wrap;
}

.stage-sec-tabs {
  display: flex;
  gap: 6px;
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

/* ── View toggle ── */
.stage-view-toggle {
  display: flex;
  border: 1px solid var(--stage-line-2);
  border-radius: 8px;
  overflow: hidden;
  flex-shrink: 0;
}

.stage-view-btn {
  padding: 5px 14px;
  background: transparent;
  border: none;
  color: var(--stage-text-dim);
  font-family: var(--stage-font-mono);
  font-size: 11px;
  font-weight: 600;
  letter-spacing: 0.5px;
  cursor: pointer;
  transition: background 0.15s, color 0.15s;
}

.stage-view-btn + .stage-view-btn {
  border-left: 1px solid var(--stage-line-2);
}

.stage-view-btn:hover {
  background: var(--stage-bg-2);
  color: var(--stage-text);
}

.stage-view-btn--active {
  background: rgba(var(--stage-accent-rgb), 0.12);
  color: var(--stage-accent);
}

/* ── Chords panel ── */
.stage-sec-panel {
  background: transparent;
  border: none;
  border-radius: 10px;
  padding: 4px 0 14px;
}

.stage-sec-scroll {
  display: flex;
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
  border-radius: 6px;
  border: 1px solid var(--stage-line-2);
  background: var(--stage-bg-1);
  box-shadow: 0 1px 3px rgba(0,0,0,0.18);
  min-width: 80px;
  position: relative;
  margin-right: 8px;
  max-width: 300px;
  overflow: hidden;
  transition:
    max-width    0.35s cubic-bezier(0.4, 0, 0.2, 1),
    min-width    0.35s cubic-bezier(0.4, 0, 0.2, 1),
    opacity      0.25s ease,
    margin       0.35s cubic-bezier(0.4, 0, 0.2, 1),
    padding      0.35s cubic-bezier(0.4, 0, 0.2, 1),
    border-width 0.35s cubic-bezier(0.4, 0, 0.2, 1),
    border-color 0.15s ease,
    box-shadow   0.15s ease;
}

.stage-sec-measure:hover {
  border-color: rgba(var(--stage-accent-rgb), 0.4);
  box-shadow: 0 2px 8px rgba(var(--stage-accent-rgb), 0.1);
}

.stage-sec-measure--active {
  border-color: var(--stage-accent);
  background: rgba(var(--stage-accent-rgb), 0.06);
  box-shadow: 0 0 0 1px rgba(var(--stage-accent-rgb), 0.2), 0 4px 12px rgba(var(--stage-accent-rgb), 0.12);
}

.stage-sec-measure--past {
  opacity: 0.35;
}

.stage-sec-measure--hidden {
  max-width: 0;
  min-width: 0;
  opacity: 0;
  padding: 0;
  margin: 0;
  border-width: 0;
  pointer-events: none;
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

/* ── Tab panel ── */
.stage-tab-panel {
  padding: 4px 0 14px;
}

.stage-tab-scroll {
  display: flex;
  overflow-x: auto;
  padding-bottom: 6px;
  scrollbar-width: thin;
  scrollbar-color: var(--stage-line-2) transparent;
  /* Dark theme overrides for tab SVG */
  --sbn-tab-bg:          transparent;
  --sbn-tab-string-color: rgba(255,255,255,0.25);
  --sbn-tab-text-color:   rgba(255,255,255,0.85);
  --sbn-tab-beat-color:   rgba(255,255,255,0.1);
  --sbn-tab-active-bg:    rgba(var(--stage-accent-rgb), 0.15);
  --sbn-chord-color:      var(--stage-text);
}

.stage-tab-scroll::-webkit-scrollbar { height: 4px; }
.stage-tab-scroll::-webkit-scrollbar-track { background: transparent; }
.stage-tab-scroll::-webkit-scrollbar-thumb { background: var(--stage-line-2); border-radius: 2px; }


/* Tab measure wrapper — click target + active highlight */
.stage-tab-measure-wrap {
  cursor: pointer;
  border-radius: 4px;
  transition: background 0.15s;
  flex-shrink: 0;
}

.stage-tab-measure-wrap:hover {
  background: rgba(var(--stage-accent-rgb), 0.05);
}

.stage-tab-measure-wrap--active {
  background: rgba(var(--stage-accent-rgb), 0.1);
  outline: 1px solid rgba(var(--stage-accent-rgb), 0.35);
  outline-offset: -1px;
}

/* Chord names in tab view — larger, stage colors */
.stage-tab-scroll :deep(.sbn-tab-chord-bar .sbn-tab-chord-name) {
  font-size: 26px;
  color: var(--stage-text);
  --sbn-chord-color: var(--stage-text);
  --clr-text: var(--stage-text);
}

.stage-tab-scroll :deep(.sbn-tab-chord-bar) {
  height: 50px;
}
</style>

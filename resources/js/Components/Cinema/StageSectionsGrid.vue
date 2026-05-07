<template>
  <div>
    <!-- Strip header -->
    <div class="stage-strip-head">
      <div class="stage-strip-title">Leadsheet · full chart</div>
      <div class="stage-strip-legend">
        <span v-for="(sec, i) in sections.slice(0, 4)" :key="i">
          <span class="stage-strip-legend-dot" :style="legendDotStyle(i)"></span>
          {{ sec.id || sec.name || String.fromCharCode(65 + i) }}
        </span>
      </div>
    </div>

    <!-- 4-column section grid -->
    <div class="stage-sections-grid">
      <div
        v-for="(sec, si) in sections" :key="si"
        class="stage-sec-col"
        :data-sec="si % 4"
      >
        <!-- Section header -->
        <div class="stage-sec-col-head">
          <span class="stage-sec-letter">{{ sec.id || String.fromCharCode(65 + si) }}</span>
          <span class="stage-sec-label">{{ sec.name || '' }}</span>
        </div>

        <!-- Bar list -->
        <div class="stage-sec-bars">
          <div
            v-for="(measure, mi) in (sec.measures || [])" :key="measure.globalIndex ?? mi"
            class="stage-sec-bar"
            :class="{
              'stage-sec-bar--active':  measure.globalIndex === currentBarIndex,
              'stage-sec-bar--past':    playing && measure.globalIndex < currentBarIndex,
              'stage-sec-bar--multi':   (measure.chordNames?.length ?? 0) > 1,
            }"
            @click="$emit('seek-measure', measure.globalIndex ?? mi)"
          >
            <!-- Bar number -->
            <div class="stage-sec-bar-num">{{ (measure.globalIndex ?? mi) + 1 }}</div>

            <!-- Chord name(s) -->
            <div class="stage-sec-bar-chord">
              <span
                v-for="(name, ci) in (measure.chordNames || [])" :key="ci"
                v-html="formatChordHtml(name)"
              ></span>
            </div>

            <!-- Tiny neon diagram (first chord) -->
            <div class="stage-sec-bar-mini">
              <NeonChordDiagram
                v-if="getVoicing(measure)"
                :frets="getVoicing(measure).frets"
                :position="getVoicing(measure).position ?? getVoicing(measure).pos ?? 1"
                width="28"
              />
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import NeonChordDiagram from '@/Components/ChordDiagram/NeonChordDiagram.vue';
import { formatChordHtml } from '@/tab-editor/utils/chordFormat.js';

const props = defineProps({
  sections:       { type: Array, default: () => [] },
  currentBarIndex:{ type: Number, default: 0 },
  playing:        { type: Boolean, default: false },
  chordVoicings:  { type: Object, default: () => ({}) },
});

defineEmits(['seek-measure']);

function getVoicing(measure) {
  if (!measure.chordNames?.length) return null;
  const name = measure.chordNames[0];
  const gi = measure.globalIndex ?? 0;
  return props.chordVoicings?.[`${name}@${gi}.0`] ?? props.chordVoicings?.[name] ?? null;
}

const LEGEND_COLORS = [
  'var(--stage-accent)',
  'var(--stage-accent)',
  '#a88bff',
  'var(--stage-good)',
];

function legendDotStyle(i) {
  return { background: LEGEND_COLORS[i % LEGEND_COLORS.length] };
}
</script>

<style scoped>
.stage-strip-head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  margin-bottom: 14px;
}

.stage-strip-title {
  font-size: 12px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 1.5px;
  color: var(--stage-text-mute);
}

.stage-strip-legend {
  display: flex;
  gap: 18px;
  font-size: 11px;
  color: var(--stage-text-mute);
}

.stage-strip-legend-dot {
  display: inline-block;
  width: 8px;
  height: 8px;
  border-radius: 2px;
  margin-right: 5px;
  vertical-align: middle;
}

.stage-sections-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 14px;
}

.stage-sec-col {
  background: var(--stage-bg-1);
  border: 1px solid var(--stage-line);
  border-radius: 10px;
  padding: 14px 12px 12px;
}

.stage-sec-col-head {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 12px;
  padding-bottom: 10px;
  border-bottom: 1px solid var(--stage-line);
}

.stage-sec-letter {
  font-family: var(--stage-font-mono);
  font-size: 11px;
  font-weight: 700;
  width: 22px;
  height: 22px;
  background: var(--stage-bg-3);
  border-radius: 5px;
  display: grid;
  place-items: center;
  color: var(--stage-text-dim);
  flex-shrink: 0;
}

.stage-sec-col[data-sec="0"] .stage-sec-letter,
.stage-sec-col[data-sec="1"] .stage-sec-letter {
  background: rgba(var(--stage-accent-rgb), 0.15);
  color: var(--stage-accent-2);
}

.stage-sec-col[data-sec="2"] .stage-sec-letter {
  background: rgba(107, 70, 246, 0.15);
  color: #a88bff;
}

.stage-sec-col[data-sec="3"] .stage-sec-letter {
  background: rgba(74, 222, 128, 0.15);
  color: var(--stage-good);
}

.stage-sec-label {
  font-family: var(--stage-font-chord);
  font-style: italic;
  font-size: 13px;
  color: var(--stage-text-dim);
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.stage-sec-bars {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.stage-sec-bar {
  display: grid;
  grid-template-columns: 20px 1fr auto;
  align-items: center;
  gap: 10px;
  padding: 8px 10px;
  border-radius: 6px;
  background: transparent;
  cursor: pointer;
  transition: background 0.15s ease;
  border-left: 2px solid transparent;
}

.stage-sec-bar:hover {
  background: var(--stage-bg-2);
}

.stage-sec-bar--active {
  background: linear-gradient(90deg, rgba(var(--stage-accent-rgb), 0.12), rgba(var(--stage-accent-rgb), 0.02));
  border-left-color: var(--stage-accent);
}

.stage-sec-bar--past {
  opacity: 0.4;
}

.stage-sec-bar-num {
  font-family: var(--stage-font-mono);
  font-size: 10px;
  color: var(--stage-text-mute);
  font-weight: 500;
}

.stage-sec-bar--active .stage-sec-bar-num {
  color: var(--stage-accent);
}

.stage-sec-bar-chord {
  font-family: var(--stage-font-chord);
  font-size: 18px;
  font-weight: 600;
  color: var(--stage-text);
  line-height: 1;
}

.stage-sec-bar--multi .stage-sec-bar-chord {
  font-size: 14px;
  display: flex;
  gap: 8px;
  align-items: baseline;
  flex-wrap: wrap;
}

.stage-sec-bar-chord :deep(.sbn-chord-accidental) {
  font-size: 0.8em;
  vertical-align: 0.1em;
}

.stage-sec-bar-chord :deep(.sbn-chord-ext) {
  font-size: 0.55em;
  vertical-align: 0.7em;
  font-weight: 600;
  color: var(--stage-accent);
  margin-left: 1px;
}

.stage-sec-bar-chord :deep(.sbn-chord-quality) {
  font-size: 0.6em;
  font-style: italic;
  font-weight: 400;
}

.stage-sec-bar-mini {
  width: 28px;
  opacity: 0.7;
  flex-shrink: 0;
}

.stage-sec-bar--active .stage-sec-bar-mini {
  opacity: 1;
}

@media (max-width: 960px) {
  .stage-sections-grid {
    grid-template-columns: repeat(2, 1fr);
  }
}
</style>

<template>
  <section class="stage-deck">
    <!-- Transport buttons -->
    <div class="stage-deck-transport">
      <button class="stage-t-btn" title="Previous bar" @click="$emit('prev')">‹‹</button>
      <button
        class="stage-t-btn stage-t-btn--primary"
        :title="playing ? 'Pause' : 'Play'"
        @click="$emit('toggle')"
      >{{ playing ? '❚❚' : '▶' }}</button>
      <button class="stage-t-btn" title="Next bar" @click="$emit('next')">››</button>
    </div>

    <!-- Timeline: position counter + progress track -->
    <div class="stage-deck-timeline">
      <span class="stage-deck-pos">
        <strong>{{ String(currentBar + 1).padStart(2, '0') }}</strong> / {{ totalBars }}
      </span>
      <div class="stage-deck-track" @click="onTrackClick">
        <!-- Section-tinted zones -->
        <div class="stage-deck-track-sections">
          <div
            v-for="(sec, i) in sections" :key="i"
            class="stage-deck-track-section"
            :data-sec="i % 4"
          ></div>
        </div>
        <!-- Fill -->
        <div
          class="stage-deck-track-fill"
          :style="{ width: fillPct + '%' }"
        ></div>
      </div>
    </div>

    <!-- Rate + Loop toggles -->
    <div class="stage-deck-toggles">
      <button
        v-for="r in rateSteps" :key="r"
        class="stage-d-toggle"
        :class="{ 'stage-d-toggle--on': playbackRate === r }"
        @click="$emit('set-rate', r)"
      >{{ r === 1 ? '1×' : r + '×' }}</button>
      <div class="stage-deck-toggle-divider"></div>
      <button
        class="stage-d-toggle"
        :class="{ 'stage-d-toggle--on': loopOn }"
        @click="$emit('toggle-loop')"
      >Loop</button>
    </div>
  </section>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  playing:    { type: Boolean, default: false },
  currentBar: { type: Number, default: 0 },
  currentBeat:{ type: Number, default: 0 },
  totalBars:  { type: Number, default: 1 },
  beatsPerMeasure: { type: Number, default: 4 },
  sections:   { type: Array, default: () => [] },
  loopOn:        { type: Boolean, default: false },
  playbackRate:  { type: Number,  default: 1 },
  rateSteps:     { type: Array,   default: () => [0.5, 0.75, 1, 1.25] },
});

const emit = defineEmits(['toggle', 'prev', 'next', 'seek-bar', 'toggle-loop', 'set-rate']);

const fillPct = computed(() => {
  const total = props.totalBars || 1;
  const pos = props.currentBar + (props.currentBeat / (props.beatsPerMeasure || 4));
  return Math.min(100, (pos / total) * 100);
});

function onTrackClick(e) {
  const rect = e.currentTarget.getBoundingClientRect();
  const pct = (e.clientX - rect.left) / rect.width;
  const bar = Math.floor(pct * props.totalBars);
  emit('seek-bar', Math.max(0, Math.min(props.totalBars - 1, bar)));
}
</script>

<style scoped>
.stage-deck {
  background: var(--clr-surface-2);
  border: 1px solid var(--clr-border);
  border-radius: var(--radius);
  padding: 14px 18px;
  display: grid;
  grid-template-columns: auto 1fr auto;
  align-items: center;
  gap: 20px;
  margin-bottom: 28px;
}

.stage-deck-transport {
  display: flex;
  align-items: center;
  gap: 8px;
}

.stage-t-btn {
  width: 38px;
  height: 38px;
  border-radius: 50%;
  border: 1px solid var(--clr-border);
  background: var(--clr-surface-3);
  color: var(--clr-text);
  cursor: pointer;
  display: grid;
  place-items: center;
  font-size: 12px;
  transition: all 0.15s ease;
}

.stage-t-btn:hover {
  background: var(--clr-white);
}

.stage-t-btn--primary {
  width: 48px;
  height: 48px;
  background: var(--stage-gradient);
  border-color: transparent;
  color: var(--clr-white);
  font-size: 15px;
  font-weight: 700;
  box-shadow: 0 0 0 4px rgba(var(--stage-accent-rgb), 0.12);
}

.stage-t-btn--primary:hover {
  background: var(--stage-gradient-hover);
  box-shadow: 0 0 0 6px rgba(var(--stage-accent-rgb), 0.15);
}

.stage-deck-timeline {
  display: flex;
  align-items: center;
  gap: 14px;
  min-width: 0;
}

.stage-deck-pos {
  font-family: var(--font-mono);
  font-size: 12px;
  color: var(--clr-text-dim);
  min-width: 70px;
  white-space: nowrap;
}

.stage-deck-pos strong {
  color: var(--clr-text);
  font-weight: 600;
}

.stage-deck-track {
  flex: 1;
  height: 6px;
  background: var(--clr-surface-3);
  border-radius: 3px;
  position: relative;
  cursor: pointer;
}

.stage-deck-track-sections {
  position: absolute;
  inset: 0;
  display: flex;
}

.stage-deck-track-section {
  flex: 1;
  border-right: 1px solid var(--clr-bg);
  opacity: 0.6;
}

.stage-deck-track-section:last-child {
  border-right: none;
}

.stage-deck-track-section[data-sec="0"] {
  background: linear-gradient(90deg, rgba(var(--stage-accent-rgb), 0.18), transparent);
}

.stage-deck-track-section[data-sec="1"] {
  background: linear-gradient(90deg, rgba(var(--stage-accent-rgb), 0.09), transparent);
}

.stage-deck-track-section[data-sec="2"] {
  background: linear-gradient(90deg, rgba(139, 92, 246, 0.15), transparent);
}

.stage-deck-track-section[data-sec="3"] {
  background: linear-gradient(90deg, rgba(16, 185, 129, 0.15), transparent);
}

.stage-deck-track-fill {
  position: absolute;
  left: 0;
  top: 0;
  bottom: 0;
  background: var(--stage-gradient);
  border-radius: 3px;
  box-shadow: 0 0 6px rgba(var(--stage-accent-rgb), 0.25);
  transition: width 0.15s linear;
}

.stage-deck-toggles {
  display: flex;
  gap: 6px;
}

.stage-d-toggle {
  padding: 6px 11px;
  border-radius: var(--radius-sm);
  border: 1px solid var(--clr-border);
  background: transparent;
  color: var(--clr-text-dim);
  font-family: var(--font-mono);
  font-size: 10px;
  font-weight: 600;
  letter-spacing: 1px;
  cursor: pointer;
  text-transform: uppercase;
  transition: all 0.15s ease;
}

.stage-d-toggle:hover {
  color: var(--clr-text);
}

.stage-d-toggle--on {
  background: rgba(var(--stage-accent-rgb), 0.1);
  color: var(--stage-accent);
  border-color: rgba(var(--stage-accent-rgb), 0.35);
}

.stage-deck-toggle-divider {
  width: 1px;
  height: 20px;
  background: var(--clr-border);
  align-self: center;
  margin: 0 2px;
}

@media (max-width: 960px) {
  .stage-deck {
    grid-template-columns: 1fr;
    gap: 12px;
  }
}
</style>

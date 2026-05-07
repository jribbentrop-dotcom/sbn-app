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

    <!-- Count / Loop / Click toggles -->
    <div class="stage-deck-toggles">
      <button
        class="stage-d-toggle"
        :class="{ 'stage-d-toggle--on': countOn }"
        @click="$emit('toggle-count')"
      >Count</button>
      <button
        class="stage-d-toggle"
        :class="{ 'stage-d-toggle--on': loopOn }"
        @click="$emit('toggle-loop')"
      >Loop</button>
      <button
        class="stage-d-toggle"
        :class="{ 'stage-d-toggle--on': clickOn }"
        @click="$emit('toggle-click')"
      >Click</button>
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
  countOn:    { type: Boolean, default: false },
  loopOn:     { type: Boolean, default: false },
  clickOn:    { type: Boolean, default: false },
});

const emit = defineEmits(['toggle', 'prev', 'next', 'seek-bar', 'toggle-count', 'toggle-loop', 'toggle-click']);

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
  background: var(--stage-bg-2);
  border: 1px solid var(--stage-line);
  border-radius: 12px;
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
  border: 1px solid var(--stage-line-2);
  background: var(--stage-bg-3);
  color: var(--stage-text);
  cursor: pointer;
  display: grid;
  place-items: center;
  font-size: 12px;
  transition: all 0.15s ease;
}

.stage-t-btn:hover {
  background: var(--stage-bg-1);
}

.stage-t-btn--primary {
  width: 48px;
  height: 48px;
  background: var(--stage-accent);
  border-color: var(--stage-accent);
  color: var(--stage-primary-ink);
  font-size: 15px;
  font-weight: 700;
  box-shadow: 0 0 0 4px rgba(var(--stage-accent-rgb), 0.15);
}

.stage-t-btn--primary:hover {
  background: var(--stage-accent-2);
  box-shadow: 0 0 0 6px rgba(var(--stage-accent-rgb), 0.18);
}

.stage-deck-timeline {
  display: flex;
  align-items: center;
  gap: 14px;
  min-width: 0;
}

.stage-deck-pos {
  font-family: var(--stage-font-mono);
  font-size: 12px;
  color: var(--stage-text-dim);
  min-width: 70px;
  white-space: nowrap;
}

.stage-deck-pos strong {
  color: var(--stage-text);
  font-weight: 600;
}

.stage-deck-track {
  flex: 1;
  height: 6px;
  background: var(--stage-bg-3);
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
  border-right: 1px solid var(--stage-bg);
  opacity: 0.6;
}

.stage-deck-track-section:last-child {
  border-right: none;
}

.stage-deck-track-section[data-sec="0"] {
  background: linear-gradient(90deg, rgba(var(--stage-accent-rgb), 0.12), transparent);
}

.stage-deck-track-section[data-sec="1"] {
  background: linear-gradient(90deg, rgba(var(--stage-accent-rgb), 0.06), transparent);
}

.stage-deck-track-section[data-sec="2"] {
  background: linear-gradient(90deg, rgba(107, 70, 246, 0.12), transparent);
}

.stage-deck-track-section[data-sec="3"] {
  background: linear-gradient(90deg, rgba(74, 222, 128, 0.12), transparent);
}

.stage-deck-track-fill {
  position: absolute;
  left: 0;
  top: 0;
  bottom: 0;
  background: var(--stage-accent);
  border-radius: 3px;
  box-shadow: 0 0 8px rgba(var(--stage-accent-rgb), 0.4);
  transition: width 0.15s linear;
}

.stage-deck-toggles {
  display: flex;
  gap: 6px;
}

.stage-d-toggle {
  padding: 6px 11px;
  border-radius: 6px;
  border: 1px solid var(--stage-line-2);
  background: transparent;
  color: var(--stage-text-dim);
  font-family: var(--stage-font-mono);
  font-size: 10px;
  font-weight: 600;
  letter-spacing: 1px;
  cursor: pointer;
  text-transform: uppercase;
  transition: all 0.15s ease;
}

.stage-d-toggle:hover {
  color: var(--stage-text);
}

.stage-d-toggle--on {
  background: rgba(var(--stage-accent-rgb), 0.15);
  color: var(--stage-accent-2);
  border-color: rgba(var(--stage-accent-rgb), 0.4);
}

@media (max-width: 960px) {
  .stage-deck {
    grid-template-columns: 1fr;
    gap: 12px;
  }
}
</style>

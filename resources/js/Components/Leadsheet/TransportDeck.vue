<template>
  <section class="tdeck">
    <!-- Transport buttons -->
    <div class="tdeck-transport">
      <button class="tdeck-btn" title="Previous bar" @click="$emit('prev')">‹‹</button>
      <button
        class="tdeck-btn tdeck-btn--primary"
        :title="playing ? 'Pause' : 'Play'"
        @click="$emit('toggle')"
      >{{ playing ? '❚❚' : '▶' }}</button>
      <button class="tdeck-btn" title="Next bar" @click="$emit('next')">››</button>
    </div>

    <!-- Timeline: position counter + progress track -->
    <div class="tdeck-timeline">
      <span class="tdeck-pos">
        <strong>{{ String(currentBar + 1).padStart(2, '0') }}</strong> / {{ totalBars }}
      </span>
      <div class="tdeck-track" @click="onTrackClick">
        <!-- Section-tinted zones -->
        <div class="tdeck-track-sections">
          <div
            v-for="(sec, i) in sections" :key="i"
            class="tdeck-track-section"
            :data-sec="i % 4"
          ></div>
        </div>
        <!-- Fill -->
        <div
          class="tdeck-track-fill"
          :style="{ width: fillPct + '%' }"
        ></div>
      </div>
    </div>

    <!-- Speed slider + Loop toggle -->
    <div class="tdeck-toggles">
      <RateSlider :model-value="rate" @update:model-value="$emit('update:rate', $event)" />
      <template v-if="showLoop">
        <div class="tdeck-toggle-divider"></div>
        <button
          class="tdeck-d-toggle"
          :class="{ 'tdeck-d-toggle--on': loopOn }"
          @click="$emit('toggle-loop')"
        >Loop</button>
      </template>
    </div>
  </section>
</template>

<script setup>
import { computed } from 'vue';
import RateSlider from './RateSlider.vue';

const props = defineProps({
  playing:    { type: Boolean, default: false },
  currentBar: { type: Number, default: 0 },
  currentBeat:{ type: Number, default: 0 },
  totalBars:  { type: Number, default: 1 },
  beatsPerMeasure: { type: Number, default: 4 },
  sections:   { type: Array, default: () => [] },
  loopOn:        { type: Boolean, default: false },
  /** Set false to omit the Loop toggle (e.g. Viewer's audio engine has no loop support yet). */
  showLoop:      { type: Boolean, default: true },
  /** Playback-rate multiplier, 0.8–1.2 (±20%). */
  rate:          { type: Number,  default: 1 },
});

const emit = defineEmits(['toggle', 'prev', 'next', 'seek-bar', 'toggle-loop', 'update:rate']);

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
/* Host-agnostic: falls back to the base DS accent tokens so this renders
   correctly on the classic Viewer (plain DS surface) as well as Cinema
   (which overrides --stage-accent/--stage-gradient per song style). */
.tdeck {
  /* Slightly translucent + blurred — it's an overlay sitting on top of the
     score/video now, not a static block in normal flow, so it shouldn't
     read as a fully opaque panel. */
  background: color-mix(in srgb, var(--clr-surface-2) 80%, transparent);
  backdrop-filter: blur(6px);
  -webkit-backdrop-filter: blur(6px);
  border: 1px solid var(--clr-border);
  border-radius: var(--radius);
  padding: 14px 18px;
  display: grid;
  grid-template-columns: auto 1fr auto;
  align-items: center;
  gap: 20px;
}

.tdeck-transport {
  display: flex;
  align-items: center;
  gap: 8px;
}

.tdeck-btn {
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

.tdeck-btn:hover {
  background: var(--clr-white);
}

.tdeck-btn--primary {
  width: 48px;
  height: 48px;
  background: var(--stage-gradient, var(--clr-gradient));
  border-color: transparent;
  color: var(--clr-white);
  font-size: 15px;
  font-weight: 700;
  box-shadow: 0 0 0 4px color-mix(in srgb, var(--stage-accent, var(--clr-accent)) 12%, transparent);
}

.tdeck-btn--primary:hover {
  background: var(--stage-gradient-hover, var(--clr-gradient-hover));
  box-shadow: 0 0 0 6px color-mix(in srgb, var(--stage-accent, var(--clr-accent)) 15%, transparent);
}

.tdeck-timeline {
  display: flex;
  align-items: center;
  gap: 14px;
  min-width: 0;
}

.tdeck-pos {
  font-family: var(--font-mono);
  font-size: 12px;
  color: var(--clr-text-dim);
  min-width: 70px;
  white-space: nowrap;
}

.tdeck-pos strong {
  color: var(--clr-text);
  font-weight: 600;
}

.tdeck-track {
  flex: 1;
  height: 6px;
  background: var(--clr-surface-3);
  border-radius: 3px;
  position: relative;
  cursor: pointer;
}

.tdeck-track-sections {
  position: absolute;
  inset: 0;
  display: flex;
}

.tdeck-track-section {
  flex: 1;
  border-right: 1px solid var(--clr-bg);
  opacity: 0.6;
}

.tdeck-track-section:last-child {
  border-right: none;
}

.tdeck-track-section[data-sec="0"] {
  background: linear-gradient(90deg, color-mix(in srgb, var(--stage-accent, var(--clr-accent)) 18%, transparent), transparent);
}

.tdeck-track-section[data-sec="1"] {
  background: linear-gradient(90deg, color-mix(in srgb, var(--stage-accent, var(--clr-accent)) 9%, transparent), transparent);
}

.tdeck-track-section[data-sec="2"] {
  background: linear-gradient(90deg, rgba(139, 92, 246, 0.15), transparent);
}

.tdeck-track-section[data-sec="3"] {
  background: linear-gradient(90deg, rgba(16, 185, 129, 0.15), transparent);
}

.tdeck-track-fill {
  position: absolute;
  left: 0;
  top: 0;
  bottom: 0;
  background: var(--stage-gradient, var(--clr-gradient));
  border-radius: 3px;
  box-shadow: 0 0 6px color-mix(in srgb, var(--stage-accent, var(--clr-accent)) 25%, transparent);
  transition: width 0.15s linear;
}

.tdeck-toggles {
  display: flex;
  align-items: center;
  gap: 10px;
}

.tdeck-d-toggle {
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

.tdeck-d-toggle:hover {
  color: var(--clr-text);
}

.tdeck-d-toggle--on {
  background: color-mix(in srgb, var(--stage-accent, var(--clr-accent)) 10%, transparent);
  color: var(--stage-accent, var(--clr-accent));
  border-color: color-mix(in srgb, var(--stage-accent, var(--clr-accent)) 35%, transparent);
}

.tdeck-toggle-divider {
  width: 1px;
  height: 20px;
  background: var(--clr-border);
  align-self: center;
  margin: 0 2px;
}

@media (max-width: 960px) {
  .tdeck {
    grid-template-columns: 1fr;
    gap: 12px;
  }
}
</style>

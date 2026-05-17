<script setup lang="ts">
import { ref, computed, watch, onBeforeUnmount } from 'vue';
import { getAudioEngine } from '../../audio/engine/AudioEngine.js';
import { rhythmPatternToEvents } from '../../audio/adapters/rhythmPatternToEvents.js';
import type { RhythmPatternData } from './RhythmPattern.vue';

interface Props {
  pattern: RhythmPatternData;
  tempo?: number;
  /** Show inline play button. Defaults to true. */
  playable?: boolean;
  /** Show optional label on the left. */
  label?: string | null;
  /** Show meter / bpm meta on the right of the eyebrow. */
  showMeta?: boolean;
  /** Tint colour for hit/accent cells. Defaults to var(--clr-accent). */
  color?: string | null;
  /** Compact variant — slimmer cells. */
  mini?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
  tempo: undefined,
  playable: true,
  label: null,
  showMeta: false,
  color: null,
  mini: false,
});

const isPlaying = ref(false);
const currentStep = ref(0);

const engine = getAudioEngine();
let unsubs: Array<(() => void) | undefined> = [];
let listenersRegistered = false;

const effectiveBpm = computed(() => props.tempo ?? props.pattern.bpm);

const stepDuration = computed(() => {
  switch (props.pattern.gridType) {
    case 'eighth': return 0.5;
    case 'triplet': return 1 / 3;
    case 'sixteenth':
    default: return 0.25;
  }
});

const loopBeats = computed(() => props.pattern.beats * stepDuration.value);

function beatToStep(beat: number): number {
  return Math.floor(beat / stepDuration.value) % props.pattern.beats;
}

const fingersArray = computed(() =>
  (props.pattern.fingers || '').padEnd(props.pattern.beats, '.').split('')
);
const thumbArray = computed(() =>
  (props.pattern.thumb || '').padEnd(props.pattern.beats, '.').split('')
);

const hasThumb = computed(() =>
  !!(props.pattern.percBass && props.pattern.percBass !== 'none' && props.pattern.thumb &&
     props.pattern.thumb.replace(/\./g, '').length > 0)
);
const hasFingers = computed(() =>
  !!(props.pattern.percTop && props.pattern.percTop !== 'none' && props.pattern.fingers &&
     props.pattern.fingers.replace(/\./g, '').length > 0)
);

// Cell state
function fingerCellClass(i: number): Record<string, boolean> {
  const c = fingersArray.value[i] || '.';
  return {
    'is-rest':    c === '.',
    'is-hit':     c === 'x',
    'is-accent':  c === 'X',
    'is-current': isPlaying.value && currentStep.value === i,
  };
}

function thumbCellClass(i: number): Record<string, boolean> {
  const c = thumbArray.value[i] || '.';
  return {
    'is-rest':    c === '.',
    'is-hit':     c.toLowerCase() === 'x',
    'is-accent':  c === 'X',
    'is-current': isPlaying.value && currentStep.value === i,
  };
}

// Audio
function registerListeners(): void {
  if (listenersRegistered) return;
  listenersRegistered = true;
  unsubs.push(
    engine.on('tick', (beat: number) => {
      if (!isPlaying.value) return;
      currentStep.value = beatToStep(beat);
    }),
    engine.on('playStarted', () => {
      // Another consumer took over the engine — reset our local state.
      if (isPlaying.value) {
        isPlaying.value = false;
        currentStep.value = 0;
      }
    }),
  );
}

async function play(): Promise<void> {
  await engine.init({
    bpm: effectiveBpm.value,
    samplesBaseUrl: '/audio/rhythm-samples/',
  });
  registerListeners();

  const events = rhythmPatternToEvents(props.pattern, { startBeat: 0 });
  engine.load(events, {
    loop: true,
    loopBeats: loopBeats.value,
    demoUrl: null,
    demoOffsetBeats: 0,
  });
  engine.setTempo(effectiveBpm.value);

  currentStep.value = 0;
  await engine.play();
  isPlaying.value = true;
}

function stop(): void {
  engine.stop();
  isPlaying.value = false;
  currentStep.value = 0;
}

function toggle(): void {
  if (isPlaying.value) stop();
  else play();
}

watch(effectiveBpm, (bpm) => { engine.setTempo(bpm); });

onBeforeUnmount(() => {
  unsubs.forEach(fn => fn?.());
  unsubs = [];
  listenersRegistered = false;
  if (isPlaying.value) engine.stop();
});

defineExpose({ play, stop, toggle });
</script>

<template>
  <div
    class="sbn-rhythm-strip"
    :class="{ 'is-playing': isPlaying, 'is-mini': mini }"
    :style="color ? { '--strip-color': color, '--strip-color-accent': color } : {}"
    role="img"
    :aria-label="`${pattern.name}: ${pattern.timeSignature} pattern`"
  >
    <!-- Eyebrow: label + meta -->
    <div v-if="label || showMeta" class="sbn-rhythm-strip-eyebrow">
      <span v-if="label" class="sbn-rhythm-strip-label">{{ label }}</span>
      <span v-if="showMeta" class="sbn-rhythm-strip-meta">
        {{ pattern.timeSignature }} · {{ effectiveBpm }} bpm
      </span>
    </div>

    <!-- Main play row: optional play btn + cells -->
    <div class="sbn-rhythm-strip-body">
      <button
        v-if="playable"
        type="button"
        class="sbn-rhythm-strip-play"
        :class="{ 'is-playing': isPlaying }"
        @click.stop="toggle"
        :aria-label="isPlaying ? 'Stop' : 'Play'"
      >
        <svg v-if="isPlaying" width="12" height="12" viewBox="0 0 12 12" aria-hidden="true">
          <rect x="3" y="2" width="2" height="8" fill="currentColor" />
          <rect x="7" y="2" width="2" height="8" fill="currentColor" />
        </svg>
        <svg v-else width="12" height="12" viewBox="0 0 12 12" aria-hidden="true">
          <path d="M3 2l7 4-7 4z" fill="currentColor" />
        </svg>
      </button>

      <div class="sbn-rhythm-strip-cells">
        <!-- Fingers row -->
        <div v-if="hasFingers" class="sbn-rhythm-strip-row sbn-rhythm-strip-row-fingers">
          <span
            v-for="(_, i) in fingersArray"
            :key="`f-${i}`"
            class="sbn-rhythm-strip-cell"
            :class="fingerCellClass(i)"
          />
        </div>
        <!-- Thumb row (slimmer) -->
        <div v-if="hasThumb" class="sbn-rhythm-strip-row sbn-rhythm-strip-row-thumb">
          <span
            v-for="(_, i) in thumbArray"
            :key="`t-${i}`"
            class="sbn-rhythm-strip-cell sbn-rhythm-strip-cell-thumb"
            :class="thumbCellClass(i)"
          />
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.sbn-rhythm-strip {
  font-family: var(--font-body);
  display: flex;
  flex-direction: column;
  gap: 6px;
  width: 100%;
}

/* Eyebrow */
.sbn-rhythm-strip-eyebrow {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 12px;
  color: var(--clr-text-muted);
  font-weight: 600;
}
.sbn-rhythm-strip-label {
  color: var(--clr-text-dim);
}
.sbn-rhythm-strip-meta {
  font-weight: 500;
  color: var(--clr-text-muted);
  font-size: 11px;
}

/* Body row */
.sbn-rhythm-strip-body {
  display: flex;
  align-items: center;
  gap: 10px;
}

/* Play button - Circular & Premium */
.sbn-rhythm-strip-play {
  width: 32px;
  height: 32px;
  border-radius: 50%;
  border: 1px solid var(--clr-border);
  background: var(--clr-white);
  cursor: pointer;
  color: var(--strip-color, var(--clr-accent));
  display: grid;
  place-items: center;
  flex-shrink: 0;
  transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
  padding: 0;
}

.sbn-rhythm-strip-play:hover {
  color: var(--strip-color, var(--clr-accent));
  border-color: var(--strip-color, var(--clr-accent));
  transform: scale(1.1);
}

.sbn-rhythm-strip-play.is-playing {
  background: var(--strip-color, var(--clr-accent));
  border-color: var(--strip-color, var(--clr-accent));
  color: var(--clr-white);
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--strip-color, var(--clr-accent)) 20%, transparent);
}

/* Cells container — two stacked rows */
.sbn-rhythm-strip-cells {
  display: flex;
  flex-direction: column;
  gap: 3px;
  flex: 1;
  min-width: 0;
}

.sbn-rhythm-strip-row {
  display: grid;
  grid-auto-flow: column;
  grid-auto-columns: 1fr;
  gap: 3px;
  flex: 1;
}

/* Fingers row — full-height cells */
.sbn-rhythm-strip-cell {
  height: 22px;
  border-radius: 3px;
  background: var(--clr-surface-3);
  transition: background 0.1s, transform 0.1s;
}

/* Hit / accent / rest states (fingers row) */
.sbn-rhythm-strip-cell.is-rest {
  height: 6px;
  align-self: center;
  background: var(--clr-surface-3);
}
.sbn-rhythm-strip-cell.is-hit {
  background: var(--strip-color, var(--clr-accent));
  opacity: 0.75;
}
.sbn-rhythm-strip-cell.is-accent {
  background: var(--strip-color-accent, var(--clr-red));
  opacity: 1;
}

/* Thumb row — slimmer cells */
.sbn-rhythm-strip-row-thumb {
  height: 8px;
}
.sbn-rhythm-strip-cell-thumb {
  height: 8px;
  border-radius: 2px;
  background: var(--clr-surface-3);
  align-self: stretch;
}
.sbn-rhythm-strip-cell-thumb.is-rest {
  height: 3px;
  background: var(--clr-border);
}
.sbn-rhythm-strip-cell-thumb.is-hit {
  background: var(--strip-color, var(--clr-text-dim));
  opacity: 0.5;
}
.sbn-rhythm-strip-cell-thumb.is-accent {
  background: var(--strip-color-accent, var(--clr-text));
  opacity: 0.8;
}

/* Current step highlight */
.sbn-rhythm-strip-cell.is-current {
  outline: 1.5px solid var(--strip-color, var(--clr-accent));
  outline-offset: 1px;
  transform: translateY(-1px);
  z-index: 2;
  transition: transform 0.1s ease;
}
.sbn-rhythm-strip-cell-thumb.is-current {
  outline: 1px solid var(--strip-color, var(--clr-accent));
  outline-offset: 1px;
}

/* Mini variant — slightly smaller */
.sbn-rhythm-strip.is-mini .sbn-rhythm-strip-cell { height: 18px; }
.sbn-rhythm-strip.is-mini .sbn-rhythm-strip-cell.is-rest { height: 5px; }
.sbn-rhythm-strip.is-mini .sbn-rhythm-strip-row-thumb { height: 6px; }
.sbn-rhythm-strip.is-mini .sbn-rhythm-strip-cell-thumb { height: 6px; }
.sbn-rhythm-strip.is-mini .sbn-rhythm-strip-cell-thumb.is-rest { height: 2px; }
</style>

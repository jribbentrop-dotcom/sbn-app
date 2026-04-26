<script setup lang="ts">
// v8 - fade-out overflow, content-based pattern check
import { ref, computed, watch, onBeforeUnmount } from 'vue';
import { getAudioEngine } from '../../audio/engine/AudioEngine.js';
import { rhythmPatternToEvents } from '../../audio/adapters/rhythmPatternToEvents.js';

/**
 * RhythmPatternData interface - exported for use across the system
 * Core data required for rendering and playback
 */
export interface RhythmPatternData {
  name: string;
  beats: number;
  gridType: 'eighth' | 'sixteenth' | 'triplet';
  thumb: string;
  fingers: string;
  bpm: number;
  timeSignature: string;
  percTop: string;
  percBass: string;
}

/**
 * Extended pattern data with metadata (from controller serialization)
 */
export interface RhythmPatternWithMeta extends RhythmPatternData {
  id: number;
  slug: string;
  description: string;
  category: string;
  styleSlug: string;
  demoUrl?: string | null;
}

interface Props {
  pattern: RhythmPatternData;
  tempo?: number;
  playable?: boolean;
  mini?: boolean;
  /**
   * Optional demo MP3 URL to blend with the sample pattern. When set, the
   * engine loads this MP3 and the detail page's blend slider cross-fades
   * between samples (0) and demo (1). Cards don't pass this.
   */
  demoUrl?: string | null;
}

const props = withDefaults(defineProps<Props>(), {
  tempo: undefined,
  playable: false,
  mini: false,
  demoUrl: null,
});

// Reactive state
const isPlaying = ref(false);
const currentStep = ref(0);
const activeSourceId = ref<string | null>(null);

// Audio engine
const engine = getAudioEngine();
let unsubs: Array<(() => void) | undefined> = [];
let _listenersRegistered = false;

// Computed
const effectiveBpm = computed(() => props.tempo ?? props.pattern.bpm);

const hasThumb = computed(() => {
  return props.pattern.percBass && props.pattern.percBass !== 'none' && props.pattern.thumb;
});

const hasFingers = computed(() => {
  return props.pattern.percTop && props.pattern.percTop !== 'none' && props.pattern.fingers;
});

// Check if there's actual pattern content (not just empty dots)
const hasAnyPattern = computed(() => {
  const hasThumbContent = props.pattern.thumb && props.pattern.thumb.replace(/\./g, '').length > 0;
  const hasFingersContent = props.pattern.fingers && props.pattern.fingers.replace(/\./g, '').length > 0;
  return hasThumbContent || hasFingersContent;
});

// Check if pattern is truncated in mini mode
const isTruncated = computed(() => {
  return props.mini && props.pattern.beats > 16;
});

// Maximum beats to show (16 for mini, full for full mode)
const maxDisplayBeats = computed(() => {
  if (!props.mini) return props.pattern.beats;
  return Math.min(props.pattern.beats, 16);
});

const thumbArray = computed(() => {
  return (props.pattern.thumb || '').padEnd(props.pattern.beats, '.').split('');
});

const fingersArray = computed(() => {
  return (props.pattern.fingers || '').padEnd(props.pattern.beats, '.').split('');
});

const beatLabels = computed(() => {
  const labels: string[] = [];
  const bpb = parseInt((props.pattern.timeSignature || '4/4').split('/')[0]) || 4;
  const sub = props.pattern.gridType === 'eighth' ? 2 :
               props.pattern.gridType === 'triplet' ? 3 : 4;
  const cpb = bpb * sub;

  for (let i = 0; i < props.pattern.beats; i++) {
    const pos = i % cpb;
    const beat = Math.floor(pos / sub) + 1;
    const s = pos % sub;
    if (s === 0) labels.push(String(beat));
    else if (props.pattern.gridType === 'triplet') labels.push(s === 1 ? 'trip' : 'let');
    else if (props.pattern.gridType === 'eighth') labels.push('+');
    else labels.push(['e', '+', 'a'][s - 1] || '');
  }
  return labels;
});

// Step duration based on grid type
const stepDuration = computed(() => {
  switch (props.pattern.gridType) {
    case 'eighth': return 0.5;
    case 'triplet': return 1/3;
    case 'sixteenth':
    default: return 0.25;
  }
});

// Convert step index to beat position
function stepToBeat(step: number): number {
  return step * stepDuration.value;
}

// Convert beat position to step index
function beatToStep(beat: number): number {
  return Math.floor(beat / stepDuration.value) % props.pattern.beats;
}

// Loop length in beats = total pattern steps × step duration.
const loopBeats = computed(() => props.pattern.beats * stepDuration.value);

// Audio functions
function registerListeners() {
  if (_listenersRegistered) return;
  _listenersRegistered = true;

  unsubs.push(
    engine.on('tick', (beat: number) => {
      if (!isPlaying.value) return;
      currentStep.value = beatToStep(beat);
    }),
    engine.on('sourceActive', (id: string) => {
      if (isPlaying.value) activeSourceId.value = id;
    }),
    engine.on('playStarted', () => {
      // Another card/consumer took over the shared engine — clear our UI state.
      if (isPlaying.value) {
        isPlaying.value = false;
        activeSourceId.value = null;
        currentStep.value = 0;
      }
    }),
  );
}

async function initAudio() {
  await engine.init({
    bpm: effectiveBpm.value,
    samplesBaseUrl: '/audio/rhythm-samples/',
  });
  registerListeners();
}

function loadEvents() {
  if (!props.playable) return;
  const events = rhythmPatternToEvents(props.pattern, { startBeat: 0 });
  engine.load(events, {
    loop: true,
    loopBeats: loopBeats.value,
    demoUrl: props.demoUrl || null,
    demoOffsetBeats: 0,
  });
  engine.setTempo(effectiveBpm.value);
}

async function play() {
  if (!props.playable) return;

  await initAudio();
  loadEvents();

  currentStep.value = 0;
  await engine.play();
  isPlaying.value = true;
}

function stop() {
  engine.stop();
  isPlaying.value = false;
  activeSourceId.value = null;
  currentStep.value = 0;
}

function toggle() {
  if (isPlaying.value) {
    stop();
  } else {
    play();
  }
}

// Cell state helpers
function getCellClass(row: 'label' | 'fingers' | 'thumb', index: number): Record<string, boolean> {
  const base: Record<string, boolean> = {};

  if (row === 'label') {
    base['is-beat'] = true;
    return base;
  }

  const arr = row === 'fingers' ? fingersArray.value : thumbArray.value;
  const char = arr[index] || '.';

  base['is-hit'] = char.toLowerCase() === 'x';
  base['is-accent'] = char === 'X';
  base['is-thumb'] = row === 'thumb';
  base['is-current'] = isPlaying.value && currentStep.value === index;

  return base;
}

function getCellContent(row: 'fingers' | 'thumb', index: number): string {
  const arr = row === 'fingers' ? fingersArray.value : thumbArray.value;
  const char = arr[index] || '.';
  return char.toLowerCase() === 'x' ? '●' : '';
}

// Watch for tempo changes
watch(effectiveBpm, (newBpm) => {
  engine.setTempo(newBpm);
});

// Cleanup
onBeforeUnmount(() => {
  unsubs.forEach(fn => fn?.());
  unsubs = [];
  _listenersRegistered = false;
  if (isPlaying.value) {
    engine.stop();
  }
});
</script>

<template>
  <div
    class="sbn-rhythm-pattern"
    :class="{
      'is-mini': mini,
      'is-full': !mini,
      'is-playing': isPlaying,
    }"
    role="img"
    :aria-label="`${pattern.name}: ${pattern.timeSignature} ${pattern.gridType} pattern with ${pattern.beats} beats`"
  >
    <!-- Grid with fade-out overlay -->
    <div class="sbn-rhythm-grid" :class="{ 'is-truncated': isTruncated }">
      <!-- Beat labels row -->
      <div class="sbn-rhythm-row">
        <span class="sbn-rhythm-label"></span>
        <div class="sbn-rhythm-cells">
          <div
            v-for="(label, i) in beatLabels.slice(0, maxDisplayBeats)"
            :key="`label-${i}`"
            class="sbn-rhythm-cell"
            :class="{ 'is-beat': true, 'is-current': isPlaying && currentStep === i }"
          >
            {{ label }}
          </div>
        </div>
      </div>

      <!-- Fingers row -->
      <div v-if="hasFingers" class="sbn-rhythm-row">
        <span class="sbn-rhythm-label">
          {{ hasThumb ? 'Fingers' : 'Rhythm' }}
        </span>
        <div class="sbn-rhythm-cells">
          <div
            v-for="(_, i) in fingersArray.slice(0, maxDisplayBeats)"
            :key="`finger-${i}`"
            class="sbn-rhythm-cell"
            :class="getCellClass('fingers', i)"
          >
            {{ getCellContent('fingers', i) }}
          </div>
        </div>
      </div>

      <!-- Thumb row -->
      <div v-if="hasThumb" class="sbn-rhythm-row">
        <span class="sbn-rhythm-label">Thumb</span>
        <div class="sbn-rhythm-cells">
          <div
            v-for="(_, i) in thumbArray.slice(0, maxDisplayBeats)"
            :key="`thumb-${i}`"
            class="sbn-rhythm-cell"
            :class="getCellClass('thumb', i)"
          >
            {{ getCellContent('thumb', i) }}
          </div>
        </div>
      </div>

      <!-- Fade-out overlay for truncated patterns -->
      <div v-if="isTruncated" class="sbn-rhythm-fade"></div>

      <!-- No pattern data message -->
      <div v-if="!hasAnyPattern && !mini" class="sbn-rhythm-no-data">
        No rhythm pattern defined
      </div>
    </div>

    <!-- Transport row: play button + optional blend slider (slot) -->
    <div v-if="playable" class="sbn-rhythm-transport">
      <button
        class="sbn-rhythm-play-btn"
        :class="{ 'is-playing': isPlaying }"
        @click="toggle"
        :aria-label="isPlaying ? 'Stop playback' : 'Start playback'"
      >
        <span class="sbn-play-icon">{{ isPlaying ? '⏸' : '▶' }}</span>
        <span class="sbn-play-text">{{ isPlaying ? 'Stop' : 'Play' }}</span>
      </button>
      <slot name="transport-extra" />
    </div>
  </div>
</template>

<style scoped>
.sbn-rhythm-pattern {
  font-family: var(--font-body);
}

/* Grid container */
.sbn-rhythm-grid {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

/* Row layout */
.sbn-rhythm-row {
  display: flex;
  align-items: center;
  gap: 8px;
}

/* Label column */
.sbn-rhythm-label {
  width: 60px;
  font-size: 11px;
  font-weight: 600;
  color: var(--clr-text-muted);
  text-align: right;
  flex-shrink: 0;
  line-height: 28px;
}

/* Cells container */
.sbn-rhythm-cells {
  display: flex;
  gap: 4px;
  flex: 1;
}

/* Individual cell */
.sbn-rhythm-cell {
  width: 28px;
  height: 28px;
  border: 2px solid var(--clr-border);
  border-radius: 4px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 11px;
  font-weight: 600;
  color: transparent;
  background: var(--clr-white);
  transition: all 0.15s ease;
  flex-shrink: 0;
}

/* Beat label cells (top row) */
.sbn-rhythm-cell.is-beat {
  background: transparent;
  border: none;
  font-size: 11px;
  font-weight: 600;
  color: var(--clr-text-muted);
}

/* Hit state (soft hit) */
.sbn-rhythm-cell.is-hit {
  background: #fef3f2;
  border-color: var(--clr-red);
  color: var(--clr-red);
}

/* Accent state (strong hit) */
.sbn-rhythm-cell.is-accent {
  background: var(--clr-red);
  border-color: #c0392b;
  color: #fff;
  font-weight: 700;
  box-shadow: 0 2px 4px rgba(232, 93, 59, 0.2);
}

/* Thumb row styling */
.sbn-rhythm-cell.is-thumb {
  border-style: dashed;
}

.sbn-rhythm-cell.is-thumb.is-hit {
  background: var(--clr-surface-3);
  border-color: var(--clr-text-muted);
  border-style: solid;
  color: var(--clr-text);
}

/* Current step highlight during playback */
.sbn-rhythm-cell.is-current {
  outline: 2px solid var(--clr-style-jazz);
  outline-offset: 1px;
  background-color: rgba(34, 113, 177, 0.08);
}

.sbn-rhythm-cell.is-accent.is-current,
.sbn-rhythm-cell.is-hit.is-current {
  outline: 2px solid var(--clr-style-jazz);
}

/* Transport row: play button + optional extras (blend slider, etc.) */
.sbn-rhythm-transport {
  display: flex;
  align-items: center;
  gap: 16px;
  margin-top: 12px;
}

/* Play button */
.sbn-rhythm-play-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  padding: 8px 14px;
  background: var(--clr-white);
  border: 2px solid var(--clr-red);
  border-radius: 6px;
  color: var(--clr-red);
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.2s ease;
  flex-shrink: 0;
}

.sbn-rhythm-play-btn:hover {
  background: #fff5f3;
  transform: translateY(-1px);
  box-shadow: 0 2px 8px rgba(232, 93, 59, 0.15);
}

.sbn-rhythm-play-btn.is-playing {
  background: var(--clr-red);
  border-color: #d04a2a;
  color: #fff;
  box-shadow: 0 2px 8px rgba(232, 93, 59, 0.3);
}

.sbn-play-icon {
  font-size: 11px;
}

/* Mini variant */
.is-mini .sbn-rhythm-cell {
  width: 20px;
  height: 20px;
  font-size: 9px;
  border-width: 1px;
}

.is-mini .sbn-rhythm-label {
  width: 48px;
  font-size: 10px;
  line-height: 20px;
}

.is-mini .sbn-rhythm-grid {
  gap: 3px;
}

.is-mini .sbn-rhythm-cells {
  gap: 3px;
}

.is-mini .sbn-rhythm-play-btn {
  padding: 5px 10px;
  font-size: 11px;
  margin-top: 8px;
}

/* Grid with fade-out overlay */
.sbn-rhythm-grid {
  position: relative;
}

/* Fade-out overlay for truncated patterns */
.sbn-rhythm-fade {
  position: absolute;
  right: 0;
  top: 0;
  bottom: 0;
  width: 48px;
  background: linear-gradient(to right, transparent, var(--clr-surface-2) 90%);
  pointer-events: none;
  border-radius: 0 var(--radius-sm) var(--radius-sm) 0;
}

.is-mini .sbn-rhythm-fade {
  width: 32px;
}

/* No pattern data message */
.sbn-rhythm-no-data {
  padding: 12px;
  text-align: center;
  color: var(--clr-text-muted);
  font-size: 13px;
  font-style: italic;
  background: var(--clr-surface-2);
  border-radius: var(--radius-sm);
  margin-top: 4px;
}

/* Responsive */
@media (max-width: 480px) {
  .sbn-rhythm-cell {
    width: 24px;
    height: 24px;
    font-size: 10px;
  }

  .sbn-rhythm-label {
    width: 50px;
    font-size: 10px;
  }
}
</style>

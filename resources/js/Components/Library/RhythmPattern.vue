<script setup lang="ts">
// v9 - Modern unified design (matches RhythmStrip)
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
  /** Show meter / bpm meta on the right. Defaults to true for full, false for mini. */
  showMeta?: boolean;
  /** Optional demo MP3 URL to blend with the sample pattern. */
  demoUrl?: string | null;
  /** Tint colour for cells. Defaults to var(--clr-accent). */
  color?: string | null;
  /** Apply SBN vintage card styling (background, border-accent). Defaults to false. */
  vintageCard?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
  tempo: undefined,
  playable: false,
  mini: false,
  showMeta: undefined,
  demoUrl: null,
  color: null,
  vintageCard: false,
});

// Reactive state
const isPlaying = ref(false);
const currentStep = ref(0);

// Audio engine
const engine = getAudioEngine();
let unsubs: Array<(() => void) | undefined> = [];
let _listenersRegistered = false;

// Computed
const effectiveBpm = computed(() => props.tempo ?? props.pattern.bpm);
const effectiveShowMeta = computed(() => props.showMeta ?? !props.mini);

const hasThumb = computed(() => {
  return props.pattern.percBass && props.pattern.percBass !== 'none' && props.pattern.thumb &&
         props.pattern.thumb.replace(/\./g, '').length > 0;
});

const hasFingers = computed(() => {
  return props.pattern.percTop && props.pattern.percTop !== 'none' && props.pattern.fingers &&
         props.pattern.fingers.replace(/\./g, '').length > 0;
});

const hasAnyPattern = computed(() => hasThumb.value || hasFingers.value);

const isTruncated = computed(() => props.mini && props.pattern.beats > 16);
const maxDisplayBeats = computed(() => props.mini ? Math.min(props.pattern.beats, 16) : props.pattern.beats);

const thumbArray = computed(() => (props.pattern.thumb || '').padEnd(props.pattern.beats, '.').split(''));
const fingersArray = computed(() => (props.pattern.fingers || '').padEnd(props.pattern.beats, '.').split(''));

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

const stepDuration = computed(() => {
  switch (props.pattern.gridType) {
    case 'eighth': return 0.5;
    case 'triplet': return 1/3;
    default: return 0.25;
  }
});

const loopBeats = computed(() => props.pattern.beats * stepDuration.value);

function beatToStep(beat: number): number {
  return Math.floor(beat / stepDuration.value) % props.pattern.beats;
}

// Audio functions
function registerListeners() {
  if (_listenersRegistered) return;
  _listenersRegistered = true;
  unsubs.push(
    engine.on('tick', (beat: number) => {
      if (!isPlaying.value) return;
      currentStep.value = beatToStep(beat);
    }),
    engine.on('playStarted', () => {
      if (isPlaying.value) {
        isPlaying.value = false;
        currentStep.value = 0;
      }
    }),
  );
}

async function play() {
  await engine.init({ bpm: effectiveBpm.value, samplesBaseUrl: '/audio/rhythm-samples/' });
  registerListeners();
  const events = rhythmPatternToEvents(props.pattern, { startBeat: 0 });
  engine.load(events, {
    loop: true,
    loopBeats: loopBeats.value,
    demoUrl: props.demoUrl || null,
    demoOffsetBeats: 0,
  });
  engine.setTempo(effectiveBpm.value);
  currentStep.value = 0;
  await engine.play();
  isPlaying.value = true;
}

function stop() {
  engine.stop();
  isPlaying.value = false;
  currentStep.value = 0;
}

function toggle() {
  if (isPlaying.value) stop();
  else play();
}

// Cell state
function getCellClass(row: 'label' | 'fingers' | 'thumb', index: number): Record<string, boolean> {
  const base: Record<string, boolean> = {};
  if (row === 'label') return { 'is-beat': true, 'is-current': isPlaying.value && currentStep.value === index };

  const arr = row === 'fingers' ? fingersArray.value : thumbArray.value;
  const char = arr[index] || '.';
  base['is-rest'] = char === '.';
  base['is-hit'] = char.toLowerCase() === 'x';
  base['is-accent'] = char === 'X';
  base['is-current'] = isPlaying.value && currentStep.value === index;
  return base;
}

watch(effectiveBpm, (v) => engine.setTempo(v));

onBeforeUnmount(() => {
  unsubs.forEach(fn => fn?.());
  unsubs = [];
  if (isPlaying.value) engine.stop();
});

defineExpose({ play, stop, toggle });
</script>

<template>
  <div
    class="sbn-rhythm-pattern"
    :class="{ 
      'is-mini': mini, 
      'is-playing': isPlaying,
      'sbn-vintage-card': vintageCard
    }"
    :style="color ? { '--strip-color': color, '--strip-color-accent': color } : {}"
    role="img"
    :aria-label="`${pattern.name}: ${pattern.timeSignature} pattern`"
  >
    <!-- Eyebrow: Meta + Slot (for Slider) -->
    <div v-if="effectiveShowMeta" class="sbn-rhythm-pattern-eyebrow">
      <div class="sbn-eyebrow-left">
        <span v-if="!mini" class="sbn-eyebrow-name">{{ pattern.name }}</span>
      </div>
      <div class="sbn-eyebrow-right">
        <span class="sbn-pattern-meta">
          {{ pattern.timeSignature }} · {{ effectiveBpm }} BPM
        </span>
        <slot name="transport-extra" />
      </div>
    </div>

    <div class="sbn-rhythm-pattern-body">
      <!-- Play button on the left (matches strip feel but for full component) -->
      <button
        v-if="playable"
        type="button"
        class="sbn-rhythm-play-btn"
        :class="{ 'is-playing': isPlaying }"
        @click="toggle"
        :aria-label="isPlaying ? 'Stop' : 'Play'"
      >
        <svg v-if="isPlaying" width="14" height="14" viewBox="0 0 12 12" fill="currentColor">
          <rect x="2" y="2" width="3" height="8" />
          <rect x="7" y="2" width="3" height="8" />
        </svg>
        <svg v-else width="14" height="14" viewBox="0 0 12 12" fill="currentColor">
          <path d="M3 2l7 4-7 4z" />
        </svg>
      </button>

      <div class="sbn-rhythm-grid-container">
        <div class="sbn-rhythm-grid" :class="{ 'is-truncated': isTruncated }">
          <!-- Beat labels row -->
          <div class="sbn-rhythm-row sbn-rhythm-row-labels">
            <span class="sbn-row-label"></span>
            <div class="sbn-rhythm-cells">
              <div
                v-for="(label, i) in beatLabels.slice(0, maxDisplayBeats)"
                :key="`label-${i}`"
                class="sbn-rhythm-cell"
                :class="getCellClass('label', i)"
              >
                {{ label }}
              </div>
            </div>
          </div>

          <!-- Fingers row -->
          <div v-if="hasFingers" class="sbn-rhythm-row sbn-rhythm-row-fingers">
            <span class="sbn-row-label">{{ hasThumb ? 'Fingers' : 'Rhythm' }}</span>
            <div class="sbn-rhythm-cells">
              <div
                v-for="(_, i) in fingersArray.slice(0, maxDisplayBeats)"
                :key="`f-${i}`"
                class="sbn-rhythm-cell"
                :class="getCellClass('fingers', i)"
              />
            </div>
          </div>

          <!-- Thumb row -->
          <div v-if="hasThumb" class="sbn-rhythm-row sbn-rhythm-row-thumb">
            <span class="sbn-row-label">Thumb</span>
            <div class="sbn-rhythm-cells">
              <div
                v-for="(_, i) in thumbArray.slice(0, maxDisplayBeats)"
                :key="`t-${i}`"
                class="sbn-rhythm-cell sbn-rhythm-cell-thumb"
                :class="getCellClass('thumb', i)"
              />
            </div>
          </div>

          <div v-if="isTruncated" class="sbn-rhythm-fade" />
          <div v-if="!hasAnyPattern && !mini" class="sbn-rhythm-no-data">No rhythm pattern defined</div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.sbn-rhythm-pattern {
  width: 100%;
  font-family: var(--font-body);
  display: flex;
  flex-direction: column;
  gap: 10px;
}

/* Eyebrow */
.sbn-rhythm-pattern-eyebrow {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 0 4px;
}

.sbn-eyebrow-name {
  font-size: 14px;
  font-weight: 700;
  color: var(--clr-text);
  letter-spacing: -0.01em;
}

.sbn-eyebrow-right {
  display: flex;
  align-items: center;
  gap: 16px;
}

.sbn-pattern-meta {
  font-size: 12px;
  font-weight: 600;
  color: var(--clr-text-muted);
}

/* Body */
.sbn-rhythm-pattern-body {
  display: flex;
  align-items: flex-start;
  gap: 16px;
}

/* Play button - Circular & Premium */
.sbn-rhythm-play-btn {
  width: 38px;
  height: 38px;
  border-radius: 50%;
  border: 1px solid var(--clr-border);
  background: var(--clr-white);
  cursor: pointer;
  color: var(--strip-color, var(--clr-accent));
  display: grid;
  place-items: center;
  flex-shrink: 0;
  transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
  margin-top: 24px; /* Align with cells, accounting for label row */
  padding: 0;
}

.sbn-rhythm-play-btn:hover {
  color: var(--strip-color, var(--clr-accent));
  border-color: var(--strip-color, var(--clr-accent));
  transform: scale(1.08);
}

.sbn-rhythm-play-btn.is-playing {
  background: var(--strip-color, var(--clr-accent));
  border-color: var(--strip-color, var(--clr-accent));
  color: var(--clr-white);
  box-shadow: 0 0 0 4px color-mix(in srgb, var(--strip-color, var(--clr-accent)) 20%, transparent);
}

/* Grid */
.sbn-rhythm-grid-container {
  flex: 1;
  min-width: 0;
}

.sbn-rhythm-grid {
  display: flex;
  flex-direction: column;
  gap: 6px;
  position: relative;
}

.sbn-rhythm-row {
  display: flex;
  align-items: center;
  gap: 10px;
}

.sbn-row-label {
  width: 54px;
  font-size: 11px;
  font-weight: 600;
  color: var(--clr-text-muted);
  text-align: right;
  flex-shrink: 0;
}

.sbn-rhythm-cells {
  display: flex;
  gap: 4px;
  flex: 1;
}

/* Cells - Block Style (Unified with RhythmStrip) */
.sbn-rhythm-cell {
  height: 28px;
  flex: 1;
  min-width: 14px;
  border-radius: 4px;
  background: var(--clr-surface-3);
  transition: all 0.15s ease;
}

/* Hit / Accent / Rest */
.sbn-rhythm-cell.is-rest {
  height: 6px;
  align-self: center;
  background: var(--clr-surface-3);
}

.sbn-rhythm-cell.is-hit {
  background: var(--strip-color, var(--clr-accent));
  opacity: 0.75;
}

.sbn-rhythm-cell.is-accent {
  background: var(--strip-color-accent, var(--clr-red));
  opacity: 1;
  box-shadow: 0 0 8px rgba(0,0,0,0.05);
}

/* Thumb row - Slimmer blocks */
.sbn-rhythm-row-thumb {
  height: 12px;
}

.sbn-rhythm-cell-thumb {
  height: 12px;
  border-radius: 3px;
}

.sbn-rhythm-cell-thumb.is-rest {
  height: 3px;
}

.sbn-rhythm-cell-thumb.is-hit {
  opacity: 0.6;
}

.sbn-rhythm-cell-thumb.is-accent {
  opacity: 0.9;
}

/* Current Step Highlight */
.sbn-rhythm-cell.is-current {
  outline: 1.5px solid var(--strip-color, var(--clr-accent));
  outline-offset: 1px;
  transform: translateY(-1px);
  z-index: 2;
  transition: transform 0.1s ease;
}

/* Label row styling */
.sbn-rhythm-row-labels .sbn-rhythm-cell {
  background: transparent;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 11px;
  font-weight: 600;
  color: var(--clr-text-muted);
  height: 20px;
}

.sbn-rhythm-row-labels .sbn-rhythm-cell.is-current {
  color: var(--clr-accent);
  transform: none;
  outline: none;
}

/* Mini Variant */
.is-mini .sbn-rhythm-cell { height: 20px; border-radius: 3px; }
.is-mini .sbn-rhythm-cell.is-rest { height: 4px; }
.is-mini .sbn-rhythm-row-thumb { height: 8px; }
.is-mini .sbn-rhythm-cell-thumb { height: 8px; }
.is-mini .sbn-rhythm-play-btn { width: 30px; height: 30px; margin-top: 18px; }
.is-mini .sbn-row-label { width: 44px; font-size: 10px; }

/* Truncation / Fade */
.sbn-rhythm-fade {
  position: absolute;
  right: 0;
  top: 0;
  bottom: 0;
  width: 48px;
  background: linear-gradient(to right, transparent, var(--clr-surface-2) 90%);
  pointer-events: none;
}

.sbn-rhythm-no-data {
  padding: 16px;
  text-align: center;
  color: var(--clr-text-muted);
  font-size: 13px;
  background: var(--clr-surface-2);
  border-radius: var(--radius-sm);
  font-style: italic;
}

/* Minimal Slider Styling (applies to slot content) */
:deep(.sbn-blend-control) {
  display: flex;
  align-items: center;
  gap: 8px;
}

:deep(.sbn-blend-slider) {
  -webkit-appearance: none;
  width: 120px;
  height: 4px;
  background: var(--clr-border);
  border-radius: 2px;
  outline: none;
  cursor: pointer;
}

:deep(.sbn-blend-slider::-webkit-slider-thumb) {
  -webkit-appearance: none;
  width: 14px;
  height: 14px;
  border-radius: 50%;
  background: var(--strip-color, var(--clr-accent));
  border: 2px solid var(--clr-white);
  box-shadow: 0 2px 4px rgba(0,0,0,0.15);
  transition: transform 0.1s;
}

:deep(.sbn-blend-slider::-webkit-slider-thumb:hover) {
  transform: scale(1.15);
}

:deep(.sbn-blend-label) {
  font-size: 11px;
  font-weight: 700;
  color: var(--clr-text-dim);
  text-transform: uppercase;
  letter-spacing: 0.05em;
  opacity: 0.4;
  transition: all 0.4s ease;
}

:deep(.sbn-blend-label.is-active) {
  color: var(--strip-color, var(--clr-accent));
  opacity: 1;
}

/* Vintage Card Variant */
.sbn-rhythm-pattern.sbn-vintage-card {
  background: var(--clr-white);
  border: 1px solid var(--clr-border);
  border-right: 3px solid var(--strip-color, var(--clr-accent));
  border-bottom: 3px solid var(--strip-color, var(--clr-accent));
  border-radius: var(--radius);
  padding: 20px;
  box-shadow: 2px 2px 0 rgba(0,0,0,0.02);
  transition: all 0.3s ease;
}

.sbn-rhythm-pattern.sbn-vintage-card:hover {
  box-shadow: 3px 3px 0 var(--strip-color, var(--clr-accent));
  transform: translate(-1px, -1px);
  border-color: var(--strip-color, var(--clr-accent));
}

.sbn-rhythm-pattern.is-playing {
  animation: pulse-card 1.5s ease-in-out infinite;
}

@keyframes pulse-card {
  0%, 100% { box-shadow: 0 0 0 0 color-mix(in srgb, var(--strip-color, var(--clr-accent)) 15%, transparent); }
  50% { box-shadow: 0 0 0 6px color-mix(in srgb, var(--strip-color, var(--clr-accent)) 15%, transparent); }
}

@media (max-width: 640px) {
  .sbn-rhythm-pattern-eyebrow { flex-direction: column; align-items: flex-start; gap: 8px; }
  .sbn-eyebrow-right { width: 100%; justify-content: space-between; }
  .sbn-row-label { width: 0; overflow: hidden; }
}
</style>


<script setup lang="ts">
import { ref, computed, watch, onBeforeUnmount } from 'vue';
import { getAudioEngine } from '../../audio/engine/AudioEngine.js';
import { rhythmPatternToEvents } from '../../audio/adapters/rhythmPatternToEvents.js';
import type { RhythmPatternData } from './RhythmPattern.vue';

/**
 * Full-grid display for picking-mode patterns (pickingMode === true).
 * Shows four rows: i / m / a / p — labelled with classical right-hand finger names.
 * Falls back gracefully to the standard two-row layout if pickingMode is false.
 */

interface Props {
  pattern: RhythmPatternData;
  tempo?: number;
  playable?: boolean;
  demoUrl?: string | null;
  color?: string | null;
}

const props = withDefaults(defineProps<Props>(), {
  tempo: undefined,
  playable: false,
  demoUrl: null,
  color: null,
});

// ── State ────────────────────────────────────────────────────────────────────

const isPlaying = ref(false);
const currentStep = ref(0);

const engine = getAudioEngine();
let unsubs: Array<(() => void) | undefined> = [];
let _listenersRegistered = false;

// ── Derived pattern data ──────────────────────────────────────────────────────

const effectiveBpm = computed(() => props.tempo ?? props.pattern.bpm);

const stepDuration = computed(() => {
  switch (props.pattern.gridType) {
    case 'eighth':  return 0.5;
    case 'triplet': return 1 / 3;
    default:        return 0.25;
  }
});

const loopBeats = computed(() => props.pattern.beats * stepDuration.value);

const beatLabels = computed(() => {
  const labels: string[] = [];
  const [numStr, denStr] = (props.pattern.timeSignature || '4/4').split('/');
  const bpb = parseInt(numStr) || 4;
  const den = parseInt(denStr) || 4;
  // Compound meter (6/8, 9/8, 12/8): the counted pulse is the eighth note itself,
  // and the numerator already counts those pulses — don't double it like simple meters.
  const isCompound = den === 8 && bpb % 3 === 0;
  const pulseBeats = isCompound ? 0.5 : 1;
  const sub = Math.max(1, Math.round(pulseBeats / stepDuration.value));
  const cpb = bpb * sub;
  for (let i = 0; i < props.pattern.beats; i++) {
    const pos  = i % cpb;
    const beat = Math.floor(pos / sub) + 1;
    const s    = pos % sub;
    if (s === 0) labels.push(String(beat));
    else if (props.pattern.gridType === 'triplet') labels.push(s === 1 ? 'trip' : 'let');
    else if (sub === 2) labels.push('+');
    else labels.push(['e', '+', 'a'][s - 1] || '');
  }
  return labels;
});

// Per-row arrays — four classical fingers
const pad = (s: string | null | undefined) =>
  (s || '').padEnd(props.pattern.beats, '.').slice(0, props.pattern.beats).split('');

const indexArr  = computed(() => pad(props.pattern.fingerIndex));
const middleArr = computed(() => pad(props.pattern.fingerMiddle));
const ringArr   = computed(() => pad(props.pattern.fingerRing));
const thumbArr  = computed(() => pad(props.pattern.thumb));

// Rows ordered a → m → i → p (high string at top, like tab staff notation).
const FINGER_ROWS = computed(() => [
  { key: 'a', label: 'a', arr: ringArr.value,   colorVar: '--pima-a' },
  { key: 'm', label: 'm', arr: middleArr.value, colorVar: '--pima-m' },
  { key: 'i', label: 'i', arr: indexArr.value,  colorVar: '--pima-i' },
  { key: 'p', label: 'p', arr: thumbArr.value,  colorVar: '--pima-p', isThumb: true },
]);

// ── Audio ─────────────────────────────────────────────────────────────────────

function beatToStep(beat: number): number {
  return Math.floor(beat / stepDuration.value) % props.pattern.beats;
}

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
    class="sbn-pima-pattern"
    :class="{ 'is-playing': isPlaying }"
    :style="color ? { '--strip-color': color, '--play-color': color } : {}"
    role="img"
    :aria-label="`${pattern.name}: ${pattern.timeSignature} fingerpicking pattern`"
  >
    <!-- Eyebrow -->
    <div class="sbn-pima-eyebrow">
      <span class="sbn-pima-name">{{ pattern.name }}</span>
      <div class="sbn-pima-meta-right">
        <span class="sbn-pima-badge">fingerpicking</span>
        <span class="sbn-pima-meta">{{ pattern.timeSignature }} · {{ effectiveBpm }} BPM</span>
        <slot name="transport-extra" />
      </div>
    </div>

    <div class="sbn-pima-body">
      <!-- Play button -->
      <button
        v-if="playable"
        type="button"
        class="sbn-play-btn sbn-pima-play-btn"
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

      <div class="sbn-pima-grid">
        <!-- Beat labels -->
        <div class="sbn-pima-row sbn-pima-row-labels">
          <span class="sbn-pima-label"></span>
          <div class="sbn-pima-cells">
            <div
              v-for="(label, i) in beatLabels"
              :key="`lbl-${i}`"
              class="sbn-pima-cell sbn-pima-cell-label"
              :class="{ 'is-current': isPlaying && currentStep === i }"
            >{{ label }}</div>
          </div>
        </div>

        <!-- a / m / i / p rows — label inside each hit cell -->
        <div
          v-for="row in FINGER_ROWS"
          :key="row.key"
          class="sbn-pima-row"
        >
          <span class="sbn-pima-label">{{ row.key === 'p' ? 'Bass' : '' }}</span>
          <div class="sbn-pima-cells">
            <div
              v-for="(char, i) in row.arr"
              :key="`${row.key}-${i}`"
              class="sbn-pima-cell"
              :class="{
                'is-rest':    char === '.',
                'is-hit':     char.toLowerCase() === 'x',
                'is-accent':  char === 'X',
                'is-thumb':   row.key === 'p',
                'is-current': isPlaying && currentStep === i,
              }"
            />
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.sbn-pima-pattern {
  width: 100%;
  font-family: var(--font-body);
  display: flex;
  flex-direction: column;
  gap: 10px;
}

/* ── Eyebrow ── */
.sbn-pima-eyebrow {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 0 4px;
}

.sbn-pima-name {
  font-size: 14px;
  font-weight: 700;
  color: var(--clr-text);
}

.sbn-pima-meta-right {
  display: flex;
  align-items: center;
  gap: 12px;
}

.sbn-pima-badge {
  font-size: 10px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  padding: 2px 7px;
  border-radius: 99px;
  background: color-mix(in srgb, var(--strip-color, var(--clr-accent)) 15%, transparent);
  color: var(--strip-color, var(--clr-accent));
  border: 1px solid color-mix(in srgb, var(--strip-color, var(--clr-accent)) 30%, transparent);
}

.sbn-pima-meta {
  font-size: 12px;
  font-weight: 600;
  color: var(--clr-text-muted);
}

/* ── Body / Grid ── */
.sbn-pima-body {
  display: flex;
  align-items: flex-start;
  gap: 16px;
}

.sbn-pima-play-btn {
  margin-top: 24px;
}

.sbn-pima-grid {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 5px;
}

.sbn-pima-row {
  display: flex;
  align-items: center;
  gap: 10px;
}

.sbn-pima-label {
  width: 54px;
  flex-shrink: 0;
  text-align: right;
  font-size: 11px;
  font-weight: 600;
  color: var(--clr-text-muted);
}

.sbn-pima-cells {
  display: flex;
  gap: 4px;
  flex: 1;
}

/* ── Cells ── */
.sbn-pima-cell {
  position: relative;
  height: 26px;
  flex: 1;
  min-width: 12px;
  border-radius: 4px;
  background: var(--clr-surface-3);
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.12s ease;
}

.sbn-pima-cell.is-rest {
  height: 5px;
  align-self: center;
}

.sbn-pima-cell.is-hit {
  background: var(--strip-color, var(--clr-accent));
  opacity: 0.75;
}

.sbn-pima-cell.is-accent {
  background: var(--strip-color, var(--clr-accent));
  opacity: 1;
  box-shadow: 0 0 8px rgba(0,0,0,0.05);
}

/* Thumb (p) — slimmer bar */
.sbn-pima-cell.is-thumb        { height: 12px; border-radius: 3px; }
.sbn-pima-cell.is-thumb.is-rest { height: 3px; }


/* Beat label row */
.sbn-pima-cell-label {
  background: transparent;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 11px;
  font-weight: 600;
  color: var(--clr-text-muted);
  height: 20px;
}

.sbn-pima-cell-label.is-current {
  color: var(--strip-color, var(--clr-accent));
}

/* Current step highlight */
.sbn-pima-cell.is-current:not(.sbn-pima-cell-label) {
  outline: 1.5px solid var(--strip-color, var(--clr-accent));
  outline-offset: 1px;
  transform: translateY(-1px);
  z-index: 2;
}

@media (max-width: 640px) {
  .sbn-pima-eyebrow { flex-direction: column; align-items: flex-start; gap: 8px; }
  .sbn-pima-label { width: 20px; }
}
</style>

<script setup lang="ts">
import { ref, computed, onUnmounted } from 'vue';
import Fretboard from '../fretboard/Fretboard.vue';
import FretboardStrip from '../fretboard/FretboardStrip.vue';
import type { Position } from '../fretboard/types';
import { SCALES, rootPc, type ScalePattern } from './scaleData';

interface Props {
  scale?: string;       // scale slug, e.g. 'minor-pentatonic'
  root?: string;        // root note name, e.g. 'A', 'E', 'F#'
  startPosition?: number | string;
  /** Kebab-case alias for startPosition — fires when used via <sbn-widget>. */
  'start-position'?: number | string;
}

const props = withDefaults(defineProps<Props>(), {
  scale: 'minor-pentatonic',
  root: 'F',
  startPosition: 1,
  'start-position': undefined,
});

const initialPosition = computed(() => {
  const raw = props['start-position'] ?? props.startPosition ?? 1;
  return Math.max(0, Number(raw) - 1);
});

const scaleDef = computed(() => SCALES[props.scale] ?? SCALES['minor-pentatonic']);

/** Semitone offset from the reference root to the user's chosen root. */
const fretOffset = computed(() => {
  const target = rootPc(props.root);
  let diff = target - scaleDef.value.referenceRootPc;
  // Keep patterns on the visible neck: prefer the closest octave near reference.
  while (diff < -6) diff += 12;
  while (diff > 6) diff -= 12;
  return diff;
});

/** Transpose a pattern's notes + windowStart by the current fret offset. */
function transposePattern(p: ScalePattern): ScalePattern {
  const off = fretOffset.value;
  return {
    ...p,
    windowStart: Math.max(0, p.windowStart + off),
    notes: p.notes
      .map(n => ({ ...n, fret: n.fret + off }))
      .filter(n => n.fret >= 0 && n.fret <= 17),
  };
}

const patterns = computed(() => scaleDef.value.patterns.map(transposePattern));

/** Continuous scrub value across positions: integer = snapped, fractional = blending. */
const scrub = ref(initialPosition.value);
const isScrubbing = ref(false);
const showRootsOnly = ref(false);

/** Nearest snapped index, used for the pills and arrow buttons. */
const posIdx = computed(() => Math.round(scrub.value));

/** Currently dominant pattern (the one whose dots are at full opacity at integer t). */
const pattern = computed(() => patterns.value[posIdx.value] ?? patterns.value[0]);

/** Linearly interpolated windowStart so the camera glides between positions. */
const blendedWindowStart = computed(() => {
  const lo = Math.floor(scrub.value);
  const hi = Math.min(lo + 1, patterns.value.length - 1);
  const frac = scrub.value - lo;
  return patterns.value[lo].windowStart * (1 - frac) + patterns.value[hi].windowStart * frac;
});

/** Linearly interpolated windowFrets so the camera width also blends smoothly. */
const blendedWindowFrets = computed(() => {
  const lo = Math.floor(scrub.value);
  const hi = Math.min(lo + 1, patterns.value.length - 1);
  const frac = scrub.value - lo;
  const loW = patterns.value[lo].windowFrets ?? 4;
  const hiW = patterns.value[hi].windowFrets ?? 4;
  return loW * (1 - frac) + hiW * frac;
});

/** During scrub: primary = current pattern, ghost = next/prev pattern with crossfade opacity. */
const blendFraction = computed(() => {
  const lo = Math.floor(scrub.value);
  return scrub.value - lo;
});

const primaryPositions = computed<Position[]>(() => {
  const lo = Math.floor(scrub.value);
  const p = patterns.value[lo];
  if (!p) return [];
  return p.notes.map(n => ({
    string: n.string,
    fret: n.fret,
    role: n.isRoot ? 'root' : 'scale-tone',
  }));
});

const ghostPositions = computed<Position[]>(() => {
  const hi = Math.min(Math.floor(scrub.value) + 1, patterns.value.length - 1);
  const p = patterns.value[hi];
  if (!p || hi === Math.floor(scrub.value)) return [];
  return p.notes.map(n => ({
    string: n.string,
    fret: n.fret,
    role: n.isRoot ? 'root' : 'scale-tone',
  }));
});

const primaryOpacity = computed(() => 1 - blendFraction.value);
const ghostFadeOpacity = computed(() => blendFraction.value);

// ── Tween: animates `scrub` between positions, identical look to manual drag ──
const TWEEN_PER_STEP = 380; // ms per integer position of distance

let tweenRaf: number | null = null;
function ease(t: number) {
  return t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2;
}

function cancelTween() {
  if (tweenRaf !== null) {
    cancelAnimationFrame(tweenRaf);
    tweenRaf = null;
  }
}

function tweenTo(target: number) {
  cancelTween();
  const from = scrub.value;
  const distance = Math.abs(target - from);
  if (distance < 1e-3) {
    scrub.value = target;
    isScrubbing.value = false;
    return;
  }
  const reduced = typeof window !== 'undefined'
    && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
  if (reduced) {
    scrub.value = target;
    isScrubbing.value = false;
    return;
  }

  const duration = Math.max(220, distance * TWEEN_PER_STEP);
  const t0 = performance.now();
  // Drive the tween through the same code path as a drag: noAnimate mode on,
  // every frame writes a fractional scrub value that the existing computed
  // properties (blendedWindowStart, primaryOpacity, ghostFadeOpacity) react to.
  isScrubbing.value = true;
  const step = (now: number) => {
    const t = Math.min((now - t0) / duration, 1);
    scrub.value = from + (target - from) * ease(t);
    if (t < 1) {
      tweenRaf = requestAnimationFrame(step);
    } else {
      tweenRaf = null;
      isScrubbing.value = false;
    }
  };
  tweenRaf = requestAnimationFrame(step);
}

onUnmounted(cancelTween);

function goTo(idx: number) {
  if (idx < 0 || idx >= patterns.value.length) return;
  tweenTo(idx);
}

/** Convert a fret position (from the strip slider) back to a position-space scrub value. */
function fretToScrub(fret: number): number {
  const ps = patterns.value;
  if (!ps.length) return 0;
  if (fret <= ps[0].windowStart) return 0;
  if (fret >= ps[ps.length - 1].windowStart) return ps.length - 1;
  for (let i = 0; i < ps.length - 1; i++) {
    const a = ps[i].windowStart;
    const b = ps[i + 1].windowStart;
    if (fret >= a && fret <= b) {
      const span = b - a;
      return span === 0 ? i : i + (fret - a) / span;
    }
  }
  return 0;
}

function onStripScrub(fret: number) {
  scrub.value = fretToScrub(fret);
}
function onScrubStart() {
  cancelTween();
  isScrubbing.value = true;
}
function onScrubEnd() {
  isScrubbing.value = false;
  // Snap is handled inside the strip via snapTargets.
}

// Touch swipe
let touchStartX: number | null = null;
function onTouchStart(e: TouchEvent) { touchStartX = e.touches[0].clientX; }
function onTouchEnd(e: TouchEvent) {
  if (touchStartX === null) return;
  const delta = touchStartX - e.changedTouches[0].clientX;
  if (Math.abs(delta) > 40) {
    goTo(delta > 0
      ? Math.min(posIdx.value + 1, patterns.value.length - 1)
      : Math.max(posIdx.value - 1, 0));
  }
  touchStartX = null;
}

const title = computed(() => scaleDef.value.name);
</script>

<template>
  <div class="scale-card" @touchstart="onTouchStart" @touchend="onTouchEnd">

    <div class="scale-header">
      <div class="scale-title">{{ title }}</div>
      <div class="scale-pills">
        <button
          v-for="(p, i) in patterns"
          :key="p.id"
          class="scale-pill"
          :class="{ active: i === posIdx }"
          @click="goTo(i)"
        >{{ i + 1 }}</button>
      </div>
    </div>

    <div class="scale-toggles">
      <label class="scale-toggle">
        <input type="checkbox" v-model="showRootsOnly" />
        <span>Roots only</span>
      </label>
    </div>

    <div class="scale-svg-wrap">
      <Fretboard
        uid="scale-positions"
        :positions="primaryPositions"
        :ghost-positions="ghostPositions"
        :window-start="blendedWindowStart"
        :window-frets="blendedWindowFrets"
        :total-frets="17"
        :show-roots-only="showRootsOnly"
        :no-animate="isScrubbing"
        :dot-opacity="primaryOpacity"
        :ghost-opacity="ghostFadeOpacity"
      />
    </div>

    <div class="scale-strip-wrap">
      <FretboardStrip
        :window-start="blendedWindowStart"
        :window-frets="blendedWindowFrets"
        :total-frets="17"
        :no-animate="isScrubbing"
        interactive
        :snap-targets="patterns.map(p => p.windowStart)"
        @update:window-start="onStripScrub"
        @scrub-start="onScrubStart"
        @scrub-end="onScrubEnd"
      />
    </div>

    <div class="scale-explanation">{{ pattern.explanation }}</div>

    <nav class="scale-nav">
      <button class="scale-arrow" @click="goTo(posIdx - 1)" :disabled="posIdx === 0">‹</button>
      <div class="scale-stepdots">
        <div v-for="(_, i) in patterns" :key="i" class="scale-stepdot" :class="{ active: i === posIdx }" />
      </div>
      <button class="scale-arrow" @click="goTo(posIdx + 1)" :disabled="posIdx === patterns.length - 1">›</button>
    </nav>

  </div>
</template>

<style scoped>
.scale-card {
  width: 100%;
  background: #0f0f17;
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 1.5rem 1.25rem;
  gap: 1.1rem;
  overflow: hidden;
  user-select: none;
  box-sizing: border-box;
}

.scale-header {
  width: 100%;
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.scale-title {
  font-family: 'DM Mono', monospace;
  font-size: 0.7rem;
  letter-spacing: 0.15em;
  text-transform: uppercase;
  color: #ffffff;
}

.scale-pills { display: flex; gap: 3px; }

.scale-pill {
  font-family: 'DM Mono', monospace;
  font-size: 0.65rem;
  letter-spacing: 0.08em;
  padding: 0.32rem 0.55rem;
  border-radius: 999px;
  border: 1px solid rgba(255,255,255,0.18);
  background: rgba(255,255,255,0.08);
  color: #ffffff;
  cursor: pointer;
  transition: all 0.2s ease;
  min-width: 26px;
}
.scale-pill:hover { border-color: rgba(255,255,255,0.35); color: rgba(255,255,255,0.9); background: rgba(255,255,255,0.12); }
.scale-pill.active { background: rgba(255,255,255,0.92); color: #0f0f17; border-color: transparent; }

.scale-toggles {
  width: 100%;
  display: flex;
  justify-content: flex-end;
  margin-top: -0.4rem;
}
.scale-toggle {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  font-family: 'DM Mono', monospace;
  font-size: 0.65rem;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: #ffffff;
  cursor: pointer;
  user-select: none;
}
.scale-toggle input { accent-color: #f59e0b; }

.scale-svg-wrap {
  width: 100%;
  position: relative;
  border-radius: 0.75rem;
  overflow: hidden;
  background: #0a0a12;
  border: 1px solid rgba(255,255,255,0.06);
}

.scale-explanation {
  font-family: system-ui, sans-serif;
  font-size: 0.82rem;
  line-height: 1.6;
  color: #ffffff;
  text-align: center;
  padding: 0 0.5rem;
  min-height: 2.8rem;
}

.scale-strip-wrap { width: 100%; position: relative; }

.scale-nav { display: flex; align-items: center; gap: 1.25rem; }

.scale-arrow {
  background: rgba(255,255,255,0.06);
  border: 1px solid rgba(255,255,255,0.15);
  color: #ffffff;
  width: 34px;
  height: 34px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  font-size: 0.85rem;
  transition: all 0.2s ease;
}
.scale-arrow:hover:not(:disabled) { border-color: rgba(255,255,255,0.22); color: rgba(255,255,255,0.85); }
.scale-arrow:disabled { opacity: 0.2; cursor: default; }

.scale-stepdots { display: flex; gap: 5px; align-items: center; }
.scale-stepdot {
  width: 5px;
  height: 5px;
  border-radius: 50%;
  background: rgba(255,255,255,0.12);
  transition: all 0.3s ease;
}
.scale-stepdot.active { width: 14px; border-radius: 3px; background: #f59e0b; }

@media (prefers-reduced-motion: reduce) {
  .scale-pill, .scale-arrow, .scale-stepdot { transition: none; }
}
</style>

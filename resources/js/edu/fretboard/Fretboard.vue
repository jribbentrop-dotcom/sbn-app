<script setup lang="ts">
import { ref, computed, watch, onUnmounted } from 'vue';
import type { Position } from './types';

/**
 * Dumb fretboard primitive. Renders a 15-fret SVG neck with a 4-fret camera
 * window that animates to `windowStart` on change. All dot data is supplied
 * by the parent; this component knows nothing about CAGED, scales, or chords.
 *
 * Coordinate convention: string 0 = low E rendered at the BOTTOM.
 */

interface Props {
  positions: Position[];
  ghostPositions?: Position[];
  windowStart: number;
  windowFrets?: number;
  totalFrets?: number;
  barre?: number | null;
  showRootsOnly?: boolean;
  /** Unique id suffix so multiple boards on one page don't share a clipPath. */
  uid?: string;
  /** When true, camera follows windowStart instantly (no easing). For live scrubbing. */
  noAnimate?: boolean;
  /** Opacity of ghost dots (0..1). Default 0.12. */
  ghostOpacity?: number;
  /** Opacity of primary dots (0..1). Default 1. */
  dotOpacity?: number;
}

const props = withDefaults(defineProps<Props>(), {
  ghostPositions: () => [],
  windowFrets: 4,
  totalFrets: 15,
  barre: null,
  showRootsOnly: false,
  uid: 'default',
  noAnimate: false,
  ghostOpacity: 0.12,
  dotOpacity: 1,
});

const ROOT_COLOR  = '#f59e0b';
const ROOT_STROKE = '#d97706';
const OTHER_FILL  = 'rgba(255,255,255,0.75)';

const VIEW_W = 260;
const VIEW_H = 158;
const PAD_TOP = 18;
const PAD_BOTTOM = 18;
const PAD_RIGHT = 10;
const N_STRINGS = 6;
const DOT_R = 9;
const PAD_LEFT_NECK = 28;
const MARKERS = [3, 5, 7, 9, 12, 15];
const DURATION = 900;

const FRET_GAP = computed(() => (VIEW_W - PAD_LEFT_NECK - PAD_RIGHT) / props.windowFrets);
const STRING_GAP = (VIEW_H - PAD_TOP - PAD_BOTTOM) / (N_STRINGS - 1);

function stringY(s: number) {
  return PAD_TOP + (N_STRINGS - 1 - s) * STRING_GAP;
}
function fretToNeckX(fret: number) {
  return PAD_LEFT_NECK + fret * FRET_GAP.value;
}
function cameraOffset(windowStart: number) {
  return windowStart * FRET_GAP.value;
}

const animOffset = ref(cameraOffset(props.windowStart));
let rafId: number | null = null;
let startTs: number | null = null;
let fromOffset = animOffset.value;
let toOffset = animOffset.value;

function ease(t: number) {
  return t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2;
}

function animateTo(target: number) {
  const reduced = typeof window !== 'undefined'
    && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
  if (reduced) {
    if (rafId !== null) cancelAnimationFrame(rafId);
    animOffset.value = target;
    return;
  }
  if (rafId !== null) cancelAnimationFrame(rafId);
  fromOffset = animOffset.value;
  toOffset = target;
  startTs = null;

  const step = (ts: number) => {
    if (startTs === null) startTs = ts;
    const t = Math.min((ts - startTs) / DURATION, 1);
    animOffset.value = fromOffset + (toOffset - fromOffset) * ease(t);
    if (t < 1) rafId = requestAnimationFrame(step);
  };
  rafId = requestAnimationFrame(step);
}

watch(() => props.windowStart, (next) => {
  if (props.noAnimate) {
    if (rafId !== null) cancelAnimationFrame(rafId);
    animOffset.value = cameraOffset(next);
    return;
  }
  animateTo(cameraOffset(next));
});

onUnmounted(() => { if (rafId !== null) cancelAnimationFrame(rafId); });

const fretLines = computed(() =>
  Array.from({ length: props.totalFrets + 1 }, (_, i) => i)
);

const clipId = computed(() => `fb-neck-clip-${props.uid}`);

function isRoot(p: Position): boolean {
  return p.role === 'root';
}

const visiblePositions = computed(() =>
  props.showRootsOnly ? props.positions.filter(isRoot) : props.positions
);

const visibleGhosts = computed(() =>
  props.showRootsOnly ? props.ghostPositions.filter(isRoot) : props.ghostPositions
);

const mutedPositions = computed(() =>
  props.positions.filter(p => p.fret === null)
);
</script>

<template>
  <svg width="100%" :viewBox="`0 0 ${VIEW_W} ${VIEW_H}`" style="display:block">
    <defs>
      <clipPath :id="clipId">
        <rect :x="PAD_LEFT_NECK - DOT_R" :y="0"
          :width="VIEW_W - PAD_LEFT_NECK - PAD_RIGHT + DOT_R * 2" :height="VIEW_H" />
      </clipPath>
    </defs>

    <!-- String labels (low E at bottom) -->
    <text v-for="(label, i) in ['E','A','D','G','B','e']" :key="'sl'+i"
      x="10" :y="stringY(i) + 4" text-anchor="middle"
      font-family="'DM Mono', monospace" font-size="7" fill="rgba(255,255,255,0.6)"
    >{{ label }}</text>

    <!-- Mute markers (× at nut) -->
    <template v-for="(n, i) in mutedPositions" :key="'mute'+i">
      <text
        :x="PAD_LEFT_NECK - 8" :y="stringY(n.string) + 4" text-anchor="middle"
        font-family="'DM Mono', monospace" font-size="9" fill="rgba(255,255,255,0.6)"
      >×</text>
    </template>

    <!-- Camera-panned neck -->
    <g :clip-path="`url(#${clipId})`">
      <g :transform="`translate(${-animOffset}, 0)`">

        <!-- Fret lines -->
        <line v-for="f in fretLines" :key="'fl'+f"
          :x1="fretToNeckX(f)" :y1="PAD_TOP"
          :x2="fretToNeckX(f)" :y2="VIEW_H - PAD_BOTTOM"
          :stroke="f === 0 ? 'rgba(255,255,255,0.6)' : 'rgba(255,255,255,0.08)'"
          :stroke-width="f === 0 ? 3 : 1"
        />

        <!-- String lines -->
        <line v-for="s in N_STRINGS" :key="'str'+s"
          :x1="fretToNeckX(0)" :y1="stringY(s-1)"
          :x2="fretToNeckX(totalFrets)" :y2="stringY(s-1)"
          stroke="rgba(255,255,255,0.2)"
          :stroke-width="[2, 1.6, 1.3, 1, 0.8, 0.8][s-1]"
        />

        <!-- Position markers -->
        <circle v-for="m in MARKERS" :key="'mk'+m"
          :cx="fretToNeckX(m) - FRET_GAP / 2" :cy="VIEW_H / 2"
          r="3.5" fill="rgba(255,255,255,0.07)"
        />

        <!-- Fret numbers -->
        <text v-for="f in [1,3,5,7,9,12,15,17]" :key="'fn'+f"
          v-show="f <= totalFrets"
          :x="fretToNeckX(f) - FRET_GAP / 2" :y="VIEW_H - 3"
          text-anchor="middle" font-family="'DM Mono', monospace" font-size="7"
          fill="rgba(255,255,255,0.35)"
        >{{ f }}</text>

        <!-- Barre -->
        <rect v-if="barre"
          :x="fretToNeckX(barre) - FRET_GAP" :y="PAD_TOP"
          :width="FRET_GAP" :height="VIEW_H - PAD_TOP - PAD_BOTTOM"
          fill="rgba(255,255,255,0.04)" rx="2"
        />

        <!-- Window highlight -->
        <rect
          :x="fretToNeckX(windowStart)" :y="PAD_TOP - 4"
          :width="FRET_GAP * windowFrets" :height="VIEW_H - PAD_TOP - PAD_BOTTOM + 8"
          fill="rgba(243,156,18,0.04)" stroke="rgba(243,156,18,0.15)" stroke-width="1" rx="4"
          :style="noAnimate ? '' : 'transition: x 0.48s cubic-bezier(0.65,0,0.35,1)'"
        />

        <!-- Ghost dots (neighbouring shapes / context) -->
        <g v-for="(n, ni) in visibleGhosts" :key="'gh'+ni" :opacity="ghostOpacity">
          <circle v-if="n.fret !== null"
            :cx="fretToNeckX(n.fret) - FRET_GAP / 2"
            :cy="stringY(n.string)"
            :r="DOT_R"
            :fill="isRoot(n) ? ROOT_COLOR : OTHER_FILL"
          />
        </g>

        <!-- Active dots -->
        <g v-for="(n, ni) in visiblePositions" :key="'dot'+ni" :opacity="dotOpacity">
          <template v-if="n.fret !== null">
            <circle
              :cx="fretToNeckX(n.fret) - FRET_GAP / 2"
              :cy="stringY(n.string)"
              :r="DOT_R"
              :fill="isRoot(n) ? ROOT_COLOR : OTHER_FILL"
              :stroke="isRoot(n) ? ROOT_STROKE : OTHER_FILL"
              stroke-width="0"
            />
            <circle v-if="isRoot(n)"
              :cx="fretToNeckX(n.fret) - FRET_GAP / 2"
              :cy="stringY(n.string)"
              :r="DOT_R * 0.35"
              fill="rgba(15,15,23,0.6)"
            />
            <text v-if="n.label"
              :x="fretToNeckX(n.fret) - FRET_GAP / 2"
              :y="stringY(n.string) + 2.5"
              text-anchor="middle"
              font-family="'DM Mono', monospace" font-size="6.5"
              :fill="isRoot(n) ? '#fff' : 'rgba(15,15,23,0.85)'"
            >{{ n.label }}</text>
          </template>
        </g>

      </g>
    </g>
  </svg>
</template>

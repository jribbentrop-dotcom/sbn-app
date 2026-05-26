<script setup lang="ts">
import { ref, computed, onUnmounted } from 'vue';

// All positions in absolute fret numbers, null = muted
// String index 0 = low E, 1 = A, 2 = D, 3 = G, 4 = B, 5 = high e
interface ShapeNote { string: number; fret: number | null; }
interface Shape {
  id: string;
  windowStart: number;
  notes: ShapeNote[];
  roots: number[];
  barre?: number;
  explanation: string;
}

const SHAPES: Shape[] = [
  {
    id: 'C', windowStart: 0,
    notes: [
      { string: 0, fret: null },
      { string: 1, fret: 3 },
      { string: 2, fret: 2 },
      { string: 3, fret: 0 },
      { string: 4, fret: 1 },
      { string: 5, fret: 0 },
    ],
    roots: [1, 4],
    explanation: 'C shape. Root on A string fret 3, and B string fret 1.',
  },
  {
    id: 'A', windowStart: 2,
    notes: [
      { string: 0, fret: null },
      { string: 1, fret: 3 },
      { string: 2, fret: 5 },
      { string: 3, fret: 5 },
      { string: 4, fret: 5 },
      { string: 5, fret: 3 },
    ],
    roots: [1, 3],
    barre: 3,
    explanation: 'A shape. Root on A string fret 3, and G string fret 5.',
  },
  {
    id: 'G', windowStart: 4,
    notes: [
      { string: 0, fret: 8 },
      { string: 1, fret: 7 },
      { string: 2, fret: 5 },
      { string: 3, fret: 5 },
      { string: 4, fret: 5 },
      { string: 5, fret: 8 },
    ],
    roots: [0, 3, 5],
    explanation: 'G shape. Root on low E fret 8, G string fret 5, high e fret 8.',
  },
  {
    id: 'E', windowStart: 7,
    notes: [
      { string: 0, fret: 8 },
      { string: 1, fret: 10 },
      { string: 2, fret: 10 },
      { string: 3, fret: 9 },
      { string: 4, fret: 8 },
      { string: 5, fret: 8 },
    ],
    roots: [0, 2, 5],
    barre: 8,
    explanation: 'E shape. Root on low E fret 8, D string fret 10, high e fret 8.',
  },
  {
    id: 'D', windowStart: 9,
    notes: [
      { string: 0, fret: null },
      { string: 1, fret: null },
      { string: 2, fret: 10 },
      { string: 3, fret: 12 },
      { string: 4, fret: 13 },
      { string: 5, fret: 12 },
    ],
    roots: [2, 4],
    explanation: 'D shape. Root on D string fret 10, B string fret 13. System complete.',
  },
];

const ROOT_COLOR  = '#f59e0b';
const ROOT_STROKE = '#d97706';
const OTHER_FILL  = 'rgba(255,255,255,0.75)';

const VIEW_W = 260;
const VIEW_H = 158;
const PAD_TOP = 18;
const PAD_BOTTOM = 18;
const PAD_RIGHT = 10;
const N_STRINGS = 6;
const N_FRETS_VISIBLE = 4;
const DOT_R = 9;
const TOTAL_FRETS = 15;
const PAD_LEFT_NECK = 28;
const FRET_GAP = (VIEW_W - PAD_LEFT_NECK - PAD_RIGHT) / N_FRETS_VISIBLE;
const STRING_GAP = (VIEW_H - PAD_TOP - PAD_BOTTOM) / (N_STRINGS - 1);
const MARKERS = [3, 5, 7, 9, 12];
const DURATION = 900;

function stringY(s: number) {
  return PAD_TOP + (N_STRINGS - 1 - s) * STRING_GAP;
}
function fretToNeckX(fret: number) {
  return PAD_LEFT_NECK + fret * FRET_GAP;
}
function cameraOffset(windowStart: number) {
  return windowStart * FRET_GAP;
}

const shapeIdx = ref(0);
const animOffset = ref(cameraOffset(SHAPES[0].windowStart));
let rafId: number | null = null;
let startTs: number | null = null;
let fromOffset = animOffset.value;
let toOffset = animOffset.value;

const shape = computed(() => SHAPES[shapeIdx.value]);

function ease(t: number) {
  return t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2;
}

function animateTo(target: number) {
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

function goTo(idx: number) {
  if (idx === shapeIdx.value) return;
  shapeIdx.value = idx;
  animateTo(cameraOffset(SHAPES[idx].windowStart));
}

onUnmounted(() => { if (rafId !== null) cancelAnimationFrame(rafId); });

const fretLines = Array.from({ length: TOTAL_FRETS + 1 }, (_, i) => i);

// Touch swipe
let touchStartX: number | null = null;
function onTouchStart(e: TouchEvent) { touchStartX = e.touches[0].clientX; }
function onTouchEnd(e: TouchEvent) {
  if (touchStartX === null) return;
  const delta = touchStartX - e.changedTouches[0].clientX;
  if (Math.abs(delta) > 40) {
    goTo(delta > 0
      ? Math.min(shapeIdx.value + 1, SHAPES.length - 1)
      : Math.max(shapeIdx.value - 1, 0));
  }
  touchStartX = null;
}

// Strip geometry helpers
function stripX(windowStart: number) {
  return 14 + (windowStart / TOTAL_FRETS) * 232;
}
const stripW = (N_FRETS_VISIBLE / TOTAL_FRETS) * 232;
</script>

<template>
  <div class="caged-card" @touchstart="onTouchStart" @touchend="onTouchEnd">

    <!-- Header -->
    <div class="caged-header">
      <div class="caged-title">C Major — CAGED</div>
      <div class="caged-pills">
        <button
          v-for="(s, i) in SHAPES"
          :key="s.id"
          class="caged-pill"
          :class="{ active: i === shapeIdx }"
          @click="goTo(i)"
        >{{ s.id }}</button>
      </div>
    </div>

    <!-- Fretboard SVG -->
    <div class="caged-svg-wrap">
      <svg width="100%" :viewBox="`0 0 ${VIEW_W} ${VIEW_H}`" style="display:block">
        <defs>
          <clipPath id="caged-neck-clip">
            <rect :x="PAD_LEFT_NECK - DOT_R" :y="0" :width="VIEW_W - PAD_LEFT_NECK - PAD_RIGHT + DOT_R * 2" :height="VIEW_H" />
          </clipPath>
        </defs>

        <!-- String labels -->
        <text v-for="(label, i) in ['E','A','D','G','B','e']" :key="'sl'+i"
          x="10" :y="stringY(i) + 4" text-anchor="middle"
          font-family="'DM Mono', monospace" font-size="7" fill="rgba(255,255,255,0.6)"
        >{{ label }}</text>

        <!-- Mute markers -->
        <template v-for="(n, i) in shape.notes" :key="'mute'+i">
          <text v-if="n.fret === null"
            :x="PAD_LEFT_NECK - 8" :y="stringY(n.string) + 4" text-anchor="middle"
            font-family="'DM Mono', monospace" font-size="9" fill="rgba(255,255,255,0.6)"
          >×</text>
        </template>

        <!-- Camera-panned neck -->
        <g clip-path="url(#caged-neck-clip)">
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
              :x2="fretToNeckX(TOTAL_FRETS)" :y2="stringY(s-1)"
              stroke="rgba(255,255,255,0.2)"
              :stroke-width="[2, 1.6, 1.3, 1, 0.8, 0.8][s-1]"
            />

            <!-- Position markers -->
            <circle v-for="m in MARKERS" :key="'mk'+m"
              :cx="fretToNeckX(m) - FRET_GAP / 2" :cy="VIEW_H / 2"
              r="3.5" fill="rgba(255,255,255,0.07)"
            />

            <!-- Fret numbers -->
            <text v-for="f in [1,3,5,7,9,12]" :key="'fn'+f"
              :x="fretToNeckX(f) - FRET_GAP / 2" :y="VIEW_H - 3"
              text-anchor="middle" font-family="'DM Mono', monospace" font-size="7"
              fill="rgba(255,255,255,0.35)"
            >{{ f }}</text>

            <!-- Barre -->
            <rect v-if="shape.barre"
              :x="fretToNeckX(shape.barre) - FRET_GAP" :y="PAD_TOP"
              :width="FRET_GAP" :height="VIEW_H - PAD_TOP - PAD_BOTTOM"
              fill="rgba(255,255,255,0.04)" rx="2"
            />

            <!-- Window highlight -->
            <rect
              :x="fretToNeckX(shape.windowStart)" :y="PAD_TOP - 4"
              :width="FRET_GAP * N_FRETS_VISIBLE" :height="VIEW_H - PAD_TOP - PAD_BOTTOM + 8"
              fill="rgba(243,156,18,0.04)" stroke="rgba(243,156,18,0.15)" stroke-width="1" rx="4"
              style="transition: x 0.48s cubic-bezier(0.65,0,0.35,1)"
            />

            <!-- Dots — all shapes ghosted, active full -->
            <template v-for="(s, si) in SHAPES" :key="'shape'+si">
              <template v-for="(n, ni) in s.notes" :key="'dot'+si+'-'+ni">
                <g v-if="n.fret !== null && (si === shapeIdx || s.roots.includes(n.string))"
                  :opacity="si === shapeIdx ? 1 : 0.12"
                  style="transition: opacity 0.4s ease"
                >
                  <circle
                    :cx="fretToNeckX(n.fret!) - FRET_GAP / 2"
                    :cy="stringY(n.string)"
                    :r="DOT_R"
                    :fill="s.roots.includes(n.string) ? ROOT_COLOR : OTHER_FILL"
                    :stroke="s.roots.includes(n.string) ? ROOT_STROKE : OTHER_FILL"
                    stroke-width="0"
                  />
                  <circle v-if="s.roots.includes(n.string) && si === shapeIdx"
                    :cx="fretToNeckX(n.fret!) - FRET_GAP / 2"
                    :cy="stringY(n.string)"
                    :r="DOT_R * 0.35"
                    fill="rgba(15,15,23,0.6)"
                  />
                </g>
              </template>
            </template>

          </g>
        </g>
      </svg>
    </div>

    <!-- Mini neck strip -->
    <div class="caged-strip-wrap">
      <svg width="100%" viewBox="-12 0 272 20" style="display:block">
        <defs>
          <clipPath id="caged-strip-clip">
            <rect x="14" y="0" width="232" height="20" />
          </clipPath>
        </defs>
        <path d="M 0,2 L 14,4 L 14,16 L 0,18 Q -4,10 0,2 Z" fill="rgba(255,255,255,0.15)" />
        <g v-for="(t, i) in [0.25, 0.5, 0.75]" :key="'peg'+i">
          <line :x1="-2" :y1="2 + t * 16 - 5" :x2="-7" :y2="2 + t * 16 - 5" stroke="rgba(255,255,255,0.2)" stroke-width="1" />
          <circle cx="-7" :cy="2 + t * 16 - 5" r="1.5" fill="rgba(255,255,255,0.6)" />
        </g>
        <rect x="14" y="4" width="232" height="12" rx="2" fill="rgba(255,255,255,0.1)" />
        <rect x="14" y="3" width="2.5" height="14" rx="1" fill="#f59e0b" />
        <line v-for="i in TOTAL_FRETS" :key="'sf'+i"
          :x1="14 + (i / TOTAL_FRETS) * 232" y1="4"
          :x2="14 + (i / TOTAL_FRETS) * 232" y2="16"
          stroke="rgba(255,255,255,0.08)" stroke-width="0.5"
        />
        <rect
          :x="stripX(shape.windowStart)" y="3"
          :width="stripW" height="14"
          rx="2" fill="rgba(243,156,18,0.35)" :stroke="ROOT_COLOR" stroke-width="1"
          :style="`transition: x ${DURATION}ms cubic-bezier(0.65,0,0.35,1)`"
        />
      </svg>
    </div>

    <!-- Explanation -->
    <div class="caged-explanation">{{ shape.explanation }}</div>

    <!-- Nav -->
    <nav class="caged-nav">
      <button class="caged-arrow" @click="goTo(shapeIdx - 1)" :disabled="shapeIdx === 0">‹</button>
      <div class="caged-stepdots">
        <div v-for="(_, i) in SHAPES" :key="i" class="caged-stepdot" :class="{ active: i === shapeIdx }" />
      </div>
      <button class="caged-arrow" @click="goTo(shapeIdx + 1)" :disabled="shapeIdx === SHAPES.length - 1">›</button>
    </nav>

  </div>
</template>

<style scoped>
.caged-card {
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

.caged-header {
  width: 100%;
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.caged-title {
  font-family: 'DM Mono', monospace;
  font-size: 0.7rem;
  letter-spacing: 0.15em;
  text-transform: uppercase;
  color: rgba(255,255,255,0.65);
}

.caged-pills { display: flex; gap: 3px; }

.caged-pill {
  font-family: 'DM Mono', monospace;
  font-size: 0.65rem;
  letter-spacing: 0.08em;
  padding: 0.32rem 0.65rem;
  border-radius: 999px;
  border: 1px solid rgba(255,255,255,0.18);
  background: rgba(255,255,255,0.08);
  color: rgba(255,255,255,0.6);
  cursor: pointer;
  transition: all 0.2s ease;
}
.caged-pill:hover { border-color: rgba(255,255,255,0.35); color: rgba(255,255,255,0.9); background: rgba(255,255,255,0.12); }
.caged-pill.active { background: rgba(255,255,255,0.92); color: #0f0f17; border-color: transparent; }

.caged-svg-wrap {
  width: 100%;
  position: relative;
  border-radius: 0.75rem;
  overflow: hidden;
  background: #0a0a12;
  border: 1px solid rgba(255,255,255,0.06);
}

.caged-explanation {
  font-family: system-ui, sans-serif;
  font-size: 0.82rem;
  line-height: 1.6;
  color: rgba(255,255,255,0.85);
  text-align: center;
  padding: 0 0.5rem;
  min-height: 2.8rem;
}

.caged-strip-wrap { width: 100%; position: relative; }

.caged-nav { display: flex; align-items: center; gap: 1.25rem; }

.caged-arrow {
  background: rgba(255,255,255,0.06);
  border: 1px solid rgba(255,255,255,0.15);
  color: rgba(255,255,255,0.65);
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
.caged-arrow:hover:not(:disabled) { border-color: rgba(255,255,255,0.22); color: rgba(255,255,255,0.85); }
.caged-arrow:disabled { opacity: 0.2; cursor: default; }

.caged-stepdots { display: flex; gap: 5px; align-items: center; }
.caged-stepdot {
  width: 5px;
  height: 5px;
  border-radius: 50%;
  background: rgba(255,255,255,0.12);
  transition: all 0.3s ease;
}
.caged-stepdot.active { width: 14px; border-radius: 3px; background: #f59e0b; }

@media (prefers-reduced-motion: reduce) {
  .caged-pill, .caged-arrow, .caged-stepdot { transition: none; }
}
</style>

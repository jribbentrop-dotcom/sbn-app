<script setup lang="ts">
/**
 * ScaleStepsWidget — whole and half steps in major / natural minor.
 * Brackets hang below the note names.
 * Whole step = deep rectangular ∪ bracket.
 * Half step  = shallower pointed V bracket.
 * Switching keys smoothly animates both shape and depth via a JS tween.
 */
import { ref, computed, watch, onUnmounted } from 'vue';

type Mode = 'major' | 'minor';

// 1 = whole step, 0 = half step
const DATA: Record<Mode, { notes: string[]; steps: number[] }> = {
  major: {
    notes: ['C', 'D', 'E', 'F', 'G', 'A', 'B', 'C'],
    steps: [1, 1, 0, 1, 1, 1, 0],
  },
  minor: {
    notes: ['C', 'D', 'E♭', 'F', 'G', 'A♭', 'B♭', 'C'],
    steps: [1, 0, 1, 1, 0, 1, 1],
  },
};

// ── SVG geometry ──────────────────────────────────────────────────────────────
const SVG_W        = 440;
const SVG_H        = 100;
const PAD_X        = 26;
const NOTE_Y       = 24;    // text baseline
const NOTE_SIZE    = 18;
const BRACKET_TOP  = 42;    // top y of brackets (below note baseline)
const BRACKET_H    = 44;    // same depth for both step types
const INNER_MARGIN = 5;     // gap so adjacent brackets don't touch
const STROKE_W     = 2;
const SPACING      = (SVG_W - 2 * PAD_X) / 7;

const COLOR_WHOLE = 'rgba(255,255,255,0.65)';
const COLOR_HALF  = '#f59e0b';

function noteX(i: number): number { return PAD_X + i * SPACING; }

// ── Tween ─────────────────────────────────────────────────────────────────────
const DURATION = 500;
function cubicInOut(t: number): number {
  return t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2;
}

const mode    = ref<Mode>('major');
const tweened = ref<number[]>([...DATA.major.steps]);
let rafId: number | null = null;

watch(mode, (newMode) => {
  const targets = DATA[newMode].steps;
  const from    = [...tweened.value];
  if (rafId !== null) cancelAnimationFrame(rafId);
  const t0 = performance.now();
  const tick = (now: number) => {
    const t = Math.min((now - t0) / DURATION, 1);
    const e = cubicInOut(t);
    tweened.value = targets.map((target, i) => from[i] + (target - from[i]) * e);
    if (t < 1) rafId = requestAnimationFrame(tick);
    else rafId = null;
  };
  rafId = requestAnimationFrame(tick);
});

onUnmounted(() => { if (rafId !== null) cancelAnimationFrame(rafId); });

// ── Path + colour helpers ─────────────────────────────────────────────────────
/**
 * tweened[i] = 1 → whole step: flat-bottomed ∪  (f = 0)
 * tweened[i] = 0 → half step:  pointed V         (f = 1)
 * Both share the same depth (BRACKET_H); only shape and colour differ.
 */
function bracketPath(i: number): string {
  const x1   = noteX(i)     + INNER_MARGIN;
  const x2   = noteX(i + 1) - INNER_MARGIN;
  const midX = (x1 + x2) / 2;
  const f    = 1 - tweened.value[i];   // 0 = rectangular, 1 = fully pointed
  const bot  = BRACKET_TOP + BRACKET_H;
  const lbx  = x1 + f * (midX - x1);
  const rbx  = x2 + f * (midX - x2);
  return `M ${x1} ${BRACKET_TOP} L ${lbx} ${bot} L ${rbx} ${bot} L ${x2} ${BRACKET_TOP}`;
}

/** Snaps colour at the midpoint of the tween, matching the shape flip. */
function bracketColor(i: number): string {
  return tweened.value[i] > 0.5 ? COLOR_WHOLE : COLOR_HALF;
}

const notes = computed(() => DATA[mode.value].notes);
</script>

<template>
  <div class="ssw-card">

    <!-- Header -->
    <div class="ssw-header">
      <span class="ssw-title">Scale Steps</span>
      <div class="ssw-pills">
        <button :class="['ssw-pill', mode === 'major' ? 'active' : '']" @click="mode = 'major'">C major</button>
        <button :class="['ssw-pill', mode === 'minor' ? 'active' : '']" @click="mode = 'minor'">C minor</button>
      </div>
    </div>

    <!-- SVG -->
    <svg :viewBox="`0 0 ${SVG_W} ${SVG_H}`" width="100%" class="ssw-svg">

      <!-- Note names -->
      <text
        v-for="(note, i) in notes"
        :key="i"
        :x="noteX(i)"
        :y="NOTE_Y"
        text-anchor="middle"
        dominant-baseline="auto"
        font-family="'DM Mono', monospace"
        :font-size="NOTE_SIZE"
        font-weight="600"
        :fill="note.includes('♭') ? '#f59e0b' : 'rgba(255,255,255,0.9)'"
      >{{ note }}</text>

      <!-- Brackets (one per interval) -->
      <path
        v-for="i in 7"
        :key="i - 1"
        :d="bracketPath(i - 1)"
        fill="none"
        :stroke="bracketColor(i - 1)"
        :stroke-width="STROKE_W"
        stroke-linejoin="miter"
        stroke-linecap="square"
      />

    </svg>

    <!-- Legend -->
    <div class="ssw-legend">
      <span class="ssw-leg-item">
        <svg width="28" height="18" viewBox="0 0 28 18">
          <path d="M 2 2 L 2 16 L 26 16 L 26 2" :stroke="COLOR_WHOLE" stroke-width="2" fill="none" stroke-linecap="square" stroke-linejoin="miter"/>
        </svg>
        whole step
      </span>
      <span class="ssw-leg-item">
        <svg width="28" height="18" viewBox="0 0 28 18">
          <path d="M 2 2 L 14 16 L 26 2" :stroke="COLOR_HALF" stroke-width="2" fill="none" stroke-linecap="square" stroke-linejoin="miter"/>
        </svg>
        half step
      </span>
    </div>

  </div>
</template>

<style scoped>
.ssw-card {
  width: 100%;
  background: #0f0f17;
  border-radius: 1.25rem;
  border: 1px solid rgba(255,255,255,0.06);
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 1.75rem 1.5rem 1.25rem;
  gap: 1rem;
  user-select: none;
}

.ssw-header {
  width: 100%;
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.ssw-title {
  font-family: 'DM Mono', monospace;
  font-size: 0.65rem;
  letter-spacing: 0.15em;
  text-transform: uppercase;
  color: #ffffff;
}

.ssw-pills { display: flex; gap: 4px; }
.ssw-pill {
  font-family: 'DM Mono', monospace;
  font-size: 0.6rem;
  letter-spacing: 0.08em;
  padding: 0.22rem 0.6rem;
  border-radius: 999px;
  border: 1px solid rgba(255,255,255,0.12);
  background: transparent;
  color: rgba(255,255,255,0.6);
  cursor: pointer;
  transition: all 0.2s ease;
}
.ssw-pill:hover { border-color: rgba(255,255,255,0.25); color: #ffffff; }
.ssw-pill.active { background: rgba(255,255,255,0.08); color: #ffffff; border-color: rgba(255,255,255,0.22); }

.ssw-svg { display: block; max-width: 500px; }

.ssw-legend {
  display: flex;
  gap: 1.5rem;
  align-items: center;
  font-family: 'DM Mono', monospace;
  font-size: 0.62rem;
  letter-spacing: 0.06em;
  color: rgba(255,255,255,0.38);
}
.ssw-leg-item {
  display: flex;
  align-items: center;
  gap: 0.35rem;
}
</style>

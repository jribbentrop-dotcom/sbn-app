<script setup lang="ts">
import { ref, computed } from 'vue';
import Fretboard from '../fretboard/Fretboard.vue';
import FretboardStrip from '../fretboard/FretboardStrip.vue';
import type { Position, Shape } from '../fretboard/types';

interface CagedShape extends Shape {
  /** Per-shape note list, with root strings flagged in `roots`. */
  rawNotes: { string: number; fret: number | null }[];
}

const RAW_SHAPES: Array<{
  id: string;
  windowStart: number;
  notes: { string: number; fret: number | null }[];
  roots: number[];
  barre?: number;
  explanation: string;
}> = [
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

/** Project a CAGED raw shape into the primitive's Position format. */
function toPositions(shape: typeof RAW_SHAPES[number]): Position[] {
  return shape.notes.map(n => ({
    string: n.string,
    fret: n.fret,
    role: shape.roots.includes(n.string) ? 'root' : 'chord-tone',
  }));
}

const shapeIdx = ref(0);
const shape = computed(() => RAW_SHAPES[shapeIdx.value]);
const positions = computed<Position[]>(() => toPositions(shape.value));

/** Ghosted root dots from every other shape (preserves the original feel). */
const ghostPositions = computed<Position[]>(() => {
  const ghosts: Position[] = [];
  RAW_SHAPES.forEach((s, i) => {
    if (i === shapeIdx.value) return;
    s.notes.forEach(n => {
      if (n.fret !== null && s.roots.includes(n.string)) {
        ghosts.push({ string: n.string, fret: n.fret, role: 'root' });
      }
    });
  });
  return ghosts;
});

function goTo(idx: number) {
  if (idx < 0 || idx >= RAW_SHAPES.length) return;
  shapeIdx.value = idx;
}

// Touch swipe
let touchStartX: number | null = null;
function onTouchStart(e: TouchEvent) { touchStartX = e.touches[0].clientX; }
function onTouchEnd(e: TouchEvent) {
  if (touchStartX === null) return;
  const delta = touchStartX - e.changedTouches[0].clientX;
  if (Math.abs(delta) > 40) {
    goTo(delta > 0
      ? Math.min(shapeIdx.value + 1, RAW_SHAPES.length - 1)
      : Math.max(shapeIdx.value - 1, 0));
  }
  touchStartX = null;
}
</script>

<template>
  <div class="caged-card" @touchstart="onTouchStart" @touchend="onTouchEnd">

    <div class="caged-header">
      <div class="caged-title">C Major — CAGED</div>
      <div class="caged-pills">
        <button
          v-for="(s, i) in RAW_SHAPES"
          :key="s.id"
          class="caged-pill"
          :class="{ active: i === shapeIdx }"
          @click="goTo(i)"
        >{{ s.id }}</button>
      </div>
    </div>

    <div class="caged-svg-wrap">
      <Fretboard
        uid="caged"
        :positions="positions"
        :ghost-positions="ghostPositions"
        :window-start="shape.windowStart"
        :barre="shape.barre ?? null"
      />
    </div>

    <div class="caged-strip-wrap">
      <FretboardStrip :window-start="shape.windowStart" />
    </div>

    <div class="caged-explanation">{{ shape.explanation }}</div>

    <nav class="caged-nav">
      <button class="caged-arrow" @click="goTo(shapeIdx - 1)" :disabled="shapeIdx === 0">‹</button>
      <div class="caged-stepdots">
        <div v-for="(_, i) in RAW_SHAPES" :key="i" class="caged-stepdot" :class="{ active: i === shapeIdx }" />
      </div>
      <button class="caged-arrow" @click="goTo(shapeIdx + 1)" :disabled="shapeIdx === RAW_SHAPES.length - 1">›</button>
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

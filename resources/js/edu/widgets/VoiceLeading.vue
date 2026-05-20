<script setup lang="ts">
/**
 * voice-leading — 6 guitar strings, two chord columns, bezier curves
 * connecting each voice to its destination. Steep curve = big leap,
 * flat curve = smooth voice leading. Fixed chord pairs; no fret grid.
 */
import { computed, ref, watch } from 'vue';

// ── Chord pair data ───────────────────────────────────────────────────────────
// Each voice has a string (1 = high E, 6 = low E) and a note label.
// Strings are assigned to reflect typical drop-2 guitar voicings.

type Role = 'Root' | 'Third' | 'Fifth' | 'Seventh';

interface Voice {
  role: Role;
  note: string;
  string: number; // 1 = high E, 6 = low E
}

interface ChordVoicing {
  label: string;   // e.g. "Cmaj7"
  voices: Voice[];
}

interface Pair {
  key: string;
  label: string;
  a: ChordVoicing;
  b: ChordVoicing;
}

const PAIRS: Pair[] = [
  {
    key: 'I-vi',
    label: 'Imaj7 → VIm7',
    a: {
      label: 'Cmaj7',
      voices: [
        { role: 'Seventh', note: 'B',  string: 1 },
        { role: 'Fifth',   note: 'G',  string: 2 },
        { role: 'Third',   note: 'E',  string: 3 },
        { role: 'Root',    note: 'C',  string: 4 },
      ],
    },
    b: {
      label: 'Am7',
      voices: [
        { role: 'Seventh', note: 'G',  string: 1 },
        { role: 'Fifth',   note: 'E',  string: 2 },
        { role: 'Third',   note: 'C',  string: 3 },
        { role: 'Root',    note: 'A',  string: 4 },
      ],
    },
  },
  {
    key: 'ii-V',
    label: 'IIm7 → V7',
    a: {
      label: 'Dm7',
      voices: [
        { role: 'Seventh', note: 'C',  string: 1 },
        { role: 'Fifth',   note: 'A',  string: 2 },
        { role: 'Third',   note: 'F',  string: 3 },
        { role: 'Root',    note: 'D',  string: 4 },
      ],
    },
    b: {
      label: 'G7',
      voices: [
        { role: 'Third',   note: 'B',  string: 1 },
        { role: 'Seventh', note: 'F',  string: 2 },
        { role: 'Fifth',   note: 'D',  string: 3 },
        { role: 'Root',    note: 'G',  string: 4 },
      ],
    },
  },
  {
    key: 'V-I',
    label: 'V7 → Imaj7',
    a: {
      label: 'G7',
      voices: [
        { role: 'Third',   note: 'B',  string: 1 },
        { role: 'Seventh', note: 'F',  string: 2 },
        { role: 'Fifth',   note: 'D',  string: 3 },
        { role: 'Root',    note: 'G',  string: 4 },
      ],
    },
    b: {
      label: 'Cmaj7',
      voices: [
        { role: 'Seventh', note: 'B',  string: 1 },
        { role: 'Third',   note: 'E',  string: 2 },
        { role: 'Fifth',   note: 'G',  string: 3 },
        { role: 'Root',    note: 'C',  string: 4 },
      ],
    },
  },
];

// ── SVG geometry ──────────────────────────────────────────────────────────────
const SVG_W    = 300;
const SVG_H    = 160;
const PAD_Y    = 20;   // top/bottom margin inside SVG
const PAD_X    = 48;   // left/right margin — room for dot + label
const STR_COUNT = 6;
const DOT_R    = 11;

// x positions of the two chord columns
const X_A = PAD_X;
const X_B = SVG_W - PAD_X;

// y for a string number (1=high E top, 6=low E bottom)
function stringY(s: number): number {
  const usable = SVG_H - PAD_Y * 2;
  return PAD_Y + ((s - 1) / (STR_COUNT - 1)) * usable;
}

// ── Role colours (shared token palette) ──────────────────────────────────────
const ROLE_COLOR: Record<Role, string> = {
  Root:    'var(--clr-role-root,    #f39c12)',
  Third:   'var(--clr-role-third,   #3b82f6)',
  Fifth:   'var(--clr-role-fifth,   #10b981)',
  Seventh: 'var(--clr-role-seventh, #8b5cf6)',
};

// ── State ─────────────────────────────────────────────────────────────────────
const activePairKey = ref(PAIRS[0].key);
const pair = computed(() => PAIRS.find(p => p.key === activePairKey.value)!);

// ── Bezier path between two string positions ──────────────────────────────────
// Control points pull horizontally toward the center so lines don't overlap dots.
function curvePath(y1: number, y2: number): string {
  const cx = SVG_W / 2;
  return `M ${X_A} ${y1} C ${cx} ${y1}, ${cx} ${y2}, ${X_B} ${y2}`;
}

// ── Pulse animation on pair change ────────────────────────────────────────────
const pulsingKeys = ref<Set<Role>>(new Set());
const prefersReducedMotion =
  typeof window !== 'undefined' &&
  window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

watch(activePairKey, () => {
  if (prefersReducedMotion) return;
  const allRoles = new Set<Role>(['Root', 'Third', 'Fifth', 'Seventh']);
  pulsingKeys.value = allRoles;
  setTimeout(() => { pulsingKeys.value = new Set(); }, 500);
});
</script>

<template>
  <div class="sbn-edu-widget vl-widget">

    <svg
      :viewBox="`0 0 ${SVG_W} ${SVG_H}`"
      width="100%"
      class="vl-svg"
      :style="{ maxHeight: SVG_H + 'px' }"
    >
      <!-- ── String lines ──────────────────────────────────────────────── -->
      <line
        v-for="s in STR_COUNT"
        :key="s"
        :x1="PAD_X - DOT_R" :y1="stringY(s)"
        :x2="SVG_W - PAD_X + DOT_R" :y2="stringY(s)"
        class="vl-string"
        :class="s >= 4 ? 'vl-string--bass' : ''"
      />

      <!-- ── Voice curves ─────────────────────────────────────────────── -->
      <path
        v-for="va in pair.a.voices"
        :key="'curve-' + va.role"
        :d="curvePath(stringY(va.string), stringY(pair.b.voices.find(vb => vb.role === va.role)?.string ?? va.string))"
        class="vl-curve"
        :style="{ stroke: ROLE_COLOR[va.role] }"
      />

      <!-- ── Chord A dots ─────────────────────────────────────────────── -->
      <g
        v-for="v in pair.a.voices"
        :key="'a-' + v.role"
        class="vl-voice"
        :style="{ transform: `translate(${X_A}px, ${stringY(v.string)}px)` }"
      >
        <circle
          cx="0" cy="0" :r="DOT_R"
          :fill="ROLE_COLOR[v.role]"
          class="vl-dot"
          :class="{ 'vl-dot--pulse': pulsingKeys.has(v.role) }"
        />
        <text x="0" y="0" text-anchor="middle" dominant-baseline="central" class="vl-note">
          {{ v.note }}
        </text>
      </g>

      <!-- ── Chord B dots ─────────────────────────────────────────────── -->
      <g
        v-for="v in pair.b.voices"
        :key="'b-' + v.role"
        class="vl-voice"
        :style="{ transform: `translate(${X_B}px, ${stringY(v.string)}px)` }"
      >
        <circle
          cx="0" cy="0" :r="DOT_R"
          :fill="ROLE_COLOR[v.role]"
          class="vl-dot"
          :class="{ 'vl-dot--pulse': pulsingKeys.has(v.role) }"
        />
        <text x="0" y="0" text-anchor="middle" dominant-baseline="central" class="vl-note">
          {{ v.note }}
        </text>
      </g>

      <!-- ── Chord labels ──────────────────────────────────────────────── -->
      <text :x="X_A" :y="SVG_H - 4" text-anchor="middle" class="vl-chord-label">
        {{ pair.a.label }}
      </text>
      <text :x="X_B" :y="SVG_H - 4" text-anchor="middle" class="vl-chord-label">
        {{ pair.b.label }}
      </text>
    </svg>

    <!-- ── Pair selector badges ──────────────────────────────────────── -->
    <div class="vl-badges">
      <button
        v-for="p in PAIRS"
        :key="p.key"
        class="vl-badge"
        :class="{ active: activePairKey === p.key }"
        @click="activePairKey = p.key"
      >{{ p.label }}</button>
    </div>

  </div>
</template>

<style scoped>
.vl-widget {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 12px;
  font-family: var(--font-body, system-ui, sans-serif);
}

/* ── Strings ─────────────────────────────────────────────────────────────── */
.vl-string {
  stroke: var(--clr-border, #e2e8f0);
  stroke-width: 1;
}

.vl-string--bass {
  stroke: var(--clr-text-dim, #9ca3af);
  stroke-width: 1.5;
}

/* ── Curves ──────────────────────────────────────────────────────────────── */
.vl-curve {
  fill: none;
  stroke-width: 1.5;
  opacity: 0.45;
  stroke-linecap: round;
  transition: d 0.45s cubic-bezier(0.34, 1.2, 0.64, 1), opacity 0.2s;
}

/* ── Voice groups ────────────────────────────────────────────────────────── */
.vl-voice {
  transition: transform 0.45s cubic-bezier(0.34, 1.2, 0.64, 1);
}

/* ── Dots ────────────────────────────────────────────────────────────────── */
.vl-dot {
  transition: filter 0.2s;
}

.vl-dot:hover {
  filter: brightness(1.2);
}

/* ── Note labels inside dots ─────────────────────────────────────────────── */
.vl-note {
  font-size: 9px;
  font-weight: 700;
  fill: #fff;
  pointer-events: none;
}

/* ── Chord name labels ───────────────────────────────────────────────────── */
.vl-chord-label {
  font-size: 10px;
  font-weight: 600;
  fill: var(--clr-text-muted, #8896a4);
}

/* ── Badges ──────────────────────────────────────────────────────────────── */
.vl-badges {
  display: flex;
  gap: 6px;
  justify-content: center;
  flex-wrap: wrap;
}

.vl-badge {
  padding: 3px 10px;
  border-radius: 999px;
  border: 1px solid var(--clr-border, #e2e8f0);
  background: transparent;
  color: var(--clr-text-muted, #8896a4);
  font-size: 11px;
  font-weight: 500;
  cursor: pointer;
  transition: background 0.15s, color 0.15s, border-color 0.15s;
}

.vl-badge:hover {
  border-color: var(--clr-accent, #f39c12);
  color: var(--clr-accent, #f39c12);
}

.vl-badge.active {
  background: var(--clr-accent, #f39c12);
  border-color: var(--clr-accent, #f39c12);
  color: #000;
}

/* ── Dot pulse animation ─────────────────────────────────────────────────── */
@keyframes vl-pulse {
  0%   { transform: scale(1);    }
  35%  { transform: scale(1.3);  }
  100% { transform: scale(1);    }
}

.vl-dot--pulse {
  animation: vl-pulse 0.45s cubic-bezier(0.34, 1.2, 0.64, 1) both;
  transform-origin: center;
  transform-box: fill-box;
}

@media (prefers-reduced-motion: reduce) {
  .vl-voice  { transition: none; }
  .vl-curve  { transition: none; }
  .vl-dot--pulse { animation: none; }
}
</style>

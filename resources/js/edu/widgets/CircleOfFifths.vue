<script setup lang="ts">
import { computed, ref } from 'vue';

// Circle of fifths order — starting at C (top), moving clockwise
const KEYS = ['C', 'G', 'D', 'A', 'E', 'B', 'F♯/G♭', 'D♭', 'A♭', 'E♭', 'B♭', 'F'] as const;
const RELATIVE_MINORS = ['Am', 'Em', 'Bm', 'F♯m', 'C♯m', 'G♯m', 'D♯m/E♭m', 'B♭m', 'Fm', 'Cm', 'Gm', 'Dm'] as const;

const props = withDefaults(defineProps<{
  initialKey?: string;
}>(), {
  initialKey: '',
});

const selectedIndex = ref<number | null>(
  props.initialKey ? KEYS.findIndex((k) => k === props.initialKey) : null,
);

// SVG geometry constants
const CX = 200;
const CY = 200;
const OUTER_R = 180;   // outer edge of a segment
const MAJOR_R = 130;   // radius where major key label sits
const MINOR_R = 90;    // radius where relative minor label sits
const INNER_R = 60;    // inner edge of a segment (creates the donut hole)

function toRad(deg: number) { return (deg * Math.PI) / 180; }

function polarToCartesian(cx: number, cy: number, r: number, angleDeg: number) {
  const rad = toRad(angleDeg);
  return { x: cx + r * Math.sin(rad), y: cy - r * Math.cos(rad) };
}

// Build the SVG <path> d-string for one donut segment (30° each)
// startAngle / endAngle are in degrees from top (12 o'clock = 0°), clockwise
function segmentPath(startDeg: number, endDeg: number, innerR: number, outerR: number): string {
  // Outer arc: start → end
  const o1 = polarToCartesian(CX, CY, outerR, startDeg);
  const o2 = polarToCartesian(CX, CY, outerR, endDeg);
  // Inner arc: end → start (reverse, to close the donut segment)
  const i1 = polarToCartesian(CX, CY, innerR, endDeg);
  const i2 = polarToCartesian(CX, CY, innerR, startDeg);

  return [
    `M ${o1.x} ${o1.y}`,
    `A ${outerR} ${outerR} 0 0 1 ${o2.x} ${o2.y}`,
    `L ${i1.x} ${i1.y}`,
    `A ${innerR} ${innerR} 0 0 0 ${i2.x} ${i2.y}`,
    'Z',
  ].join(' ');
}

interface Segment {
  index: number;
  key: string;
  relMinor: string;
  path: string;
  majorLabelPos: { x: number; y: number };
  minorLabelPos: { x: number; y: number };
  // rotation for text readability — slant each label toward segment center
  labelAngle: number;
}

const segments = computed<Segment[]>(() =>
  KEYS.map((key, i) => {
    const startDeg = i * 30 - 15;    // center each segment on its clock position
    const endDeg = startDeg + 30;
    const midDeg = i * 30;           // center angle for label placement

    return {
      index: i,
      key,
      relMinor: RELATIVE_MINORS[i],
      path: segmentPath(startDeg, endDeg, INNER_R, OUTER_R),
      majorLabelPos: polarToCartesian(CX, CY, MAJOR_R, midDeg),
      minorLabelPos: polarToCartesian(CX, CY, MINOR_R, midDeg),
      // rotate text so it reads radially; top/bottom keys don't need rotation
      labelAngle: midDeg,
    };
  }),
);

function selectKey(i: number) {
  selectedIndex.value = selectedIndex.value === i ? null : i;
}

function segmentClass(i: number) {
  return selectedIndex.value === i ? 'cof-seg cof-seg--selected' : 'cof-seg';
}

// Info line shown below the wheel when a key is selected
const infoText = computed(() => {
  if (selectedIndex.value === null) return '';
  const key = KEYS[selectedIndex.value];
  const rel = RELATIVE_MINORS[selectedIndex.value];
  return `${key} major — relative minor: ${rel}`;
});
</script>

<template>
  <div class="sbn-edu-widget cof-widget">
    <svg
      viewBox="0 0 400 400"
      width="100%"
      class="cof-svg"
      role="img"
      aria-label="Circle of fifths — click a segment to select a key"
    >
      <!-- Donut segments -->
      <g>
        <path
          v-for="seg in segments"
          :key="seg.index"
          :d="seg.path"
          :class="segmentClass(seg.index)"
          role="button"
          :aria-label="`${seg.key} (${seg.relMinor})`"
          tabindex="0"
          @click="selectKey(seg.index)"
          @keydown.enter="selectKey(seg.index)"
          @keydown.space.prevent="selectKey(seg.index)"
        />
      </g>

      <!-- Dividing lines between segments (thin radial lines) -->
      <g class="cof-dividers">
        <line
          v-for="i in 12"
          :key="i"
          :x1="polarToCartesian(CX, CY, INNER_R, (i - 1) * 30 - 15).x"
          :y1="polarToCartesian(CX, CY, INNER_R, (i - 1) * 30 - 15).y"
          :x2="polarToCartesian(CX, CY, OUTER_R, (i - 1) * 30 - 15).x"
          :y2="polarToCartesian(CX, CY, OUTER_R, (i - 1) * 30 - 15).y"
        />
      </g>

      <!-- Major key labels (outer ring) -->
      <text
        v-for="seg in segments"
        :key="`maj-${seg.index}`"
        :x="seg.majorLabelPos.x"
        :y="seg.majorLabelPos.y"
        :class="['cof-label-major', selectedIndex === seg.index ? 'cof-label--selected' : '']"
        text-anchor="middle"
        dominant-baseline="middle"
        @click="selectKey(seg.index)"
      >{{ seg.key }}</text>

      <!-- Relative minor labels (inner ring) -->
      <text
        v-for="seg in segments"
        :key="`min-${seg.index}`"
        :x="seg.minorLabelPos.x"
        :y="seg.minorLabelPos.y"
        :class="['cof-label-minor', selectedIndex === seg.index ? 'cof-label--selected' : '']"
        text-anchor="middle"
        dominant-baseline="middle"
        @click="selectKey(seg.index)"
      >{{ seg.relMinor }}</text>

      <!-- Centre circle -->
      <circle :cx="CX" :cy="CY" :r="INNER_R - 4" class="cof-center" />
      <text :x="CX" :y="CY" text-anchor="middle" dominant-baseline="middle" class="cof-center-label">
        5ths
      </text>
    </svg>

    <!-- Selected key info strip -->
    <div class="cof-info" :class="{ 'cof-info--visible': selectedIndex !== null }">
      {{ infoText }}
    </div>
  </div>
</template>

<style scoped>
.cof-widget {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  padding: 12px;
  background: var(--clr-surface-2, #f7fafc);
  border: 1px solid var(--clr-border, #e2e8f0);
  border-radius: var(--radius, 10px);
  font-family: var(--font-body, system-ui, sans-serif);
  user-select: none;
}

.cof-svg {
  max-width: 360px;
  cursor: pointer;
  display: block;
}

/* Base segment */
.cof-seg {
  fill: var(--clr-surface, #fff);
  stroke: var(--clr-surface-2, #f7fafc);
  stroke-width: 0;
  transition: fill 0.15s var(--ease, ease);
}

.cof-seg:hover {
  fill: var(--clr-accent-bg, rgba(243, 156, 18, 0.08));
}

.cof-seg:focus {
  outline: none;
  fill: var(--clr-accent-bg, rgba(243, 156, 18, 0.08));
}

/* Selected key */
.cof-seg--selected {
  fill: var(--clr-accent, #f39c12);
}

.cof-seg--selected:hover {
  fill: var(--clr-accent-dim, #e67e22);
}

/* Divider lines */
.cof-dividers line {
  stroke: var(--clr-border, #e2e8f0);
  stroke-width: 1;
}

/* Major key text */
.cof-label-major {
  font-size: 13px;
  font-weight: 700;
  fill: var(--clr-text, #2c3e50);
  cursor: pointer;
  pointer-events: none;
}

/* Minor key text */
.cof-label-minor {
  font-size: 10px;
  font-weight: 400;
  fill: var(--clr-text-muted, #8896a4);
  cursor: pointer;
  pointer-events: none;
}

/* Labels on selected segment */
.cof-label--selected {
  fill: var(--clr-surface, #fff);
}

/* Centre circle */
.cof-center {
  fill: var(--clr-surface-2, #f7fafc);
  stroke: var(--clr-border, #e2e8f0);
  stroke-width: 1;
}

.cof-center-label {
  font-size: 11px;
  font-weight: 600;
  fill: var(--clr-text-muted, #8896a4);
  letter-spacing: 0.5px;
  text-transform: uppercase;
  pointer-events: none;
}

/* Info strip below the wheel */
.cof-info {
  min-height: 20px;
  font-size: 13px;
  font-weight: 600;
  color: var(--clr-text, #2c3e50);
  opacity: 0;
  transition: opacity 0.2s var(--ease, ease);
}

.cof-info--visible {
  opacity: 1;
}
</style>

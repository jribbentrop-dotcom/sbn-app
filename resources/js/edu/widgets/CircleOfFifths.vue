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
const MAJOR_R = 152;   // radius where major key label sits
const MINOR_R = 118;   // radius where relative minor label sits
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
    <div class="cof-header">
      <div class="cof-label">Circle of Fifths</div>
    </div>
    <svg
      viewBox="-10 -10 420 420"
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
      >{{ seg.relMinor }}</text>

      <!-- Outer accent ring -->
      <circle :cx="CX" :cy="CY" :r="OUTER_R + 6" class="cof-outer-ring" />

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
  padding: 1.75rem 1.5rem 1.5rem;
  background: #0f0f17;
  border-radius: var(--radius, 10px);
  font-family: var(--font-body, system-ui, sans-serif);
  user-select: none;
}

.cof-header { width: 100%; display: flex; align-items: center; justify-content: space-between; }
.cof-label { font-family: 'DM Mono', monospace; font-size: 0.65rem; letter-spacing: 0.15em; text-transform: uppercase; color: #ffffff; }

.cof-svg {
  max-width: 420px;
  cursor: pointer;
  display: block;
}

/* Base segment */
.cof-seg {
  fill: rgba(255,255,255,0.05);
  stroke: #0f0f17;
  stroke-width: 2;
  transition: fill 0.15s ease;
}

.cof-seg:hover {
  fill: rgba(255,255,255,0.12);
}

.cof-seg:focus {
  outline: none;
  fill: rgba(255,255,255,0.12);
}

/* Selected key — higher specificity beats :hover */
.cof-seg.cof-seg--selected,
.cof-seg.cof-seg--selected:hover,
.cof-seg.cof-seg--selected:focus {
  fill: rgba(255,255,255,0.88) !important;
}

/* Outer accent ring */
.cof-outer-ring {
  fill: none;
  stroke: rgba(255,255,255,0.35);
  stroke-width: 0.75;
  pointer-events: none;
}

/* Divider lines */
.cof-dividers line {
  stroke: rgba(255,255,255,0.35);
  stroke-width: 0.75;
}

/* Major key text */
.cof-label-major {
  font-size: 20px;
  font-weight: 700;
  fill: #ffffff;
  cursor: pointer;
  pointer-events: none;
}

/* Minor key text */
.cof-label-minor {
  font-size: 13px;
  font-weight: 500;
  fill: #ffffff;
  cursor: pointer;
  pointer-events: none;
}

/* Labels on selected segment */
.cof-label--selected {
  fill: #0f0f17;
}

/* Centre circle */
.cof-center {
  fill: #0f0f17;
  stroke: rgba(255,255,255,0.35);
  stroke-width: 0.75;
}

.cof-center-label {
  font-size: 12px;
  font-weight: 600;
  fill: #ffffff;
  letter-spacing: 0.5px;
  text-transform: uppercase;
  pointer-events: none;
}

/* Info strip below the wheel */
.cof-info {
  min-height: 20px;
  font-size: 13px;
  font-weight: 600;
  color: #ffffff;
  opacity: 0;
  transition: opacity 0.2s ease;
}

.cof-info--visible {
  opacity: 1;
}
</style>

<script setup lang="ts">
import { computed, reactive, ref, watch, onUnmounted } from 'vue';

const KEYS = ['C', 'G', 'D', 'A', 'E', 'B', 'F♯/G♭', 'D♭', 'A♭', 'E♭', 'B♭', 'F'] as const;
const RELATIVE_MINORS = ['Am', 'Em', 'Bm', 'F♯m', 'C♯m', 'G♯m', 'D♯m/E♭m', 'B♭m', 'Fm', 'Cm', 'Gm', 'Dm'] as const;

const SCALES: Record<string, string[]> = {
  'C':     ['C',  'D',  'E',  'F',  'G',  'A',  'B' ],
  'G':     ['G',  'A',  'B',  'C',  'D',  'E',  'F♯'],
  'D':     ['D',  'E',  'F♯', 'G',  'A',  'B',  'C♯'],
  'A':     ['A',  'B',  'C♯', 'D',  'E',  'F♯', 'G♯'],
  'E':     ['E',  'F♯', 'G♯', 'A',  'B',  'C♯', 'D♯'],
  'B':     ['B',  'C♯', 'D♯', 'E',  'F♯', 'G♯', 'A♯'],
  'F♯/G♭': ['F♯', 'G♯', 'A♯', 'B',  'C♯', 'D♯', 'E♯'],
  'D♭':    ['D♭', 'E♭', 'F',  'G♭', 'A♭', 'B♭', 'C' ],
  'A♭':    ['A♭', 'B♭', 'C',  'D♭', 'E♭', 'F',  'G' ],
  'E♭':    ['E♭', 'F',  'G',  'A♭', 'B♭', 'C',  'D' ],
  'B♭':    ['B♭', 'C',  'D',  'E♭', 'F',  'G',  'A' ],
  'F':     ['F',  'G',  'A',  'B♭', 'C',  'D',  'E' ],
};

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
const OUTER_R = 180;
const MAJOR_R = 152;
const MINOR_R = 118;
const INNER_R = 60;

function toRad(deg: number) { return (deg * Math.PI) / 180; }

function polarToCartesian(cx: number, cy: number, r: number, angleDeg: number) {
  const rad = toRad(angleDeg);
  return { x: cx + r * Math.sin(rad), y: cy - r * Math.cos(rad) };
}

function segmentPath(startDeg: number, endDeg: number, innerR: number, outerR: number): string {
  const o1 = polarToCartesian(CX, CY, outerR, startDeg);
  const o2 = polarToCartesian(CX, CY, outerR, endDeg);
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
  labelAngle: number;
}

const segments = computed<Segment[]>(() =>
  KEYS.map((key, i) => {
    const startDeg = i * 30 - 15;
    const endDeg   = startDeg + 30;
    const midDeg   = i * 30;
    return {
      index: i,
      key,
      relMinor: RELATIVE_MINORS[i],
      path: segmentPath(startDeg, endDeg, INNER_R, OUTER_R),
      majorLabelPos: polarToCartesian(CX, CY, MAJOR_R, midDeg),
      minorLabelPos: polarToCartesian(CX, CY, MINOR_R, midDeg),
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

// Scale notes for selected key
const scaleNotes = computed(() => {
  if (selectedIndex.value === null) return [];
  return SCALES[KEYS[selectedIndex.value]] ?? [];
});

// Per-note highlight state — snaps to amber, slow fade back to white
const noteHighlights = reactive<boolean[]>(Array(7).fill(false));
let animTimers: ReturnType<typeof setTimeout>[] = [];

watch(selectedIndex, () => {
  animTimers.forEach(t => clearTimeout(t));
  animTimers = [];
  for (let i = 0; i < 7; i++) noteHighlights[i] = false;

  if (selectedIndex.value === null) return;

  // Stagger the amber highlight across all 7 scale notes (root → leading tone)
  for (let i = 0; i < 7; i++) {
    const onAt  = i * 90;   // 90 ms between each note
    const offAt = onAt + 950;
    animTimers.push(
      setTimeout(() => { noteHighlights[i] = true;  }, onAt),
      setTimeout(() => { noteHighlights[i] = false; }, offAt),
    );
  }
});

onUnmounted(() => { animTimers.forEach(t => clearTimeout(t)); });

const infoText = computed(() => {
  if (selectedIndex.value === null) return '';
  return `${KEYS[selectedIndex.value]} major · ${RELATIVE_MINORS[selectedIndex.value]}`;
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

      <!-- Dividing lines between segments -->
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

      <!-- Major key labels -->
      <text
        v-for="seg in segments"
        :key="`maj-${seg.index}`"
        :x="seg.majorLabelPos.x"
        :y="seg.majorLabelPos.y"
        :class="['cof-label-major', selectedIndex === seg.index ? 'cof-label--selected' : '']"
        text-anchor="middle"
        dominant-baseline="middle"
      >{{ seg.key }}</text>

      <!-- Relative minor labels -->
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

    <!-- Selected key info + scale notes -->
    <div class="cof-info" :class="{ 'cof-info--visible': selectedIndex !== null }">
      <div class="cof-info-text">{{ infoText }}</div>
      <div class="cof-scale-notes">
        <span
          v-for="(note, i) in scaleNotes"
          :key="i"
          class="cof-note"
          :class="{ 'cof-note--highlight': noteHighlights[i] }"
        >{{ note }}</span>
      </div>
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

.cof-svg { max-width: 420px; cursor: pointer; display: block; }

.cof-seg {
  fill: rgba(255,255,255,0.05);
  stroke: #0f0f17;
  stroke-width: 2;
  transition: fill 0.15s ease;
}
.cof-seg:hover { fill: rgba(255,255,255,0.12); }
.cof-seg:focus { outline: none; fill: rgba(255,255,255,0.12); }
.cof-seg.cof-seg--selected,
.cof-seg.cof-seg--selected:hover,
.cof-seg.cof-seg--selected:focus { fill: rgba(255,255,255,0.88) !important; }

.cof-outer-ring { fill: none; stroke: rgba(255,255,255,0.35); stroke-width: 0.75; pointer-events: none; }
.cof-dividers line { stroke: rgba(255,255,255,0.35); stroke-width: 0.75; }

.cof-label-major { font-size: 20px; font-weight: 700; fill: #ffffff; cursor: pointer; pointer-events: none; }
.cof-label-minor { font-size: 13px; font-weight: 500; fill: #ffffff; cursor: pointer; pointer-events: none; }
.cof-label--selected { fill: #0f0f17; }

.cof-center { fill: #0f0f17; stroke: rgba(255,255,255,0.35); stroke-width: 0.75; }
.cof-center-label { font-size: 12px; font-weight: 600; fill: #ffffff; letter-spacing: 0.5px; text-transform: uppercase; pointer-events: none; }

/* Info strip */
.cof-info {
  width: 100%;
  min-height: 60px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  opacity: 0;
  transition: opacity 0.3s ease;
}
.cof-info--visible { opacity: 1; }

.cof-info-text {
  font-family: 'DM Mono', monospace;
  font-size: 0.7rem;
  font-weight: 500;
  color: rgba(255,255,255,0.4);
  letter-spacing: 0.08em;
  text-align: center;
}

/* Scale notes row */
.cof-scale-notes {
  display: flex;
  gap: 2px;
  justify-content: center;
  align-items: center;
}

.cof-note {
  font-family: 'DM Mono', monospace;
  font-size: 0.95rem;
  font-weight: 500;
  min-width: 2.4rem;
  text-align: center;
  color: rgba(255,255,255,0.85);
  transition: color 0.65s ease; /* slow fade back to white */
  padding: 0.2rem 0;
}

/* Fast snap to amber on highlight, then the cof-note transition carries the fade out */
.cof-note--highlight {
  color: #f59e0b;
  transition: color 0.08s ease;
}
</style>

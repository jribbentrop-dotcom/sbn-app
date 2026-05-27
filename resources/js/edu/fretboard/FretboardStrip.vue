<script setup lang="ts">
import { computed, ref } from 'vue';

/**
 * Mini neck strip with peghead and a position-window indicator that doubles
 * as a draggable slider handle. The orange rect IS the slider thumb.
 *
 * Emits `update:windowStart` when the user drags; clamps to a snap-friendly
 * range and snaps to the nearest snap target on release if `snapTargets` is
 * provided.
 */

interface Props {
  windowStart: number;
  windowFrets?: number;
  totalFrets?: number;
  noAnimate?: boolean;
  /** When the user lets go, snap to the nearest value in this list (fret numbers). */
  snapTargets?: number[];
  /** Disable interaction (e.g. CAGED, where the parent drives windowStart). */
  interactive?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
  windowFrets: 4,
  totalFrets: 15,
  noAnimate: false,
  snapTargets: () => [],
  interactive: false,
});

const emit = defineEmits<{
  (e: 'update:windowStart', value: number): void;
  (e: 'scrub-start'): void;
  (e: 'scrub-end'): void;
}>();

const ROOT_COLOR = '#f59e0b';
const DURATION = 900;

// Geometry — viewBox is "-12 0 272 20"; the playable strip spans x=14 to x=246 (width 232).
const STRIP_X0 = 14;
const STRIP_W = 232;

function stripX(windowStart: number) {
  return STRIP_X0 + (windowStart / props.totalFrets) * STRIP_W;
}

const stripW = computed(() => (props.windowFrets / props.totalFrets) * STRIP_W);

const stripFretLines = computed(() =>
  Array.from({ length: props.totalFrets }, (_, i) => i + 1)
);

const svgRef = ref<SVGSVGElement | null>(null);
const isDragging = ref(false);

/** Convert a pointer event into a windowStart (fret) value. */
function pointerToWindowStart(e: PointerEvent): number {
  const svg = svgRef.value;
  if (!svg) return props.windowStart;
  const rect = svg.getBoundingClientRect();
  // Map clientX → SVG viewBox X. viewBox starts at -12, total width 272.
  const vbX = -12 + ((e.clientX - rect.left) / rect.width) * 272;
  // The handle's leftmost edge can be at STRIP_X0; its rightmost at STRIP_X0 + STRIP_W - stripW.
  // We treat the pointer as the *centre* of the handle for a natural feel.
  const handleCentre = vbX;
  const handleLeft = handleCentre - stripW.value / 2;
  const clampedLeft = Math.max(
    STRIP_X0,
    Math.min(STRIP_X0 + STRIP_W - stripW.value, handleLeft)
  );
  return ((clampedLeft - STRIP_X0) / STRIP_W) * props.totalFrets;
}

function onPointerDown(e: PointerEvent) {
  if (!props.interactive) return;
  isDragging.value = true;
  emit('scrub-start');
  (e.target as Element).setPointerCapture?.(e.pointerId);
  emit('update:windowStart', pointerToWindowStart(e));
  e.preventDefault();
}

function onPointerMove(e: PointerEvent) {
  if (!isDragging.value) return;
  emit('update:windowStart', pointerToWindowStart(e));
}

function onPointerUp(e: PointerEvent) {
  if (!isDragging.value) return;
  isDragging.value = false;
  (e.target as Element).releasePointerCapture?.(e.pointerId);

  emit('scrub-end');
  if (props.snapTargets.length) {
    let nearest = props.snapTargets[0];
    let bestDist = Math.abs(props.windowStart - nearest);
    for (const t of props.snapTargets) {
      const d = Math.abs(props.windowStart - t);
      if (d < bestDist) { bestDist = d; nearest = t; }
    }
    emit('update:windowStart', nearest);
  }
}

const cursorStyle = computed(() =>
  props.interactive ? (isDragging.value ? 'grabbing' : 'grab') : 'default'
);
</script>

<template>
  <svg
    ref="svgRef"
    width="100%"
    viewBox="-12 0 272 20"
    style="display:block; touch-action: none;"
    :style="{ cursor: cursorStyle }"
    @pointerdown="onPointerDown"
    @pointermove="onPointerMove"
    @pointerup="onPointerUp"
    @pointercancel="onPointerUp"
  >
    <path d="M 0,2 L 14,4 L 14,16 L 0,18 Q -4,10 0,2 Z" fill="rgba(255,255,255,0.15)" />
    <g v-for="(t, i) in [0.25, 0.5, 0.75]" :key="'peg'+i">
      <line :x1="-2" :y1="2 + t * 16 - 5" :x2="-7" :y2="2 + t * 16 - 5"
        stroke="rgba(255,255,255,0.2)" stroke-width="1" />
      <circle cx="-7" :cy="2 + t * 16 - 5" r="1.5" fill="rgba(255,255,255,0.6)" />
    </g>
    <rect x="14" y="4" width="232" height="12" rx="2" fill="rgba(255,255,255,0.1)" />
    <rect x="14" y="3" width="2.5" height="14" rx="1" fill="#f59e0b" />
    <line v-for="i in stripFretLines" :key="'sf'+i"
      :x1="14 + (i / totalFrets) * 232" y1="4"
      :x2="14 + (i / totalFrets) * 232" y2="16"
      stroke="rgba(255,255,255,0.08)" stroke-width="0.5"
    />
    <!-- Position-window indicator / slider handle -->
    <rect
      :x="stripX(windowStart)" y="3"
      :width="stripW" height="14"
      rx="2" fill="rgba(243,156,18,0.35)" :stroke="ROOT_COLOR" stroke-width="1"
      :style="noAnimate ? '' : `transition: x ${DURATION}ms cubic-bezier(0.65,0,0.35,1)`"
      style="pointer-events: none"
    />
  </svg>
</template>

<template>
    <div
        class="sbn-sync-badge"
        :class="`sbn-sync-badge--${context}`"
        :title="`Sync point ${markerIndex + 1} · ${formatTime(displayTime)} — drag to adjust, click to seek`"
        @pointerdown.stop="onPointerDown"
    >{{ markerIndex + 1 }}</div>
</template>

<script setup>
import { ref, inject } from 'vue';

const props = defineProps({
    markerIndex: { type: Number, required: true },
    videoTime:   { type: Number, required: true },
    measureIndex:{ type: Number, required: true },
    context:     { type: String, default: 'chord' }, // 'chord' | 'tab'
});

const nudgeSyncMapping = inject('nudgeSyncMapping', null);
const seekToMeasure    = inject('seekToMeasure', null);

// Live preview during drag — starts at the committed videoTime.
const displayTime = ref(props.videoTime);

// Keep displayTime in sync when prop changes (e.g. from undo or another drag).
import { watch } from 'vue';
watch(() => props.videoTime, (v) => { displayTime.value = v; });

function formatTime(sec) {
    if (!isFinite(sec)) return '0:00.0';
    const m = Math.floor(sec / 60);
    const s = (sec % 60).toFixed(1).padStart(4, '0');
    return `${m}:${s}`;
}

// ── Drag to adjust time ─────────────────────────────────────────
// Horizontal drag only. Each pixel = SECS_PER_PX seconds.
// During drag: update displayTime live (preview). On release: commit via nudge.

const SECS_PER_PX = 0.05;

let _startX    = 0;
let _startTime = 0;
let _pointerId = null;

function onPointerDown(e) {
    if (e.button !== 0) return;
    e.preventDefault();
    _startX    = e.clientX;
    _startTime = props.videoTime;
    _pointerId = e.pointerId;
    displayTime.value = _startTime;
    e.currentTarget.setPointerCapture(e.pointerId);
    e.currentTarget.addEventListener('pointermove', onPointerMove);
    e.currentTarget.addEventListener('pointerup',   onPointerUp);
    e.currentTarget.addEventListener('pointercancel', onPointerUp);
}

function onPointerMove(e) {
    const dx    = e.clientX - _startX;
    const newT  = Math.max(0, _startTime + dx * SECS_PER_PX);
    displayTime.value = newT;
}

function onPointerUp(e) {
    e.currentTarget.releasePointerCapture(_pointerId);
    e.currentTarget.removeEventListener('pointermove', onPointerMove);
    e.currentTarget.removeEventListener('pointerup',   onPointerUp);
    e.currentTarget.removeEventListener('pointercancel', onPointerUp);
    const delta = displayTime.value - props.videoTime;
    if (Math.abs(delta) > 0.001) {
        nudgeSyncMapping?.(props.measureIndex, delta);
    } else {
        // Treat as click — seek playback to this sync point
        seekToMeasure?.(props.measureIndex);
    }
}
</script>

<style scoped>
.sbn-sync-badge {
    position: absolute;
    left: 0;
    transform: translateX(-50%);
    width: 22px;
    height: 22px;
    border-radius: 50%;
    background: radial-gradient(circle at 40% 35%, rgba(243,156,18,0.85) 0%, rgba(231,76,60,0.75) 100%);
    border: 1.5px solid rgba(231,76,60,0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 10px;
    font-weight: 700;
    font-family: var(--font-mono, monospace);
    color: #fff;
    text-shadow: 0 1px 2px rgba(0,0,0,0.4);
    cursor: ew-resize;
    user-select: none;
    touch-action: none;
    z-index: 10;
    pointer-events: all;
}

/* chord view: near top of measure cell */
.sbn-sync-badge--chord { top: 6px; }

/* tab view: between G (string 3, y=25) and D (string 4, y=35) inside the SVG.
   SVG starts after the 27px chord bar, midpoint is 30px into SVG → 57px total. */
.sbn-sync-badge--tab { top: 57px; }

.sbn-sync-badge:hover {
    background: radial-gradient(circle at 40% 35%, rgba(243,156,18,1) 0%, rgba(231,76,60,0.95) 100%);
    border-color: rgba(231,76,60,0.8);
    box-shadow: 0 0 0 3px rgba(231,76,60,0.15);
}

.sbn-sync-badge:active {
    transform: translateX(-50%) scale(0.92);
}
</style>
